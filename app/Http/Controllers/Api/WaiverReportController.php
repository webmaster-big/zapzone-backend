<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\Waiver;
use App\Models\WaiverBulkInvite;
use App\Models\WaiverDeletionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaiverReportController extends Controller
{
    use ScopesByAuthUser;

    /** Dispatch the MVP waiver reports. Read-only; scoped to the auth user. */
    public function report(Request $request, string $type): JsonResponse
    {
        $data = match ($type) {
            'completed-by-date' => $this->completedByDate($request),
            'missing' => $this->missing($request),
            'bulk-completion' => $this->bulkCompletion($request),
            'by-event' => $this->groupedCount($request, 'event_id'),
            'by-template' => $this->groupedCount($request, 'waiver_template_id'),
            'by-source' => $this->groupedCount($request, 'source'),
            'marketing-consent' => $this->marketingConsent($request),
            'deleted' => $this->deleted($request),
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

        $rows = $q->selectRaw("{$column} as key, COUNT(*) as count")
            ->groupBy($column)
            ->orderByDesc('count')
            ->get();

        // resolve labels for event/template
        $labels = $this->labelsFor($column, $rows->pluck('key')->filter()->all());

        return $rows->map(fn ($r) => [
            'key' => $r->key,
            'label' => $labels[$r->key] ?? (string) $r->key,
            'count' => (int) $r->count,
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
}
