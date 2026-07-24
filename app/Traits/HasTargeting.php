<?php

namespace App\Traits;

trait HasTargeting
{
    public function initializeHasTargeting(): void
    {
        $this->mergeFillable([
            'location_ids',
            'package_ids',
            'attraction_ids',
            'event_ids',
        ]);

        $this->mergeCasts([
            'location_ids' => 'array',
            'package_ids' => 'array',
            'attraction_ids' => 'array',
            'event_ids' => 'array',
        ]);
    }

    public static function normalizeIds($value): ?array
    {
        return !empty($value) ? array_values(array_map('intval', $value)) : null;
    }

    protected function containsId($column, int $id): bool
    {
        $ids = $this->{$column};

        if (empty($ids)) {
            return false;
        }

        return in_array($id, $ids) || in_array((string) $id, $ids);
    }

    public function isAllLocations(): bool
    {
        return empty($this->location_ids);
    }

    public function isItemWide(): bool
    {
        return empty($this->package_ids)
            && empty($this->attraction_ids)
            && empty($this->event_ids);
    }

    public function appliesToLocation(?int $locationId): bool
    {
        if ($this->isAllLocations()) {
            return true;
        }

        return $locationId !== null && $this->containsId('location_ids', $locationId);
    }

    public function appliesToPackage(int $packageId): bool
    {
        if ($this->isItemWide()) {
            return true;
        }

        return $this->containsId('package_ids', $packageId);
    }

    public function appliesToAttraction(int $attractionId): bool
    {
        if ($this->isItemWide()) {
            return true;
        }

        return $this->containsId('attraction_ids', $attractionId);
    }

    public function appliesToEvent(int $eventId): bool
    {
        if ($this->isItemWide()) {
            return true;
        }

        return $this->containsId('event_ids', $eventId);
    }

    public function appliesToItem(string $type, int $id): bool
    {
        return match ($type) {
            'package' => $this->appliesToPackage($id),
            'attraction' => $this->appliesToAttraction($id),
            'event' => $this->appliesToEvent($id),
            default => false,
        };
    }

    public function scopeForLocation($query, $locationId)
    {
        return $query->where(function ($q) use ($locationId) {
            $q->whereNull('location_ids')
              ->orWhereJsonContains('location_ids', (int) $locationId)
              ->orWhereJsonContains('location_ids', (string) $locationId);
        });
    }

    public function scopeForPackage($query, $packageId)
    {
        return $query->where(function ($q) use ($packageId) {
            $q->whereNull('package_ids')
              ->orWhereJsonContains('package_ids', (int) $packageId)
              ->orWhereJsonContains('package_ids', (string) $packageId);
        });
    }

    public function scopeForAttraction($query, $attractionId)
    {
        return $query->where(function ($q) use ($attractionId) {
            $q->whereNull('attraction_ids')
              ->orWhereJsonContains('attraction_ids', (int) $attractionId)
              ->orWhereJsonContains('attraction_ids', (string) $attractionId);
        });
    }

    public function scopeForEvent($query, $eventId)
    {
        return $query->where(function ($q) use ($eventId) {
            $q->whereNull('event_ids')
              ->orWhereJsonContains('event_ids', (int) $eventId)
              ->orWhereJsonContains('event_ids', (string) $eventId);
        });
    }
}
