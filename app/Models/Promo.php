<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Promo extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
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

    // Relationships
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'package_promos');
    }

    // Scopes
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

    // Helpers
    public function isExpired(): bool
    {
        return $this->end_date < now()->toDateString();
    }

    public function hasStarted(): bool
    {
        return $this->start_date <= now()->toDateString();
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
