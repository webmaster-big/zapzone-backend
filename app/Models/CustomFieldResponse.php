<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CustomFieldResponse extends Model
{
    protected $fillable = [
        'custom_field_id',
        'label',
        'type',
        'value',
        'respondable_type',
        'respondable_id',
    ];

    protected $casts = [
        'value' => 'boolean',
    ];

    public function customField(): BelongsTo
    {
        return $this->belongsTo(CustomField::class);
    }

    public function respondable(): MorphTo
    {
        return $this->morphTo();
    }
}
