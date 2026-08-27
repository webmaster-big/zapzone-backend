<?php

namespace App\Services;

use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\EventPurchase;
use App\Support\DateRange;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SlotCapacityGuard
{
    public function assertBookingFits(Booking $booking, string $context): void
    {
        $this->raise($this->bookingProblem($booking), $context, 'booking', (int) $booking->id);
    }

    public function assertAttractionPurchaseFits(AttractionPurchase $purchase, string $context): void
    {
        $this->raise($this->attractionProblem($purchase), $context, 'attraction_purchase', (int) $purchase->id);
    }

    public function assertEventPurchaseFits(EventPurchase $purchase, string $context): void
    {
        $this->raise($this->eventProblem($purchase), $context, 'event_purchase', (int) $purchase->id);
    }

    public function bookingProblem(Booking $booking): ?string
    {
        $package = $booking->package;
        $date = $this->dateString($booking->booking_date);

        if (!$package || $date === null || $this->isPast($date)) {
            return null;
        }

        $remaining = $package->remainingTicketsForSlot($date, $this->timeString($booking->booking_time), (int) $booking->id);

        if ($remaining === null || (int) $booking->participants <= $remaining) {
            return null;
        }

        $label = strtolower($package->participant_label ?: 'ticket');

        return $remaining === 0
            ? 'That time slot is already full.'
            : "Only {$remaining} {$label}" . ($remaining === 1 ? '' : 's') . ' fit in that time slot.';
    }

    public function attractionProblem(AttractionPurchase $purchase): ?string
    {
        $attraction = $purchase->attraction;
        $date = $this->dateString($purchase->scheduled_date);
        $time = $purchase->scheduled_time ? $this->timeString($purchase->scheduled_time) : null;

        if (!$attraction || $attraction->max_tickets_per_slot === null || $date === null || $time === null || $this->isPast($date)) {
            return null;
        }

        $taken = (int) $attraction->purchases()
            ->where('scheduled_date', $date)
            ->whereNotIn('status', [AttractionPurchase::STATUS_CANCELLED, AttractionPurchase::STATUS_REFUNDED])
            ->where('id', '!=', $purchase->id)
            ->whereRaw("TIME_FORMAT(scheduled_time, '%H:%i') = ?", [$time])
            ->sum('quantity');
        $remaining = max(0, (int) $attraction->max_tickets_per_slot - $taken);

        if ((int) $purchase->quantity <= $remaining) {
            return null;
        }

        return $remaining === 0
            ? 'That time slot is already full.'
            : "Only {$remaining} ticket" . ($remaining === 1 ? '' : 's') . ' fit in that time slot.';
    }

    public function eventProblem(EventPurchase $purchase): ?string
    {
        $event = $purchase->event;
        $date = $this->dateString($purchase->purchase_date);
        $time = $purchase->purchase_time ? $this->timeString($purchase->purchase_time) : null;

        if (!$event || $date === null || $time === null || $this->isPast($date)) {
            return null;
        }

        $others = $event->eventPurchases()
            ->where('purchase_date', $date)
            ->whereNotIn('status', ['cancelled'])
            ->where('id', '!=', $purchase->id)
            ->whereRaw("TIME_FORMAT(purchase_time, '%H:%i') = ?", [$time]);

        if ($event->max_tickets_per_slot !== null) {
            $remaining = max(0, (int) $event->max_tickets_per_slot - (int) $others->clone()->sum('quantity'));

            if ((int) $purchase->quantity > $remaining) {
                return $remaining === 0
                    ? 'That time slot is already full.'
                    : "Only {$remaining} ticket" . ($remaining === 1 ? '' : 's') . ' fit in that time slot.';
            }
        }

        if ($event->max_bookings_per_slot !== null && $others->clone()->count() >= (int) $event->max_bookings_per_slot) {
            return 'That time slot already has the maximum number of bookings.';
        }

        return null;
    }

    public function isPast(string $date): bool
    {
        $tz = DateRange::businessTimezone();

        return Carbon::parse($date, $tz)->startOfDay()->lt(Carbon::now($tz)->startOfDay());
    }

    private function raise(?string $problem, string $context, string $type, int $id): void
    {
        if ($problem === null) {
            return;
        }

        if ((string) config('booking_rules.capacity', 'log') === 'enforce') {
            throw new RuntimeException($problem);
        }

        Log::warning('Capacity rule (log-only)', ['context' => $context, 'type' => $type, 'id' => $id, 'problem' => $problem]);
    }

    private function dateString(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    private function timeString(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('H:i') : substr((string) $value, 0, 5);
    }
}
