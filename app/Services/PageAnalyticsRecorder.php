<?php

namespace App\Services;

use App\Models\Attraction;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\Event;
use App\Models\EventPurchase;
use App\Models\GiftCard;
use App\Models\Location;
use App\Models\Package;
use App\Models\PageView;
use App\Models\Promo;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PageAnalyticsRecorder
{
    public const ENTITY_MAP = [
        'package'             => Package::class,
        'attraction'          => Attraction::class,
        'event'               => Event::class,
        'booking'             => Booking::class,
        'attraction_purchase' => AttractionPurchase::class,
        'event_purchase'      => EventPurchase::class,
        'gift_card'           => GiftCard::class,
        'promo'               => Promo::class,
    ];

    public function recordFromRequest(Request $request, array $payload): PageView
    {
        $payload['event_type'] = $payload['event_type'] ?? 'page_view';
        $payload['event_name'] = $payload['event_name'] ?? $payload['event_type'];
        $payload['source']     = $payload['source'] ?? $this->detectSource($request);

        return $this->persist($request, $payload);
    }

    public function recordConversion(
        string $eventName,
        ?Model $entity = null,
        ?float $value = null,
        ?Request $request = null,
        array $extra = []
    ): ?PageView {
        $request = $request ?: request();

        $payload = array_merge([
            'event_type'       => 'conversion',
            'event_name'       => $eventName,
            'conversion_value' => $value,
            'source'           => $this->detectSource($request, /*serverDefault*/ true),
            'tracking_id'      => $extra['tracking_id'] ?? $this->buildServerTrackingId($eventName, $entity),
        ], $extra);

        if ($entity) {
            $payload['entity_type'] = $payload['entity_type'] ?? $this->classToEntityType($entity);
            $payload['entity_id']   = $payload['entity_id']   ?? $entity->getKey();

            [$offerType, $offerId] = $this->resolveOfferEntity($entity);
            if ($offerType && $offerId) {
                $payload['entity_type'] = $offerType;
                $payload['entity_id']   = $offerId;
            }
        }

        if (!empty($payload['tracking_id'])) {
            $existing = PageView::where('tracking_id', $payload['tracking_id'])->first();
            if ($existing) {
                return $existing;
            }
        }

        try {
            return $this->persist($request, $payload);
        } catch (\Throwable $e) {
            Log::warning('PageAnalyticsRecorder: server-side conversion failed', [
                'event_name' => $eventName,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }


    protected function persist(Request $request, array $payload): PageView
    {
        $visitorId = $payload['visitor_id'] ?? $request->header('X-Visitor-Id');
        $sessionId = $payload['session_id'] ?? $request->header('X-Session-Id');
        $payload['visitor_id'] = $visitorId ?: null;
        $payload['session_id'] = $sessionId ?: null;

        if (($payload['event_type'] ?? null) === 'conversion'
            && empty($payload['page_path'])
            && !empty($sessionId)) {
            $lastView = PageView::where('session_id', $sessionId)
                ->where('event_type', 'page_view')
                ->whereNotNull('page_path')
                ->latest()
                ->first(['page_path', 'page_type']);
            if ($lastView) {
                $payload['page_path'] = $lastView->page_path;
                if (empty($payload['page_type'])) {
                    $payload['page_type'] = $lastView->page_type;
                }
            }
        }

        $ua = (string) $request->header('User-Agent', '');
        $payload['user_agent']  = $ua;
        $payload['ip_address']  = $request->ip();
        $payload['device_type'] = $payload['device_type'] ?? $this->detectDeviceType($ua);
        $payload['browser']     = $payload['browser']     ?? $this->detectBrowser($ua);
        $payload['os']          = $payload['os']          ?? $this->detectOs($ua);

        $payload['country'] = $payload['country'] ?? $request->header('CF-IPCountry');
        $payload['city']    = $payload['city']    ?? $request->header('CF-IPCity');
        if ($payload['country'] === 'XX') {
            $payload['country'] = null; // CF unknown
        }

        [$companyId, $locationId] = $this->resolveTenancy(
            $payload['entity_type'] ?? null,
            $payload['entity_id'] ?? null,
            $payload['location_id'] ?? null,
        );
        $payload['company_id']  = $payload['company_id']  ?? $companyId;
        $payload['location_id'] = $payload['location_id'] ?? $locationId;

        if (($payload['event_type'] ?? null) === 'page_view') {
            if ($payload['visitor_id']) {
                $payload['is_new_visitor'] = !PageView::where('visitor_id', $payload['visitor_id'])
                    ->where('event_type', 'page_view')
                    ->exists();
            }
            if ($payload['session_id']) {
                $payload['is_landing'] = !PageView::where('session_id', $payload['session_id'])
                    ->where('event_type', 'page_view')
                    ->exists();
            }
        }

        if ($payload['visitor_id']) {
            $first = PageView::where('visitor_id', $payload['visitor_id'])
                ->whereNotNull('utm_source')
                ->orderBy('created_at')
                ->first(['utm_source', 'utm_medium', 'utm_campaign']);

            if ($first) {
                $payload['first_touch_source']   = $payload['first_touch_source']   ?? $first->utm_source;
                $payload['first_touch_medium']   = $payload['first_touch_medium']   ?? $first->utm_medium;
                $payload['first_touch_campaign'] = $payload['first_touch_campaign'] ?? $first->utm_campaign;
            } elseif (!empty($payload['utm_source'])) {
                $payload['first_touch_source']   = $payload['utm_source'];
                $payload['first_touch_medium']   = $payload['utm_medium']   ?? null;
                $payload['first_touch_campaign'] = $payload['utm_campaign'] ?? null;
            }
        }

        return PageView::create($payload);
    }

    protected function detectSource(Request $request, bool $serverDefault = false): string
    {
        $hdr = $request->header('X-Analytics-Source');
        if ($hdr && in_array($hdr, ['web', 'email', 'server'], true)) {
            return $hdr;
        }
        return $serverDefault ? 'server' : 'web';
    }

    protected function buildServerTrackingId(string $eventName, ?Model $entity): string
    {
        if ($entity) {
            $type = $this->classToEntityType($entity) ?: class_basename($entity);
            return "srv:{$type}:{$entity->getKey()}:{$eventName}";
        }
        return 'srv:'.$eventName.':'.(string) Str::uuid();
    }

    protected function classToEntityType(Model $model): ?string
    {
        foreach (self::ENTITY_MAP as $key => $cls) {
            if ($model instanceof $cls) {
                return $key;
            }
        }
        return null;
    }

    protected function resolveOfferEntity(Model $entity): array
    {
        if ($entity instanceof Booking && !empty($entity->package_id)) {
            return ['package', (int) $entity->package_id];
        }
        if ($entity instanceof AttractionPurchase && !empty($entity->attraction_id)) {
            return ['attraction', (int) $entity->attraction_id];
        }
        if ($entity instanceof EventPurchase && !empty($entity->event_id)) {
            return ['event', (int) $entity->event_id];
        }
        return [null, null];
    }

    protected function resolveTenancy(?string $entityType, $entityId, $explicitLocationId): array
    {
        $companyId  = null;
        $locationId = $explicitLocationId ? (int) $explicitLocationId : null;

        if ($entityType && $entityId && isset(self::ENTITY_MAP[$entityType])) {
            $cls = self::ENTITY_MAP[$entityType];
            $row = $cls::query()->find($entityId);
            if ($row) {
                if (!$locationId && !empty($row->location_id)) {
                    $locationId = (int) $row->location_id;
                }
                if (!empty($row->company_id)) {
                    $companyId = (int) $row->company_id;
                }
            }
        }

        if ($locationId && !$companyId) {
            $loc = Location::query()->find($locationId, ['id', 'company_id']);
            if ($loc) {
                $companyId = (int) $loc->company_id;
            }
        }

        return [$companyId, $locationId];
    }


    public function detectDeviceType(string $ua): string
    {
        $ua = strtolower($ua);
        if (preg_match('/bot|crawl|spider|slurp/', $ua)) return 'bot';
        if (preg_match('/ipad|tablet/', $ua))             return 'tablet';
        if (preg_match('/mobi|android|iphone|ipod/', $ua)) return 'mobile';
        return 'desktop';
    }

    public function detectBrowser(string $ua): ?string
    {
        $ua = strtolower($ua);
        return match (true) {
            str_contains($ua, 'edg/')                            => 'Edge',
            str_contains($ua, 'opr/'), str_contains($ua, 'opera') => 'Opera',
            str_contains($ua, 'chrome')                          => 'Chrome',
            str_contains($ua, 'firefox')                         => 'Firefox',
            str_contains($ua, 'safari')                          => 'Safari',
            str_contains($ua, 'msie'), str_contains($ua, 'trident') => 'IE',
            default => null,
        };
    }

    public function detectOs(string $ua): ?string
    {
        $ua = strtolower($ua);
        return match (true) {
            str_contains($ua, 'windows')                              => 'Windows',
            str_contains($ua, 'mac os'), str_contains($ua, 'macintosh') => 'macOS',
            str_contains($ua, 'android')                              => 'Android',
            str_contains($ua, 'iphone'), str_contains($ua, 'ipad'),
            str_contains($ua, 'ios')                                  => 'iOS',
            str_contains($ua, 'linux')                                => 'Linux',
            default => null,
        };
    }
}
