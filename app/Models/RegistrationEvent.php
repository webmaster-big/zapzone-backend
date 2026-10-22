<?php

namespace App\Models;

use App\Support\OperatingDay;
use App\Support\SchemaSupport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RegistrationEvent extends Model
{
    use SoftDeletes;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_PAST = 'past';

    private const CODE_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    protected $fillable = [
        'location_id',
        'event_id',
        'name',
        'event_date',
        'description',
        'confirmation_message',
        'header_image_path',
        'header_image_width',
        'header_image_height',
        'previous_edition_id',
        'is_open',
    ];

    protected $casts = [
        'event_date' => 'date',
        'is_open' => 'boolean',
        'header_image_width' => 'integer',
        'header_image_height' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (RegistrationEvent $event) {
            if (empty($event->public_code)) {
                $event->public_code = self::uniquePublicCode((string) $event->name);
            }
        });
    }

    public static function isAvailable(): bool
    {
        return SchemaSupport::hasTable('registration_events')
            && SchemaSupport::hasTable('registration_contacts')
            && SchemaSupport::hasTable('registration_entries');
    }

    public static function uniquePublicCode(string $name): string
    {
        $slug = trim(substr(Str::slug($name), 0, 24), '-');
        $slug = $slug === '' ? 'event' : $slug;

        do {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            $code = $slug . '-' . $suffix;
        } while (self::withTrashed()->where('public_code', $code)->exists());

        return $code;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(RegistrationEventGroup::class, 'group_id');
    }

    public function previousEdition(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_edition_id');
    }

    public function nextEditions(): HasMany
    {
        return $this->hasMany(self::class, 'previous_edition_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(RegistrationEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publicUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/register/' . $this->public_code;
    }

    public function status(): string
    {
        if ($this->event_date->toDateString() < OperatingDay::calendarDateFor($this->location)) {
            return self::STATUS_PAST;
        }

        return $this->is_open ? self::STATUS_OPEN : self::STATUS_CLOSED;
    }

    public function startsOn(): ?string
    {
        if (!SchemaSupport::hasColumn('registration_events', 'event_id') || $this->event_id === null) {
            return null;
        }

        $linked = $this->event;
        if ($linked === null || (int) $linked->location_id !== (int) $this->location_id || $linked->start_date === null || $linked->end_date === null) {
            return null;
        }

        $start = $linked->start_date->toDateString();
        $date = $this->event_date->toDateString();

        return $start < $date && $date <= $linked->end_date->toDateString() ? $start : null;
    }

    public function firstDay(): string
    {
        return $this->startsOn() ?? $this->event_date->toDateString();
    }

    public function coversDay(string $day): bool
    {
        return $this->firstDay() <= $day && $day <= $this->event_date->toDateString();
    }

    public function dayCount(): int
    {
        return (int) Carbon::parse($this->firstDay())->diffInDays(Carbon::parse($this->event_date->toDateString()), true) + 1;
    }

    public function photoWindow(?Location $location = null): array
    {
        $location ??= $this->location;

        $start = Carbon::parse($this->firstDay(), OperatingDay::timezoneFor($location))
            ->setTime(OperatingDay::CUTOFF_HOUR, 0)
            ->setTimezone(config('app.timezone'));

        return [$start, OperatingDay::cutoffAfter($location, $this->event_date->toDateString())];
    }
}
