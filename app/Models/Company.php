<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_name',
        'logo_path',
        'email',
        'website',
        'phone',
        'tax_id',
        'registration_number',
        'address',
        'city',
        'state',
        'country',
        'zip_code',
        'industry',
        'company_size',
        'founded_date',
        'description',
        'total_locations',
        'total_employees',
        'status',
    ];

    protected $casts = [
        'founded_date' => 'date',
    ];

    // Relationships
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // Scopes
    public function scopeByName($query, $name)
    {
        return $query->where('company_name', 'like', "%{$name}%");
    }
}
