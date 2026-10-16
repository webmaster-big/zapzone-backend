<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;


class Customer extends Model
{
    use HasFactory, Notifiable, HasApiTokens;

    protected static function booted(): void
    {
        static::deleting(function (Customer $customer) {
            if ($customer->hasMembershipHistory()) {
                return false;
            }

            $customer->keepHistoryAsGuest();
        });
    }

    public function hasMembershipHistory(): bool
    {
        return Membership::where('customer_id', $this->id)->exists()
            || MembershipGroup::where('payer_customer_id', $this->id)->exists()
            || MembershipPayment::where('customer_id', $this->id)->exists()
            || MembershipVisit::where('customer_id', $this->id)->exists()
            || MembershipBenefitRedemption::where('customer_id', $this->id)->exists();
    }

    public function keepHistoryAsGuest(): void
    {
        $guest = array_filter([
            'guest_name' => trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')),
            'guest_email' => $this->email,
            'guest_phone' => $this->phone,
        ], fn ($value) => $value !== null && $value !== '');

        foreach ([Booking::class, AttractionPurchase::class, EventPurchase::class] as $model) {
            $model::withTrashed()->where('customer_id', $this->id)->get()->each(function (Model $record) use ($guest) {
                foreach ($guest as $column => $value) {
                    if ($record->getAttribute($column) === null || $record->getAttribute($column) === '') {
                        $record->setAttribute($column, $value);
                    }
                }

                $record->forceFill(['customer_id' => null])->save();
            });
        }

        foreach ([Payment::class, PackageTimeSlot::class] as $model) {
            $query = in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model), true)
                ? $model::withTrashed()
                : $model::query();

            $query->where('customer_id', $this->id)->get()->each(
                fn (Model $record) => $record->forceFill(['customer_id' => null])->save()
            );
        }
    }

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'zip',
        'country',
        'password',
        'date_of_birth',
        'total_bookings',
        'total_spent',
        'last_visit',
        'status',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'total_spent' => 'decimal:2',
        'last_visit' => 'datetime',
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function attractionPurchases(): HasMany
    {
        return $this->hasMany(AttractionPurchase::class);
    }

    public function eventPurchases(): HasMany
    {
        return $this->hasMany(EventPurchase::class);
    }

    public function giftCards(): BelongsToMany
    {
        return $this->belongsToMany(GiftCard::class, 'customer_gift_cards');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(CustomerNotification::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function activeMembership()
    {
        return $this->hasOne(Membership::class)->whereIn('status', ['active', 'past_due', 'frozen']);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByEmail($query, $email)
    {
        return $query->where('email', 'like', "%{$email}%");
    }

    public function scopeByName($query, $name)
    {
        return $query->where(function ($q) use ($name) {
            $q->where('first_name', 'like', "%{$name}%")
              ->orWhere('last_name', 'like', "%{$name}%");
        });
    }

    public function getBillingData(): array
    {
        return [
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'zip' => $this->zip,
            'country' => $this->country ?? 'US',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
