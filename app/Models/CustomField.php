<?php

namespace App\Models;

use App\Traits\HasTargeting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An extra question attached to a purchase — today always a checkbox, which is why the
 * type column exists but only ever holds 'checkbox'. Which items show it is decided by
 * the same targeting arrays promos and gift cards use: leave a list empty and it means
 * every one of them.
 */
class CustomField extends Model
{
    use HasFactory, SoftDeletes, HasTargeting;

    public const TYPE_CHECKBOX = 'checkbox';

    public const AUDIENCE_CUSTOMER = 'customer';
    public const AUDIENCE_ADMIN = 'admin';
    public const AUDIENCE_BOTH = 'both';

    public const AUDIENCES = [self::AUDIENCE_CUSTOMER, self::AUDIENCE_ADMIN, self::AUDIENCE_BOTH];

    protected $fillable = [
        'company_id',
        'label',
        'type',
        'help_text',
        'is_required',
        'audience',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    public function responses()
    {
        return $this->hasMany(CustomFieldResponse::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** 'both' fields answer to either side, so a side-specific ask still matches it. */
    public function scopeForAudience(Builder $query, string $audience): Builder
    {
        return $query->whereIn('audience', array_unique([$audience, self::AUDIENCE_BOTH]));
    }

    public function appliesToAudience(string $audience): bool
    {
        return $this->audience === self::AUDIENCE_BOTH || $this->audience === $audience;
    }

    /**
     * Every field a given item should show, narrowed by company, location and audience.
     * Item type is one of package|attraction|event.
     */
    public static function applicableTo(
        string $itemType,
        int $itemId,
        ?int $companyId = null,
        ?int $locationId = null,
        string $audience = self::AUDIENCE_CUSTOMER,
    ) {
        $query = static::query()->active()->forAudience($audience);

        // No resolvable company means we only trust company-less fields — matching every
        // company's questions instead would ask one venue's guests about another's.
        if ($companyId) {
            $query->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            });
        } else {
            $query->whereNull('company_id');
        }

        if ($locationId !== null) {
            $query->forLocation($locationId);
        }

        $query->where(function ($q) use ($itemType, $itemId) {
            // Item-wide fields carry no item lists at all; targeted ones must name this item.
            $q->where(function ($wide) {
                $wide->whereNull('package_ids')
                    ->whereNull('attraction_ids')
                    ->whereNull('event_ids');
            });

            $column = match ($itemType) {
                'package' => 'package_ids',
                'attraction' => 'attraction_ids',
                'event' => 'event_ids',
                default => null,
            };

            if ($column) {
                $q->orWhereJsonContains($column, (int) $itemId)
                    ->orWhereJsonContains($column, (string) $itemId);
            }
        });

        return $query->orderBy('display_order')->orderBy('id')->get();
    }
}
