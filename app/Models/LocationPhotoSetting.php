<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationPhotoSetting extends Model
{
    use HasFactory;

    public const DATE_POSITIONS = ['top_left', 'top_right', 'bottom_left', 'bottom_right'];

    public const DATE_BACKGROUNDS = ['none', 'solid', 'shadow'];

    public const DATE_FORMATS = ['M j, Y', 'F j, Y', 'm/d/Y', 'd/m/Y', 'Y-m-d', 'D, M j, Y'];

    public const SLIDESHOW_DURATIONS = [5, 6, 8, 10, 12, 15, 20];

    public const COUNTDOWN_OPTIONS = [0, 3, 5, 10];

    protected $fillable = [
        'company_id',
        'location_id',
        'kiosk_enabled',
        'slideshow_enabled',
        'kiosk_countdown_seconds',
        'kiosk_passcode',
        'slideshow_passcode',
        'slideshow_duration_seconds',
        'retention_days',
        'date_format',
        'date_position',
        'date_font_size',
        'date_margin',
        'date_background',
        'failure_notify_email',
        'slideshow_seen_at',
    ];

    protected $casts = [
        'kiosk_enabled' => 'boolean',
        'slideshow_enabled' => 'boolean',
        'kiosk_passcode' => 'encrypted',
        'slideshow_passcode' => 'encrypted',
        'kiosk_countdown_seconds' => 'integer',
        'slideshow_duration_seconds' => 'integer',
        'retention_days' => 'integer',
        'date_font_size' => 'integer',
        'date_margin' => 'integer',
        'slideshow_seen_at' => 'datetime',
    ];

    protected $hidden = [
        'kiosk_passcode',
        'slideshow_passcode',
    ];

    protected $attributes = [
        'kiosk_enabled' => true,
        'slideshow_enabled' => true,
        'kiosk_countdown_seconds' => 10,
        'slideshow_duration_seconds' => 8,
        'retention_days' => 90,
        'date_format' => 'M j, Y',
        'date_position' => 'bottom_right',
        'date_font_size' => 34,
        'date_margin' => 28,
        'date_background' => 'solid',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public static function forLocation(Location $location): self
    {
        $setting = self::where('location_id', $location->id)->first();

        if ($setting) {
            return $setting;
        }

        return self::create([
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'kiosk_passcode' => self::generatePasscode(),
            'slideshow_passcode' => self::generatePasscode(),
        ]);
    }

    public static function generatePasscode(): string
    {
        return (string) random_int(100000, 999999);
    }

    public function matchesKioskPasscode(?string $candidate): bool
    {
        return $this->kiosk_passcode !== null
            && $candidate !== null
            && hash_equals((string) $this->kiosk_passcode, trim($candidate));
    }

    public function matchesSlideshowPasscode(?string $candidate): bool
    {
        return $this->slideshow_passcode !== null
            && $candidate !== null
            && hash_equals((string) $this->slideshow_passcode, trim($candidate));
    }

    public function kioskUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/photos/kiosk/' . $this->location_id;
    }

    public function slideshowUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/photos/slideshow/' . $this->location_id;
    }

    public function toAdminArray(): array
    {
        return array_merge($this->toArray(), [
            'kiosk_passcode' => (string) $this->kiosk_passcode,
            'slideshow_passcode' => (string) $this->slideshow_passcode,
            'kiosk_url' => $this->kioskUrl(),
            'slideshow_url' => $this->slideshowUrl(),
        ]);
    }
}
