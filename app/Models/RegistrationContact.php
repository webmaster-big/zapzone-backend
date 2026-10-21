<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegistrationContact extends Model
{
    protected $fillable = [
        'company_id',
        'email_key',
        'phone_key',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(RegistrationEntry::class);
    }
}
