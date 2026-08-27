<?php

namespace App\Services;

use App\Models\AddOn;
use App\Models\DayOff;
use App\Models\Attraction;
use App\Models\Event;
use App\Models\FeeSupport;
use App\Models\SpecialPricing;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TicketOrderPricer
{
    public const TYPE_ATTRACTION = 'attraction';
    public const TYPE_EVENT = 'event';

    public function priceCart(array $items, ?int $locationId = null): array
    {
        if ($items === []) {
            throw new RuntimeException('A cart must contain at least one item.');
        }

        $lines = [];
        $position = 0;

        foreach ($items as $item) {
            $lines[] = $this->priceLine($item, ++$position, $locationId);
        }

        $this->assertSlotCapacity($lines);

        $resolvedLocationIds = collect($lines)->pluck('location_id')->unique()->values();

        if ($resolvedLocationIds->count() > 1) {
            throw new RuntimeException(
                'All items in one order must belong to the same location. Found: ' . $resolvedLocationIds->implode(', ')
            );
        }

        return [
            'location_id' => (int) $resolvedLocationIds->first(),
            'lines' => $lines,
            'subtotal' => round(collect($lines)->sum('subtotal'), 2),
            'discount_amount' => round(collect($lines)->sum('discount_amount'), 2),
            'fee_total' => round(collect($lines)->sum('fee_total'), 2),
            'total_amount' => round(collect($lines)->sum('total_amount'), 2),
            'item_count' => count($lines),
            'ticket_count' => (int) collect($lines)->sum('quantity'),
        ];
    }

    public function assertSlotCapacity(array $lines, bool $lock = false): void
    {
        $grouped = collect($lines)
            ->filter(fn (array $line) => !empty($line['scheduled_date']) && !empty($line['scheduled_time']))
            ->groupBy(fn (array $line) => implode('|', [
                $line['type'],
                $line['entity_id'],
                $line['scheduled_date'],
                substr((string) $line['scheduled_time'], 0, 5),
            ]));

        foreach ($grouped as $key => $group) {
            [$type, $id, $date, $time] = explode('|', (string) $key);
            $wanted = (int) $group->sum('quantity');

            $query = $type === self::TYPE_ATTRACTION
                ? \App\Models\Attraction::where('id', $id)
                : \App\Models\Event::where('id', $id);

            $model = ($lock ? $query->lockForUpdate() : $query)->first();

            if (!$model) {
                continue;
            }

            if ($model->max_tickets_per_slot !== null) {
                $remaining = $model->remainingTicketsForSlot($date, $time);

                if ($wanted > $remaining) {
                    $name = $model->name;
                    throw new RuntimeException(
                        $remaining === 0
                            ? "The {$time} time for {$name} just sold out. Please pick another time."
                            : "Only {$remaining} ticket" . ($remaining === 1 ? '' : 's') . " left for {$name} at {$time}. Pick fewer tickets or another time."
                    );
                }
            }

            if ($type === self::TYPE_EVENT && $model->max_bookings_per_slot !== null) {
                $existing = (int) $model->eventPurchases()
                    ->where('purchase_date', $date)
                    ->whereNotIn('status', ['cancelled'])
                    ->whereRaw("TIME_FORMAT(purchase_time, '%H:%i') = ?", [$time])
                    ->count();
                $wantedLines = $group->count();

                if ($existing + $wantedLines > (int) $model->max_bookings_per_slot) {
                    $message = "The {$time} time for {$model->name} has no booking slots left. Please pick another time.";

                    if ((string) config('booking_rules.capacity', 'log') === 'enforce') {
                        throw new RuntimeException($message);
                    }

                    Log::warning('Ticket order bookings-per-slot (log-only)', [
                        'event_id' => (int) $id,
                        'date' => $date,
                        'time' => $time,
                        'existing' => $existing,
                        'wanted_lines' => $wantedLines,
                    ]);
                }
            }
        }
    }

    public function priceLine(array $item, int $position, ?int $expectedLocationId = null): array
    {
        $type = $item['type'] ?? null;

        if (!in_array($type, [self::TYPE_ATTRACTION, self::TYPE_EVENT], true)) {
            throw new RuntimeException('Cart items must be an attraction or an event.');
        }

        $quantity = (int) ($item['quantity'] ?? 0);

        if ($quantity < 1) {
            throw new RuntimeException('Every cart line needs a quantity of at least one.');
        }

        return $type === self::TYPE_ATTRACTION
            ? $this->priceAttractionLine($item, $quantity, $position, $expectedLocationId)
            : $this->priceEventLine($item, $quantity, $position, $expectedLocationId);
    }

    private function priceAttractionLine(array $item, int $quantity, int $position, ?int $expectedLocationId): array
    {
        $attraction = Attraction::find($item['id'] ?? null);

        if (!$attraction || !$attraction->is_active) {
            throw new RuntimeException('That attraction is not available.');
        }

        $locationId = (int) $attraction->location_id;
        $this->assertLocation($locationId, $expectedLocationId);

        $unitPrice = (float) $attraction->price;
        $scheduledDate = $this->parseDate($item['scheduled_date'] ?? null);
        $scheduledTime = $item['scheduled_time'] ?? null;

        if (!$scheduledDate || !$scheduledTime) {
            throw new RuntimeException("Please pick a visit date and time for {$attraction->name}.");
        }

        $blocked = DayOff::isTimeSlotBlockedForAttraction(
            $locationId,
            (int) $attraction->id,
            $scheduledDate->toDateString(),
            $scheduledTime
        );

        if ($blocked) {
            throw new RuntimeException("{$attraction->name} is closed on the date or time you picked. Please choose another.");
        }

        $pricing = SpecialPricing::getFullPriceBreakdown(
            SpecialPricing::ENTITY_ATTRACTION,
            $attraction->id,
            $unitPrice,
            null,
            $locationId
        );

        $unitAfterDiscount = (float) $pricing['discounted_price'];
        $baseSubtotal = round($unitPrice * $quantity, 2);
        $discountTotal = round(((float) $pricing['total_discount']) * $quantity, 2);

        $addOns = $this->priceAddOns($item['add_ons'] ?? []);

        $feeBase = round(($unitAfterDiscount * $quantity) + $addOns['total'], 2);
        $fees = FeeSupport::getFullPriceBreakdown(
            FeeSupport::ENTITY_ATTRACTION,
            $attraction->id,
            $feeBase,
            $locationId
        );

        $feeTotal = round(((float) $fees['total']) - $feeBase, 2);

        return [
            'type' => self::TYPE_ATTRACTION,
            'position' => $position,
            'entity_id' => (int) $attraction->id,
            'entity_name' => (string) $attraction->name,
            'location_id' => $locationId,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit_price_after_discount' => $unitAfterDiscount,
            'subtotal' => $baseSubtotal,
            'add_ons' => $addOns['lines'],
            'add_ons_total' => $addOns['total'],
            'discount_amount' => $discountTotal,
            'applied_discounts' => $pricing['discounts_applied'],
            'fee_total' => $feeTotal,
            'applied_fees' => $fees['fees'],
            'total_amount' => round(($unitAfterDiscount * $quantity) + $addOns['total'] + $feeTotal, 2),
            'scheduled_date' => $scheduledDate?->toDateString(),
            'scheduled_time' => $scheduledTime,
        ];
    }

    private function priceEventLine(array $item, int $quantity, int $position, ?int $expectedLocationId): array
    {
        $event = Event::find($item['id'] ?? null);

        if (!$event || !$event->is_active) {
            throw new RuntimeException('That event is not available.');
        }

        if (!$event->time_start || !$event->time_end) {
            throw new RuntimeException("{$event->name} is booked by phone — please call the venue to reserve it.");
        }

        $locationId = (int) $event->location_id;
        $this->assertLocation($locationId, $expectedLocationId);

        $unitPrice = (float) $event->price;
        $scheduledDate = $this->parseDate($item['scheduled_date'] ?? null) ?? Carbon::parse($event->start_date);

        $eventStart = Carbon::parse($event->start_date)->startOfDay();
        $eventEnd = $event->end_date ? Carbon::parse($event->end_date)->endOfDay() : $eventStart->clone()->endOfDay();

        if ($scheduledDate->lt($eventStart) || $scheduledDate->gt($eventEnd)) {
            throw new RuntimeException("{$event->name} does not run on the date you picked.");
        }

        if (method_exists($event, 'isDateValid') && !$event->isDateValid($scheduledDate->toDateString())) {
            throw new RuntimeException("{$event->name} is not available on the date you picked.");
        }

        $requestedTime = $item['scheduled_time'] ?? null;
        $eventIsCapped = $event->max_tickets_per_slot !== null || $event->max_bookings_per_slot !== null;

        if ($requestedTime === null && $eventIsCapped) {
            throw new RuntimeException("Please pick a time for {$event->name}.");
        }

        if ($requestedTime !== null) {
            $slots = $event->getAvailableTimeSlotsForDate($scheduledDate->toDateString());

            if (!in_array(substr((string) $requestedTime, 0, 5), array_map(fn ($t) => substr((string) $t, 0, 5), $slots), true)) {
                throw new RuntimeException("That time for {$event->name} is full or unavailable. Please pick another slot.");
            }
        }

        $pricing = SpecialPricing::getFullPriceBreakdown(
            SpecialPricing::ENTITY_EVENT,
            $event->id,
            $unitPrice,
            null,
            $locationId
        );

        $unitAfterDiscount = (float) $pricing['discounted_price'];
        $baseSubtotal = round($unitPrice * $quantity, 2);
        $discountTotal = round(((float) $pricing['total_discount']) * $quantity, 2);

        $addOns = $this->priceAddOns($item['add_ons'] ?? []);

        $feeBase = round(($unitAfterDiscount * $quantity) + $addOns['total'], 2);
        $fees = FeeSupport::getFullPriceBreakdown(
            FeeSupport::ENTITY_EVENT,
            $event->id,
            $feeBase,
            $locationId
        );

        $feeTotal = round(((float) $fees['total']) - $feeBase, 2);

        return [
            'type' => self::TYPE_EVENT,
            'position' => $position,
            'entity_id' => (int) $event->id,
            'entity_name' => (string) $event->name,
            'location_id' => $locationId,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit_price_after_discount' => $unitAfterDiscount,
            'subtotal' => $baseSubtotal,
            'add_ons' => $addOns['lines'],
            'add_ons_total' => $addOns['total'],
            'discount_amount' => $discountTotal,
            'applied_discounts' => $pricing['discounts_applied'],
            'fee_total' => $feeTotal,
            'applied_fees' => $fees['fees'],
            'total_amount' => round(($unitAfterDiscount * $quantity) + $addOns['total'] + $feeTotal, 2),
            'scheduled_date' => $scheduledDate?->toDateString(),
            'scheduled_time' => $item['scheduled_time'] ?? null,
        ];
    }

    private function priceAddOns(array $requested): array
    {
        $lines = [];
        $total = 0.0;
        $wanted = [];

        foreach ($requested as $addOnInput) {
            $quantity = (int) ($addOnInput['quantity'] ?? 0);

            if ($quantity < 1) {
                continue;
            }

            $key = (int) ($addOnInput['id'] ?? 0);
            $wanted[$key] = ($wanted[$key] ?? 0) + $quantity;
        }

        foreach ($wanted as $addOnId => $quantity) {
            $addOn = AddOn::find($addOnId ?: null);

            if (!$addOn) {
                throw new RuntimeException('An add-on in the cart no longer exists.');
            }

            if ($addOn->max_quantity !== null && $quantity > (int) $addOn->max_quantity) {
                throw new RuntimeException("You can add at most {$addOn->max_quantity} x {$addOn->name}.");
            }

            if ($addOn->min_quantity !== null && $quantity < (int) $addOn->min_quantity) {
                throw new RuntimeException("You need at least {$addOn->min_quantity} x {$addOn->name}.");
            }

            $priceAtPurchase = (float) $addOn->price;
            $lineTotal = round($priceAtPurchase * $quantity, 2);
            $total += $lineTotal;

            $lines[] = [
                'add_on_id' => (int) $addOn->id,
                'name' => (string) $addOn->name,
                'quantity' => $quantity,
                'price_at_purchase' => $priceAtPurchase,
                'line_total' => $lineTotal,
            ];
        }

        return ['lines' => $lines, 'total' => round($total, 2)];
    }

    private function assertLocation(int $locationId, ?int $expectedLocationId): void
    {
        if ($expectedLocationId !== null && $locationId !== $expectedLocationId) {
            throw new RuntimeException('All items in one order must belong to the same location.');
        }
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        return Carbon::parse($value, config('app.timezone'));
    }
}
