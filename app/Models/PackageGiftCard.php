<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class PackageGiftCard extends Model
{
    protected $fillable = [
        'package_id',
        'gift_card_id',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function giftCard()
    {
        return $this->belongsTo(GiftCard::class);
    }
}
