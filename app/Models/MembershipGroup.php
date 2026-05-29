<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipGroup extends Model
{
    use HasFactory;

    protected $fillable = ['payer_customer_id', 'name'];

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'payer_customer_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
