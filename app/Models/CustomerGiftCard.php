<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerGiftCard extends Model
{
    protected $fillable = [
        'customer_id',
        'gift_card_id',
        'redeemed',
        'redeemed_at',
        'amount',
    ];

    protected $casts = [
        'redeemed' => 'boolean',
        'redeemed_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function giftCard()
    {
        return $this->belongsTo(GiftCard::class);
    }
}
