<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AttractionPurchase;
use App\Models\Contact;
use App\Models\CustomerNotification;
use App\Models\Membership;
use App\Models\Notification;
use App\Models\PageView;
use Illuminate\Support\Facades\Log;

/**
 * Everything that has to happen for an attraction purchase to be a finished purchase
 * rather than just a database row: waiver, membership redemptions, customer and staff
 * notifications, audit log, CRM contact, the configurable email pipeline, and the
 * analytics conversion.
 *
 * Both the single-item checkout and each line of a bulk order run through here, so a
 * cart cannot silently skip half of them.
 */
class PurchaseCompletionService
{
    use \App\Http\Traits\RecordsPageAnalytics;

    /**
     * @param  array<string, mixed>  $input   the validated request payload (membership, consent, send_email)
     * @param  array<string, bool>   $only    per-step switches; a bulk order turns the
     *                                        customer-facing ones off and does them once at order level
     */
    public function completeAttractionPurchase(
        AttractionPurchase $purchase,
        array $input = [],
        array $only = []
    ): void {
        $run = fn (string $step) => $only[$step] ?? true;

        $purchase->loadMissing(['attraction.location', 'customer', 'createdBy', 'addOns']);

        if ($run('waiver')) {
            $this->safely('waiver', $purchase, fn () => app(WaiverService::class)->ensureForAttractionPurchase($purchase));
        }

        if ($run('membership')) {
            $this->safely('membership', $purchase, fn () => $this->recordMembershipRedemptions($purchase, $input));
        }

        if ($run('customer_notification')) {
            $this->safely('customer_notification', $purchase, fn () => $this->customerNotification($purchase));
        }

        if ($run('staff_notification')) {
            $this->safely('staff_notification', $purchase, fn () => $this->staffNotification($purchase));
        }

        if ($run('activity_log')) {
            $this->safely('activity_log', $purchase, fn () => $this->activityLog($purchase));
        }

        if ($run('contact')) {
            $this->safely('contact', $purchase, fn () => $this->crmContact($purchase, $input));
        }

        if ($run('email') && ($input['send_email'] ?? true)) {
            $this->safely('email', $purchase, fn () => app(EmailNotificationService::class)->processPurchaseCreated($purchase));
        }

        if ($run('conversion')) {
            $this->safely('conversion', $purchase, fn () => $this->recordConversion(
                'purchase_completed',
                $purchase,
                (float) ($purchase->total_amount ?? 0)
            ));
        }
    }

