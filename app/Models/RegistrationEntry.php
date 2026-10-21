<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationEntry extends Model
{
    protected $fillable = [
        'registration_event_id',
        'registration_contact_id',
        'company_id',
        'location_id',
        'name',
        'name_key',
        'email',
        'phone',
        'submission_key',
    ];

    protected $casts = [
        'hidden_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(RegistrationEvent::class, 'registration_event_id')->withTrashed();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(RegistrationContact::class, 'registration_contact_id');
    }

    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_by');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('hidden_at');
    }
}
