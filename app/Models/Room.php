<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'name',
        'capacity',
        'is_available',
        'break_time',
        'area_group',
        'booking_interval',
    ];

    protected $casts = [
        'is_available' => 'boolean',
        'break_time' => 'array',
        'booking_interval' => 'integer',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'package_rooms');
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeByCapacity($query, $minCapacity)
    {
        return $query->where('capacity', '>=', $minCapacity);
    }

    public function scopeByAreaGroup($query, $areaGroup)
    {
        return $query->where('area_group', $areaGroup);
    }

    public function getRoomsInSameAreaGroup()
    {
        if (!$this->area_group) {
            return collect([$this]);
        }

        return self::where('area_group', $this->area_group)
            ->where('location_id', $this->location_id)
            ->get();
    }
}
