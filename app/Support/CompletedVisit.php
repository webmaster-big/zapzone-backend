<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\EmailNotification;
use App\Models\EscapeRoomSession;
use App\Models\EventPurchase;
use App\Models\Location;
use App\Models\VisitFollowUp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

final class CompletedVisit
{
    public function __construct(
        public readonly string $type,
        public readonly Model $subject,
        public readonly int $companyId,
        public readonly ?Location $location,
        public readonly string $entityType,
        public readonly ?int $entityId,
        public readonly string $activityName,
        public readonly ?string $date,
        public readonly ?string $time,
        public readonly ?Carbon $completedAt,
        public readonly ?string $reference = null,
        public readonly ?EscapeRoomSession $game = null,
    ) {
    }

    public static function fromBooking(Booking $booking): ?self
    {
        $booking->loadMissing(['location.company', 'package']);
        $companyId = $booking->location?->company_id;

        if (!$companyId) {
            return null;
        }

        return new self(
            VisitFollowUp::VISIT_BOOKING,
            $booking,
            (int) $companyId,
            $booking->location,
            EmailNotification::ENTITY_PACKAGE,
            $booking->package_id ? (int) $booking->package_id : null,
            (string) ($booking->package?->name ?? 'your visit'),
            $booking->booking_date?->toDateString(),
            self::timeOf($booking->booking_time, $booking->getRawOriginal('booking_time')),
            $booking->completed_at,
            $booking->reference_number,
        );
    }

    public static function fromGame(EscapeRoomSession $game): ?self
    {
        $game->loadMissing(['location.company', 'package']);

        if (!$game->company_id) {
            return null;
        }

        return new self(
            VisitFollowUp::VISIT_ESCAPE_ROOM_GAME,
            $game,
            (int) $game->company_id,
            $game->location,
            EmailNotification::ENTITY_PACKAGE,
            (int) $game->package_id,
            (string) ($game->package?->name ?? 'your escape room'),
            $game->dateKey() ?: null,
            $game->timeKey() ?: null,
            $game->completed_at,
            null,
            $game,
        );
    }

    public static function fromEventPurchase(EventPurchase $purchase): ?self
    {
        $purchase->loadMissing(['location.company', 'event']);
        $companyId = $purchase->location?->company_id;

        if (!$companyId) {
            return null;
        }

        return new self(
            VisitFollowUp::VISIT_EVENT_PURCHASE,
            $purchase,
            (int) $companyId,
            $purchase->location,
            EmailNotification::ENTITY_EVENT,
            $purchase->event_id ? (int) $purchase->event_id : null,
            (string) ($purchase->event?->name ?? 'your event'),
            $purchase->purchase_date?->toDateString(),
            self::timeOf($purchase->purchase_time, $purchase->getRawOriginal('purchase_time')),
            $purchase->completed_at,
            $purchase->reference_number,
        );
    }

    public static function find(string $type, int $id): ?self
    {
        return match ($type) {
            VisitFollowUp::VISIT_BOOKING => ($booking = Booking::find($id)) ? self::fromBooking($booking) : null,
            VisitFollowUp::VISIT_ESCAPE_ROOM_GAME => EscapeRoomSession::isAvailable() && ($game = EscapeRoomSession::find($id)) ? self::fromGame($game) : null,
            VisitFollowUp::VISIT_EVENT_PURCHASE => ($purchase = EventPurchase::find($id)) ? self::fromEventPurchase($purchase) : null,
            default => null,
        };
    }

    public function id(): int
    {
        return (int) $this->subject->getKey();
    }

    public function locationId(): ?int
    {
        return $this->location?->id ? (int) $this->location->id : null;
    }

    public function isGame(): bool
    {
        return $this->game !== null;
    }

    public function isEscapeRoom(): bool
    {
        if ($this->game !== null) {
            return true;
        }

        return $this->subject instanceof Booking && (bool) $this->subject->package?->isEscapeRoom();
    }

    public function isStillComplete(): bool
    {
        if ($this->game) {
            return $this->game->fresh()?->completed_at !== null;
        }

        $fresh = $this->subject->fresh();

        return $fresh !== null && $fresh->status === 'completed';
    }

    private static function timeOf(mixed $cast, mixed $raw): ?string
    {
        if ($cast instanceof \DateTimeInterface) {
            return $cast->format('H:i');
        }

        $value = substr((string) $raw, 0, 5);

        return preg_match('/^\d{2}:\d{2}$/', $value) ? $value : null;
    }
}
