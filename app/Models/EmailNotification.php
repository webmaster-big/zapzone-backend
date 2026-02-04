<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailNotification extends Model
{
    use HasFactory;

    // ============================================
    // TRIGGER TYPES
    // ============================================

    // Booking Triggers
    const TRIGGER_BOOKING_CREATED = 'booking_created';
    const TRIGGER_BOOKING_CONFIRMED = 'booking_confirmed';
    const TRIGGER_BOOKING_UPDATED = 'booking_updated';
    const TRIGGER_BOOKING_RESCHEDULED = 'booking_rescheduled';
    const TRIGGER_BOOKING_CANCELLED = 'booking_cancelled';
    const TRIGGER_BOOKING_CHECKED_IN = 'booking_checked_in';
    const TRIGGER_BOOKING_COMPLETED = 'booking_completed';
    const TRIGGER_BOOKING_REMINDER = 'booking_reminder'; // Uses send_before_hours
    const TRIGGER_BOOKING_FOLLOWUP = 'booking_followup'; // Uses send_after_hours
    const TRIGGER_BOOKING_NO_SHOW = 'booking_no_show';

    // Payment Triggers (for bookings)
    const TRIGGER_PAYMENT_RECEIVED = 'payment_received';
    const TRIGGER_PAYMENT_FAILED = 'payment_failed';
    const TRIGGER_PAYMENT_REFUNDED = 'payment_refunded';
    const TRIGGER_PAYMENT_PARTIAL = 'payment_partial';
    const TRIGGER_PAYMENT_PENDING = 'payment_pending';

    // Attraction Purchase Triggers
    const TRIGGER_PURCHASE_CREATED = 'purchase_created';
    const TRIGGER_PURCHASE_CONFIRMED = 'purchase_confirmed';
    const TRIGGER_PURCHASE_CANCELLED = 'purchase_cancelled';
    const TRIGGER_PURCHASE_COMPLETED = 'purchase_completed';
    const TRIGGER_PURCHASE_CHECKED_IN = 'purchase_checked_in';
    const TRIGGER_PURCHASE_REFUNDED = 'purchase_refunded';
    const TRIGGER_PURCHASE_REMINDER = 'purchase_reminder';
    const TRIGGER_PURCHASE_FOLLOWUP = 'purchase_followup';

    // Entity types
    const ENTITY_PACKAGE = 'package';
    const ENTITY_ATTRACTION = 'attraction';
    const ENTITY_ALL = 'all';

    // Recipient types
    const RECIPIENT_CUSTOMER = 'customer';
    const RECIPIENT_STAFF = 'staff';
    const RECIPIENT_COMPANY_ADMIN = 'company_admin';
    const RECIPIENT_LOCATION_MANAGER = 'location_manager';
    const RECIPIENT_CUSTOM = 'custom';

    protected $fillable = [
        'company_id',
        'location_id',
        'name',
        'trigger_type',
        'entity_type',
        'entity_ids',
        'email_template_id',
        'subject',
        'body',
        'recipient_types',
        'custom_emails',
        'include_qr_code',
        'is_active',
        'send_before_hours',
        'send_after_hours',
    ];

    protected $casts = [
        'entity_ids' => 'array',
        'recipient_types' => 'array',
        'custom_emails' => 'array',
        'include_qr_code' => 'boolean',
        'is_active' => 'boolean',
        'send_before_hours' => 'integer',
        'send_after_hours' => 'integer',
    ];

    /**
     * Get the company that owns this notification.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the location (optional).
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Get the email template (optional).
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    /**
     * Get the notification logs.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(EmailNotificationLog::class);
    }

    /**
     * Scope to get active notifications.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope by trigger type.
     */
    public function scopeForTrigger($query, string $triggerType)
    {
        return $query->where('trigger_type', $triggerType);
    }

    /**
     * Scope by entity type.
     */
    public function scopeForEntityType($query, string $entityType)
    {
        return $query->where('entity_type', $entityType);
    }

    /**
     * Check if this notification applies to a specific entity ID.
     */
    public function appliesToEntity(?int $entityId): bool
    {
        $entityIds = $this->entity_ids ?? [];

        // If entity_ids is empty or null, applies to all
        if (empty($entityIds)) {
            return true;
        }

        // If entity type is 'all', applies to everything
        if ($this->entity_type === self::ENTITY_ALL) {
            return true;
        }

        return in_array($entityId, $entityIds);
    }

    /**
     * Get the subject (from template or custom).
     */
    public function getEffectiveSubject(): string
    {
        if ($this->template) {
            return $this->template->subject;
        }

        return $this->subject ?? 'Notification';
    }

    /**
     * Get the body (from template or custom).
     */
    public function getEffectiveBody(): string
    {
        if ($this->template) {
            return $this->template->body;
        }

        return $this->body ?? '';
    }

    /**
     * Find matching notifications for a booking by trigger type.
     */
    public static function findForBooking(Booking $booking, string $triggerType = self::TRIGGER_BOOKING_CREATED): \Illuminate\Support\Collection
    {
        return self::active()
            ->forTrigger($triggerType)
            ->where(function ($query) {
                $query->where('entity_type', self::ENTITY_PACKAGE)
                    ->orWhere('entity_type', self::ENTITY_ALL);
            })
            ->where(function ($query) use ($booking) {
                // Match by location if specified
                $query->whereNull('location_id')
                    ->orWhere('location_id', $booking->location_id);
            })
            ->get()
            ->filter(function ($notification) use ($booking) {
                return $notification->appliesToEntity($booking->package_id);
            });
    }

    /**
     * Find matching notifications for an attraction purchase by trigger type.
     */
    public static function findForPurchase(AttractionPurchase $purchase, string $triggerType = self::TRIGGER_PURCHASE_CREATED): \Illuminate\Support\Collection
    {
        return self::active()
            ->forTrigger($triggerType)
            ->where(function ($query) {
                $query->where('entity_type', self::ENTITY_ATTRACTION)
                    ->orWhere('entity_type', self::ENTITY_ALL);
            })
            ->where(function ($query) use ($purchase) {
                // Match by location if specified
                $query->whereNull('location_id')
                    ->orWhere('location_id', $purchase->attraction->location_id ?? null);
            })
            ->get()
            ->filter(function ($notification) use ($purchase) {
                return $notification->appliesToEntity($purchase->attraction_id);
            });
    }

    /**
     * Find matching notifications for a payment by trigger type.
     */
    public static function findForPayment($payment, string $triggerType): \Illuminate\Support\Collection
    {
        $locationId = null;

        // Get location from payable entity
        if ($payment->payable_type === 'App\\Models\\Booking') {
            $locationId = $payment->payable?->location_id;
        }

        return self::active()
            ->forTrigger($triggerType)
            ->where(function ($query) use ($locationId) {
                $query->whereNull('location_id');
                if ($locationId) {
                    $query->orWhere('location_id', $locationId);
                }
            })
            ->get();
    }

    /**
     * Get all available trigger types organized by category.
     */
    public static function getTriggerTypes(): array
    {
        return [
            'booking' => [
                self::TRIGGER_BOOKING_CREATED => 'Booking Created',
                self::TRIGGER_BOOKING_CONFIRMED => 'Booking Confirmed',
                self::TRIGGER_BOOKING_UPDATED => 'Booking Updated',
                self::TRIGGER_BOOKING_RESCHEDULED => 'Booking Rescheduled',
                self::TRIGGER_BOOKING_CANCELLED => 'Booking Cancelled',
                self::TRIGGER_BOOKING_CHECKED_IN => 'Booking Checked In',
                self::TRIGGER_BOOKING_COMPLETED => 'Booking Completed',
                self::TRIGGER_BOOKING_REMINDER => 'Booking Reminder (Before)',
                self::TRIGGER_BOOKING_FOLLOWUP => 'Booking Follow-up (After)',
                self::TRIGGER_BOOKING_NO_SHOW => 'Booking No-Show',
            ],
            'payment' => [
                self::TRIGGER_PAYMENT_RECEIVED => 'Payment Received',
                self::TRIGGER_PAYMENT_FAILED => 'Payment Failed',
                self::TRIGGER_PAYMENT_REFUNDED => 'Payment Refunded',
                self::TRIGGER_PAYMENT_PARTIAL => 'Partial Payment',
                self::TRIGGER_PAYMENT_PENDING => 'Payment Pending',
            ],
            'purchase' => [
                self::TRIGGER_PURCHASE_CREATED => 'Purchase Created',
                self::TRIGGER_PURCHASE_CONFIRMED => 'Purchase Confirmed',
                self::TRIGGER_PURCHASE_CANCELLED => 'Purchase Cancelled',
                self::TRIGGER_PURCHASE_COMPLETED => 'Purchase Completed',
                self::TRIGGER_PURCHASE_CHECKED_IN => 'Purchase Checked In',
                self::TRIGGER_PURCHASE_REFUNDED => 'Purchase Refunded',
                self::TRIGGER_PURCHASE_REMINDER => 'Purchase Reminder',
                self::TRIGGER_PURCHASE_FOLLOWUP => 'Purchase Follow-up',
            ],
        ];
    }

    /**
     * Get flat list of all trigger types.
     */
    public static function getAllTriggerTypes(): array
    {
        $types = [];
        foreach (self::getTriggerTypes() as $category => $triggers) {
            foreach ($triggers as $key => $label) {
                $types[$key] = $label;
            }
        }
        return $types;
    }

    /**
     * Get trigger type category.
     */
    public static function getTriggerCategory(string $triggerType): ?string
    {
        foreach (self::getTriggerTypes() as $category => $triggers) {
            if (isset($triggers[$triggerType])) {
                return $category;
            }
        }
        return null;
    }

    /**
     * Check if trigger type is a reminder (uses send_before_hours).
     */
    public function isReminderType(): bool
    {
        return in_array($this->trigger_type, [
            self::TRIGGER_BOOKING_REMINDER,
            self::TRIGGER_PURCHASE_REMINDER,
        ]);
    }

    /**
     * Check if trigger type is a follow-up (uses send_after_hours).
     */
    public function isFollowUpType(): bool
    {
        return in_array($this->trigger_type, [
            self::TRIGGER_BOOKING_FOLLOWUP,
            self::TRIGGER_PURCHASE_FOLLOWUP,
        ]);
    }

    /**
     * Get all available entity types.
     */
    public static function getEntityTypes(): array
    {
        return [
            self::ENTITY_ALL => 'All (Packages & Attractions)',
            self::ENTITY_PACKAGE => 'Packages Only',
            self::ENTITY_ATTRACTION => 'Attractions Only',
        ];
    }

    /**
     * Get all available recipient types.
     */
    public static function getRecipientTypes(): array
    {
        return [
            self::RECIPIENT_CUSTOMER => 'Customer',
            self::RECIPIENT_STAFF => 'Staff (Attendants)',
            self::RECIPIENT_COMPANY_ADMIN => 'Company Admin',
            self::RECIPIENT_LOCATION_MANAGER => 'Location Manager',
            self::RECIPIENT_CUSTOM => 'Custom Emails',
        ];
    }
}
