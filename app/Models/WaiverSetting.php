<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaiverSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'default_validity_days',
        'waivers_expire',
        'default_expiration_days',
        'require_new_on_text_change',
        'default_duplicate_rule',
        'reminder_window_hours',
        'always_include_link_in_confirmation',
        'search_auto_refresh_seconds',
        'kiosk_inactivity_timeout_seconds',
        'kiosk_disable_autofill',
        'admin_delete_enabled',
        'manager_print_export_enabled',
        'manager_can_build_templates',
        'manager_can_view_deletion_log',
        'marketing_consent_enabled',
        'crm_sync_only_when_consented',
        'minor_marketing_disabled',
    ];

    protected $casts = [
        'default_validity_days' => 'integer',
        'waivers_expire' => 'boolean',
        'default_expiration_days' => 'integer',
        'require_new_on_text_change' => 'boolean',
        'reminder_window_hours' => 'integer',
        'always_include_link_in_confirmation' => 'boolean',
        'search_auto_refresh_seconds' => 'integer',
        'kiosk_inactivity_timeout_seconds' => 'integer',
        'kiosk_disable_autofill' => 'boolean',
        'admin_delete_enabled' => 'boolean',
        'manager_print_export_enabled' => 'boolean',
        'manager_can_build_templates' => 'boolean',
        'manager_can_view_deletion_log' => 'boolean',
        'marketing_consent_enabled' => 'boolean',
        'crm_sync_only_when_consented' => 'boolean',
        'minor_marketing_disabled' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Fetch (or lazily create) the settings row for a company, applying schema defaults.
     */
    public static function forCompany(int $companyId): self
    {
        return static::firstOrCreate(['company_id' => $companyId]);
    }
}
