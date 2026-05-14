<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageView extends Model
{
    use HasFactory;

    public const EVENT_TYPES = ['page_view', 'conversion', 'engagement'];

    // Mirrors the actual customer-facing routes in the React frontend
    // (see web app `App.tsx`). ZapZone has no cart and no list/index
    // pages — customers go straight from the landing into the
    // book/purchase page which doubles as the detail screen.
    public const PAGE_TYPES = [
        'home',                  // /  and /home
        'package_book',          // /book/package/:location/:slug
        'attraction_buy',        // /purchase/attraction/:location/:slug
        'event_buy',             // /purchase/event/:location/:slug  and  /events/:eventId/purchase
        'rsvp',                  // /rsvp/:token
        'customer_login',        // /customer/login
        'customer_register',     // /customer/register
        'my_reservations',       // /customer/reservations
        'my_attractions',        // /customer/attractions
        'my_events',             // /customer/events
        'my_gift_cards',         // /customer/gift-cards
        'my_notifications',      // /customer/notifications
        'other',
    ];

    public const ENTITY_TYPES = [
        'package',
        'attraction',
        'event',
        'booking',
        'attraction_purchase',
        'event_purchase',
        'gift_card',
        'promo',
    ];

    protected $fillable = [
        'company_id',
        'location_id',
        'visitor_id',
        'session_id',
        'customer_id',
        'source',
        'tracking_id',
        'event_type',
        'event_name',
        'page_type',
        'page_url',
        'page_path',
        'page_title',
        'referrer',
        'entity_type',
        'entity_id',
        'conversion_value',
        'currency',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'first_touch_source',
        'first_touch_medium',
        'first_touch_campaign',
        'user_agent',
        'ip_address',
        'device_type',
        'browser',
        'os',
        'country',
        'city',
        'language',
        'duration_ms',
        'scroll_depth',
        'is_new_visitor',
        'is_landing',
        'metadata',
    ];

    protected $casts = [
        'metadata'         => 'array',
        'conversion_value' => 'decimal:2',
        'entity_id'        => 'integer',
        'duration_ms'      => 'integer',
        'scroll_depth'     => 'integer',
        'is_new_visitor'   => 'boolean',
        'is_landing'       => 'boolean',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // ---- Scopes -----------------------------------------------------------

    public function scopeBetween($query, $from, $to)
    {
        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }
        return $query;
    }

    public function scopePageViews($query)
    {
        return $query->where('event_type', 'page_view');
    }

    public function scopeConversions($query)
    {
        return $query->where('event_type', 'conversion');
    }

    public function scopeForEntity($query, string $type, int $id)
    {
        return $query->where('entity_type', $type)->where('entity_id', $id);
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByLocation($query, $locationId)
    {
        return $query->where('location_id', $locationId);
    }
}
