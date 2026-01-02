<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'location_id',
        'created_by',
        'name',
        'subject',
        'body',
        'available_variables',
        'status',
        'category',
    ];

    protected $casts = [
        'available_variables' => 'array',
    ];

    /**
     * Default available variables for email templates
     */
    public const DEFAULT_VARIABLES = [
        'recipient_email' => 'The recipient\'s email address',
        'recipient_name' => 'The recipient\'s full name',
        'recipient_first_name' => 'The recipient\'s first name',
        'recipient_last_name' => 'The recipient\'s last name',
        'company_name' => 'The company name',
        'company_email' => 'The company email',
        'company_phone' => 'The company phone number',
        'company_address' => 'The company address',
        'location_name' => 'The location name',
        'location_email' => 'The location email',
        'location_phone' => 'The location phone number',
        'location_address' => 'The location full address',
        'current_date' => 'Current date (formatted)',
        'current_year' => 'Current year',
    ];

    /**
     * Customer-specific variables
     */
    public const CUSTOMER_VARIABLES = [
        'customer_email' => 'Customer\'s email address',
        'customer_name' => 'Customer\'s full name',
        'customer_first_name' => 'Customer\'s first name',
        'customer_last_name' => 'Customer\'s last name',
        'customer_phone' => 'Customer\'s phone number',
        'customer_address' => 'Customer\'s full address',
        'customer_total_bookings' => 'Customer\'s total number of bookings',
        'customer_total_spent' => 'Customer\'s total amount spent',
        'customer_last_visit' => 'Customer\'s last visit date',
    ];

    /**
     * User (Attendant/Admin) specific variables
     */
    public const USER_VARIABLES = [
        'user_email' => 'User\'s email address',
        'user_name' => 'User\'s full name',
        'user_first_name' => 'User\'s first name',
        'user_last_name' => 'User\'s last name',
        'user_role' => 'User\'s role',
        'user_department' => 'User\'s department',
        'user_position' => 'User\'s position',
    ];

    // Relationships
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(EmailCampaign::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Get all available variables for this template based on recipient types
     */
    public function getAllVariables(): array
    {
        return array_merge(
            self::DEFAULT_VARIABLES,
            self::CUSTOMER_VARIABLES,
            self::USER_VARIABLES
        );
    }

    /**
     * Extract variables used in the body
     */
    public function extractUsedVariables(): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z_]+)\s*\}\}/', $this->body . ' ' . $this->subject, $matches);
        return array_unique($matches[1] ?? []);
    }
}
