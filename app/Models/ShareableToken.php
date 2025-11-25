<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ShareableToken extends Model
{
    protected $table = 'access_shareable_tokens';

    protected $fillable = [
        'token',
        'email',
        'role',
        'created_by',
        'used_at',
        'is_active',
        'company_id',
        'location_id',
    ];

    protected $casts = [
        'used_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($token) {
            if (empty($token->token)) {
                $token->token = self::generateUniqueToken();
            }

        });
    }

    /**
     * Generate a unique token.
     */
    public static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(32) . bin2hex(random_bytes(16));
        } while (self::where('token', $token)->exists());

        return $token;
    }

    /**
     * Relationships
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function usedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeUnused($query)
    {
        return $query->whereNull('used_at');
    }

    public function scopeValid($query)
    {
        return $query->active()->unused()->notExpired();
    }

    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    public function scopeByEmail($query, string $email)
    {
        return $query->where('email', $email);
    }

    /**
     * Helper methods
     */
    public function isValid(): bool
    {
        return $this->is_active
            && is_null($this->used_at);
    }



    public function isUsed(): bool
    {
        return !is_null($this->used_at);
    }

    public function markAsUsed(int $userId): bool
    {
        return $this->update([
            'used_at' => now(),
        ]);
    }

    public function deactivate(): bool
    {
        return $this->update(['is_active' => false]);
    }

    public function getShareableLink(): string
    {
        $baseUrl = env('APP_FRONTEND_URL', 'http://localhost:5173');
        $url = "{$baseUrl}/admin/register?token={$this->token}&role={$this->role}&companyId={$this->company_id}";

        // Only add locationId for location_manager and attendant roles
        if (in_array($this->role, ['location_manager', 'attendant']) && $this->location_id) {
            $url .= "&locationId={$this->location_id}";
        }

        return $url;
    }
}
