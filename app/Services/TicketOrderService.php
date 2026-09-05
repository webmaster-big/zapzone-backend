<?php

namespace App\Services;

use App\Models\Attraction;
use App\Models\AttractionPurchase;
use App\Models\EventPurchase;
use App\Models\Location;
use App\Models\Notification;
use App\Models\TicketOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class TicketOrderService
{
    public function __construct(private TicketOrderPricer $pricer)
    {
    }

    public function quote(array $items): array
    {
        return $this->pricer->priceCart($items);
    }

    public function create(array $items, array $context = []): TicketOrder
    {
        $priced = $this->pricer->priceCart($items);

        $location = Location::find($priced['location_id']);

        if (!$location) {
            throw new RuntimeException('That location could not be found.');
        }

        $order = DB::transaction(function () use ($priced, $location, $context) {
            $this->pricer->assertSlotCapacity($priced['lines'], true);

                $reference = TicketOrder::generateReference();
            $giftCardApplied = $this->applyGiftCardToPricing($priced, $location, $context, $reference);
            $giftCardId = $giftCardApplied['id'] ?? null;

            $order = TicketOrder::create([
                'reference_number' => $reference,
                'company_id' => $location->company_id,
                'location_id' => $location->id,
                'customer_id' => $context['customer_id'] ?? null,
                'membership_id' => $context['membership_id'] ?? null,
                'created_by' => $context['created_by'] ?? null,
                'guest_name' => $context['guest_name'] ?? null,
                'guest_email' => $context['guest_email'] ?? null,
                'guest_phone' => $context['guest_phone'] ?? null,
                'guest_address' => $context['guest_address'] ?? null,
                'guest_city' => $context['guest_city'] ?? null,
                'guest_state' => $context['guest_state'] ?? null,
                'guest_zip' => $context['guest_zip'] ?? null,
                'guest_country' => $context['guest_country'] ?? null,
                'purchase_date' => $context['purchase_date'] ?? Carbon::now(config('app.timezone'))->toDateString(),
                'item_count' => $priced['item_count'],
                'ticket_count' => $priced['ticket_count'],
                'subtotal' => $priced['subtotal'],
                'discount_amount' => $priced['discount_amount'],
                'fee_total' => $priced['fee_total'],
                'total_amount' => $priced['total_amount'],
                'gift_card_id' => $giftCardId,
                'amount_paid' => 0,
                'applied_fees' => $this->collectFees($priced['lines']),
                'applied_discounts' => $this->collectDiscounts($priced['lines']),
                'payment_method' => $context['payment_method'] ?? null,
                'status' => $context['status'] ?? TicketOrder::STATUS_PENDING,
                'notes' => $context['notes'] ?? null,
                'expires_at' => $context['expires_at'] ?? null,
            ]);

            foreach ($priced['lines'] as $line) {
                $line['type'] === TicketOrderPricer::TYPE_ATTRACTION
                    ? $this->writeAttractionLine($order, $line)
                    : $this->writeEventLine($order, $line);
            }

            if ($giftCardApplied) {
                app(DiscountService::class)->recordRedemptionForPayable(
                    (int) $giftCardApplied['id'],
                    (float) $giftCardApplied['amount'],
                    \App\Models\Payment::TYPE_TICKET_ORDER,
                    (int) $order->id
                );
            }

            $this->assertLinesMatchOrder($order->fresh());

            return $order->fresh(['attractionPurchases', 'eventPurchases']);
        });

        return $this->finalizeIfNothingDue($order);
    }

    public function finalizeIfNothingDue(TicketOrder $order): TicketOrder
    {
        if ($order->status === TicketOrder::STATUS_PENDING
            && TicketOrderAllocator::toCents((float) $order->total_amount) === 0
            && TicketOrderAllocator::toCents((float) $order->amount_paid) === 0
            && (float) $order->discount_amount > 0) {
            return $this->applyPayment($order, 0.0);
        }

        return $order;
    }

    private function applyGiftCardToPricing(array &$priced, Location $location, array $context, string $reference): ?array
    {
        $code = trim((string) ($context['gift_card_code'] ?? ''));

        if ($code === '') {
            return null;
        }

        $items = [];
        foreach ($priced['lines'] as $line) {
            $items[] = [
                'type' => $line['type'] === TicketOrderPricer::TYPE_ATTRACTION ? 'attraction' : 'event',
                'id' => (int) $line['entity_id'],
            ];
        }

        $card = \App\Models\GiftCard::byCode($code)->first();
        $eligibleIndexes = array_keys($priced['lines']);

        if ($card && !$card->isItemWide()) {
            $eligibleIndexes = [];
            foreach ($priced['lines'] as $index => $line) {
                $type = $line['type'] === TicketOrderPricer::TYPE_ATTRACTION ? 'attraction' : 'event';
                if ($card->appliesToItem($type, (int) $line['entity_id'])) {
                    $eligibleIndexes[] = $index;
                }
            }
        }

        $eligibleSubtotal = 0.0;
        foreach ($eligibleIndexes as $index) {
            $eligibleSubtotal += (float) $priced['lines'][$index]['total_amount'];
        }
        $eligibleSubtotal = round($eligibleSubtotal, 2);

        $result = app(DiscountService::class)->applyToCheckout([
            'gift_card_code' => $code,
            'subtotal' => $eligibleSubtotal,
            'location_id' => $location->id,
            'items' => $items,
            'customer_id' => $context['customer_id'] ?? null,
            'tracking_prefix' => 'srv:ticket_order:' . $reference,
        ]);

        $discount = round((float) ($result['gift_card_discount'] ?? 0), 2);
        $giftCardId = $result['gift_card_id'] ?? null;

        if ($discount <= 0 || !$giftCardId) {
            $why = implode(' ', array_filter(array_map(
                fn ($e) => is_string($e) ? $e : ($e['message'] ?? ''),
                (array) ($result['errors'] ?? [])
            )));

            throw new RuntimeException($why !== ''
                ? $why
                : 'That gift card could not be applied to this order.');
        }

        $entryTemplate = null;
        foreach ((array) ($result['applied_discounts'] ?? []) as $entry) {
            if (is_array($entry) && ($entry['source'] ?? null) === 'gift_card') {
                $entryTemplate = $entry;
                break;
            }
        }

        $weights = [];
        foreach ($eligibleIndexes as $index) {
            $weights[$index] = TicketOrderAllocator::toCents((float) $priced['lines'][$index]['total_amount']);
        }

        $shares = TicketOrderAllocator::allocate(TicketOrderAllocator::toCents($discount), $weights);

        foreach ($priced['lines'] as $index => $line) {
            $share = TicketOrderAllocator::toAmount($shares[$index] ?? 0);

            if ($share <= 0) {
                continue;
            }

            $priced['lines'][$index]['total_amount'] = round((float) $line['total_amount'] - $share, 2);
            $priced['lines'][$index]['discount_amount'] = round((float) ($line['discount_amount'] ?? 0) + $share, 2);

            $entry = $entryTemplate ?? ['source' => 'gift_card', 'gift_card_id' => $giftCardId];
            $entry['discount_amount'] = $share;
            $priced['lines'][$index]['applied_discounts'] = array_merge(
                (array) ($line['applied_discounts'] ?? []),
                [$entry]
            );
        }

        $priced['discount_amount'] = round((float) $priced['discount_amount'] + $discount, 2);
        $priced['total_amount'] = round((float) $priced['total_amount'] - $discount, 2);

        return ['id' => (int) $giftCardId, 'amount' => $discount];
    }

    private function writeAttractionLine(TicketOrder $order, array $line): AttractionPurchase
    {
        $purchase = AttractionPurchase::create([
            'ticket_order_id' => $order->id,
            'line_position' => $line['position'],
            'attraction_id' => $line['entity_id'],
            'customer_id' => $order->customer_id,
            'membership_id' => $order->membership_id,
            'created_by' => $order->created_by,
            'guest_name' => $order->guest_name,
            'guest_email' => $order->guest_email,
            'guest_phone' => $order->guest_phone,
            'guest_address' => $order->guest_address,
            'guest_city' => $order->guest_city,
            'guest_state' => $order->guest_state,
            'guest_zip' => $order->guest_zip,
            'guest_country' => $order->guest_country,
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
            'unit_price_after_discount' => $line['unit_price_after_discount'],
            'total_amount' => $line['total_amount'],
            'applied_fees' => $line['applied_fees'],
            'discount_amount' => $line['discount_amount'],
            'applied_discounts' => $line['applied_discounts'],
            'amount_paid' => 0,
            'payment_method' => $order->payment_method,
            'status' => AttractionPurchase::STATUS_PENDING,
            'purchase_date' => $order->purchase_date->toDateString(),
            'scheduled_date' => $line['scheduled_date'],
            'scheduled_time' => $line['scheduled_time'],
        ]);

        $this->writeAddOns('attraction_purchase_add_ons', 'attraction_purchase_id', $purchase->id, $line['add_ons']);

        return $purchase;
    }

    private function writeEventLine(TicketOrder $order, array $line): EventPurchase
    {
        $purchase = EventPurchase::create([
            'ticket_order_id' => $order->id,
            'line_position' => $line['position'],
            'reference_number' => $this->generateEventReference(),
            'event_id' => $line['entity_id'],
            'customer_id' => $order->customer_id,
            'membership_id' => $order->membership_id,
            'location_id' => $order->location_id,
            'guest_name' => $order->guest_name,
            'guest_email' => $order->guest_email,
            'guest_phone' => $order->guest_phone,
            'purchase_date' => $line['scheduled_date'] ?? $order->purchase_date->toDateString(),
            'purchase_time' => $line['scheduled_time'] ?? '00:00',
            'quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'],
            'unit_price_after_discount' => $line['unit_price_after_discount'],
            'total_amount' => $line['total_amount'],
            'applied_fees' => $line['applied_fees'],
            'discount_amount' => $line['discount_amount'],
            'applied_discounts' => $line['applied_discounts'],
            'amount_paid' => 0,
            'payment_method' => $order->payment_method,
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);

        $this->writeAddOns('event_purchase_add_ons', 'event_purchase_id', $purchase->id, $line['add_ons']);

        return $purchase;
    }

    private function writeAddOns(string $table, string $foreignKey, int $lineId, array $addOns): void
    {
        if ($addOns === []) {
            return;
        }

        $rows = [];

        foreach ($addOns as $addOn) {
            $rows[] = [
                $foreignKey => $lineId,
                'add_on_id' => $addOn['add_on_id'],
                'quantity' => $addOn['quantity'],
                'price_at_purchase' => $addOn['price_at_purchase'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table($table)->insert($rows);
    }

    public function applyPayment(TicketOrder $order, float $amount, ?string $transactionId = null): TicketOrder
    {
        $justConfirmed = false;

        $result = DB::transaction(function () use ($order, $amount, $transactionId, &$justConfirmed) {
            $order->refresh();

            $lines = $order->lines();

            if ($lines->isEmpty()) {
                throw new RuntimeException('An order with no lines cannot take a payment.');
            }

            if ($order->status === TicketOrder::STATUS_CANCELLED) {
                throw new RuntimeException('This order is cancelled and cannot take a payment.');
            }

            if ($amount < 0) {
                throw new RuntimeException('A payment amount cannot be negative.');
            }

            $newPaidCents = TicketOrderAllocator::toCents((float) $order->amount_paid + $amount);
            $orderTotalCents = TicketOrderAllocator::toCents((float) $order->total_amount);

            if ($newPaidCents > $orderTotalCents) {
                throw new RuntimeException('That payment is more than the order balance.');
            }

            $weights = [];
            foreach ($lines as $index => $line) {
                $weights[$index] = TicketOrderAllocator::toCents((float) $line['model']->total_amount);
            }

            $perLine = $newPaidCents === $orderTotalCents
                ? $weights
                : TicketOrderAllocator::allocate($newPaidCents, $weights);

            $fullyPaid = $newPaidCents === $orderTotalCents;

            foreach ($lines as $index => $line) {
                $model = $line['model'];
                $model->amount_paid = TicketOrderAllocator::toAmount($perLine[$index]);
                $model->status = $fullyPaid
                    ? ($line['type'] === TicketOrderPricer::TYPE_ATTRACTION
                        ? AttractionPurchase::STATUS_CONFIRMED
                        : 'confirmed')
                    : $model->status;

                if ($line['type'] === TicketOrderPricer::TYPE_EVENT) {
                    $model->payment_status = $fullyPaid ? 'paid' : 'partial';
                }

                if ($transactionId) {
                    $model->transaction_id = $transactionId;
                }

                $model->save();
            }

            $order->amount_paid = TicketOrderAllocator::toAmount($newPaidCents);
            $order->transaction_id = $transactionId ?? $order->transaction_id;

            $justConfirmed = $fullyPaid && $order->confirmed_at === null;

            if ($fullyPaid) {
                $order->status = TicketOrder::STATUS_CONFIRMED;
                $order->confirmed_at = $order->confirmed_at ?? now();
            }

            $order->save();

            $this->assertLinesMatchOrder($order->fresh());

            return $order->fresh(['attractionPurchases', 'eventPurchases']);
        });

        if ($justConfirmed) {
            $this->sendOrderNotifications($result->fresh([
                'location', 'customer', 'attractionPurchases.attraction', 'eventPurchases.event',
            ]));
        }

        return $result;
    }

    public function applyRefund(TicketOrder $order, float $refundAmount, bool $cancel = false, string $mode = 'refund'): TicketOrder
    {
        return DB::transaction(function () use ($order, $refundAmount, $cancel, $mode) {
            if ($refundAmount < 0) {
                throw new RuntimeException('A refund amount cannot be negative.');
            }

            $order->refresh();

            $lines = $order->lines();

            $newPaidCents = max(
                0,
                TicketOrderAllocator::toCents((float) $order->amount_paid) - TicketOrderAllocator::toCents($refundAmount)
            );
            $orderTotalCents = TicketOrderAllocator::toCents((float) $order->total_amount);
            $fullyPaid = $newPaidCents === $orderTotalCents && $orderTotalCents > 0;

            $weights = [];
            foreach ($lines as $index => $line) {
                $weights[$index] = TicketOrderAllocator::toCents((float) $line['model']->total_amount);
            }

            $perLine = $fullyPaid ? $weights : TicketOrderAllocator::allocate($newPaidCents, $weights);

            foreach ($lines as $index => $line) {
                $model = $line['model'];
                $model->amount_paid = TicketOrderAllocator::toAmount($perLine[$index]);

                $redeemed = $model->checked_in_at !== null;

                if ($line['type'] === TicketOrderPricer::TYPE_ATTRACTION) {
                    if (!$redeemed) {
                        if ($cancel) {
                            $model->status = AttractionPurchase::STATUS_CANCELLED;
                        } elseif ($newPaidCents === 0) {
                            $model->status = $mode === 'resync' ? AttractionPurchase::STATUS_PENDING : AttractionPurchase::STATUS_REFUNDED;
                        } elseif (!$fullyPaid) {
                            $model->status = AttractionPurchase::STATUS_PENDING;
                        }
                    }
                } else {
                    if ($newPaidCents === 0) {
                        $model->payment_status = $mode === 'void' ? 'voided' : ($mode === 'resync' ? 'pending' : 'refunded');
                    } else {
                        $model->payment_status = $fullyPaid ? 'paid' : 'partial';
                    }

                    if (!$redeemed && ($cancel || ($mode === 'void' && $newPaidCents === 0))) {
                        $model->status = 'cancelled';
                        $model->cancelled_at = $model->cancelled_at ?? now();
                    } elseif (!$redeemed && !$fullyPaid && $model->status === 'confirmed') {
                        $model->status = 'pending';
                    }
                }

                $model->save();
            }

            $order->amount_paid = TicketOrderAllocator::toAmount($newPaidCents);

            $anyRedeemed = $lines->contains(fn (array $line) => $line['model']->checked_in_at !== null);

            if ($cancel) {
                $order->status = $anyRedeemed ? $order->status : TicketOrder::STATUS_CANCELLED;
            } elseif ($newPaidCents === 0) {
                $order->status = $mode === 'void'
                    ? TicketOrder::STATUS_CANCELLED
                    : ($mode === 'resync' ? TicketOrder::STATUS_PENDING : TicketOrder::STATUS_REFUNDED);
            } elseif (!$fullyPaid && $order->status === TicketOrder::STATUS_CONFIRMED) {
                $order->status = TicketOrder::STATUS_PENDING;
            }

            if ($order->status === TicketOrder::STATUS_CANCELLED) {
                $order->cancelled_at = $order->cancelled_at ?? now();
            }

            $order->save();

            if (in_array($order->status, [TicketOrder::STATUS_CANCELLED, TicketOrder::STATUS_REFUNDED], true)) {
                try {
                    app(DiscountService::class)->reverseGiftCardForPayable($order, \App\Models\Payment::TYPE_TICKET_ORDER, 'order_' . $mode);
                } catch (\Throwable $e) {
                    Log::critical('Gift card reversal FAILED on order refund and needs manual recovery', [
                        'ticket_order_id' => $order->id,
                        'gift_card_id' => $order->gift_card_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $order->fresh(['attractionPurchases', 'eventPurchases']);
        });
    }

    public function cancelOrder(TicketOrder $order): TicketOrder
    {
        return DB::transaction(function () use ($order) {
            $order->refresh();

            if ((float) $order->amount_paid > 0) {
                throw new RuntimeException('This order has money on it. Refund its payment first — the refund can cancel the order in the same step.');
            }

            foreach ($order->lines() as $line) {
                $model = $line['model'];

                if ($model->checked_in_at !== null) {
                    throw new RuntimeException('A ticket on this order is already checked in, so the order cannot be cancelled.');
                }

                if ($line['type'] === TicketOrderPricer::TYPE_ATTRACTION) {
                    $model->status = AttractionPurchase::STATUS_CANCELLED;
                } else {
                    $model->status = 'cancelled';
                    $model->cancelled_at = $model->cancelled_at ?? now();
                }

                $model->save();
            }

            $order->status = TicketOrder::STATUS_CANCELLED;
            $order->cancelled_at = $order->cancelled_at ?? now();
            $order->save();

            try {
                app(DiscountService::class)->reverseGiftCardForPayable($order, \App\Models\Payment::TYPE_TICKET_ORDER, 'order_cancelled');
            } catch (\Throwable $e) {
                Log::critical('Gift card reversal FAILED on order cancel and needs manual recovery', [
                    'ticket_order_id' => $order->id,
                    'gift_card_id' => $order->gift_card_id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $order->fresh(['attractionPurchases', 'eventPurchases']);
        });
    }

    public function syncCheckInStatus(TicketOrder $order): void
    {
        $order->refresh();

        $lines = $order->lines();

        if ($lines->isEmpty()) {
            return;
        }

        $allIn = $lines->every(fn (array $line) => $line['model']->checked_in_at !== null);

        if ($allIn && $order->status !== TicketOrder::STATUS_CHECKED_IN) {
            $order->status = TicketOrder::STATUS_CHECKED_IN;
            $order->save();
        }
    }

    /**
     * The customer receipt (email + SMS rides it) and the staff push, exactly once.
     * In-store orders fire this at creation; card orders only after the charge settles,
     * so a declined card never produces a receipt for an order that was rolled back.
     */
    public function sendOrderNotifications(TicketOrder $order): void
    {
        if ($order->confirmed_at === null) {
            $order->forceFill(['confirmed_at' => now()])->save();
        }

        try {
            app(EmailNotificationService::class)->triggerOrderNotification($order);
        } catch (\Throwable $e) {
            Log::error('Order customer notification failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $notification = Notification::create([
                'location_id' => $order->location_id,
                'title' => 'New Order Received',
                'message' => $order->customer_name . ' — ' . $order->item_count . ' items, '
                    . $order->ticket_count . ' tickets • $' . number_format((float) $order->total_amount, 2),
                'type' => 'payment',
                'priority' => 'high',
                'action_url' => '/orders/' . $order->id,
                'action_text' => 'View order',
                'status' => 'unread',
            ]);

            app(PushNotificationService::class)->sendForNotification($notification);
        } catch (\Throwable $e) {
            Log::warning('Order staff notification failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function assertLinesMatchOrder(TicketOrder $order): void
    {
        $lines = $order->lines();

        $lineTotal = 0;
        $linePaid = 0;

        foreach ($lines as $line) {
            $lineTotal += TicketOrderAllocator::toCents((float) $line['model']->total_amount);
            $linePaid += TicketOrderAllocator::toCents((float) $line['model']->amount_paid);
        }

        $orderTotal = TicketOrderAllocator::toCents((float) $order->total_amount);
        $orderPaid = TicketOrderAllocator::toCents((float) $order->amount_paid);

        if ($lineTotal !== $orderTotal) {
            throw new RuntimeException(
                "Order {$order->reference_number} totals do not reconcile: lines {$lineTotal}c vs order {$orderTotal}c."
            );
        }

        if ($linePaid !== $orderPaid) {
            throw new RuntimeException(
                "Order {$order->reference_number} payments do not reconcile: lines {$linePaid}c vs order {$orderPaid}c."
            );
        }
    }

    private function collectFees(array $lines): array
    {
        $fees = [];

        foreach ($lines as $line) {
            foreach ($line['applied_fees'] as $fee) {
                $fees[] = $fee + ['line_position' => $line['position']];
            }
        }

        return $fees;
    }

    private function collectDiscounts(array $lines): array
    {
        $discounts = [];

        foreach ($lines as $line) {
            foreach ($line['applied_discounts'] as $discount) {
                $discounts[] = $discount + ['line_position' => $line['position']];
            }
        }

        return $discounts;
    }

    private function generateEventReference(): string
    {
        do {
            $candidate = 'EVT-' . strtoupper(Str::random(8));
        } while (EventPurchase::withTrashed()->where('reference_number', $candidate)->exists());

        return $candidate;
    }
}
