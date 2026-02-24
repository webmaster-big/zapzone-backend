<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BookingInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'guest_name',
        'guest_email',
        'guest_phone',
        'send_via',
        'rsvp_token',
        'rsvp_status',
        'rsvp_full_name',
        'rsvp_email',
        'rsvp_phone',
        'rsvp_guest_count',
        'rsvp_notes',
        'marketing_opt_in',
        'email_sent_at',
        'sms_sent_at',
        'responded_at',
    ];

    protected $casts = [
        'rsvp_guest_count' => 'integer',
        'marketing_opt_in' => 'boolean',
        'email_sent_at' => 'datetime',
        'sms_sent_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($invitation) {
            if (empty($invitation->rsvp_token)) {
                $invitation->rsvp_token = self::generateUniqueToken();
            }
        });
    }

    /**
     * Generate a unique RSVP token.
     */
    public static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::where('rsvp_token', $token)->exists());

        return $token;
    }

    // ============================================
    // RELATIONSHIPS
    // ============================================

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeByBooking($query, int $bookingId)
    {
        return $query->where('booking_id', $bookingId);
    }

    public function scopePending($query)
    {
        return $query->where('rsvp_status', 'pending');
    }

    public function scopeAttending($query)
    {
        return $query->where('rsvp_status', 'attending');
    }

    public function scopeDeclined($query)
    {
        return $query->where('rsvp_status', 'declined');
    }

    // ============================================
    // HELPERS
    // ============================================

    /**
     * Check if the invitation has been responded to.
     */
    public function hasResponded(): bool
    {
        return $this->rsvp_status !== 'pending';
    }

    /**
     * Check if the guest is attending.
     */
    public function isAttending(): bool
    {
        return $this->rsvp_status === 'attending';
    }

    /**
     * Mark the invitation as responded with RSVP data.
     */
    public function submitRsvp(array $data): self
    {
        $this->update([
            'rsvp_status' => $data['rsvp_status'],
            'rsvp_full_name' => $data['full_name'] ?? null,
            'rsvp_email' => $data['email'] ?? null,
            'rsvp_phone' => $data['phone'] ?? null,
            'rsvp_guest_count' => $data['guest_count'] ?? null,
            'rsvp_notes' => $data['notes'] ?? null,
            'marketing_opt_in' => $data['marketing_opt_in'] ?? false,
            'responded_at' => now(),
        ]);

        return $this;
    }

    /**
     * Get the RSVP URL for this invitation.
     */
    public function getRsvpUrl(): string
    {
        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
        return $frontendUrl . '/rsvp/' . $this->rsvp_token;
    }

    /**
     * Get summary stats for a booking's invitations.
     */
    public static function getSummaryForBooking(int $bookingId, int $maxParticipants): array
    {
        $invitations = self::where('booking_id', $bookingId)->get();

        $attending = $invitations->where('rsvp_status', 'attending');
        $totalGuestCount = $attending->sum('rsvp_guest_count') ?: $attending->count();

        return [
            'total_invited' => $invitations->count(),
            'attending' => $attending->count(),
            'declined' => $invitations->where('rsvp_status', 'declined')->count(),
            'pending' => $invitations->where('rsvp_status', 'pending')->count(),
            'total_guest_count' => $totalGuestCount,
            'remaining_slots' => max(0, $maxParticipants - $invitations->count()),
        ];
    }
}
