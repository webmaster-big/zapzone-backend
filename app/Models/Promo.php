<?php

namespace App\Models;

use App\Traits\HasTargeting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Promo extends Model
{
    use HasFactory, HasTargeting;

    protected $fillable = [
        'code',
        'code_mode',
        'batch_id',
        'name',
        'type',
        'value',
        'start_date',
        'end_date',
        'usage_limit_total',
        'usage_limit_per_user',
        'current_usage',
        'status',
        'description',
        'created_by',
        'deleted',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'deleted' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')->where('deleted', false);
    }

    public function scopeNotExpired($query)
    {
        return $query->where('end_date', '>=', now()->toDateString());
    }

    public function scopeStarted($query)
    {
        return $query->where('start_date', '<=', now()->toDateString());
    }

    public function scopeValid($query)
    {
        return $query->active()->notExpired()->started();
    }

    public function scopeByCode($query, $code)
    {
        return $query->where('code', $code);
    }

    public function scopeByBatch($query, $batchId)
    {
        return $query->where('batch_id', $batchId);
    }

    public function scopeSingleMode($query)
    {
        return $query->where('code_mode', 'single');
    }

    public function scopeUniqueMode($query)
    {
        return $query->where('code_mode', 'unique');
    }

    public function isExpired(): bool
    {
        return $this->end_date !== null && $this->end_date->startOfDay()->lt(now()->startOfDay());
    }

    public function hasStarted(): bool
    {
        return $this->start_date === null || $this->start_date->startOfDay()->lte(now()->startOfDay());
    }

    public function belongsToCompany(int $companyId): bool
    {
        $creatorCompanyId = $this->creator?->company_id;

        if ($creatorCompanyId !== null) {
            return (int) $creatorCompanyId === $companyId;
        }

        $targets = self::normalizeIds($this->location_ids);

        if ($targets === null) {
            return false;
        }

        $companyLocationIds = Location::where('company_id', $companyId)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_diff($targets, $companyLocationIds) === [];
    }

    public function offerLabel(): string
    {
        $value = (float) $this->value;

        if ($this->type === 'percentage') {
            $number = fmod($value, 1.0) === 0.0 ? (string) (int) $value : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

            return $number . '% off';
        }

        return '$' . (fmod($value, 1.0) === 0.0 ? number_format($value, 0) : number_format($value, 2)) . ' off';
    }

    public function isUsedUp(): bool
    {
        return $this->usage_limit_total && $this->current_usage >= $this->usage_limit_total;
    }

    public function isValid(): bool
    {
        return $this->status === 'active' &&
               !$this->deleted &&
               $this->hasStarted() &&
               !$this->isExpired() &&
               (!$this->usage_limit_total || $this->current_usage < $this->usage_limit_total);
    }
}
