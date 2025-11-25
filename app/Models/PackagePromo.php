<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class PackagePromo extends Model
{
    protected $fillable = [
        'package_id',
        'promo_id',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function promo()
    {
        return $this->belongsTo(Promo::class);
    }
}
