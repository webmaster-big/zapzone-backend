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

    /**
     * Scope to get only active notes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get notes for a specific package
     */
    public function scopeForPackage($query, $packageId)
    {
        return $query->where(function ($q) use ($packageId) {
            // Notes with null package_ids apply to all packages
            $q->whereNull('package_ids')
              // Or notes where package_ids array contains this package ID
              ->orWhereJsonContains('package_ids', $packageId);
        });
    }

    /**
     * Get notes ordered by display order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order', 'asc')->orderBy('id', 'asc');
    }
}
