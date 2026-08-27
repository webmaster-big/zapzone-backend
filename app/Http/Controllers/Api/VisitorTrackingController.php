<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PageView;
use App\Models\VisitorIdentity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VisitorTrackingController extends Controller
{
    private const EXPORT_MAX_SESSIONS = 500;
    private const EXPORT_MAX_ACTIONS_PER_SESSION = 150;

    public function identify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120', 'regex:/^[^\r\n]+$/'],
            'phone' => ['required', 'string', 'min:7', 'max:40', 'regex:/^[^\r\n]+$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'visitor_id' => ['nullable', 'string', 'max:64'],
        ], [
            'name.required' => 'Please tell us your name.',
            'phone.required' => 'Please give us a phone number.',
        ]);

        $visitorId = trim((string) ($validated['visitor_id'] ?? $request->header('X-Visitor-Id', '')));

        if ($visitorId === '') {
            Log::info('Visitor identify skipped - no visitor id on the request', [
                'name' => $validated['name'],
                'ip' => $request->ip(),
            ]);

            return response()->json(['success' => true, 'data' => ['recorded' => false]]);
        }

        if (strlen(preg_replace('/\D+/', '', $validated['phone'])) < 7) {
            return response()->json([
                'success' => false,
                'message' => 'That phone number does not look complete.',
                'errors' => ['phone' => ['That phone number does not look complete.']],
            ], 422);
        }

        try {
            $values = [
                'name' => trim($validated['name']),
                'phone' => trim($validated['phone']),
                'last_seen_at' => now(),
            ];
            if (!empty($validated['email'])) {
                $values['email'] = strtolower(trim($validated['email']));
            }
            if (!empty($validated['location_id'])) {
                $values['location_id'] = $validated['location_id'];
            }

            $identity = VisitorIdentity::updateOrCreate(['visitor_id' => $visitorId], $values);

            Log::info('Visitor identified', [
                'visitor_identity_id' => $identity->id,
                'visitor_id' => $visitorId,
                'name' => $identity->name,
                'phone' => $identity->phone,
                'location_id' => $identity->location_id,
                'was_new' => $identity->wasRecentlyCreated,
            ]);
        } catch (\Throwable $e) {
            Log::error('Could not save a visitor identity', [
                'visitor_id' => $visitorId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json(['success' => true, 'data' => ['recorded' => false]]);
        }

        return response()->json(['success' => true, 'data' => ['recorded' => true]], 201);
    }

    public function sessions(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'identified' => ['nullable', 'in:known,anonymous'],
            'device_type' => ['nullable', 'in:desktop,mobile,tablet'],
            'activity' => ['nullable', 'in:purchased,clicked,multi_page,reached_checkout'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $user = $request->user();
            $cacheKey = 'visitor-sessions:' . md5(implode('|', [
                $user?->company_id,
                in_array($user?->role, ['location_manager', 'attendant'], true) ? $user?->location_id : '',
                $request->get('location_id', ''),
                $request->get('date_from', ''),
                $request->get('date_to', ''),
                $request->get('identified', ''),
                $request->get('device_type', ''),
                $request->get('activity', ''),
                $request->get('search', ''),
                $request->get('page', 1),
                min((int) $request->get('per_page', 20), 100),
            ]));

            [$sessions, $pageMeta] = Cache::remember($cacheKey, 45, function () use ($request) {
                $query = $this->groupedSessions($request);

                $perPage = min((int) $request->get('per_page', 20), 100);
                $paginated = $query->paginate($perPage);

                $rows = collect($paginated->items());
                $edges = $this->sessionEdges($rows);

                $sessions = $rows->map(function ($row) use ($edges) {
                    $key = $row->visitor_id . '|' . $row->session_date;
                    $edge = $edges[$key] ?? null;

                    return $this->presentSession($row, $edge);
                })->values();

                return [$sessions, [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ]];
            });
        } catch (\Throwable $e) {
            Log::error('Visitor sessions listing failed', [
                'user_id' => $request->user()?->id,
                'filters' => $request->only(['location_id', 'date_from', 'date_to', 'identified', 'device_type', 'activity', 'search', 'page']),
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json(['success' => false, 'message' => 'Could not load visitor sessions.'], 500);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'sessions' => $sessions,
                'pagination' => $pageMeta,
            ],
        ]);
    }

    public function statistics(Request $request): JsonResponse
    {
        $today = now()->toDateString();
        $weekAgo = now()->subDays(6)->toDateString();

        $user = $request->user();

        try {
            $identityScope = VisitorIdentity::query();
        if ($user && in_array($user->role, ['location_manager', 'attendant'], true) && $user->location_id) {
            $identityScope->where(function ($q) use ($user) {
                $q->whereNull('location_id')->orWhere('location_id', $user->location_id);
            });
        } elseif ($user && $user->company_id) {
            $companyLocationIds = \App\Models\Location::where('company_id', $user->company_id)->pluck('id');
            $identityScope->where(function ($q) use ($companyLocationIds) {
                $q->whereNull('location_id')->orWhereIn('location_id', $companyLocationIds);
            });
        }

            $statsKey = 'visitor-stats:' . md5(implode('|', [
                $user?->company_id,
                in_array($user?->role, ['location_manager', 'attendant'], true) ? $user?->location_id : '',
                $request->get('location_id', ''),
                $today,
            ]));

            $data = Cache::remember($statsKey, 60, fn () => [
                'sessions_today' => $this->groupedSessions($request, ['date_from' => $today, 'date_to' => $today])->getCountForPagination(),
                'sessions_week' => $this->groupedSessions($request, ['date_from' => $weekAgo, 'date_to' => $today])->getCountForPagination(),
                'identified_today' => $this->groupedSessions($request, ['date_from' => $today, 'date_to' => $today, 'identified' => 'known'])->getCountForPagination(),
                'identified_total' => $identityScope->count(),
            ]);

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            Log::error('Visitor session statistics failed', [
                'user_id' => $user?->id,
                'location_id' => $request->get('location_id'),
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json(['success' => false, 'message' => 'Could not load statistics.'], 500);
        }
    }

    public function detail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visitor_id' => ['required', 'string', 'max:64'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $events = PageView::where('visitor_id', $validated['visitor_id'])
            ->whereRaw('DATE(created_at) = ?', [$validated['date']])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(2000)
            ->get([
                'id', 'event_type', 'event_name', 'page_type', 'page_path', 'page_title',
                'entity_type', 'entity_id', 'location_id', 'company_id', 'duration_ms',
                'scroll_depth', 'conversion_value', 'device_type', 'browser', 'os',
                'referrer', 'metadata', 'created_at',
            ]);

        if ($events->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No activity found for this session.'], 404);
        }

        $user = $request->user();

        if ($user && in_array($user->role, ['location_manager', 'attendant'], true) && $user->location_id) {
            $touchesLocation = $events->contains(fn ($e) => (int) $e->location_id === (int) $user->location_id);
            if (!$touchesLocation) {
                return response()->json(['success' => false, 'message' => 'This session did not touch your location.'], 403);
            }
        }

        if ($user && $user->company_id) {
            $companyIds = $events->pluck('company_id')->filter();
            if ($companyIds->isNotEmpty() && !$companyIds->contains((int) $user->company_id)) {
                return response()->json(['success' => false, 'message' => 'This session belongs to another company.'], 403);
            }

            $events = $events->filter(
                fn ($e) => $e->company_id === null || (int) $e->company_id === (int) $user->company_id
            )->values();
        }

        $identity = VisitorIdentity::where('visitor_id', $validated['visitor_id'])->first();

        Log::info('Visitor session timeline viewed', [
            'user_id' => $user?->id,
            'visitor_id' => $validated['visitor_id'],
            'session_date' => $validated['date'],
            'events' => $events->count(),
            'identified' => (bool) $identity,
        ]);

        $timeline = $events->map(function ($event) {
            return [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'event_name' => $event->event_name,
                'label' => data_get($event->metadata, 'label'),
                'page_type' => $event->page_type,
                'page_path' => $event->page_path,
                'page_title' => $event->page_title,
                'entity_type' => $event->entity_type,
                'entity_id' => $event->entity_id,
                'duration_ms' => $event->duration_ms,
                'scroll_depth' => $event->scroll_depth,
                'conversion_value' => $event->conversion_value,
                'time_label' => $event->created_at->format('g:i:s A'),
                'created_at' => $event->created_at->toDateTimeString(),
            ];
        })->values();

        $first = $events->first();
        $pageViews = $events->where('event_type', 'page_view');

        return response()->json([
            'success' => true,
            'data' => [
                'visitor_id' => $validated['visitor_id'],
                'session_date' => $validated['date'],
                'date_label' => Carbon::parse($validated['date'])->format('D, M j, Y'),
                'guest' => $identity ? [
                    'name' => $identity->name,
                    'phone' => $identity->phone,
                    'email' => $identity->email,
                ] : null,
                'device' => [
                    'device_type' => $first->device_type,
                    'browser' => $first->browser,
                    'os' => $first->os,
                ],
                'referrer' => $first->referrer,
                'summary' => [
                    'page_views' => $pageViews->count(),
                    'clicks' => $events->where('event_type', 'engagement')->count(),
                    'conversions' => $events->where('event_type', 'conversion')->count(),
                    'duration_ms' => (int) $pageViews->sum('duration_ms'),
                    'first_seen_label' => $events->first()->created_at->format('g:i A'),
                    'last_seen_label' => $events->last()->created_at->format('g:i A'),
                ],
                'timeline' => $timeline,
            ],
        ]);
    }

    public function export(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'identified' => ['nullable', 'in:known,anonymous'],
            'device_type' => ['nullable', 'in:desktop,mobile,tablet'],
            'activity' => ['nullable', 'in:purchased,clicked,multi_page,reached_checkout'],
        ]);

        try {
            $rows = $this->groupedSessions($request)
                ->limit(self::EXPORT_MAX_SESSIONS + 1)
                ->get();
        } catch (\Throwable $e) {
            Log::error('Visitor sessions export failed', [
                'user_id' => $request->user()?->id,
                'filters' => $request->only(['location_id', 'date_from', 'date_to', 'identified', 'device_type', 'activity', 'search']),
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json(['success' => false, 'message' => 'Export failed.'], 500);
        }

        $truncated = $rows->count() > self::EXPORT_MAX_SESSIONS;
        $rows = $rows->take(self::EXPORT_MAX_SESSIONS);

        $edges = $this->sessionEdges($rows);
        $actions = $this->sessionActions($rows);

        $sessions = $rows->map(function ($row) use ($edges, $actions) {
            $key = $row->visitor_id . '|' . $row->session_date;
            $session = $this->presentSession($row, $edges[$key] ?? null);
            $session['actions'] = implode('; ', $actions[$key] ?? []);

            return $session;
        })->values();

        Log::info('Visitor sessions exported', [
            'user_id' => $request->user()?->id,
            'rows' => $sessions->count(),
            'truncated' => $truncated,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'sessions' => $sessions,
                'truncated' => $truncated,
                'max_sessions' => self::EXPORT_MAX_SESSIONS,
            ],
        ]);
    }

    private function groupedSessions(Request $request, array $overrides = [])
    {
        $query = DB::table('page_views as pv')
            ->leftJoin('visitor_identities as vi', 'vi.visitor_id', '=', 'pv.visitor_id')
            ->whereNotNull('pv.visitor_id')
            ->selectRaw('pv.visitor_id')
            ->selectRaw('DATE(pv.created_at) as session_date')
            ->selectRaw('MIN(pv.created_at) as first_seen')
            ->selectRaw('MAX(pv.created_at) as last_seen')
            ->selectRaw("SUM(pv.event_type = 'page_view') as page_views")
            ->selectRaw("SUM(pv.event_type = 'engagement') as clicks")
            ->selectRaw("SUM(pv.event_type = 'conversion') as conversions")
            ->selectRaw("COALESCE(SUM(CASE WHEN pv.event_type = 'page_view' THEN pv.duration_ms END), 0) as duration_ms")
            ->selectRaw("SUM(pv.page_type IN ('package_book', 'attraction_buy', 'event_buy', 'cart', 'checkout')) > 0 as reached_checkout")
            ->selectRaw('MAX(vi.name) as guest_name')
            ->selectRaw('MAX(vi.phone) as guest_phone')
            ->selectRaw('MAX(vi.email) as guest_email')
            ->selectRaw('MAX(pv.device_type) as device_type')
            ->selectRaw('MAX(pv.browser) as browser')
            ->groupBy('pv.visitor_id', DB::raw('DATE(pv.created_at)'))
            ->orderByDesc('last_seen');

        $user = $request->user();

        if ($user && $user->company_id) {
            $query->havingRaw('(SUM(pv.company_id = ?) > 0 OR COUNT(pv.company_id) = 0)', [(int) $user->company_id]);
        }

        $locationId = null;
        if ($user && in_array($user->role, ['location_manager', 'attendant'], true) && $user->location_id) {
            $locationId = (int) $user->location_id;
        } elseif ($request->filled('location_id')) {
            $locationId = (int) $request->location_id;
        }
        if ($locationId) {
            $query->havingRaw('SUM(pv.location_id = ?) > 0', [$locationId]);
        }

        $dateFrom = $overrides['date_from'] ?? ($request->filled('date_from') ? $request->date_from : null);
        $dateTo = $overrides['date_to'] ?? ($request->filled('date_to') ? $request->date_to : null);
        $identified = $overrides['identified'] ?? $request->get('identified');
        if ($request->boolean('identified_only')) {
            $identified = 'known';
        }

        if ($dateFrom) {
            $query->whereRaw('DATE(pv.created_at) >= ?', [$dateFrom]);
        }
        if ($dateTo) {
            $query->whereRaw('DATE(pv.created_at) <= ?', [$dateTo]);
        }

        if ($identified === 'known') {
            $query->whereNotNull('vi.id');
        } elseif ($identified === 'anonymous') {
            $query->whereNull('vi.id');
        }

        $deviceType = $request->get('device_type');
        if (in_array($deviceType, ['desktop', 'mobile', 'tablet'], true)) {
            $query->havingRaw('MAX(pv.device_type) = ?', [$deviceType]);
        }

        $activity = $request->get('activity');
        if ($activity === 'purchased') {
            $query->havingRaw("SUM(pv.event_type = 'conversion') > 0");
        } elseif ($activity === 'clicked') {
            $query->havingRaw("SUM(pv.event_type = 'engagement') > 0");
        } elseif ($activity === 'multi_page') {
            $query->havingRaw("SUM(pv.event_type = 'page_view') >= 2");
        } elseif ($activity === 'reached_checkout') {
            $query->havingRaw("SUM(pv.page_type IN ('package_book', 'attraction_buy', 'event_buy', 'cart', 'checkout')) > 0");
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $digits = preg_replace('/\D+/', '', (string) $request->search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('vi.name', 'like', $search)
                    ->orWhere('vi.email', 'like', $search)
                    ->orWhere('vi.phone', 'like', $search);
                if ($digits !== '') {
                    $q->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(vi.phone, ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?", ['%' . $digits . '%']);
                }
            });
        }

        return $query;
    }

    private function sessionEdges($rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, $rows->count(), '(?, ?)'));
        $bindings = $rows->flatMap(fn ($r) => [$r->visitor_id, $r->session_date])->all();

        $views = DB::table('page_views')
            ->selectRaw('visitor_id, DATE(created_at) as session_date, page_path, page_title, id')
            ->where('event_type', 'page_view')
            ->whereRaw("(visitor_id, DATE(created_at)) IN ($placeholders)", $bindings)
            ->orderBy('id')
            ->limit(50000)
            ->get();

        $edges = [];
        foreach ($views as $view) {
            $key = $view->visitor_id . '|' . $view->session_date;
            if (!isset($edges[$key])) {
                $edges[$key] = ['entry' => $view->page_path, 'entry_title' => $view->page_title, 'exit' => $view->page_path, 'exit_title' => $view->page_title];
            } else {
                $edges[$key]['exit'] = $view->page_path;
                $edges[$key]['exit_title'] = $view->page_title;
            }
        }

        return $edges;
    }

    private function sessionActions($rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, $rows->count(), '(?, ?)'));
        $bindings = $rows->flatMap(fn ($r) => [$r->visitor_id, $r->session_date])->all();

        $events = DB::table('page_views')
            ->selectRaw('visitor_id, DATE(created_at) as session_date, event_type, event_name, page_path, page_title, duration_ms, conversion_value, metadata, created_at, id')
            ->whereRaw("(visitor_id, DATE(created_at)) IN ($placeholders)", $bindings)
            ->orderBy('id')
            ->limit(30000)
            ->get();

        $actions = [];
        foreach ($events as $event) {
            $key = $event->visitor_id . '|' . $event->session_date;
            if (count($actions[$key] ?? []) >= self::EXPORT_MAX_ACTIONS_PER_SESSION) {
                continue;
            }

            $time = Carbon::parse($event->created_at)->format('g:i:s A');
            $meta = json_decode((string) $event->metadata, true);

            $line = match ($event->event_type) {
                'page_view' => sprintf(
                    '%s Viewed %s%s',
                    $time,
                    $event->page_title ?: $event->page_path,
                    $event->duration_ms ? ' (' . round($event->duration_ms / 1000) . 's)' : ''
                ),
                'engagement' => sprintf(
                    '%s Clicked "%s"',
                    $time,
                    is_array($meta) && !empty($meta['label']) ? $meta['label'] : $event->event_name
                ),
                'conversion' => sprintf(
                    '%s Completed %s%s',
                    $time,
                    str_replace('_', ' ', $event->event_name),
                    $event->conversion_value ? ' ($' . number_format((float) $event->conversion_value, 2) . ')' : ''
                ),
                default => sprintf('%s %s', $time, $event->event_name),
            };

            $actions[$key][] = $line;
        }

        return $actions;
    }

    private function presentSession($row, ?array $edge): array
    {
        $firstSeen = Carbon::parse($row->first_seen);
        $lastSeen = Carbon::parse($row->last_seen);

        return [
            'visitor_id' => $row->visitor_id,
            'session_date' => $row->session_date,
            'date_label' => Carbon::parse($row->session_date)->format('D, M j, Y'),
            'first_seen' => (string) $row->first_seen,
            'last_seen' => (string) $row->last_seen,
            'reached_checkout' => (bool) ($row->reached_checkout ?? false),
            'guest_name' => $row->guest_name,
            'guest_phone' => $row->guest_phone,
            'guest_email' => $row->guest_email,
            'first_seen_label' => $firstSeen->format('g:i A'),
            'last_seen_label' => $lastSeen->format('g:i A'),
            'page_views' => (int) $row->page_views,
            'clicks' => (int) $row->clicks,
            'conversions' => (int) $row->conversions,
            'duration_ms' => (int) $row->duration_ms,
            'entry_page' => $edge['entry'] ?? null,
            'entry_title' => $edge['entry_title'] ?? null,
            'exit_page' => $edge['exit'] ?? null,
            'exit_title' => $edge['exit_title'] ?? null,
            'device_type' => $row->device_type,
            'browser' => $row->browser,
        ];
    }
}