    private function safely(string $step, AttractionPurchase $purchase, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            Log::warning("Purchase completion step '{$step}' failed", [
                'attraction_purchase_id' => $purchase->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function customerName(AttractionPurchase $purchase): string
    {
        return $purchase->customer
            ? trim($purchase->customer->first_name . ' ' . $purchase->customer->last_name)
            : (string) $purchase->guest_name;
    }

    private function isPaid(AttractionPurchase $purchase): bool
    {
        return $purchase->status !== AttractionPurchase::STATUS_PENDING
            && (float) ($purchase->amount_paid ?? 0) > 0;
    }

    private function customerNotification(AttractionPurchase $purchase): void
    {
        if (!$purchase->customer_id || !$this->isPaid($purchase)) {
            return;
        }

        CustomerNotification::create([
            'customer_id' => $purchase->customer_id,
            'location_id' => $purchase->attraction->location_id ?? null,
            'type' => 'payment',
            'priority' => 'medium',
            'title' => 'Attraction Purchase Confirmed',
            'message' => "Your purchase of {$purchase->quantity} x {$purchase->attraction->name} has been confirmed. Total: $"
                . number_format((float) $purchase->total_amount, 2),
            'status' => 'unread',
            'action_url' => "/attractions/purchases/{$purchase->id}",
            'action_text' => 'View Purchase',
            'metadata' => [
                'purchase_id' => $purchase->id,
                'attraction_id' => $purchase->attraction_id,
                'quantity' => $purchase->quantity,
                'total_amount' => $purchase->total_amount,
                'ticket_order_id' => $purchase->ticket_order_id,
            ],
        ]);
    }

    private function staffNotification(AttractionPurchase $purchase): void
    {
        if (!$this->isPaid($purchase) || !$purchase->attraction->location_id) {
            return;
        }

        $name = $this->customerName($purchase);

        $notification = Notification::create([
            'location_id' => $purchase->attraction->location_id,
            'type' => 'payment',
            'priority' => 'medium',
            'user_id' => $purchase->created_by ?? auth()->id(),
            'title' => 'New Attraction Purchase',
            'message' => "{$name} — {$purchase->quantity}x {$purchase->attraction->name} • $"
                . number_format((float) $purchase->total_amount, 2),
            'status' => 'unread',
            'action_url' => "/attractions/purchases/{$purchase->id}",
            'action_text' => 'View Purchase',
            'metadata' => [
                'purchase_id' => $purchase->id,
                'attraction_id' => $purchase->attraction_id,
                'customer_id' => $purchase->customer_id,
                'quantity' => $purchase->quantity,
                'total_amount' => $purchase->total_amount,
            ],
        ]);

        app(PushNotificationService::class)->sendForNotification($notification);
    }

    /**
     * Logged for every purchase, staff-created or not. The single-item path only logged
     * when created_by was set, which meant guest online purchases left no audit trail.
     */
    private function activityLog(AttractionPurchase $purchase): void
    {
        $name = $this->customerName($purchase);

        ActivityLog::log(
            action: 'Attraction Purchase Created',
            category: 'create',
            description: "Attraction purchase: {$purchase->quantity} x {$purchase->attraction->name} by {$name}"
                . ($purchase->ticket_order_id ? " (bulk order line {$purchase->line_position})" : ''),
            userId: $purchase->created_by,
            locationId: $purchase->attraction->location_id ?? null,
            entityType: 'attraction_purchase',
            entityId: $purchase->id,
            metadata: [
                'created_by' => [
                    'user_id' => $purchase->created_by,
                    'name' => $purchase->createdBy
                        ? $purchase->createdBy->first_name . ' ' . $purchase->createdBy->last_name
                        : null,
                    'email' => $purchase->createdBy?->email,
                ],
                'created_at' => now()->toIso8601String(),
                'ticket_order_id' => $purchase->ticket_order_id,
                'line_position' => $purchase->line_position,
                'purchase_details' => [
                    'purchase_id' => $purchase->id,
                    'attraction_id' => $purchase->attraction_id,
                    'attraction_name' => $purchase->attraction->name,
                    'quantity' => $purchase->quantity,
                    'total_amount' => $purchase->total_amount,
                    'amount_paid' => $purchase->amount_paid,
                    'payment_method' => $purchase->payment_method,
                    'status' => $purchase->status,
                ],
                'customer_details' => [
                    'customer_id' => $purchase->customer_id,
                    'name' => $name,
                    'email' => $purchase->customer?->email ?? $purchase->guest_email,
                    'phone' => $purchase->customer?->phone ?? $purchase->guest_phone,
                ],
            ]
        );
    }

    private function crmContact(AttractionPurchase $purchase, array $input): void
    {
        $email = $purchase->customer?->email ?? $purchase->guest_email;
        $locationId = $purchase->attraction->location_id ?? null;

        if (!$email || !$locationId) {
            return;
        }

        $location = $purchase->attraction->location;

        if (!$location || !$location->company_id) {
            return;
        }

        Contact::createOrUpdateFromSource(
            companyId: $location->company_id,
            data: [
                'email' => $email,
                'name' => $this->customerName($purchase),
                'phone' => $purchase->customer?->phone ?? $purchase->guest_phone,
                'sms_consent' => array_key_exists('sms_consent', $input) ? (bool) $input['sms_consent'] : null,
            ],
            source: $purchase->ticket_order_id ? 'ticket_order' : 'attraction_purchase',
            tags: $purchase->ticket_order_id
                ? ['ticket_order', 'attraction_purchase', 'customer']
                : ['attraction_purchase', 'customer'],
            locationId: $locationId,
            createdBy: auth()->id()
        );
    }

    private function recordMembershipRedemptions(AttractionPurchase $purchase, array $input): void
    {
        if (empty($input['membership_id'])) {
            return;
        }

        $membership = Membership::find($input['membership_id']);

        if (!$membership || empty($input['membership_applied'])) {
            return;
        }

        app(MembershipBenefitService::class)->recordPurchaseRedemptions(
            $membership,
            $purchase,
            $input['membership_applied'],
            $purchase->attraction->location_id ?? null
        );
    }
}
