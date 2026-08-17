<?php

namespace App\Models;

use App\Support\LocationSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'slug',
        'address',
        'city',
        'state',
        'zip_code',
        'latitude',
        'longitude',
        'phone',
        'email',
        'timezone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
        'geocoded_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function authorizeNetAccount(): HasOne
    {
        return $this->hasOne(AuthorizeNetAccount::class);
    }

    public function googleCalendarSetting(): HasOne
    {
        return $this->hasOne(GoogleCalendarSetting::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function giftCards(): HasMany
    {
        return $this->hasMany(GiftCard::class);
    }

    public function photoSetting(): HasOne
    {
        return $this->hasOne(LocationPhotoSetting::class);
    }

    public function photoOverlays(): HasMany
    {
        return $this->hasMany(PhotoOverlay::class);
    }

    public function photoSessions(): HasMany
    {
        return $this->hasMany(PhotoSession::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    public function slideshowQueues(): HasMany
    {
        return $this->hasMany(SlideshowQueue::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Location $location) {
            if (blank($location->slug)) {
                $location->slug = LocationSlug::unique(
                    LocationSlug::preferredSource($location->city, $location->name),
                    self::takenSlugs()
                );
                return;
            }

            $location->slug = LocationSlug::make($location->slug);
        });

        static::updating(function (Location $location) {
            if (blank($location->slug)) {
                $location->slug = LocationSlug::unique(
                    LocationSlug::preferredSource($location->city, $location->name),
                    self::takenSlugs($location->id)
                );
                return;
            }

            if ($location->isDirty('slug')) {
                $location->slug = LocationSlug::make($location->slug);
                return;
            }

            if (!$location->isDirty('name') && !$location->isDirty('city')) {
                return;
            }

            $derivedFromOriginal = LocationSlug::make(LocationSlug::preferredSource(
                $location->getOriginal('city'),
                $location->getOriginal('name')
            ));

            if ($location->getOriginal('slug') === $derivedFromOriginal) {
                $location->slug = LocationSlug::unique(
                    LocationSlug::preferredSource($location->city, $location->name),
                    self::takenSlugs($location->id)
                );
            }
        });
    }

    protected static function takenSlugs(?int $ignoreId = null): array
    {
        return static::query()
            ->whereNotNull('slug')
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->pluck('slug')
            ->all();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBySlug($query, string $slug)
    {
        return $query->where('slug', LocationSlug::make($slug));
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByCity($query, $city)
    {
        return $query->where('city', 'like', "%{$city}%");
    }
}
