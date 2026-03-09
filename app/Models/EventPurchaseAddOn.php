<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventPurchaseAddOn extends Model
{
    use HasFactory;

    protected $table = 'event_purchase_add_ons';

    protected $fillable = [
        'event_purchase_id',
        'add_on_id',
        'quantity',
        'price_at_purchase',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price_at_purchase' => 'decimal:2',
    ];

    public function eventPurchase(): BelongsTo
    {
        return $this->belongsTo(EventPurchase::class);
    }

    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class);
    }
}
