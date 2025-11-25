<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class PackageAddOn extends Model
{
    protected $fillable = [
        'package_id',
        'add_on_id',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function addOn()
    {
        return $this->belongsTo(AddOn::class, 'add_on_id');
    }
}
