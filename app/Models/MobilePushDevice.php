<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobilePushDevice extends Model
{
    use HasFactory;

    public const PLATFORMS = ['ios', 'android'];

    public const TOKEN_PATTERN = '/^Expo(nent)?PushToken\[[A-Za-z0-9._-]+\]$/';

    protected $fillable = [
        'user_id',
        'company_id',
        'expo_push_token',
        'platform',
        'device_name',
        'app_version',
        'is_active',
        'last_used_at',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForToken($query, string $token)
    {
        return $query->where('expo_push_token', $token);
    }

    public static function isValidToken(?string $token): bool
    {
        return is_string($token) && preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    public function deactivate(): bool
    {
        return $this->forceFill(['is_active' => false])->save();
    }
}
