<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttractionPurchaseAddOn extends Model
{
    protected $table = 'attraction_purchase_add_ons';

    protected $fillable = [
        'attraction_purchase_id',
        'add_on_id',
        'quantity',
        'price_at_purchase',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price_at_purchase' => 'decimal:2',
    ];

    public function attractionPurchase()
    {
        return $this->belongsTo(AttractionPurchase::class);
    }

    public function addOn()
    {
        return $this->belongsTo(AddOn::class, 'add_on_id');
    }
}
