<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PageView;
use App\Models\Promo;
use App\Services\PageAnalyticsRecorder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PageAnalyticsController extends Controller
{
    public function __construct(protected PageAnalyticsRecorder $recorder)
    {
    }


    public function track(Request $request): JsonResponse
    {
        $payload = $this->validateEvent($request);
        $row = $this->recorder->recordFromRequest($request, $payload);

        return response()->json([
            'success' => true,
            'data'    => ['id' => $row->id, 'tracking_id' => $row->tracking_id],
        ], 201);
    }

    public function trackBatch(Request $request): JsonResponse
    {
        $request->validate([
            'events'   => 'required|array|min:1|max:50',
            'events.*' => 'array',
        ]);

        $ids = [];
        foreach ($request->input('events', []) as $event) {
            $sub = new Request($event);
            $sub->headers = $request->headers;
            $sub->server  = $request->server;
            try {
                $payload = $this->validateEvent($sub);
                $row = $this->recorder->recordFromRequest($request, $payload);
                $ids[] = $row->id;
            } catch (\Throwable $e) {
                continue; // skip individual bad events
            }
        }

        return response()->json([
            'success' => true,
            'data'    => ['ids' => $ids, 'count' => count($ids)],
        ], 201);
    }

    public function patchDuration(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id'           => 'required|integer|exists:page_views,id',
            'duration_ms'  => 'nullable|integer|min:0|max:86400000',
            'scroll_depth' => 'nullable|integer|min:0|max:100',
        ]);

        $row = PageView::find($validated['id']);
        if ($row) {
            $update = [];
            if (array_key_exists('duration_ms', $validated))  $update['duration_ms']  = $validated['duration_ms'];
            if (array_key_exists('scroll_depth', $validated)) $update['scroll_depth'] = $validated['scroll_depth'];
            if (!empty($update)) $row->update($update);
        }

        return response()->json(['success' => true]);
    }


    public function overview(Request $request): JsonResponse
    {
        $base = $this->scopedQuery($request);

        $pvBase = (clone $base)->where('event_type', 'page_view');

        $views          = (clone $pvBase)->count();
        $uniqueVisitors = (clone $pvBase)->whereNotNull('visitor_id')->distinct('visitor_id')->count('visitor_id');
        $newVisitors    = (clone $pvBase)->where('is_new_visitor', true)->whereNotNull('visitor_id')->distinct('visitor_id')->count('visitor_id');
        $sessions       = (clone $pvBase)->whereNotNull('session_id')->distinct('session_id')->count('session_id');
        $avgDuration    = (float) (clone $pvBase)->whereNotNull('duration_ms')->avg('duration_ms');

        $conversions    = (clone $base)->where('event_type', 'conversion')->count();
        $convValue      = (float) (clone $base)->where('event_type', 'conversion')->sum('conversion_value');

        $bouncedSessions = (int) (clone $pvBase)
            ->whereNotNull('session_id')
            ->select('session_id')
            ->groupBy('session_id')
            ->havingRaw('COUNT(*) = 1')
            ->get()
            ->count();

        $convRate   = $uniqueVisitors > 0 ? round(($conversions / $uniqueVisitors) * 100, 2) : 0;
        $bounceRate = $sessions       > 0 ? round(($bouncedSessions / $sessions) * 100, 2) : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'page_views'         => $views,
                'unique_visitors'    => $uniqueVisitors,
                'new_visitors'       => $newVisitors,
                'returning_visitors' => max($uniqueVisitors - $newVisitors, 0),
                'sessions'           => $sessions,
                'conversions'        => $conversions,
                'conversion_rate'    => $convRate,
                'conversion_value'   => round($convValue, 2),
                'bounce_rate'        => $bounceRate,
                'avg_duration_ms'    => (int) round($avgDuration),
            ],
        ]);
    }

    public function timeseries(Request $request): JsonResponse
    {
        $request->validate([
            'bucket' => ['sometimes', Rule::in(['hour', 'day', 'week', 'month'])],
        ]);
        $bucket = $request->get('bucket', 'day');
        $expr   = $this->bucketExpression(DB::connection()->getDriverName(), $bucket);

        $rows = $this->scopedQuery($request)
            ->selectRaw("$expr as bucket")
            ->selectRaw("SUM(event_type = 'page_view') as page_views")
            ->selectRaw("SUM(event_type = 'conversion') as conversions")
            ->selectRaw("COALESCE(SUM(CASE WHEN event_type='conversion' THEN conversion_value ELSE 0 END),0) as revenue")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return response()->json(['success' => true, 'data' => ['bucket' => $bucket, 'series' => $rows]]);
    }

    public function topPages(Request $request): JsonResponse
    {
        $limit = (int) $request->get('limit', 20);

        $rows = $this->scopedQuery($request)
            ->whereNotNull('page_path')
            ->select('page_path', 'page_type')
            ->selectRaw("SUM(event_type = 'page_view') as views")
            ->selectRaw("COUNT(DISTINCT CASE WHEN event_type='page_view' THEN visitor_id END) as unique_visitors")
            ->selectRaw("AVG(CASE WHEN event_type='page_view' THEN duration_ms END) as avg_duration_ms")
            ->selectRaw("SUM(event_type = 'conversion') as conversions")
            ->selectRaw("COALESCE(SUM(CASE WHEN event_type='conversion' THEN conversion_value ELSE 0 END),0) as revenue")
            ->groupBy('page_path', 'page_type')
            ->orderByDesc('views')
            ->limit($limit)
            ->get()
            ->map(function ($r) {
                $r->conversion_rate = $r->unique_visitors > 0
                    ? round(($r->conversions / $r->unique_visitors) * 100, 2)
                    : 0;
                return $r;
            });

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function topEntities(Request $request): JsonResponse
    {
        $request->validate([
            'entity_type' => ['sometimes', Rule::in(array_keys(PageAnalyticsRecorder::ENTITY_MAP))],
        ]);
        $limit = (int) $request->get('limit', 20);

        $q = $this->scopedQuery($request)
            ->whereNotNull('entity_type')
            ->whereNotNull('entity_id')
            ->select('entity_type', 'entity_id')
            ->selectRaw("SUM(event_type = 'page_view') as views")
            ->selectRaw("SUM(event_type = 'conversion') as conversions")
            ->selectRaw("COUNT(DISTINCT visitor_id) as unique_visitors")
            ->groupBy('entity_type', 'entity_id')
            ->orderByDesc('views')
            ->limit($limit);

        if ($request->filled('entity_type')) {
            $q->where('entity_type', $request->get('entity_type'));
        }

        $rows = $q->get();

        $byType = $rows->groupBy('entity_type');
        foreach ($byType as $type => $group) {
            $cls = PageAnalyticsRecorder::ENTITY_MAP[$type] ?? null;
            if (!$cls) continue;
            $ids   = $group->pluck('entity_id')->all();
            $named = $cls::whereIn('id', $ids)->get()->keyBy('id');
            foreach ($group as $g) {
                $m = $named[$g->entity_id] ?? null;
                $g->name = $m?->name ?? $m?->title ?? $m?->code ?? null;
            }
        }

        return response()->json(['success' => true, 'data' => $rows->values()]);
    }

    public function sources(Request $request): JsonResponse
    {
        $base = $this->scopedQuery($request);

        $utm = (clone $base)
            ->select('utm_source', 'utm_medium', 'utm_campaign')
            ->selectRaw('COUNT(*) as events')
            ->selectRaw("SUM(event_type = 'page_view') as views")
            ->selectRaw("SUM(event_type = 'conversion') as conversions")
            ->selectRaw("COALESCE(SUM(CASE WHEN event_type='conversion' THEN conversion_value ELSE 0 END),0) as revenue")
            ->whereNotNull('utm_source')
            ->groupBy('utm_source', 'utm_medium', 'utm_campaign')
            ->orderByDesc('events')
            ->limit(50)
            ->get();

        $directBase = (clone $base)->whereNull('utm_source');
        $direct = [
            'events'      => (clone $directBase)->count(),
            'views'       => (clone $directBase)->where('event_type', 'page_view')->count(),
            'conversions' => (clone $directBase)->where('event_type', 'conversion')->count(),
            'revenue'     => round((float) (clone $directBase)->where('event_type', 'conversion')->sum('conversion_value'), 2),
        ];

        $referrers = (clone $base)
            ->select('referrer')
            ->selectRaw('COUNT(*) as views')
            ->where('event_type', 'page_view')
            ->whereNotNull('referrer')
            ->groupBy('referrer')
            ->orderByDesc('views')
            ->limit(50)
            ->get();

        return response()->json(['success' => true, 'data' => [
            'utm'       => $utm,
            'direct'    => $direct,
            'referrers' => $referrers,
        ]]);
    }

    public function devices(Request $request): JsonResponse
    {
        $base = $this->scopedQuery($request)->where('event_type', 'page_view');

        $devices = (clone $base)->select('device_type')->selectRaw('COUNT(*) as views')
            ->groupBy('device_type')->orderByDesc('views')->get();

        $browsers = (clone $base)->select('browser')->selectRaw('COUNT(*) as views')
            ->whereNotNull('browser')->groupBy('browser')->orderByDesc('views')->limit(20)->get();

        $oses = (clone $base)->select('os')->selectRaw('COUNT(*) as views')
            ->whereNotNull('os')->groupBy('os')->orderByDesc('views')->limit(20)->get();

        $sources = (clone $base)->select('source')->selectRaw('COUNT(*) as views')
            ->groupBy('source')->orderByDesc('views')->get();

        return response()->json(['success' => true, 'data' => compact('devices', 'browsers', 'oses', 'sources')]);
    }

    public function funnel(Request $request): JsonResponse
    {
        $base = $this->scopedQuery($request);

        $countVisitors = function ($q) {
            return (clone $q)->whereNotNull('visitor_id')->distinct('visitor_id')->count('visitor_id');
        };

        $visited       = $countVisitors((clone $base)->where('event_name', 'page_view'));
        $viewedOffer   = $countVisitors((clone $base)
            ->where('event_type', 'page_view')
            ->whereIn('page_type', ['package_book', 'attraction_buy', 'event_buy']));
        $startedForm   = $countVisitors((clone $base)->where('event_name', 'form_started'));
        $converted     = $countVisitors((clone $base)->where('event_type', 'conversion'));

        $steps = [
            ['key' => 'visited',       'label' => 'Visited site',     'visitors' => $visited],
            ['key' => 'viewed_offer',  'label' => 'Viewed an offer',  'visitors' => $viewedOffer],
            ['key' => 'started_form',  'label' => 'Started form',     'visitors' => $startedForm],
            ['key' => 'converted',     'label' => 'Converted',        'visitors' => $converted],
        ];

        $top = $steps[0]['visitors'] ?: 1;
        foreach ($steps as &$s) $s['rate'] = round(($s['visitors'] / $top) * 100, 2);

        return response()->json(['success' => true, 'data' => $steps]);
    }

    public function conversions(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 25);
        $rows = $this->scopedQuery($request)
            ->where('event_type', 'conversion')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $items = collect($rows->items());
        $byType = $items->whereNotNull('entity_type')->groupBy('entity_type');
        foreach ($byType as $type => $group) {
            $cls = PageAnalyticsRecorder::ENTITY_MAP[$type] ?? null;
            if (!$cls) continue;
            $ids = $group->pluck('entity_id')->filter()->unique()->values()->all();
            if (empty($ids)) continue;
            $named = $cls::whereIn('id', $ids)->get(['id', 'name', 'title', 'code'])->keyBy('id');
            foreach ($group as $item) {
                $m = $named[$item->entity_id] ?? null;
                $item->entity_name = $m?->name ?? $m?->title ?? $m?->code ?? null;
            }
        }

        return response()->json([
            'success'    => true,
            'data'       => $items->values(),
            'pagination' => $this->paginationMeta($rows),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 50);
        $rows = $this->scopedQuery($request)->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success'    => true,
            'data'       => $rows->items(),
            'pagination' => $this->paginationMeta($rows),
        ]);
    }


    public function live(Request $request): JsonResponse
    {
        $minutes = (int) $request->get('minutes', 5);
        $minutes = max(1, min($minutes, 60));

        $q = $this->tenantScope($request, PageView::query());
        $since = Carbon::now()->subMinutes($minutes);
        $q->where('created_at', '>=', $since);

        $activeSessions = (clone $q)->whereNotNull('session_id')->distinct('session_id')->count('session_id');
        $activeVisitors = (clone $q)->whereNotNull('visitor_id')->distinct('visitor_id')->count('visitor_id');

        $byPage = (clone $q)
            ->where('event_type', 'page_view')
            ->select('page_path', 'page_type')
            ->selectRaw('COUNT(DISTINCT session_id) as active_sessions')
            ->whereNotNull('page_path')
            ->groupBy('page_path', 'page_type')
            ->orderByDesc('active_sessions')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'window_minutes'  => $minutes,
                'active_sessions' => $activeSessions,
                'active_visitors' => $activeVisitors,
                'by_page'         => $byPage,
            ],
        ]);
    }

    public function landingPages(Request $request): JsonResponse
    {
        $limit = (int) $request->get('limit', 20);

        $landings = $this->scopedQuery($request)
            ->where('is_landing', true)
            ->where('event_type', 'page_view')
            ->whereNotNull('page_path')
            ->whereNotNull('session_id')
            ->select('page_path', 'page_type', 'session_id')
            ->get();

        if ($landings->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $sessionIds = $landings->pluck('session_id')->unique()->values();
        $pvCounts = PageView::whereIn('session_id', $sessionIds)
            ->where('event_type', 'page_view')
            ->select('session_id', DB::raw('COUNT(*) as c'))
            ->groupBy('session_id')
            ->pluck('c', 'session_id');

        $convBySession = PageView::whereIn('session_id', $sessionIds->all())
            ->where('event_type', 'conversion')
            ->select('session_id')
            ->selectRaw('COUNT(*) as conv_count')
            ->selectRaw('COALESCE(SUM(conversion_value), 0) as conv_revenue')
            ->groupBy('session_id')
            ->get()
            ->keyBy('session_id');

        $rows = $landings
            ->groupBy(fn ($r) => $r->page_path.'|'.$r->page_type)
            ->map(function ($group) use ($pvCounts, $convBySession) {
                $sessions    = $group->count();
                $bounces     = $group->filter(fn ($r) => ($pvCounts[$r->session_id] ?? 0) <= 1)->count();
                $convCount   = (int) $group->sum(fn ($r) => $convBySession[$r->session_id]?->conv_count   ?? 0);
                $convRevenue = (float) $group->sum(fn ($r) => $convBySession[$r->session_id]?->conv_revenue ?? 0.0);
                return (object) [
                    'page_path'   => $group->first()->page_path,
                    'page_type'   => $group->first()->page_type,
                    'sessions'    => $sessions,
                    'bounces'     => $bounces,
                    'bounce_rate' => $sessions > 0 ? round(($bounces / $sessions) * 100, 2) : 0,
                    'conversions' => $convCount,
                    'revenue'     => round($convRevenue, 2),
                ];
            })
            ->sortByDesc('sessions')
            ->take($limit)
            ->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function session(Request $request, string $sessionId): JsonResponse
    {
        $events = $this->tenantScope($request, PageView::query())
            ->where('session_id', $sessionId)
            ->orderBy('created_at')
            ->get();

        if ($events->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Session not found'], 404);
        }

        $first = $events->first();
        $last  = $events->last();

        return response()->json([
            'success' => true,
            'data'    => [
                'session_id'   => $sessionId,
                'visitor_id'   => $first->visitor_id,
                'started_at'   => $first->created_at,
                'ended_at'     => $last->created_at,
                'duration_sec' => $first->created_at->diffInSeconds($last->created_at),
                'event_count'  => $events->count(),
                'page_views'   => $events->where('event_type', 'page_view')->count(),
                'conversions'  => $events->where('event_type', 'conversion')->count(),
                'revenue'      => round($events->where('event_type', 'conversion')->sum('conversion_value'), 2),
                'device_type'  => $first->device_type,
                'browser'      => $first->browser,
                'os'           => $first->os,
                'country'      => $first->country,
                'utm_source'   => $first->utm_source,
                'utm_medium'   => $first->utm_medium,
                'utm_campaign' => $first->utm_campaign,
                'events'       => $events,
            ],
        ]);
    }

    public function searches(Request $request): JsonResponse
    {
        $limit  = (int) $request->get('limit', 20);
        $driver = DB::connection()->getDriverName();

        $isMysql = in_array($driver, ['mysql', 'mariadb'], true);
        $queryExpr   = $isMysql ? "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.query'))"   : "json_extract(metadata, '$.query')";
        $resultsExpr = $isMysql ? "CAST(JSON_EXTRACT(metadata, '$.results') AS UNSIGNED)" : "CAST(json_extract(metadata, '$.results') AS INTEGER)";

        $base = $this->scopedQuery($request)->where('event_name', 'search_performed');

        $top = (clone $base)
            ->selectRaw("$queryExpr as q, COUNT(*) as searches, AVG($resultsExpr) as avg_results")
            ->whereRaw("$queryExpr IS NOT NULL")
            ->groupBy('q')
            ->orderByDesc('searches')
            ->limit($limit)
            ->get();

        $zero = (clone $base)
            ->selectRaw("$queryExpr as q, COUNT(*) as searches")
            ->whereRaw("$queryExpr IS NOT NULL")
            ->whereRaw("$resultsExpr = 0")
            ->groupBy('q')
            ->orderByDesc('searches')
            ->limit($limit)
            ->get();

        return response()->json(['success' => true, 'data' => ['top' => $top, 'zero_result' => $zero]]);
    }

    public function promoPerformance(Request $request): JsonResponse
    {
        $limit = (int) $request->get('limit', 20);

        $rows = $this->scopedQuery($request)
            ->where('entity_type', 'promo')
            ->whereNotNull('entity_id')
            ->select('entity_id')
            ->selectRaw("SUM(event_name = 'promo_validated') as validations")
            ->selectRaw("SUM(event_name = 'promo_applied') as applications")
            ->selectRaw("SUM(event_name = 'promo_failed') as failures")
            ->selectRaw("COALESCE(SUM(CASE WHEN event_name='promo_applied' THEN conversion_value ELSE 0 END),0) as revenue_attributed")
            ->groupBy('entity_id')
            ->orderByDesc('applications')
            ->limit($limit)
            ->get();

        $promos = Promo::whereIn('id', $rows->pluck('entity_id'))->get()->keyBy('id');
        foreach ($rows as $r) {
            $p = $promos[$r->entity_id] ?? null;
            $r->code = $p?->code;
            $r->name = $p?->name;
        }

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function entityDetail(Request $request, string $type, int $id): JsonResponse
    {
        if (!array_key_exists($type, PageAnalyticsRecorder::ENTITY_MAP)) {
            return response()->json([
                'success' => false,
                'message' => 'Unknown entity type',
            ], 422);
        }

        $cls = PageAnalyticsRecorder::ENTITY_MAP[$type];
        $entity = $cls::query()->find($id);
        if (!$entity) {
            return response()->json([
                'success' => false,
                'message' => 'Entity not found',
            ], 404);
        }

        $authUser = $request->user();
        if ($authUser && $authUser->company_id && !empty($entity->company_id)
            && (int) $entity->company_id !== (int) $authUser->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $request->merge(['entity_type' => $type, 'entity_id' => $id]);

        $base   = $this->scopedQuery($request);
        $pvBase = (clone $base)->where('event_type', 'page_view');

        $views          = (clone $pvBase)->count();
        $uniqueVisitors = (clone $pvBase)->whereNotNull('visitor_id')->distinct('visitor_id')->count('visitor_id');
        $newVisitors    = (clone $pvBase)->where('is_new_visitor', true)->whereNotNull('visitor_id')->distinct('visitor_id')->count('visitor_id');
        $sessions       = (clone $pvBase)->whereNotNull('session_id')->distinct('session_id')->count('session_id');
        $avgDuration    = (float) (clone $pvBase)->whereNotNull('duration_ms')->avg('duration_ms');
        $avgScroll      = (float) (clone $pvBase)->whereNotNull('scroll_depth')->avg('scroll_depth');

        $startedForm    = (int) (clone $base)->where('event_name', 'form_started')
            ->whereNotNull('visitor_id')->distinct('visitor_id')->count('visitor_id');
        $conversions    = (clone $base)->where('event_type', 'conversion')->count();
        $convValue      = (float) (clone $base)->where('event_type', 'conversion')->sum('conversion_value');

        $bouncedSessions = (int) (clone $pvBase)
            ->whereNotNull('session_id')
            ->select('session_id')
            ->groupBy('session_id')
            ->havingRaw('COUNT(*) = 1')
            ->get()
            ->count();

        $convRate    = $uniqueVisitors > 0 ? round(($conversions  / $uniqueVisitors) * 100, 2) : 0;
        $startRate   = $uniqueVisitors > 0 ? round(($startedForm  / $uniqueVisitors) * 100, 2) : 0;
        $finishRate  = $startedForm    > 0 ? round(($conversions  / $startedForm)    * 100, 2) : 0;
        $bounceRate  = $sessions       > 0 ? round(($bouncedSessions / $sessions)    * 100, 2) : 0;

        $request->validate(['bucket' => ['sometimes', Rule::in(['hour', 'day', 'week', 'month'])]]);
        $bucket = $request->get('bucket', 'day');
        $expr   = $this->bucketExpression(DB::connection()->getDriverName(), $bucket);

        $series = (clone $base)
            ->selectRaw("$expr as bucket")
            ->selectRaw("SUM(event_type = 'page_view') as page_views")
            ->selectRaw("SUM(event_name = 'form_started') as form_starts")
            ->selectRaw("SUM(event_type = 'conversion') as conversions")
            ->selectRaw("COALESCE(SUM(CASE WHEN event_type='conversion' THEN conversion_value ELSE 0 END),0) as revenue")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $byPath = (clone $pvBase)
            ->select('page_path', 'location_id')
            ->selectRaw('COUNT(*) as views')
            ->selectRaw('COUNT(DISTINCT visitor_id) as unique_visitors')
            ->whereNotNull('page_path')
            ->groupBy('page_path', 'location_id')
            ->orderByDesc('views')
            ->limit(20)
            ->get();

        $sources = (clone $base)
            ->select('utm_source', 'utm_medium', 'utm_campaign')
            ->selectRaw('COUNT(*) as events')
            ->selectRaw("SUM(event_type = 'page_view') as views")
            ->selectRaw("SUM(event_type = 'conversion') as conversions")
            ->selectRaw("COALESCE(SUM(CASE WHEN event_type='conversion' THEN conversion_value ELSE 0 END),0) as revenue")
            ->whereNotNull('utm_source')
            ->groupBy('utm_source', 'utm_medium', 'utm_campaign')
            ->orderByDesc('events')
            ->limit(20)
            ->get();

        $directBase = (clone $base)->whereNull('utm_source');
        $directRow  = [
            'events'      => (clone $directBase)->count(),
            'views'       => (clone $directBase)->where('event_type', 'page_view')->count(),
            'conversions' => (clone $directBase)->where('event_type', 'conversion')->count(),
            'revenue'     => round((float) (clone $directBase)->where('event_type', 'conversion')->sum('conversion_value'), 2),
        ];

        $devices = (clone $pvBase)->select('device_type')->selectRaw('COUNT(*) as views')
            ->groupBy('device_type')->orderByDesc('views')->get();

        $referrers = (clone $pvBase)->select('referrer')->selectRaw('COUNT(*) as views')
            ->whereNotNull('referrer')->groupBy('referrer')->orderByDesc('views')->limit(10)->get();

        $countries = (clone $pvBase)->select('country')->selectRaw('COUNT(*) as views')
            ->whereNotNull('country')->groupBy('country')->orderByDesc('views')->limit(20)->get();

        $recent = (clone $base)
            ->where('event_type', 'conversion')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'event_name', 'conversion_value', 'currency', 'utm_source', 'utm_campaign', 'created_at']);

        return response()->json([
            'success' => true,
            'data'    => [
                'entity' => [
                    'type'        => $type,
                    'id'          => (int) $id,
                    'name'        => $entity->name ?? $entity->title ?? $entity->code ?? null,
                    'company_id'  => $entity->company_id ?? null,
                    'location_id' => $entity->location_id ?? null,
                ],
                'totals' => [
                    'page_views'         => $views,
                    'unique_visitors'    => $uniqueVisitors,
                    'new_visitors'       => $newVisitors,
                    'returning_visitors' => max($uniqueVisitors - $newVisitors, 0),
                    'sessions'           => $sessions,
                    'form_starts'        => $startedForm,
                    'conversions'        => $conversions,
                    'conversion_value'   => round($convValue, 2),
                    'avg_duration_ms'    => (int) round($avgDuration),
                    'avg_scroll_depth'   => (int) round($avgScroll),
                    'bounce_rate'        => $bounceRate,
                    'conversion_rate'    => $convRate,    // converted / unique_visitors
                    'form_start_rate'    => $startRate,   // form_started / unique_visitors
                    'form_finish_rate'   => $finishRate,  // converted / form_started
                ],
                'timeseries' => [
                    'bucket' => $bucket,
                    'series' => $series,
                ],
                'by_path'   => $byPath,
                'sources'   => [
                    'utm'       => $sources,
                    'direct'    => $directRow,
                    'referrers' => $referrers,
                ],
                'devices'   => $devices,
                'countries' => $countries,
                'recent_conversions' => $recent,
            ],
        ]);
    }

    public function entitiesLeaderboard(Request $request): JsonResponse
    {
        $request->validate([
            'entity_type' => ['required', Rule::in(array_keys(PageAnalyticsRecorder::ENTITY_MAP))],
            'sort'        => ['sometimes', Rule::in(['views', 'unique_visitors', 'conversions', 'revenue', 'conversion_rate', 'form_starts'])],
            'limit'       => 'sometimes|integer|min:1|max:200',
        ]);

        $type  = $request->get('entity_type');
        $sort  = $request->get('sort', 'views');
        $limit = (int) $request->get('limit', 50);

        $req = clone $request;
        $req->offsetUnset('entity_type');
        $req->offsetUnset('entity_id');
        $base = $this->scopedQuery($req)
            ->where('entity_type', $type)
            ->whereNotNull('entity_id');

        $rows = (clone $base)
            ->select('entity_id')
            ->selectRaw("SUM(event_type = 'page_view') as views")
            ->selectRaw("COUNT(DISTINCT CASE WHEN event_type='page_view' THEN visitor_id END) as unique_visitors")
            ->selectRaw("COUNT(DISTINCT CASE WHEN event_type='page_view' THEN session_id END) as sessions")
            ->selectRaw("SUM(event_name = 'form_started') as form_starts")
            ->selectRaw("SUM(event_type = 'conversion') as conversions")
            ->selectRaw("COALESCE(SUM(CASE WHEN event_type='conversion' THEN conversion_value ELSE 0 END),0) as revenue")
            ->selectRaw("AVG(CASE WHEN event_type='page_view' THEN duration_ms END) as avg_duration_ms")
            ->groupBy('entity_id')
            ->get()
            ->map(function ($r) {
                $r->conversion_rate = $r->unique_visitors > 0
                    ? round(($r->conversions / $r->unique_visitors) * 100, 2)
                    : 0;
                $r->avg_duration_ms = (int) round((float) $r->avg_duration_ms);
                return $r;
            });

        $rows = $rows->sortByDesc(fn ($r) => (float) $r->{$sort})->take($limit)->values();

        $cls   = PageAnalyticsRecorder::ENTITY_MAP[$type];
        $named = $cls::whereIn('id', $rows->pluck('entity_id'))->get()->keyBy('id');
        foreach ($rows as $r) {
            $m = $named[$r->entity_id] ?? null;
            $r->name        = $m?->name ?? $m?->title ?? $m?->code ?? null;
            $r->location_id = $m?->location_id ?? null;
            $r->company_id  = $m?->company_id ?? null;
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'entity_type' => $type,
                'sort'        => $sort,
                'rows'        => $rows,
            ],
        ]);
    }

    public function attribution(Request $request): JsonResponse
    {
        $base = $this->scopedQuery($request)->where('event_type', 'conversion');

        $firstTouch = (clone $base)
            ->select('first_touch_source as source', 'first_touch_medium as medium', 'first_touch_campaign as campaign')
            ->selectRaw('COUNT(*) as conversions')
            ->selectRaw('COALESCE(SUM(conversion_value),0) as revenue')
            ->whereNotNull('first_touch_source')
            ->groupBy('first_touch_source', 'first_touch_medium', 'first_touch_campaign')
            ->orderByDesc('revenue')
            ->limit(50)
            ->get();

        $lastTouch = (clone $base)
            ->select('utm_source as source', 'utm_medium as medium', 'utm_campaign as campaign')
            ->selectRaw('COUNT(*) as conversions')
            ->selectRaw('COALESCE(SUM(conversion_value),0) as revenue')
            ->whereNotNull('utm_source')
            ->groupBy('utm_source', 'utm_medium', 'utm_campaign')
            ->orderByDesc('revenue')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => ['first_touch' => $firstTouch, 'last_touch' => $lastTouch],
        ]);
    }


    protected function validateEvent(Request $request): array
    {
        return $request->validate([
            'event_type'      => ['sometimes', Rule::in(PageView::EVENT_TYPES)],
            'event_name'      => 'sometimes|string|max:64',
            'page_type'       => ['sometimes', 'nullable', Rule::in(PageView::PAGE_TYPES)],
            'page_url'        => 'sometimes|nullable|string|max:2048',
            'page_path'       => 'sometimes|nullable|string|max:500',
            'page_title'      => 'sometimes|nullable|string|max:500',
            'referrer'        => 'sometimes|nullable|string|max:2048',

            'visitor_id'      => 'sometimes|nullable|string|max:64',
            'session_id'      => 'sometimes|nullable|string|max:64',
            'customer_id'     => 'sometimes|nullable|integer|exists:customers,id',

            'source'          => ['sometimes', 'nullable', Rule::in(['web', 'email', 'server'])],
            'tracking_id'     => 'sometimes|nullable|string|max:64',

            'entity_type'     => ['sometimes', 'nullable', Rule::in(array_keys(PageAnalyticsRecorder::ENTITY_MAP))],
            'entity_id'       => 'sometimes|nullable|integer',
            'location_id'     => 'sometimes|nullable|integer|exists:locations,id',

            'conversion_value' => 'sometimes|nullable|numeric|min:0',
            'currency'        => 'sometimes|nullable|string|max:8',

            'utm_source'      => 'sometimes|nullable|string|max:100',
            'utm_medium'      => 'sometimes|nullable|string|max:100',
            'utm_campaign'    => 'sometimes|nullable|string|max:150',
            'utm_term'        => 'sometimes|nullable|string|max:150',
            'utm_content'     => 'sometimes|nullable|string|max:150',

            'device_type'     => ['sometimes', 'nullable', Rule::in(['desktop', 'mobile', 'tablet', 'bot'])],
            'browser'         => 'sometimes|nullable|string|max:64',
            'os'              => 'sometimes|nullable|string|max:64',
            'language'        => 'sometimes|nullable|string|max:16',

            'duration_ms'     => 'sometimes|nullable|integer|min:0|max:86400000',
            'scroll_depth'    => 'sometimes|nullable|integer|min:0|max:100',

            'metadata'        => 'sometimes|nullable|array',
        ]);
    }

    protected function tenantScope(Request $request, $q)
    {
        $authUser = $request->user();
        if (!$authUser) return $q;

        if ($authUser->company_id) {
            $q->where('company_id', $authUser->company_id);
        }
        if (in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
            $q->where('location_id', $authUser->location_id);
        } elseif ($request->filled('location_id')) {
            $q->where('location_id', (int) $request->get('location_id'));
        }
        return $q;
    }

    protected function scopedQuery(Request $request)
    {
        $q = $this->tenantScope($request, PageView::query());

        $from = $request->filled('from') ? Carbon::parse($request->get('from'))->startOfDay() : Carbon::now()->subDays(30)->startOfDay();
        $to   = $request->filled('to')   ? Carbon::parse($request->get('to'))->endOfDay()    : Carbon::now()->endOfDay();
        $q->whereBetween('created_at', [$from, $to]);

        foreach (['event_type', 'event_name', 'page_type', 'entity_type', 'utm_source', 'utm_campaign', 'device_type', 'source'] as $f) {
            if ($request->filled($f)) $q->where($f, $request->get($f));
        }
        if ($request->filled('entity_id')) $q->where('entity_id', (int) $request->get('entity_id'));

        return $q;
    }

    protected function bucketExpression(string $driver, string $bucket): string
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return match ($bucket) {
                'hour'  => "DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')",
                'week'  => "DATE_FORMAT(DATE_SUB(created_at, INTERVAL WEEKDAY(created_at) DAY), '%Y-%m-%d')",
                'month' => "DATE_FORMAT(created_at, '%Y-%m-01')",
                default => "DATE(created_at)",
            };
        }
        return match ($bucket) {
            'hour'  => "strftime('%Y-%m-%d %H:00:00', created_at)",
            'week'  => "strftime('%Y-%W', created_at)",
            'month' => "strftime('%Y-%m-01', created_at)",
            default => "date(created_at)",
        };
    }

    protected function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
        ];
    }
}
