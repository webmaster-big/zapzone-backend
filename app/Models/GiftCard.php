<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class GiftCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'type',
        'initial_value',
        'balance',
        'max_usage',
        'description',
        'status',
        'expiry_date',
        'created_by',
        'deleted',
    ];

    protected $casts = [
        'initial_value' => 'decimal:2',
        'balance' => 'decimal:2',
        'expiry_date' => 'date',
        'deleted' => 'boolean',
    ];

    // Relationships
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'package_gift_cards');
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_gift_cards');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active')->where('deleted', false);
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expiry_date')
              ->orWhere('expiry_date', '>=', now()->toDateString());
        });
    }

    public function scopeByCode($query, $code)
    {
        return $query->where('code', $code);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    // Helpers
    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date < now()->toDateString();
    }

    public function isValid(): bool
    {
        return $this->status === 'active' &&
               !$this->deleted &&
               !$this->isExpired() &&
               $this->balance > 0;
    }
}
