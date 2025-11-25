<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class PackageAttraction extends Model
{
    protected $fillable = [
        'package_id',
        'attraction_id',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function attraction()
    {
        return $this->belongsTo(Attraction::class);
    }
}
