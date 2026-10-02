<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $appends = [
        'name',
    ];

    protected $fillable = [
        'company_id',
        'location_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'profile_path',
        'role',
        'employee_id',
        'department',
        'position',
        'shift',
        'assigned_areas',
        'hire_date',
        'status',
        'last_login',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // the override PIN is a credential: it must never reach a response
        'override_pin',
        'pin_hash',
        'pin_lookup',
        'pin_lookup_v',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'override_pin_set_at' => 'datetime',
            'pin_set_at' => 'datetime',
            'pin_locked_until' => 'datetime',
            'assigned_areas' => 'array',
            'hire_date' => 'date',
            'last_login' => 'datetime',
        ];
    }

    public function staffTerminals()
    {
        return $this->hasMany(StaffTerminal::class, 'enrolled_by_user_id');
    }

    public function hasPin(): bool
    {
        return $this->pin_hash !== null;
    }

    public function pinIsLocked(): bool
    {
        return $this->pin_locked_until !== null && $this->pin_locked_until->isFuture();
    }

    public function scopeWithPin($query)
    {
        return $query->whereNotNull('pin_hash');
    }

    public function getNameAttribute(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function mobilePushDevices()
    {
        return $this->hasMany(MobilePushDevice::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByRole($query, $role)
    {
        return $query->where('role', $role);
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }
}
