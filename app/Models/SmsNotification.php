<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsNotification extends Model
{
    use HasFactory;

    // Booking (party) triggers — mirror EmailNotification trigger strings.
    const TRIGGER_BOOKING_CONFIRMED = 'booking_confirmed';
    const TRIGGER_BOOKING_UPDATED = 'booking_updated';
    const TRIGGER_BOOKING_RESCHEDULED = 'booking_rescheduled';
    const TRIGGER_BOOKING_CANCELLED = 'booking_cancelled';
    const TRIGGER_BOOKING_REMINDER = 'booking_reminder';

    // Attraction purchase triggers.
    const TRIGGER_PURCHASE_CONFIRMED = 'purchase_confirmed';
    const TRIGGER_PURCHASE_RESCHEDULED = 'purchase_rescheduled';
    const TRIGGER_PURCHASE_CANCELLED = 'purchase_cancelled';
    const TRIGGER_PURCHASE_REMINDER = 'purchase_reminder';

    // Event purchase triggers.
    const TRIGGER_EVENT_CONFIRMED = 'event_confirmed';
    const TRIGGER_EVENT_RESCHEDULED = 'event_rescheduled';
    const TRIGGER_EVENT_CANCELLED = 'event_cancelled';
    const TRIGGER_EVENT_REMINDER = 'event_reminder';

    // Payment triggers.
    const TRIGGER_PAYMENT_RECEIVED = 'payment_received';
    const TRIGGER_PAYMENT_REFUNDED = 'payment_refunded';

    // Invitation (party guest) trigger — sent per-guest by InvitationService.
    const TRIGGER_INVITATION_SENT = 'invitation_sent';

    // Waiver triggers — mirror EmailNotification trigger strings.
    const TRIGGER_WAIVER_REMINDER = 'waiver_reminder';
    const TRIGGER_WAIVER_SIGNED = 'waiver_signed';
    const TRIGGER_WAIVER_STAFF_SENT = 'waiver_staff_sent';
    const TRIGGER_WAIVER_BULK_CHAPERONE = 'waiver_bulk_chaperone';
    const TRIGGER_WAIVER_PARENT_INVITE = 'waiver_parent_invite';

    // Default keys (one row per template). Segment x lifecycle.
    const DEFAULT_BOOKING_CONFIRMATION_CUSTOMER = 'booking_confirmation_customer';
    const DEFAULT_BOOKING_REMINDER_CUSTOMER = 'booking_reminder_customer';
    const DEFAULT_BOOKING_RESCHEDULE_CUSTOMER = 'booking_reschedule_customer';
    const DEFAULT_BOOKING_CANCELLATION_CUSTOMER = 'booking_cancellation_customer';
    const DEFAULT_BOOKING_CONFIRMATION_STAFF = 'booking_confirmation_staff';

    const DEFAULT_PURCHASE_CONFIRMATION_CUSTOMER = 'purchase_confirmation_customer';
    const DEFAULT_PURCHASE_REMINDER_CUSTOMER = 'purchase_reminder_customer';
    const DEFAULT_PURCHASE_RESCHEDULE_CUSTOMER = 'purchase_reschedule_customer';
    const DEFAULT_PURCHASE_CANCELLATION_CUSTOMER = 'purchase_cancellation_customer';

    const DEFAULT_EVENT_CONFIRMATION_CUSTOMER = 'event_confirmation_customer';
    const DEFAULT_EVENT_REMINDER_CUSTOMER = 'event_reminder_customer';
    const DEFAULT_EVENT_RESCHEDULE_CUSTOMER = 'event_reschedule_customer';
    const DEFAULT_EVENT_CANCELLATION_CUSTOMER = 'event_cancellation_customer';

    const DEFAULT_PAYMENT_RECEIVED_CUSTOMER = 'payment_received_customer';
    const DEFAULT_PAYMENT_REFUNDED_CUSTOMER = 'payment_refunded_customer';

    const DEFAULT_INVITATION_GUEST = 'invitation_guest';

    const DEFAULT_WAIVER_REMINDER_CUSTOMER = 'waiver_reminder_customer';
    const DEFAULT_WAIVER_SIGNED_CUSTOMER = 'waiver_signed_customer';
    const DEFAULT_WAIVER_STAFF_SENT_CUSTOMER = 'waiver_staff_sent_customer';
    const DEFAULT_WAIVER_BULK_CHAPERONE = 'waiver_bulk_chaperone';
    const DEFAULT_WAIVER_PARENT_INVITE = 'waiver_parent_invite';

    const ENTITY_PACKAGE = 'package';
    const ENTITY_ATTRACTION = 'attraction';
    const ENTITY_EVENT = 'event';
    const ENTITY_WAIVER = 'waiver';
    const ENTITY_ALL = 'all';

    const RECIPIENT_CUSTOMER = 'customer';
    const RECIPIENT_STAFF = 'staff';
    const RECIPIENT_COMPANY_ADMIN = 'company_admin';
    const RECIPIENT_LOCATION_MANAGER = 'location_manager';
    const RECIPIENT_CUSTOM = 'custom';

    protected $fillable = [
        'company_id',
        'location_id',
        'name',
        'description',
        'trigger_type',
        'entity_type',
        'entity_ids',
        'body',
        'default_body',
        'recipient_types',
        'custom_phones',
        'is_active',
        'is_default',
        'default_key',
        'send_before_hours',
        'send_after_hours',
    ];

    protected $casts = [
        'entity_ids' => 'array',
        'recipient_types' => 'array',
        'custom_phones' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'send_before_hours' => 'integer',
        'send_after_hours' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SmsNotificationLog::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForTrigger($query, string $triggerType)
    {
        return $query->where('trigger_type', $triggerType);
    }

    public function scopeDefaults($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeCustom($query)
    {
        return $query->where('is_default', false);
    }

    public function appliesToEntity(?int $entityId): bool
    {
        $entityIds = $this->entity_ids ?? [];

        if (empty($entityIds)) {
            return true;
        }

        if ($this->entity_type === self::ENTITY_ALL) {
            return true;
        }

        return in_array($entityId, $entityIds);
    }

    public function getEffectiveBody(): string
    {
        if ($this->body !== null) {
            return $this->body;
        }

        return $this->default_body ?? '';
    }

    public function isBodyCustomized(): bool
    {
        if (!$this->is_default) {
            return false;
        }

        return $this->body !== null && $this->body !== $this->default_body;
    }

    public function resetToDefault(): void
    {
        $this->update(['body' => null]);
    }

    public function isReminderType(): bool
    {
        return in_array($this->trigger_type, [
            self::TRIGGER_BOOKING_REMINDER,
            self::TRIGGER_PURCHASE_REMINDER,
            self::TRIGGER_EVENT_REMINDER,
        ]);
    }

    public static function findDefault(int $companyId, string $defaultKey): ?self
    {
        return self::where('company_id', $companyId)
            ->where('default_key', $defaultKey)
            ->where('is_default', true)
            ->first();
    }

    public static function findForBooking(Booking $booking, string $triggerType): \Illuminate\Support\Collection
    {
        $companyId = $booking->location?->company_id;

        if (!$companyId) {
            return collect();
        }

        return self::active()
            ->forTrigger($triggerType)
            ->where('company_id', $companyId)
            ->where(function ($query) {
                $query->where('entity_type', self::ENTITY_PACKAGE)
                    ->orWhere('entity_type', self::ENTITY_ALL);
            })
            ->where(function ($query) use ($booking) {
                $query->whereNull('location_id')
                    ->orWhere('location_id', $booking->location_id);
            })
            ->get()
            ->filter(fn ($n) => $n->appliesToEntity($booking->package_id));
    }

    public static function findForPurchase(AttractionPurchase $purchase, string $triggerType): \Illuminate\Support\Collection
    {
        $companyId = $purchase->attraction?->location?->company_id;

        if (!$companyId) {
            return collect();
        }

        return self::active()
            ->forTrigger($triggerType)
            ->where('company_id', $companyId)
            ->where(function ($query) {
                $query->where('entity_type', self::ENTITY_ATTRACTION)
                    ->orWhere('entity_type', self::ENTITY_ALL);
            })
            ->where(function ($query) use ($purchase) {
                $query->whereNull('location_id')
                    ->orWhere('location_id', $purchase->attraction->location_id ?? null);
            })
            ->get()
            ->filter(fn ($n) => $n->appliesToEntity($purchase->attraction_id));
    }

    public static function findForEvent(EventPurchase $purchase, string $triggerType): \Illuminate\Support\Collection
    {
        $companyId = $purchase->location?->company_id;

        if (!$companyId) {
            return collect();
        }

        return self::active()
            ->forTrigger($triggerType)
            ->where('company_id', $companyId)
            ->where(function ($query) {
                $query->where('entity_type', self::ENTITY_EVENT)
                    ->orWhere('entity_type', self::ENTITY_ALL);
            })
            ->where(function ($query) use ($purchase) {
                $query->whereNull('location_id')
                    ->orWhere('location_id', $purchase->location_id);
            })
            ->get()
            ->filter(fn ($n) => $n->appliesToEntity($purchase->event_id));
    }

    public static function findForWaiver(Waiver $waiver, string $triggerType): \Illuminate\Support\Collection
    {
        $companyId = $waiver->company_id ?? $waiver->location?->company_id;

        if (!$companyId) {
            return collect();
        }

        return self::active()
            ->forTrigger($triggerType)
            ->where('company_id', $companyId)
            ->where(function ($query) {
                $query->where('entity_type', self::ENTITY_WAIVER)
                    ->orWhere('entity_type', self::ENTITY_ALL);
            })
            ->where(function ($query) use ($waiver) {
                $query->whereNull('location_id')
                    ->orWhere('location_id', $waiver->location_id);
            })
            ->get()
            ->filter(fn ($n) => $n->appliesToEntity($waiver->waiver_template_id));
    }

    public static function findForPayment($payment, string $triggerType): \Illuminate\Support\Collection
    {
        $locationId = null;
        $companyId = null;

        if ($payment->payable_type === 'App\\Models\\Booking') {
            $locationId = $payment->payable?->location_id;
            $companyId = $payment->payable?->location?->company_id;
        } elseif ($payment->payable_type === 'App\\Models\\AttractionPurchase') {
            $locationId = $payment->payable?->location_id ?? $payment->payable?->attraction?->location_id;
            $companyId = $payment->payable?->attraction?->location?->company_id;
        } elseif ($payment->payable_type === 'App\\Models\\EventPurchase') {
            $locationId = $payment->payable?->location_id;
            $companyId = $payment->payable?->location?->company_id;
        }

        return self::active()
            ->forTrigger($triggerType)
            ->when($companyId, function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            }, function ($query) {
                $query->whereRaw('1 = 0');
            })
            ->where(function ($query) use ($locationId) {
                $query->whereNull('location_id');
                if ($locationId) {
                    $query->orWhere('location_id', $locationId);
                }
            })
            ->get();
    }

    public static function getDefaultKeys(): array
    {
        return [
            self::DEFAULT_BOOKING_CONFIRMATION_CUSTOMER => 'Party Booking Confirmation (Customer)',
            self::DEFAULT_BOOKING_REMINDER_CUSTOMER => 'Party Booking Reminder (Customer)',
            self::DEFAULT_BOOKING_RESCHEDULE_CUSTOMER => 'Party Booking Reschedule (Customer)',
            self::DEFAULT_BOOKING_CANCELLATION_CUSTOMER => 'Party Booking Cancellation (Customer)',
            self::DEFAULT_BOOKING_CONFIRMATION_STAFF => 'Party Booking Alert (Staff)',
            self::DEFAULT_PURCHASE_CONFIRMATION_CUSTOMER => 'Attraction Confirmation (Customer)',
            self::DEFAULT_PURCHASE_REMINDER_CUSTOMER => 'Attraction Reminder (Customer)',
            self::DEFAULT_PURCHASE_RESCHEDULE_CUSTOMER => 'Attraction Reschedule (Customer)',
            self::DEFAULT_PURCHASE_CANCELLATION_CUSTOMER => 'Attraction Cancellation (Customer)',
            self::DEFAULT_EVENT_CONFIRMATION_CUSTOMER => 'Event Confirmation (Customer)',
            self::DEFAULT_EVENT_REMINDER_CUSTOMER => 'Event Reminder (Customer)',
            self::DEFAULT_EVENT_RESCHEDULE_CUSTOMER => 'Event Reschedule (Customer)',
            self::DEFAULT_EVENT_CANCELLATION_CUSTOMER => 'Event Cancellation (Customer)',
            self::DEFAULT_PAYMENT_RECEIVED_CUSTOMER => 'Payment Received (Customer)',
            self::DEFAULT_PAYMENT_REFUNDED_CUSTOMER => 'Payment Refunded (Customer)',
            self::DEFAULT_INVITATION_GUEST => 'Party Invitation (Guest)',
            self::DEFAULT_WAIVER_REMINDER_CUSTOMER => 'Waiver Reminder (Customer)',
            self::DEFAULT_WAIVER_SIGNED_CUSTOMER => 'Waiver Signed (Customer)',
            self::DEFAULT_WAIVER_STAFF_SENT_CUSTOMER => 'Waiver Link Sent (Customer)',
            self::DEFAULT_WAIVER_BULK_CHAPERONE => 'Bulk Waiver Invite (Chaperone)',
            self::DEFAULT_WAIVER_PARENT_INVITE => 'Waiver Invite (Parent/Guardian)',
        ];
    }

    public static function getTriggerTypes(): array
    {
        return [
            'booking' => [
                self::TRIGGER_BOOKING_CONFIRMED => 'Party Booking Confirmed',
                self::TRIGGER_BOOKING_UPDATED => 'Party Booking Updated',
                self::TRIGGER_BOOKING_RESCHEDULED => 'Party Booking Rescheduled',
                self::TRIGGER_BOOKING_CANCELLED => 'Party Booking Cancelled',
                self::TRIGGER_BOOKING_REMINDER => 'Party Booking Reminder (Before)',
            ],
            'purchase' => [
                self::TRIGGER_PURCHASE_CONFIRMED => 'Attraction Confirmed',
                self::TRIGGER_PURCHASE_RESCHEDULED => 'Attraction Rescheduled',
                self::TRIGGER_PURCHASE_CANCELLED => 'Attraction Cancelled',
                self::TRIGGER_PURCHASE_REMINDER => 'Attraction Reminder (Before)',
            ],
            'event' => [
                self::TRIGGER_EVENT_CONFIRMED => 'Event Confirmed',
                self::TRIGGER_EVENT_RESCHEDULED => 'Event Rescheduled',
                self::TRIGGER_EVENT_CANCELLED => 'Event Cancelled',
                self::TRIGGER_EVENT_REMINDER => 'Event Reminder (Before)',
            ],
            'payment' => [
                self::TRIGGER_PAYMENT_RECEIVED => 'Payment Received',
                self::TRIGGER_PAYMENT_REFUNDED => 'Payment Refunded',
            ],
            'invitation' => [
                self::TRIGGER_INVITATION_SENT => 'Party Invitation Sent',
            ],
            'waiver' => [
                self::TRIGGER_WAIVER_REMINDER => 'Waiver Reminder (Incomplete)',
                self::TRIGGER_WAIVER_SIGNED => 'Waiver Signed',
                self::TRIGGER_WAIVER_STAFF_SENT => 'Waiver Link Sent (Staff)',
                self::TRIGGER_WAIVER_BULK_CHAPERONE => 'Bulk Waiver Invite (Chaperone)',
                self::TRIGGER_WAIVER_PARENT_INVITE => 'Waiver Invite (Parent/Guardian)',
            ],
        ];
    }

    public static function getAllTriggerTypes(): array
    {
        $types = [];
        foreach (self::getTriggerTypes() as $triggers) {
            foreach ($triggers as $key => $label) {
                $types[$key] = $label;
            }
        }
        return $types;
    }

    public static function getEntityTypes(): array
    {
        return [
            self::ENTITY_ALL => 'All Segments',
            self::ENTITY_PACKAGE => 'Parties (Packages)',
            self::ENTITY_ATTRACTION => 'Attractions',
            self::ENTITY_EVENT => 'Events',
            self::ENTITY_WAIVER => 'Waivers',
        ];
    }

    public static function getRecipientTypes(): array
    {
        return [
            self::RECIPIENT_CUSTOMER => 'Customer',
            self::RECIPIENT_STAFF => 'Staff (Attendants)',
            self::RECIPIENT_COMPANY_ADMIN => 'Company Admin',
            self::RECIPIENT_LOCATION_MANAGER => 'Location Manager',
            self::RECIPIENT_CUSTOM => 'Custom Phone Numbers',
        ];
    }

    /**
     * GSM-7 messages fit 160 chars/segment (153 in a multi-segment message).
     * If any non-GSM char is present the encoding falls back to UCS-2 (70/67).
     */
    public static function segmentCount(string $message): int
    {
        $length = mb_strlen($message);
        if ($length === 0) {
            return 0;
        }

        $isUnicode = (bool) preg_match('/[^\x00-\x7F]/', $message);
        $single = $isUnicode ? 70 : 160;
        $multi = $isUnicode ? 67 : 153;

        if ($length <= $single) {
            return 1;
        }

        return (int) ceil($length / $multi);
    }
}
