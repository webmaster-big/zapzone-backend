<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\PresentsPhotos;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Photo;
use App\Models\PhotoSession;
use App\Models\Waiver;
use App\Services\PhotoDeliveryService;
use App\Services\PhotoProcessingService;
use App\Support\OperatingDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PhotoLibraryController extends Controller
{
    use ScopesByAuthUser, PresentsPhotos;

    public function __construct(protected PhotoDeliveryService $deliveries)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'source' => ['nullable', Rule::in([PhotoSession::SOURCE_STAFF, PhotoSession::SOURCE_KIOSK])],
            'slideshow_state' => ['nullable', Rule::in([Photo::SLIDESHOW_VISIBLE, Photo::SLIDESHOW_HIDDEN, Photo::SLIDESHOW_REMOVED])],
            'days' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        if ($request->filled('location_id')
            && ($denied = $this->guardLocationAccess($request, $request->integer('location_id')))) {
            return $denied;
        }

        $query = Photo::with(['session.location', 'session.deliveries', 'overlay', 'location'])
            ->ready();

        $this->applyAuthScope($query, $request);

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->integer('location_id'));
        }
        if ($request->filled('from')) {
            $query->whereDate('operating_day', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('operating_day', '<=', $request->date('to'));
        }
        if ($request->filled('slideshow_state')) {
            $query->where('slideshow_state', $request->input('slideshow_state'));
        }
        if ($request->filled('source')) {
            $source = $request->input('source');
            $query->whereHas('session', fn ($q) => $q->where('source', $source));
        }

        $dayLimit = $request->integer('days') ?: 14;

        $photos = $query->orderByDesc('operating_day')->orderByDesc('captured_at')->limit(1500)->get();

        $groups = $photos
            ->groupBy(fn (Photo $photo) => $photo->operating_day?->toDateString() ?? 'unknown')
            ->take($dayLimit)
            ->map(function ($items, $day) {
                $location = $items->first()->location;

                return [
                    'operating_day' => $day,
                    'label' => $day === 'unknown' ? 'Unknown day' : OperatingDay::label($location, $day),
                    'photo_count' => $items->count(),
                    'kiosk_count' => $items->filter(fn ($p) => $p->session?->source === PhotoSession::SOURCE_KIOSK)->count(),
                    'staff_count' => $items->filter(fn ($p) => $p->session?->source === PhotoSession::SOURCE_STAFF)->count(),
                    'photos' => $items->map(fn (Photo $photo) => array_merge($this->presentPhoto($photo), [
                        'session' => [
                            'id' => $photo->session?->id,
                            'source' => $photo->session?->source,
                            'delivery_status' => $photo->session?->deliveryStatus(),
                            'access_status' => $photo->session?->accessStatus(),
                            'access_expires_at' => $photo->session?->access_expires_at?->toIso8601String(),
                            'photo_link' => $photo->session ? $this->sessionPhotoLink($photo->session) : null,
                        ],
                        'location_name' => $photo->location?->name,
                    ]))->values(),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'days' => $groups,
                'total_photos' => $photos->count(),
                'truncated' => $photos->count() >= 1500,
            ],
        ]);
    }

    public function show(Request $request, Photo $photo): JsonResponse
    {
        if (!$this->authorizeRecordScope($photo)) {
            return $this->forbidden();
        }

        $photo->load(['session.location', 'session.deliveries', 'session.waivers', 'overlay']);

        return response()->json([
            'success' => true,
            'data' => array_merge($this->presentPhoto($photo), [
                'session' => $this->presentSession($photo->session, true),
            ]),
        ]);
    }

    public function download(Request $request, Photo $photo): SymfonyResponse
    {
        if (!$this->authorizeRecordScope($photo)) {
            return $this->forbidden();
        }
        if (!$photo->isReady() || !$photo->delivery_path) {
            return response()->json(['success' => false, 'message' => 'This photo is no longer available.'], 404);
        }

        $photo->increment('download_count');

        ActivityLog::log(
            'photo_downloaded',
            'photos',
            sprintf('Downloaded photo #%d from the daily library', $photo->id),
            $this->resolveAuthUser($request)?->id,
            $photo->location_id,
            'photo',
            $photo->id
        );

        return Storage::disk(PhotoProcessingService::DISK)->download(
            $photo->delivery_path,
            sprintf('zapzone-%s-%d.jpg', $photo->operating_day?->format('Y-m-d') ?? 'photo', $photo->id)
        );
    }

    public function downloadMany(Request $request): SymfonyResponse
    {
        $validated = $request->validate([
            'photo_ids' => ['required', 'array', 'min:1', 'max:200'],
            'photo_ids.*' => ['integer'],
        ]);

        $query = Photo::whereIn('id', $validated['photo_ids'])->ready();
        $this->applyAuthScope($query, $request);
        $photos = $query->get();

        if ($photos->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'None of those photos is available any more.'], 404);
        }

        if (!class_exists(\ZipArchive::class)) {
            return response()->json([
                'success' => false,
                'message' => 'Bulk download is not available on this server. Please download the photos one at a time.',
            ], 422);
        }

        $zipName = 'zapzone-photos-' . now()->format('Ymd-His') . '.zip';
        $tempPath = storage_path('app/' . $zipName);

        $zip = new \ZipArchive();
        if ($zip->open($tempPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['success' => false, 'message' => 'We could not build the download archive. Please try again.'], 500);
        }

        foreach ($photos as $photo) {
            $absolute = Storage::disk(PhotoProcessingService::DISK)->path($photo->delivery_path);
            if (is_file($absolute)) {
                $zip->addFile($absolute, sprintf(
                    '%s/photo-%d.jpg',
                    $photo->operating_day?->format('Y-m-d') ?? 'unknown',
                    $photo->id
                ));
            }
        }
        $zip->close();

        Photo::whereIn('id', $photos->pluck('id'))->increment('download_count');

        ActivityLog::log(
            'photos_downloaded_bulk',
            'photos',
            sprintf('Downloaded %d photos from the daily library', $photos->count()),
            $this->resolveAuthUser($request)?->id,
            $photos->first()->location_id,
            'photo',
            null,
            ['photo_ids' => $photos->pluck('id')->all()]
        );

        return response()->download($tempPath, $zipName)->deleteFileAfterSend(true);
    }

    public function destroy(Request $request, Photo $photo): JsonResponse
    {
        if (!$this->authorizeRecordScope($photo)) {
            return $this->forbidden();
        }

        if ($photo->purged_at !== null) {
            return response()->json([
                'success' => true,
                'message' => 'That photo had already been deleted.',
            ]);
        }

        $photo->purge();

        ActivityLog::log(
            'photo_deleted',
            'photos',
            sprintf('Deleted photo #%d from the daily library', $photo->id),
            $this->resolveAuthUser($request)?->id,
            $photo->location_id,
            'photo',
            $photo->id,
            ['photo_session_id' => $photo->photo_session_id, 'operating_day' => $photo->operating_day?->toDateString()]
        );

        return response()->json([
            'success' => true,
            'message' => 'Photo deleted. The image file is gone and any customer link for it no longer shows it.',
        ]);
    }

    public function destroyMany(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'photo_ids' => ['required', 'array', 'min:1', 'max:200'],
            'photo_ids.*' => ['integer'],
        ]);

        $query = Photo::whereIn('id', $validated['photo_ids'])->whereNull('purged_at');
        $this->applyAuthScope($query, $request);
        $photos = $query->get();

        if ($photos->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'None of those photos is available to delete.',
            ], 404);
        }

        foreach ($photos as $photo) {
            $photo->purge();
        }

        ActivityLog::log(
            'photos_deleted_bulk',
            'photos',
            sprintf('Deleted %d photos from the daily library', $photos->count()),
            $this->resolveAuthUser($request)?->id,
            $photos->first()->location_id,
            'photo',
            null,
            ['photo_ids' => $photos->pluck('id')->all()]
        );

        return response()->json([
            'success' => true,
            'message' => sprintf(
                'Deleted %d photo%s. The image files are gone and any customer links no longer show them.',
                $photos->count(),
                $photos->count() === 1 ? '' : 's'
            ),
            'data' => ['deleted' => $photos->count()],
        ]);
    }

    public function send(Request $request, Photo $photo): JsonResponse
    {
        if (!$this->authorizeRecordScope($photo)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'waiver_ids' => ['required', 'array', 'min:1'],
            'waiver_ids.*' => ['integer', 'exists:waivers,id'],
            'schedule' => ['nullable', Rule::in([PhotoSession::SCHEDULE_IMMEDIATE, PhotoSession::SCHEDULE_NEXT_DAY])],
        ]);

        $photo->loadMissing('session.location');
        $session = $photo->session;

        if (!$session || !$session->accessIsActive()) {
            return response()->json([
                'success' => false,
                'message' => 'The customer link for this photo has expired, so it cannot be sent again.',
            ], 422);
        }

        $waiverQuery = Waiver::whereIn('id', $validated['waiver_ids'])->where('status', Waiver::STATUS_COMPLETED);
        $this->applyAuthScope($waiverQuery, $request);
        $waivers = $waiverQuery->get();

        $contactable = $waivers->filter(fn ($w) => $this->deliveries->contactableChannels($w) !== []);

        if ($contactable->isEmpty()) {
            $reason = $waivers->map(fn ($w) => $this->deliveries->unavailableReason($w))->filter()->first();

            return response()->json([
                'success' => false,
                'message' => $reason ?? 'None of the selected waivers can be contacted.',
            ], 422);
        }

        $session->waivers()->syncWithoutDetaching($waivers->pluck('id')->all());

        $result = $this->deliveries->queueWaiverDeliveries(
            $session,
            $waivers,
            $validated['schedule'] ?? PhotoSession::SCHEDULE_IMMEDIATE,
            $this->resolveAuthUser($request)?->id
        );

        ActivityLog::log(
            'photo_backend_send',
            'photos',
            sprintf('Sent photo session #%d from the daily library to %d waiver record(s)', $session->id, $waivers->count()),
            $this->resolveAuthUser($request)?->id,
            $session->location_id,
            'photo_session',
            $session->id,
            ['waiver_ids' => $waivers->pluck('id')->all(), 'deliveries_created' => $result['created']]
        );

        return response()->json([
            'success' => true,
            'message' => 'Sent using the normal waiver message delivery flow.',
            'data' => $this->presentSession($session->fresh(), true),
        ]);
    }

    protected function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'You do not have access to that photo.',
        ], 403);
    }
}
