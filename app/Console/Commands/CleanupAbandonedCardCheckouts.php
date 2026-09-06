<?php

namespace App\Console\Commands;

use App\Http\Traits\ReversesGiftCards;
use App\Models\ActivityLog;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\EventPurchase;
use App\Models\Payment;
use App\Services\GoogleCalendarService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupAbandonedCardCheckouts extends Command
{
    use ReversesGiftCards;

    protected $signature = 'checkouts:cleanup-abandoned {--minutes=60} {--grace-days=7} {--dry-run}';

    protected $description = 'Silently delete card checkouts whose charge never completed, and cancel unpaid pay-later bookings whose visit day has long passed';

    public function handle(): int
    {
        $minutes = max(15, (int) $this->option('minutes'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subMinutes($minutes);
        $removed = 0;

        $bookings = Booking::where('status', 'pending')
            ->where('payment_method', 'authorize.net')
            ->whereNull('created_by')
            ->where(fn ($q) => $q->where('payment_status', 'pending')->orWhereNull('payment_status'))
            ->where(fn ($q) => $q->where('amount_paid', 0)->orWhereNull('amount_paid'))
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($bookings as $booking) {
            if ($this->hasCompletedPayment(Payment::TYPE_BOOKING, $booking->id)) {
                continue;
            }

            if ($dryRun) {
                $this->line("would delete booking {$booking->reference_number} ({$booking->created_at})");
                $removed++;
                continue;
            }

            try {
                $gcalService = new GoogleCalendarService($booking->location_id);
                if ($gcalService->isConnected() && $booking->google_calendar_event_id) {
                    $gcalService->deleteEvent($booking);
                }
            } catch (\Throwable $e) {
                Log::warning('Google Calendar cleanup failed during abandoned checkout sweep', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->reverseGiftCardFor($booking, Payment::TYPE_BOOKING, 'abandoned_card_checkout');
            $this->logRemoval('booking', $booking->id, $booking->reference_number, $booking->location_id, $booking->created_at?->toIso8601String());
            $booking->forceDelete();
            $removed++;
        }

        $purchaseSets = [
            ['model' => AttractionPurchase::class, 'type' => Payment::TYPE_ATTRACTION_PURCHASE, 'label' => 'attraction purchase', 'has_created_by' => true],
            ['model' => EventPurchase::class, 'type' => Payment::TYPE_EVENT_PURCHASE, 'label' => 'event purchase', 'has_created_by' => false],
        ];

        foreach ($purchaseSets as $set) {
            $purchases = $set['model']::where('status', 'pending')
                ->where('payment_method', 'authorize.net')
                ->when($set['has_created_by'], fn ($q) => $q->whereNull('created_by'))
                ->whereNull('ticket_order_id')
                ->where(fn ($q) => $q->where('amount_paid', 0)->orWhereNull('amount_paid'))
                ->where('created_at', '<', $cutoff)
                ->get();

            foreach ($purchases as $purchase) {
                if ($purchase->checked_in_at !== null) {
                    continue;
                }

                if ($this->hasCompletedPayment($set['type'], $purchase->id)) {
                    continue;
                }

                if ($dryRun) {
                    $ref = $purchase->reference_number ?? $purchase->id;
                    $this->line("would delete {$set['label']} {$ref} ({$purchase->created_at})");
                    $removed++;
                    continue;
                }

                $this->reverseGiftCardFor($purchase, $set['type'], 'abandoned_card_checkout');
                $this->logRemoval($set['label'], $purchase->id, $purchase->reference_number ?? (string) $purchase->id, $purchase->location_id ?? null, $purchase->created_at?->toIso8601String());
                $purchase->forceDelete();
                $removed++;
            }
        }

        $graceDays = max(1, (int) $this->option('grace-days'));
        $staleCutoff = now()->subDays($graceDays)->toDateString();
        $expired = 0;

        $staleBookings = Booking::where('status', 'pending')
            ->whereIn('payment_method', ['paylater', 'in-store'])
            ->where(fn ($q) => $q->where('amount_paid', 0)->orWhereNull('amount_paid'))
            ->whereNull('checked_in_at')
            ->where('booking_date', '<', $staleCutoff)
            ->get();

        foreach ($staleBookings as $booking) {
            if ($this->hasCompletedPayment(Payment::TYPE_BOOKING, $booking->id)) {
                continue;
            }

            if ($dryRun) {
                $this->line("would cancel unpaid pay-later booking {$booking->reference_number} (visit day {$booking->booking_date?->format('Y-m-d')})");
                $expired++;
                continue;
            }

            $this->reverseGiftCardFor($booking, Payment::TYPE_BOOKING, 'stale_paylater_expired');
            $booking->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
            $this->logRemoval('stale pay-later booking', $booking->id, $booking->reference_number, $booking->location_id, $booking->created_at?->toIso8601String());
            $expired++;
        }

        $verb = $dryRun ? 'would remove' : 'removed';
        $expireVerb = $dryRun ? 'would cancel' : 'cancelled';
        $this->info("Abandoned card checkouts: {$verb} {$removed}; stale pay-later bookings: {$expireVerb} {$expired}");

        return self::SUCCESS;
    }

    private function hasCompletedPayment(string $payableType, int $payableId): bool
    {
        return Payment::where('payable_type', $payableType)
            ->where('payable_id', $payableId)
            ->whereIn('status', ['completed', 'refunded'])
            ->exists();
    }

    private function logRemoval(string $kind, int $id, string $reference, ?int $locationId, ?string $createdAt): void
    {
        Log::info('Abandoned card checkout removed', [
            'kind' => $kind,
            'id' => $id,
            'reference' => $reference,
            'location_id' => $locationId,
            'created_at' => $createdAt,
        ]);

        try {
            ActivityLog::log(
                action: 'Abandoned Checkout Removed',
                category: 'delete',
                description: ucfirst($kind) . " {$reference} removed after its card payment never completed",
                userId: null,
                locationId: $locationId,
                entityType: str_replace(' ', '_', $kind),
                entityId: $id,
                metadata: [
                    'reference_number' => $reference,
                    'created_at' => $createdAt,
                    'removed_at' => now()->toIso8601String(),
                    'removed_by' => 'checkouts:cleanup-abandoned',
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Activity log write failed during abandoned checkout sweep', ['error' => $e->getMessage()]);
        }
    }
}
