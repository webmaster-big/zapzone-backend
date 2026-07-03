<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WaiverTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'location_id',
        'title',
        'internal_description',
        'status',
        'is_default',
        'current_version',
        'body_text',
        'validity_duration_days',
        'max_minors',
        'duplicate_rule',
        'reminder_eligible',
        'assigned_package_ids',
        'assigned_attraction_ids',
        'assigned_event_ids',
        'assigned_party_types',
        'minor_section_enabled',
        'dob_required',
        'relationship_required',
        'photo_video_release_enabled',
        'medical_ack_enabled',
        'property_damage_enabled',
        'group_leader_clause_enabled',
        'electronic_consent_enabled',
        'marketing_consent_enabled',
        'marketing_consent_text',
        'marketing_helper_text',
        'crm_sync_allowed',
        'crm_sync_birthday',
        'crm_sync_minor',
        'attorney_reviewed',
        'created_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'current_version' => 'integer',
        'validity_duration_days' => 'integer',
        'max_minors' => 'integer',
        'reminder_eligible' => 'boolean',
        'assigned_package_ids' => 'array',
        'assigned_attraction_ids' => 'array',
        'assigned_event_ids' => 'array',
        'assigned_party_types' => 'array',
        'minor_section_enabled' => 'boolean',
        'dob_required' => 'boolean',
        'relationship_required' => 'boolean',
        'photo_video_release_enabled' => 'boolean',
        'medical_ack_enabled' => 'boolean',
        'property_damage_enabled' => 'boolean',
        'group_leader_clause_enabled' => 'boolean',
        'electronic_consent_enabled' => 'boolean',
        'marketing_consent_enabled' => 'boolean',
        'crm_sync_allowed' => 'boolean',
        'crm_sync_birthday' => 'boolean',
        'crm_sync_minor' => 'boolean',
        'attorney_reviewed' => 'boolean',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    public const DUPLICATE_NONE = 'none';
    public const DUPLICATE_ALLOW = 'allow';
    public const DUPLICATE_MANAGER_ONLY = 'manager_only';

    /** Activity types a template can be assigned to, mapped to their array column. */
    public const ASSIGNMENT_COLUMNS = [
        'package' => 'assigned_package_ids',
        'attraction' => 'assigned_attraction_ids',
        'event' => 'assigned_event_ids',
        'party_type' => 'assigned_party_types',
    ];

    /** Clause flags + marketing text frozen into each version snapshot. */
    public const CLAUSE_FIELDS = [
        'minor_section_enabled',
        'dob_required',
        'relationship_required',
        'photo_video_release_enabled',
        'medical_ack_enabled',
        'property_damage_enabled',
        'group_leader_clause_enabled',
        'electronic_consent_enabled',
        'marketing_consent_enabled',
        'marketing_consent_text',
        'marketing_helper_text',
        'max_minors',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WaiverTemplateVersion::class)->orderByDesc('version');
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(Waiver::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Does this template cover the given activity?
     * Mirrors FeeSupport::appliesToEntity().
     */
    public function appliesToActivity(string $type, int|string $id): bool
    {
        $column = self::ASSIGNMENT_COLUMNS[$type] ?? null;
        if (!$column) {
            return false;
        }
        return in_array($id, $this->{$column} ?? [], false);
    }

    /** All activity IDs of a given type this template is assigned to. */
    public function assignedIds(string $type): array
    {
        $column = self::ASSIGNMENT_COLUMNS[$type] ?? null;
        return $column ? ($this->{$column} ?? []) : [];
    }

    /**
     * Resolve the active template for a given activity within a company, preferring a
     * location-specific template over a company-wide one, then falling back to the
     * company/location default. Mirrors FeeSupport::getFeesForEntity()'s lookup style.
     */
    public static function resolveForActivity(
        int $companyId,
        ?int $locationId,
        ?int $packageId = null,
        array $attractionIds = [],
        ?int $eventId = null,
        ?string $partyType = null
    ): ?self {
        $candidates = static::active()
            ->forCompany($companyId)
            ->where(function ($q) use ($locationId) {
                $q->whereNull('location_id');
                if ($locationId) {
                    $q->orWhere('location_id', $locationId);
                }
            })
            // location-specific templates win over company-wide ones
            ->orderByRaw('location_id IS NULL')
            ->get();

        foreach ($candidates as $template) {
            if ($eventId && $template->appliesToActivity('event', $eventId)) {
                return $template;
            }
            if ($packageId !== null && $template->appliesToActivity('package', $packageId)) {
                return $template;
            }
            foreach ($attractionIds as $attractionId) {
                if ($template->appliesToActivity('attraction', $attractionId)) {
                    return $template;
                }
            }
            if ($partyType !== null && $template->appliesToActivity('party_type', $partyType)) {
                return $template;
            }
        }

        return $candidates->firstWhere('is_default', true);
    }
}
