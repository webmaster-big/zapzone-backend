<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'name',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'zip',
        'country',
        'source',
        'total_bookings',
        'total_purchases',
        'total_spent',
        'last_activity_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'total_spent' => 'decimal:2',
        'last_activity_at' => 'datetime',
    ];

    // Relationships
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeBySource($query, $source)
    {
        return $query->where('source', $source);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%")
              ->orWhere('phone', 'like', "%{$search}%");
        });
    }

    /**
     * Find or create a contact by email
     *
     * @param string $email
     * @param array $data
     * @return Contact
     */
    public static function findOrCreateByEmail(string $email, array $data = []): Contact
    {
        $contact = self::where('email', $email)->first();

        if ($contact) {
            // Update last activity
            $contact->update([
                'last_activity_at' => now(),
            ]);
            return $contact;
        }

        // Create new contact
        return self::create(array_merge([
            'email' => $email,
            'last_activity_at' => now(),
        ], $data));
    }

    /**
     * Increment booking count and update stats
     *
     * @param float $amount
     * @return void
     */
    public function incrementBooking(float $amount = 0): void
    {
        $this->increment('total_bookings');
        $this->increment('total_spent', $amount);
        $this->update(['last_activity_at' => now()]);
    }

    /**
     * Increment purchase count and update stats
     *
     * @param float $amount
     * @return void
     */
    public function incrementPurchase(float $amount = 0): void
    {
        $this->increment('total_purchases');
        $this->increment('total_spent', $amount);
        $this->update(['last_activity_at' => now()]);
    }
}
