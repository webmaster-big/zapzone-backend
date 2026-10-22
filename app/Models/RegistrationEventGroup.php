<?php

namespace App\Models;

use App\Support\SchemaSupport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RegistrationEventGroup extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'event_date',
        'description',
        'confirmation_message',
        'header_image_path',
        'header_image_width',
        'header_image_height',
        'previous_group_id',
    ];

    protected $casts = [
        'event_date' => 'date',
        'header_image_width' => 'integer',
        'header_image_height' => 'integer',
    ];

    public static function isAvailable(): bool
    {
        return RegistrationEvent::isAvailable()
            && SchemaSupport::hasTable('registration_event_groups')
            && SchemaSupport::hasColumn('registration_events', 'group_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function forms(): HasMany
    {
        return $this->hasMany(RegistrationEvent::class, 'group_id');
    }

    public function previousGroup(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_group_id');
    }

    public function nextGroups(): HasMany
    {
        return $this->hasMany(self::class, 'previous_group_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
