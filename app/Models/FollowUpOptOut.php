<?php

namespace App\Models;

use App\Support\SchemaSupport;
use Illuminate\Database\Eloquent\Model;

class FollowUpOptOut extends Model
{
    protected $fillable = [
        'company_id',
        'email',
        'visit_follow_up_id',
    ];

    public static function isAvailable(): bool
    {
        return SchemaSupport::hasTable('follow_up_opt_outs');
    }

    public static function normalize(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    public static function hasOptedOut(int $companyId, ?string $email): bool
    {
        if (!self::isAvailable()) {
            return false;
        }

        $normalized = self::normalize($email);

        return $normalized !== '' && self::where('company_id', $companyId)->where('email', $normalized)->exists();
    }
}
