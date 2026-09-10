<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'hidden_quick_actions',
    ];

    protected $casts = [
        'hidden_quick_actions' => 'array',
    ];

    public const DEFAULT_HIDDEN_QUICK_ACTIONS = [
        'Calendar',
        'Packages',
        'Attractions',
        'Customers',
        'Analytics',
        'Locations',
    ];

    public static function forCompany(?int $companyId): ?self
    {
        if (!$companyId) {
            return null;
        }

        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('dashboard_settings')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return self::firstOrCreate(
            ['company_id' => $companyId],
            ['hidden_quick_actions' => self::DEFAULT_HIDDEN_QUICK_ACTIONS],
        );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
