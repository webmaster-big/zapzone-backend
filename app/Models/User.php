<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    public const MULTI_LOCATION_ROLE = 'location_manager';

    protected ?int $sessionHomeLocationId = null;

    protected ?array $workLocationIdsMemo = null;

    protected $appends = [
        'name',
    ];

    protected static function booted(): void
    {
        static::deleting(function (User $user) {
            ShareableToken::where('created_by', $user->id)
                ->whereNull('used_at')
                ->where('is_active', true)
                ->update(['is_active' => false]);
        });

        static::updated(function (User $user) {
            if ($user->wasChanged('status')) {
                $user->tokens()->delete();

                if ($user->status === 'inactive') {
                    ShareableToken::where('created_by', $user->id)
                        ->whereNull('used_at')
                        ->where('is_active', true)
                        ->update(['is_active' => false]);
                }
            }
        });
    }

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

    public function locations()
    {
        return $this->belongsToMany(Location::class)->withTimestamps();
    }

    public static function tracksWorkLocations(): bool
    {
        static $known = null;

        if ($known === null) {
            try {
                $known = \Illuminate\Support\Facades\Schema::hasTable('location_user');
            } catch (\Throwable $e) {
                $known = false;
            }
        }

        return $known;
    }

    public function canHoldSeveralLocations(): bool
    {
        return (string) $this->role === self::MULTI_LOCATION_ROLE;
    }

    public function homeLocationId(): ?int
    {
        if ($this->sessionHomeLocationId !== null) {
            return $this->sessionHomeLocationId;
        }

        return $this->location_id !== null ? (int) $this->location_id : null;
    }

    public function workLocationIds(): array
    {
        if ($this->workLocationIdsMemo !== null) {
            return $this->workLocationIdsMemo;
        }

        $home = $this->homeLocationId();
        $ids = $home !== null ? [$home] : [];

        if ($this->canHoldSeveralLocations() && $this->getKey() !== null && self::tracksWorkLocations()) {
            $assigned = $this->locations()
                ->where('locations.is_active', true)
                ->when($this->company_id, fn ($query) => $query->where('locations.company_id', $this->company_id))
                ->pluck('locations.id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $ids = array_values(array_unique(array_merge($ids, $assigned)));
        }

        return $this->workLocationIdsMemo = $ids;
    }

    public function canWorkAt($locationId): bool
    {
        if ($locationId === null || $locationId === '' || ! is_numeric($locationId)) {
            return false;
        }

        return in_array((int) $locationId, $this->workLocationIds(), true);
    }

    public function enterLocation(int $locationId): bool
    {
        if (! $this->canHoldSeveralLocations() || ! $this->canWorkAt($locationId)) {
            return false;
        }

        $this->sessionHomeLocationId = $this->homeLocationId();
        $this->setAttribute('location_id', $locationId);
        $this->syncOriginalAttribute('location_id');
        $this->unsetRelation('location');

        return true;
    }

    public function workLocations()
    {
        $ids = $this->workLocationIds();

        if ($ids === []) {
            return collect();
        }

        $home = $this->homeLocationId();

        return Location::query()
            ->whereIn('id', $ids)
            ->get(['id', 'company_id', 'name', 'slug', 'city', 'state', 'is_active'])
            ->sortBy(fn (Location $location) => [(int) $location->id === $home ? 0 : 1, $location->name])
            ->values();
    }

    public function locationAccessPayload(): array
    {
        return [
            'home_location_id' => $this->homeLocationId(),
            'work_locations' => $this->workLocations()
                ->map(fn (Location $location) => [
                    'id' => (int) $location->id,
                    'name' => $location->name,
                    'slug' => $location->slug,
                    'city' => $location->city,
                    'state' => $location->state,
                ])
                ->all(),
        ];
    }

    public function forgetWorkLocations(): void
    {
        $this->workLocationIdsMemo = null;
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

    public function scopeWorkingAt($query, $locationId)
    {
        if (! self::tracksWorkLocations()) {
            return $query->where('users.location_id', $locationId);
        }

        return $query->where(function ($staff) use ($locationId) {
            $staff->where('users.location_id', $locationId)
                ->orWhere(function ($manager) use ($locationId) {
                    $manager->where('users.role', self::MULTI_LOCATION_ROLE)
                        ->whereHas('locations', fn ($location) => $location->where('locations.id', $locationId));
                });
        });
    }
}
