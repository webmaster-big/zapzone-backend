<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\LocationPhotoSetting;
use App\Models\Photo;
use App\Models\PhotoDelivery;
use App\Models\PhotoOverlay;
use App\Models\PhotoSession;
use App\Models\SlideshowQueue;
use App\Services\PhotoProcessingService;
use App\Support\DateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhotoReportController extends Controller
{
    use ScopesByAuthUser;

    public const TYPES = ['activity', 'delivery', 'qr', 'kiosk', 'slideshow', 'library', 'overlay', 'audit'];

    public function __construct(protected PhotoProcessingService $processor)
    {
    }

    public function report(Request $request, string $type): JsonResponse
    {
        if (!in_array($type, self::TYPES, true)) {
            return response()->json(['success' => false, 'message' => 'Unknown report.'], 404);
        }

        $request->validate([
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        if ($request->filled('location_id')) {
            $scoped = $this->scopedLocation($request, $request->integer('location_id'));
            if (!$scoped instanceof Location) {
                return $scoped;
            }
        }

        $from = $request->input('from') ?: now()->subDays(29)->toDateString();
        $to = $request->input('to') ?: now()->toDateString();

        $data = match ($type) {
            'activity' => $this->activity($request, $from, $to),
            'delivery' => $this->delivery($request, $from, $to),
            'qr' => $this->qr($request, $from, $to),
            'kiosk' => $this->kiosk($request, $from, $to),
            'slideshow' => $this->slideshow($request, $from, $to),
            'library' => $this->library($request, $from, $to),
            'overlay' => $this->overlay($request),
            'audit' => $this->audit($request, $from, $to),
        };

        return response()->json([
            'success' => true,
            'data' => array_merge($data, [
                'type' => $type,
                'from' => $from,
                'to' => $to,
                'business_timezone' => DateRange::businessTimezone(),
            ]),
        ]);
    }

    protected function sessions(Request $request, string $from, string $to)
    {
        $query = PhotoSession::query();
        $this->applyAuthScope($query, $request);
        $this->scopeLocation($query, $request);
        DateRange::apply($query, 'photo_sessions.created_at', $from, $to);

        return $query;
    }

    protected function photos(Request $request, string $from, string $to)
    {
        $query = Photo::query();
        $this->applyAuthScope($query, $request);
        $this->scopeLocation($query, $request);
        DateRange::apply($query, 'photos.created_at', $from, $to);

        return $query;
    }

    protected function deliveriesQuery(Request $request, string $from, string $to)
    {
        $query = PhotoDelivery::query();
        $this->applyAuthScope($query, $request);
        $this->scopeLocation($query, $request);
        DateRange::apply($query, 'photo_deliveries.created_at', $from, $to);

        return $query;
    }

    protected function logs(Request $request, string $from, string $to)
    {
        $query = ActivityLog::where('category', 'photos');
        $authUser = $this->resolveAuthUser($request);

        // activity_logs carries no company_id, so company scoping has to go through the
        // caller's own locations. Without this a company_admin could read another tenant's
        // photo audit trail by naming their location_id.
        if (!$authUser) {
            return $query->whereRaw('1 = 0');
        }

        if (in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
            $query->where('location_id', $authUser->location_id);
        } elseif ($authUser->company_id) {
            $query->whereIn('location_id', Location::where('company_id', $authUser->company_id)->select('id'));
        } else {
            return $query->whereRaw('1 = 0');
        }

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->integer('location_id'));
        }

        DateRange::apply($query, 'activity_logs.created_at', $from, $to);

        return $query;
    }

    protected function scopeLocation($query, Request $request): void
    {
        if ($request->filled('location_id')) {
            $query->where('location_id', $request->integer('location_id'));
        }
    }

    protected function activity(Request $request, string $from, string $to): array
    {
        $sessions = $this->sessions($request, $from, $to);
        $photos = $this->photos($request, $from, $to);

        return [
            'sessions_total' => (clone $sessions)->count(),
            'sessions_staff' => (clone $sessions)->where('source', PhotoSession::SOURCE_STAFF)->count(),
            'sessions_kiosk' => (clone $sessions)->where('source', PhotoSession::SOURCE_KIOSK)->count(),
            'photos_total' => (clone $photos)->count(),
            'photos_camera' => (clone $photos)->where('source', Photo::SOURCE_CAMERA)->count(),
            'photos_uploaded' => (clone $photos)->where('source', Photo::SOURCE_UPLOAD)->count(),
            'photos_kiosk' => (clone $photos)->where('source', Photo::SOURCE_KIOSK)->count(),
            'processing_failures' => (clone $photos)->where('processing_status', Photo::PROCESSING_FAILED)->count(),
            'retakes' => (clone $this->logs($request, $from, $to))->where('action', 'kiosk_photo_retaken')->count(),
            'discarded_sessions' => (clone $this->logs($request, $from, $to))->where('action', 'photo_session_discarded')->count(),
        ];
    }

    protected function delivery(Request $request, string $from, string $to): array
    {
        $deliveries = $this->deliveriesQuery($request, $from, $to);
        $real = (clone $deliveries)->real();

        return [
            'total' => (clone $real)->where('status', '!=', PhotoDelivery::STATUS_SKIPPED)->count(),
            'skipped_unsupported_channel' => (clone $real)->where('status', PhotoDelivery::STATUS_SKIPPED)->count(),
            'immediate' => (clone $real)->where('kind', PhotoDelivery::KIND_IMMEDIATE)->count(),
            'next_day' => (clone $real)->where('kind', PhotoDelivery::KIND_NEXT_DAY)->count(),
            'kiosk' => (clone $real)->where('kind', PhotoDelivery::KIND_KIOSK)->count(),
            'email_sent' => (clone $real)->where('channel', PhotoDelivery::CHANNEL_EMAIL)->where('status', PhotoDelivery::STATUS_SENT)->count(),
            'email_failed' => (clone $real)->where('channel', PhotoDelivery::CHANNEL_EMAIL)->where('status', PhotoDelivery::STATUS_FAILED)->count(),
            'sms_sent' => (clone $real)->where('channel', PhotoDelivery::CHANNEL_SMS)->where('status', PhotoDelivery::STATUS_SENT)->count(),
            'sms_failed' => (clone $real)->where('channel', PhotoDelivery::CHANNEL_SMS)->where('status', PhotoDelivery::STATUS_FAILED)->count(),
            'scheduled_pending' => (clone $real)->where('status', PhotoDelivery::STATUS_SCHEDULED)->count(),
            'canceled' => (clone $real)->where('status', PhotoDelivery::STATUS_CANCELED)->count(),
            'deduped' => (clone $deliveries)->whereNotNull('duplicate_of_id')->count(),
            'resends' => (clone $real)->where('attempts', '>', 1)->count(),
            'link_opens' => (clone $real)->whereNotNull('opened_at')->count(),
            'downloads' => (int) (clone $this->photos($request, $from, $to))->sum('download_count'),
        ];
    }

    protected function qr(Request $request, string $from, string $to): array
    {
        $sessions = $this->sessions($request, $from, $to)->whereNotNull('qr_expires_at');

        return [
            'created' => (clone $sessions)->count(),
            'staff_qr' => (clone $sessions)->where('delivery_method', PhotoSession::DELIVERY_STAFF_QR)->count(),
            'kiosk_qr' => (clone $sessions)->where('delivery_method', PhotoSession::DELIVERY_KIOSK_QR)->count(),
            'scans' => (int) (clone $sessions)->sum('qr_scan_count'),
            'scanned_sessions' => (clone $sessions)->where('qr_scan_count', '>', 0)->count(),
            'expired_without_scan' => (clone $sessions)
                ->where('qr_scan_count', 0)
                ->where('qr_expires_at', '<', now())
                ->count(),
            'active_now' => (clone $sessions)->where('qr_expires_at', '>=', now())->count(),
            'validity_hours' => PhotoSession::QR_VALID_HOURS,
        ];
    }

    protected function kiosk(Request $request, string $from, string $to): array
    {
        $sessions = $this->sessions($request, $from, $to)->where('source', PhotoSession::SOURCE_KIOSK);
        $logs = $this->logs($request, $from, $to);

        return [
            'starts' => (clone $logs)->where('action', 'kiosk_session_started')->count(),
            'sessions_created' => (clone $sessions)->count(),
            'completions' => (clone $sessions)->whereNotNull('accepted_at')->count(),
            'contact_forms_completed' => (clone $sessions)->whereNotNull('kiosk_contact_at')->count(),
            'slideshow_selections' => (clone $sessions)->where('slideshow_opt_in', true)->count(),
            'slideshow_declines' => (clone $sessions)->where('slideshow_opt_in', false)->whereNotNull('accepted_at')->count(),
            'marketing_opt_ins' => (clone $sessions)->where('kiosk_marketing_consent', true)->count(),
            'inactivity_resets' => (clone $logs)->where('action', 'kiosk_timeout_reset')->count(),
            'retakes' => (clone $logs)->where('action', 'kiosk_photo_retaken')->count(),
            'passcode_failures' => (clone $logs)->where('action', 'kiosk_passcode_rejected')->count(),
            'countdown_seconds' => $this->configuredCountdown($request),
            'idle_seconds' => PhotoSession::KIOSK_IDLE_SECONDS,
        ];
    }

    /**
     * The countdown is per location, so only report a number when one location is in scope.
     */
    protected function configuredCountdown(Request $request): ?int
    {
        $locationId = $request->integer('location_id')
            ?: $this->resolveAuthUser($request)?->location_id;

        if (!$locationId) {
            return null;
        }

        // report() has already scoped an explicit location_id; re-check so this helper is
        // never the thing that provisions a settings row inside another tenant.
        $location = $this->scopedLocation($request, $locationId);

        return $location instanceof Location
            ? (int) LocationPhotoSetting::forLocation($location)->kiosk_countdown_seconds
            : null;
    }

    protected function slideshow(Request $request, string $from, string $to): array
    {
        $photos = $this->photos($request, $from, $to)->where('slideshow_eligible', true);

        $queueQuery = SlideshowQueue::query();
        $this->applyAuthScope($queueQuery, $request);
        $this->scopeLocation($queueQuery, $request);

        $activeQueues = (clone $queueQuery)->active()->get();

        return [
            'eligible_photos' => (clone $photos)->count(),
            'visible' => (clone $photos)->where('slideshow_state', Photo::SLIDESHOW_VISIBLE)->count(),
            'hidden' => (clone $photos)->where('slideshow_state', Photo::SLIDESHOW_HIDDEN)->count(),
            'removed' => (clone $photos)->where('slideshow_state', Photo::SLIDESHOW_REMOVED)->count(),
            'active_queues' => $activeQueues->count(),
            'paused_queues' => $activeQueues->where('is_paused', true)->count(),
            'closed_queues' => (clone $queueQuery)->where('status', SlideshowQueue::STATUS_CLOSED)->count(),
            'average_queue_size' => $activeQueues->count() > 0
                ? round($activeQueues->sum(fn ($queue) => $queue->visiblePhotos()->count()) / $activeQueues->count(), 1)
                : 0,
            'hide_actions' => (clone $this->logs($request, $from, $to))->where('action', 'slideshow_photo_updated')->count(),
        ];
    }

    protected function library(Request $request, string $from, string $to): array
    {
        $photos = $this->photos($request, $from, $to)->ready();

        $byDay = (clone $photos)
            ->selectRaw('operating_day, COUNT(*) as total, SUM(download_count) as downloads')
            ->groupBy('operating_day')
            ->orderByDesc('operating_day')
            ->limit(60)
            ->get()
            ->map(fn ($row) => [
                'operating_day' => $row->operating_day instanceof \DateTimeInterface
                    ? $row->operating_day->format('Y-m-d')
                    : (string) $row->operating_day,
                'photos' => (int) $row->total,
                'downloads' => (int) $row->downloads,
            ]);

        $sessions = $this->sessions($request, $from, $to);

        return [
            'photos_total' => (clone $photos)->count(),
            'downloads_total' => (int) (clone $photos)->sum('download_count'),
            'backend_sends' => (clone $this->logs($request, $from, $to))->where('action', 'photo_backend_send')->count(),
            'access_active' => (clone $sessions)->where('access_expires_at', '>=', now())->count(),
            'access_expired' => (clone $sessions)->where('access_expires_at', '<', now())->count(),
            'purged' => (clone $photos)->whereNotNull('purged_at')->count(),
            'by_day' => $byDay,
            'retention_note' => 'Photos are removed from the photo library when the configured retention period ends.',
        ];
    }

    protected function overlay(Request $request): array
    {
        $query = PhotoOverlay::query();
        $this->applyAuthScope($query, $request);
        $this->scopeLocation($query, $request);

        $overlays = $query->get();
        $conflicts = [];

        foreach ($overlays->pluck('location_id')->unique() as $locationId) {
            foreach ($this->processor->overlayConflicts((int) $locationId) as $conflict) {
                $conflicts[] = array_merge($conflict, ['location_id' => (int) $locationId]);
            }
        }

        $photoQuery = Photo::query();
        $this->applyAuthScope($photoQuery, $request);
        $this->scopeLocation($photoQuery, $request);

        return [
            'overlays_total' => $overlays->count(),
            'enabled' => $overlays->where('is_enabled', true)->count(),
            'scheduled' => $overlays->filter(fn ($o) => $o->status() === PhotoOverlay::STATUS_SCHEDULED)->count(),
            'expired' => $overlays->filter(fn ($o) => $o->status() === PhotoOverlay::STATUS_EXPIRED)->count(),
            'active' => $overlays->filter(fn ($o) => $o->status() === PhotoOverlay::STATUS_ACTIVE)->count(),
            'conflicts' => $conflicts,
            'photos_with_overlay' => (clone $photoQuery)->whereNotNull('photo_overlay_id')->count(),
            'photos_date_only' => (clone $photoQuery)->whereNull('photo_overlay_id')->count(),
            'processing_errors' => (clone $photoQuery)->where('processing_status', Photo::PROCESSING_FAILED)->count(),
        ];
    }

    protected function audit(Request $request, string $from, string $to): array
    {
        $logs = $this->logs($request, $from, $to)
            ->with(['user', 'location'])
            ->latest()
            ->limit(300)
            ->get();

        return [
            'entries' => $logs->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'user_name' => $log->user
                    ? trim(($log->user->first_name ?? '') . ' ' . ($log->user->last_name ?? ''))
                    : 'Device / system',
                'location_name' => $log->location?->name,
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values(),
            'total' => $logs->count(),
        ];
    }
}
