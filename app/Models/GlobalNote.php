<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlobalNote extends Model
{
    protected $fillable = [
        'title',
        'content',
        'package_ids',
        'is_active',
        'display_order',
    ];

    protected $casts = [
        'package_ids' => 'array',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForPackage($query, $packageId)
    {
        return $query->where(function ($q) use ($packageId) {
            $q->whereNull('package_ids')
              ->orWhereJsonContains('package_ids', $packageId);
        });
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order', 'asc')->orderBy('id', 'asc');
    }
}
