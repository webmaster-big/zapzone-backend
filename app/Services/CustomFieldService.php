<?php

namespace App\Services;

use App\Models\Attraction;
use App\Models\CustomField;
use App\Models\Event;
use App\Models\Location;
use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * One place that decides which extra questions an item asks and records the answers, so
 * bookings, attraction tickets, event tickets and bulk orders cannot drift apart on it.
 */
class CustomFieldService
{
    /**
     * Only a staff account is the admin side. A logged-in customer still carries a token,
     * so "is authenticated" would hand guests the staff questions and skip their own.
     */
    public function audienceFor(mixed $actor): string
    {
        return $actor instanceof User
            ? CustomField::AUDIENCE_ADMIN
            : CustomField::AUDIENCE_CUSTOMER;
    }

    /**
     * Where an item actually lives, read from the database so a caller cannot scope a
     * question to a location or company the item does not belong to.
     *
     * @return array{0: int|null, 1: int|null} [locationId, companyId]
     */
    public function resolveScope(string $itemType, int $itemId): array
    {
        $locationId = match ($itemType) {
            'package' => Package::whereKey($itemId)->value('location_id'),
            'attraction' => Attraction::whereKey($itemId)->value('location_id'),
            'event' => Event::whereKey($itemId)->value('location_id'),
            default => null,
        };

        if (!$locationId) {
            return [null, null];
        }

        return [(int) $locationId, Location::whereKey($locationId)->value('company_id')];
    }

    /** @return Collection<int, CustomField> */
    public function applicableFor(
        string $itemType,
        int $itemId,
        ?int $companyId = null,
        ?int $locationId = null,
        string $audience = CustomField::AUDIENCE_CUSTOMER,
    ): Collection {
        return CustomField::applicableTo($itemType, $itemId, $companyId, $locationId, $audience);
    }

    /**
     * Normalises the answers a client sent into [field_id => bool].
     *
     * @param array<int, array{id?: int|string, value?: mixed}>|null $answers
     * @return array<int, bool>
     */
    public function normalizeAnswers(?array $answers): array
    {
        $normalized = [];

        foreach ($answers ?? [] as $answer) {
            if (!is_array($answer) || !isset($answer['id'])) {
                continue;
            }

            $normalized[(int) $answer['id']] = filter_var(
                $answer['value'] ?? false,
                FILTER_VALIDATE_BOOLEAN,
            );
        }

        return $normalized;
    }

    /**
     * Every question raised by any line of an order, asked once. A guest ticking a box
     * for a five-line order should not be asked the same thing five times.
     *
     * @param array<int, array{type?: string, id?: int|string}> $items
     * @return Collection<int, CustomField>
     */
    public function applicableForMany(
        array $items,
        string $audience = CustomField::AUDIENCE_CUSTOMER,
    ): Collection {
        $found = collect();
        $scopes = [];

        foreach ($items as $item) {
            $type = $item['type'] ?? null;
            $id = $item['id'] ?? null;

            if (!$type || !$id) {
                continue;
            }

            $cacheKey = $type . ':' . (int) $id;
            $scopes[$cacheKey] ??= $this->resolveScope($type, (int) $id);
            [$locationId, $companyId] = $scopes[$cacheKey];

            $found = $found->merge(
                $this->applicableFor($type, (int) $id, $companyId, $locationId, $audience),
            );
        }

        return $found->unique('id')->sortBy([['display_order', 'asc'], ['id', 'asc']])->values();
    }

    /**
     * @param Collection<int, CustomField> $fields
     * @param array<int, bool> $answers
     */
    public function assertRequired(Collection $fields, array $answers): void
    {
        foreach ($fields as $field) {
            if ($field->is_required && ($answers[$field->id] ?? false) !== true) {
                throw new RuntimeException("Please confirm: {$field->label}");
            }
        }
    }

    /**
     * Writes one row per applicable field — including the unticked ones, because "they
     * were asked and said no" is different from "they were never asked".
     *
     * @param Collection<int, CustomField> $fields
     * @param array<int, bool> $answers
     */
    public function saveFor(Model $respondable, Collection $fields, array $answers): void
    {
        foreach ($fields as $field) {
            $respondable->customFieldResponses()->create([
                'custom_field_id' => $field->id,
                'label' => $field->label,
                'type' => $field->type,
                'value' => $answers[$field->id] ?? false,
            ]);
        }
    }

    /**
     * Resolve, validate and persist in one call for a single-item purchase.
     *
     * @param array<int, array{id?: int|string, value?: mixed}>|null $answers
     */
    public function handle(
        Model $respondable,
        string $itemType,
        int $itemId,
        ?array $answers,
        string $audience = CustomField::AUDIENCE_CUSTOMER,
    ): void {
        [$locationId, $companyId] = $this->resolveScope($itemType, $itemId);

        $fields = $this->applicableFor($itemType, $itemId, $companyId, $locationId, $audience);

        if ($fields->isEmpty()) {
            return;
        }

        $normalized = $this->normalizeAnswers($answers);
        $this->assertRequired($fields, $normalized);
        $this->saveFor($respondable, $fields, $normalized);
    }
}
