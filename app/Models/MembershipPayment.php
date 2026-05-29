<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'membership_id', 'customer_id', 'payment_id',
        'amount', 'status', 'transaction_id', 'description',
        'retry_attempt', 'charged_at', 'failed_at', 'failure_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'charged_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
