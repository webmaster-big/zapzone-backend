<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Waiver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class WaiverCheckInService
{
    public const ENTITY_TYPES = [
        'booking',
        'attraction_purchase',
        'event_purchase',
        'customer',
    ];

    public function scopeToEntity(Builder $query, string $type, int $id): bool
    {
        switch ($type) {
            case 'booking':
                $query->where('booking_id', $id);
                return true;

            case 'attraction_purchase':
                $query->where('attraction_purchase_id', $id);
                return true;

            case 'event_purchase':
                if (!Waiver::supportsEventPurchaseId()) {
                    $query->whereRaw('1 = 0');
                    return true;
                }
                $query->where('event_purchase_id', $id);
                return true;

            case 'customer':
                $query->where('customer_id', $id);
                return true;

            default:
                return false;
        }
    }

    public function checkInForEntity(string $type, int $id, ?User $actor = null): int
    {
        if (!in_array($type, self::ENTITY_TYPES, true) || $id <= 0) {
            return 0;
        }

        try {
            $query = Waiver::query();

            if (!$this->scopeToEntity($query, $type, $id)) {
                return 0;
            }

            $waivers = $query->where('status', Waiver::STATUS_COMPLETED)
                ->whereNull('checked_in_at')
                ->orderBy('id')
                ->limit(200)
                ->get();

            $count = 0;

            foreach ($waivers as $waiver) {
                $this->stamp($waiver, $actor);
                $count++;
            }

            return $count;
        } catch (\Throwable $e) {
            Log::warning('Waiver cascade check-in failed', [
                'entity_type' => $type,
                'entity_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    public function checkInForTicketOrderLines(array $lines, ?User $actor = null): int
    {
        $count = 0;

        foreach ($lines as $line) {
            $type = ($line['type'] ?? null) === 'event' ? 'event_purchase' : 'attraction_purchase';
            $modelId = (int) ($line['id'] ?? 0);

            if ($modelId > 0) {
                $count += $this->checkInForEntity($type, $modelId, $actor);
            }
        }

        return $count;
    }

    public function stamp(Waiver $waiver, ?User $actor = null): bool
    {
        if ($waiver->status !== Waiver::STATUS_COMPLETED || $waiver->checked_in_at) {
            return false;
        }

        $waiver->update([
            'checked_in_at' => now(),
            'checked_in_by' => $actor?->id,
        ]);

        ActivityLog::log(
            action: 'Waiver Checked In',
            category: 'check-in',
            description: "Waiver #{$waiver->id} ({$waiver->adult_full_name}) checked in",
            userId: $actor?->id,
            locationId: $waiver->location_id,
            entityType: 'waiver',
            entityId: $waiver->id,
        );

        return true;
    }

    public function resolveByReference(string $reference): ?Waiver
    {
        $reference = strtoupper(trim($reference));

        if ($reference === '' || !Waiver::supportsReferenceNumber()) {
            return null;
        }

        return Waiver::query()
            ->where('reference_number', $reference)
            ->first();
    }
}
