<?php

namespace App\Models;

use App\Support\SchemaSupport;
use App\Traits\HasTargeting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailTemplate extends Model
{
    use HasFactory, SoftDeletes, HasTargeting;

    public const CATEGORY_EVENT_PHOTOS = 'event_photos';

    protected $fillable = [
        'company_id',
        'location_id',
        'created_by',
        'name',
        'subject',
        'body',
        'available_variables',
        'status',
        'category',
        'attach_photos',
        'sms_body',
    ];

    protected $casts = [
        'available_variables' => 'array',
        'attach_photos' => 'boolean',
    ];

    public const EVENT_PHOTO_VARIABLES = [
        'first_name' => 'Greeting name ("there" for people who registered)',
        'event_name' => 'Event name',
        'event_date' => 'Event date (a range for multi-day events)',
        'photo_link' => 'Link to their photos',
        'photo_count' => 'How many photos they have',
        'photo_date' => 'Date the photos were taken',
        'expires_on' => 'Date the photo link stops working',
        'business_name' => 'Business name',
        'support_contact' => 'Who to contact with questions',
        'location_phone' => 'Location phone number',
        'links' => 'This location\'s links (set in Photos, Settings, Links)',
    ];

    public const DEFAULT_VARIABLES = [
        'recipient_email' => 'The recipient\'s email address',
        'recipient_name' => 'The recipient\'s full name',
        'recipient_first_name' => 'The recipient\'s first name',
        'recipient_last_name' => 'The recipient\'s last name',
        'company_name' => 'The company name',
        'company_email' => 'The company email',
        'company_phone' => 'The company phone number',
        'company_address' => 'The company address',
        'location_name' => 'The location name',
        'location_email' => 'The location email',
        'location_phone' => 'The location phone number',
        'location_address' => 'The location full address',
        'current_date' => 'Current date (formatted)',
        'current_year' => 'Current year',
    ];

    public const CUSTOMER_VARIABLES = [
        'customer_email' => 'Customer\'s email address',
        'customer_name' => 'Customer\'s full name',
        'customer_first_name' => 'Customer\'s first name',
        'customer_last_name' => 'Customer\'s last name',
        'customer_phone' => 'Customer\'s phone number',
        'customer_address' => 'Customer\'s full address',
        'customer_total_bookings' => 'Customer\'s total number of bookings',
        'customer_total_spent' => 'Customer\'s total amount spent',
        'customer_last_visit' => 'Customer\'s last visit date',
    ];

    public const USER_VARIABLES = [
        'user_email' => 'User\'s email address',
        'user_name' => 'User\'s full name',
        'user_first_name' => 'User\'s first name',
        'user_last_name' => 'User\'s last name',
        'user_role' => 'User\'s role',
        'user_department' => 'User\'s department',
        'user_position' => 'User\'s position',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(EmailCampaign::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeEventPhotos($query)
    {
        return $query->where('category', self::CATEGORY_EVENT_PHOTOS);
    }

    public function isEventPhotos(): bool
    {
        return $this->category === self::CATEGORY_EVENT_PHOTOS;
    }

    public static function supportsTargeting(): bool
    {
        return SchemaSupport::hasColumn('email_templates', 'location_ids');
    }

    public function targetLocationIds(): ?array
    {
        $ids = self::normalizeIds($this->location_ids);

        if ($ids !== null) {
            return $ids;
        }

        return $this->location_id ? [(int) $this->location_id] : null;
    }

    public function isAllLocations(): bool
    {
        return $this->targetLocationIds() === null;
    }

    public function appliesToLocation(?int $locationId): bool
    {
        $ids = $this->targetLocationIds();

        return $ids === null || ($locationId !== null && in_array($locationId, $ids, true));
    }

    public function scopeForLocation($query, $locationId)
    {
        return $this->scopeCoveringLocation($query, (int) $locationId);
    }

    public function scopeEveryLocation($query)
    {
        return $query->whereNull('location_id')
            ->when(self::supportsTargeting(), fn ($targeted) => $targeted->where(fn ($none) => $none->whereNull('location_ids')->orWhereJsonLength('location_ids', 0)));
    }

    public function scopeCoveringLocation($query, int $locationId)
    {
        $targeting = self::supportsTargeting();

        return $query->where(function ($inScope) use ($locationId, $targeting) {
            $inScope->where(fn ($every) => $every->everyLocation())
                ->orWhere(function ($single) use ($locationId, $targeting) {
                    $single->where('location_id', $locationId)
                        ->when($targeting, fn ($legacy) => $legacy->where(fn ($none) => $none->whereNull('location_ids')->orWhereJsonLength('location_ids', 0)));
                })
                ->when($targeting, fn ($listed) => $listed->orWhereJsonContains('location_ids', $locationId)->orWhereJsonContains('location_ids', (string) $locationId));
        });
    }

    public static function eventPhotoFor(?int $companyId, ?int $locationId): ?self
    {
        if ($companyId === null || !self::supportsTargeting()) {
            return null;
        }

        return self::query()
            ->eventPhotos()
            ->active()
            ->where('company_id', $companyId)
            ->when($locationId !== null, fn ($query) => $query->coveringLocation($locationId), fn ($query) => $query->everyLocation())
            ->get()
            ->filter(fn (self $template) => $template->isEventPhotos())
            ->sortByDesc('id')
            ->sortBy(fn (self $template) => $template->targetLocationIds() === null ? 1 : 0)
            ->first();
    }

    public function getAllVariables(): array
    {
        return array_merge(
            self::DEFAULT_VARIABLES,
            self::CUSTOMER_VARIABLES,
            self::USER_VARIABLES
        );
    }

    public function extractUsedVariables(): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $this->body . ' ' . $this->subject, $matches);
        return array_unique($matches[1] ?? []);
    }
}
