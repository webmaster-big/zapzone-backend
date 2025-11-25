<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class PackageRoom extends Model
{
    protected $fillable = [
        'package_id',
        'room_id',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
