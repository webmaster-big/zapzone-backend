<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\Waiver;
use App\Models\WaiverAdEvent;
use App\Models\WaiverAdSend;
use App\Models\WaiverBulkInvite;
use App\Models\WaiverDeletionLog;
use App\Models\WaiverTemplateAd;
use App\Support\DateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaiverReportController extends Controller
{
    use ScopesByAuthUser;

    private const REQUEST_LOG_LIMIT = 500;

    /** Dispatch the MVP waiver reports. Read-only; scoped to the auth user. */
    public function report(Request $request, string $type): JsonResponse
    {
        if ($type === 'ad-performance') {
            $role = (string) ($this->resolveAuthUser($request)?->role ?? '');
            if (!in_array($role, ['company_admin', 'admin', 'location_manager'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ad reporting is available to location managers and administrators.',
                ], 403);
            }
        }

        $data = match ($type) {
            'completed-by-date' => $this->completedByDate($request),
            'missing' => $this->missing($request),
            'bulk-completion' => $this->bulkCompletion($request),
            'by-event' => $this->groupedCount($request, 'event_id'),
            'by-template' => $this->groupedCount($request, 'waiver_template_id'),
            'by-source' => $this->groupedCount($request, 'source'),
            'marketing-consent' => $this->marketingConsent($request),
            'deleted' => $this->deleted($request),
            'ad-performance' => $this->adPerformance($request),
            default => null,
        };

        if ($data === null) {
            return response()->json(['success' => false, 'message' => "Unknown report type '{$type}'."], 422);
        }

        return response()->json(['success' => true, 'type' => $type, 'data' => $data]);
    }

    private function completedByDate(Request $request): array
    {
        $q = Waiver::completed();
        $this->applyAuthScope($q, $request);
        $this->applyDateRange($q, $request);

        return $q->selectRaw('selected_date, COUNT(*) as count')
            ->groupBy('selected_date')
            ->orderBy('selected_date', 'desc')
            ->get()
            ->map(fn ($r) => [
                // selected_date is cast to Carbon, so normalize to a plain Y-m-d string
                'date' => \Illuminate\Support\Carbon::parse($r->selected_date)->toDateString(),
                'count' => (int) $r->count,
            ])
            ->all();
    }

    private function missing(Request $request): array
    {
        // "Missing" = required waivers that exist but are still incomplete (pending).
        $q = Waiver::pending()->with(['template:id,title', 'location:id,name', 'booking:id,reference_number', 'event:id,name']);
        $this->applyAuthScope($q, $request);
        $this->applyDateRange($q, $request);

        $items = $q->orderBy('selected_date')->limit(1000)->get();

        return [
            'count' => $items->count(),
            'items' => $items->map(fn (Waiver $w) => [
                'id' => $w->id,
                'name' => $w->adult_full_name,
                'email' => $w->adult_email,
                'phone' => $w->adult_phone,
                'selected_date' => (string) $w->selected_date,
                'template' => $w->template?->title,
                'booking' => $w->booking?->reference_number,
                'event' => $w->event?->name,
            ])->all(),
        ];
    }

    private function bulkCompletion(Request $request): array
    {
        $q = WaiverBulkInvite::withCount([
            'recipients',
            'recipients as complete_count' => fn ($r) => $r->where('status', 'complete'),
        ])->with(['template:id,title', 'location:id,name']);
        $this->applyAuthScope($q, $request);

        return $q->orderByDesc('created_at')->limit(500)->get()->map(fn ($b) => [
            'id' => $b->id,
            'chaperone' => $b->chaperone_name,
            'template' => $b->template?->title,
            'location' => $b->location?->name,
            'selected_date' => (string) $b->selected_date,
            'invited' => (int) $b->recipients_count,
            'complete' => (int) $b->complete_count,
            'not_complete' => (int) $b->recipients_count - (int) $b->complete_count,
        ])->all();
    }

    private function groupedCount(Request $request, string $column): array
    {
        $q = Waiver::completed();
        $this->applyAuthScope($q, $request);
        $this->applyDateRange($q, $request);

        if ($column === 'event_id') {
            $q->whereNotNull('event_id');
        }

        $rows = $q->selectRaw("{$column} as group_key, COUNT(*) as total")
            ->groupBy($column)
            ->orderByDesc('total')
            ->get();

        // resolve labels for event/template
        $labels = $this->labelsFor($column, $rows->pluck('group_key')->filter()->all());

        return $rows->map(fn ($r) => [
            'key' => $r->group_key,
            'label' => $labels[$r->group_key] ?? (string) $r->group_key,
            'count' => (int) $r->total,
        ])->all();
    }

    private function marketingConsent(Request $request): array
    {
        $q = Waiver::completed();
        $this->applyAuthScope($q, $request);
        $this->applyDateRange($q, $request);

        $counts = $q->selectRaw('marketing_consent_status as status, COUNT(*) as count')
            ->groupBy('marketing_consent_status')
            ->pluck('count', 'status');

        return [
            'opted_in' => (int) ($counts[Waiver::MARKETING_OPTED_IN] ?? 0),
            'not_opted_in' => (int) ($counts[Waiver::MARKETING_NOT_OPTED_IN] ?? 0),
            'withdrawn' => (int) ($counts[Waiver::MARKETING_WITHDRAWN] ?? 0),
        ];
    }

    private function deleted(Request $request): array
    {
        $q = WaiverDeletionLog::with('deleter:id,first_name,last_name');
        $this->applyAuthScope($q, $request);

        $items = $q->orderByDesc('created_at')->limit(500)->get();

        return [
            'count' => $items->count(),
            'items' => $items->map(fn ($l) => [
                'waiver_id' => $l->waiver_id,
                'reason' => $l->reason,
                'deleted_by' => trim(($l->deleter?->first_name ?? '') . ' ' . ($l->deleter?->last_name ?? '')),
                'deleted_at' => $l->created_at?->toIso8601String(),
                'snapshot' => $l->snapshot,
            ])->all(),
        ];
    }

    private function labelsFor(string $column, array $keys): array
    {
        if (empty($keys)) {
            return [];
        }
        return match ($column) {
            'event_id' => \App\Models\Event::whereIn('id', $keys)->pluck('name', 'id')->all(),
            'waiver_template_id' => \App\Models\WaiverTemplate::whereIn('id', $keys)->pluck('title', 'id')->all(),
            default => [],
        };
    }

    private function applyDateRange($query, Request $request): void
    {
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('selected_date', [$request->date('start_date'), $request->date('end_date')]);
        } elseif ($request->filled('date')) {
            $query->whereDate('selected_date', $request->date('date'));
        }
    }

    private function adPerformance(Request $request): array
    {
        $start = $request->input('start_date');
        $end = $request->input('end_date');

        $displaysQuery = WaiverAdEvent::query()->where('event', 'displayed');
        $this->applyAuthScope($displaysQuery, $request);
        DateRange::apply($displaysQuery, 'created_at', $start, $end);
        $displays = $displaysQuery
            ->selectRaw('waiver_template_ad_id, COUNT(*) as total')
            ->groupBy('waiver_template_ad_id')
            ->pluck('total', 'waiver_template_ad_id');

        $sendsQuery = WaiverAdSend::query();
        $this->applyAuthScope($sendsQuery, $request);
        DateRange::apply($sendsQuery, 'created_at', $start, $end);
        $sends = $sendsQuery
            ->selectRaw("waiver_template_ad_id, channel, COUNT(*) as requested, SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as delivered")
            ->groupBy('waiver_template_ad_id', 'channel')
            ->get()
            ->groupBy('waiver_template_ad_id');

        $adIds = $displays->keys()->merge($sends->keys())->unique()->values();
        $ads = WaiverTemplateAd::with('template:id,title')->whereIn('id', $adIds)->get()->keyBy('id');

        $rows = $adIds->map(function ($adId) use ($displays, $sends, $ads) {
            $ad = $ads->get($adId);
            $adSends = $sends->get($adId, collect());
            $requests = (int) $adSends->sum('requested');
            $displayCount = (int) ($displays[$adId] ?? 0);

            return [
                'ad_id' => (int) $adId,
                'ad_name' => $ad?->name ?: ('Ad #' . $adId),
                'image_path' => $ad?->image_path,
                'template' => $ad?->template?->title,
                'is_fallback' => (bool) ($ad?->is_fallback),
                'displays' => $displayCount,
                'learn_more_requests' => $requests,
                'sent_by_email' => (int) $adSends->firstWhere('channel', 'email')?->delivered,
                'sent_by_text' => (int) $adSends->firstWhere('channel', 'sms')?->delivered,
                'request_rate' => $displayCount > 0 ? round($requests / $displayCount, 4) : 0,
            ];
        })->sortByDesc('displays')->values()->all();

        $requestLogQuery = WaiverAdSend::query()->with('ad:id,name');
        $this->applyAuthScope($requestLogQuery, $request);
        DateRange::apply($requestLogQuery, 'created_at', $start, $end);
        $requestLogTotal = (clone $requestLogQuery)->count();
        $recentRequests = $requestLogQuery
            ->orderByDesc('created_at')
            ->limit(self::REQUEST_LOG_LIMIT)
            ->get()
            ->map(fn (WaiverAdSend $send) => [
                'ad_name' => $send->ad?->name ?: ('Ad #' . $send->waiver_template_ad_id),
                'channel' => $send->channel,
                'status' => $send->status,
                'requested_at' => $send->created_at?->toIso8601String(),
            ])->all();

        return [
            'ads' => $rows,
            'recent_requests' => $recentRequests,
            'recent_requests_total' => $requestLogTotal,
            'recent_requests_limit' => self::REQUEST_LOG_LIMIT,
        ];
    }
}
