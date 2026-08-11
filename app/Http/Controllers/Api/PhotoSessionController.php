<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\PresentsPhotos;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\LocationPhotoSetting;
use App\Models\Photo;
use App\Models\PhotoDelivery;
use App\Models\PhotoSession;
use App\Models\SlideshowQueue;
use App\Models\Waiver;
use App\Services\PhotoDeliveryService;
use App\Services\PhotoProcessingService;
use App\Support\OperatingDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PhotoSessionController extends Controller
{
    use ScopesByAuthUser, PresentsPhotos;

    public function __construct(
        protected PhotoProcessingService $processor,
        protected PhotoDeliveryService $deliveries
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = PhotoSession::with(['location', 'photos', 'deliveries', 'creator'])
            ->live()
            ->latest();

        $this->applyAuthScope($query, $request);

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->integer('location_id'));
        }
        if ($request->filled('source')) {
            $query->where('source', $request->input('source'));
        }
        if ($request->filled('operating_day')) {
            $query->whereDate('operating_day', $request->input('operating_day'));
        }

        $sessions = $query->paginate(min(100, $request->integer('per_page') ?: 25));

        return response()->json([
            'success' => true,
            'data' => $sessions->through(fn ($session) => $this->presentSession($session, true)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'verbal_consent' => ['required', 'accepted'],
        ], [
            'verbal_consent.accepted' => 'Confirm that the customer agreed to the photo before capturing.',
        ]);

        if ($denied = $this->guardLocationAccess($request, $validated['location_id'])) {
            return $denied;
        }

        $location = Location::findOrFail($validated['location_id']);

        if ($denied = $this->guardCompanyAccess($request, $location->company_id)) {
            return $denied;
        }

        $now = now();
        $session = PhotoSession::create([
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'source' => PhotoSession::SOURCE_STAFF,
            'status' => PhotoSession::STATUS_IN_PROGRESS,
            'created_by' => $this->resolveAuthUser($request)?->id,
            'verbal_consent_at' => $now,
            'captured_at' => null,
            'capture_date' => OperatingDay::calendarDateFor($location, $now),
            'operating_day' => OperatingDay::forLocation($location, $now),
        ]);

        ActivityLog::log(
            'photo_session_started',
            'photos',
            'Started a staff photo session after confirming verbal consent',
            $session->created_by,
            $location->id,
            'photo_session',
            $session->id
        );

        return response()->json([
            'success' => true,
            'data' => $this->presentSession($session),
        ], 201);
    }

    public function show(Request $request, PhotoSession $photoSession): JsonResponse
    {
        if (!$this->authorizeRecordScope($photoSession)) {
            return $this->forbidden();
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentSession($photoSession, true),
        ]);
    }

    public function addPhoto(Request $request, PhotoSession $photoSession): JsonResponse
    {
        if (!$this->authorizeRecordScope($photoSession)) {
            return $this->forbidden();
        }

        $maxKb = max(512, (int) config('photos.max_upload_kb', 12288));

        $request->validate([
            'image' => ['nullable', 'string'],
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:' . $maxKb],
            'source' => ['nullable', Rule::in([Photo::SOURCE_CAMERA, Photo::SOURCE_UPLOAD])],
        ]);

        if (!$request->filled('image') && !$request->hasFile('file')) {
            return response()->json([
                'success' => false,
                'message' => 'Attach a captured image or choose a file to upload.',
            ], 422);
        }

        if ($photoSession->photos()->count() >= $photoSession->maxPhotos()) {
            return response()->json([
                'success' => false,
                'message' => sprintf(
                    'This session already holds %d photos, which is the maximum.',
                    $photoSession->maxPhotos()
                ),
            ], 422);
        }

        $binary = $request->hasFile('file')
            ? file_get_contents($request->file('file')->getRealPath())
            : $this->decodeDataUrl($request->input('image'));

        if ($binary === null || $binary === false || strlen($binary) === 0) {
            return response()->json([
                'success' => false,
                'message' => 'That image could not be read. Please capture or choose it again.',
            ], 422);
        }

        $photoSession->loadMissing('location');
        $location = $photoSession->location;
        $now = now();

        $photo = Photo::create([
            'photo_session_id' => $photoSession->id,
            'company_id' => $photoSession->company_id,
            'location_id' => $photoSession->location_id,
            'position' => (int) $photoSession->photos()->max('position') + 1,
            'source' => $request->hasFile('file')
                ? Photo::SOURCE_UPLOAD
                : ($request->input('source') ?: Photo::SOURCE_CAMERA),
            'processing_status' => Photo::PROCESSING_PENDING,
            'captured_at' => $now,
            'capture_date' => OperatingDay::calendarDateFor($location, $now),
            'operating_day' => OperatingDay::forLocation($location, $now),
        ]);

        $photo->update(['original_path' => $this->processor->storeSource($binary, $photo)]);

        $photoSession->update([
            'status' => PhotoSession::STATUS_PROCESSING,
            'captured_at' => $photoSession->captured_at ?: $now,
        ]);

        $photo = $this->processor->process($photo);

        $photoSession->update([
            'status' => $photo->processing_status === Photo::PROCESSING_READY
                ? PhotoSession::STATUS_READY
                : PhotoSession::STATUS_IN_PROGRESS,
        ]);

        if ($photo->processing_status === Photo::PROCESSING_FAILED) {
            return response()->json([
                'success' => false,
                'message' => 'We could not process that photo. Please try again.',
                'data' => $this->presentPhoto($photo),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentSession($photoSession->fresh()),
        ], 201);
    }

    public function destroyPhoto(Request $request, PhotoSession $photoSession, Photo $photo): JsonResponse
    {
        if (!$this->authorizeRecordScope($photoSession)) {
            return $this->forbidden();
        }
        if ($photo->photo_session_id !== $photoSession->id) {
            return response()->json(['success' => false, 'message' => 'That photo is not part of this session.'], 404);
        }
        if ($photoSession->delivered_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This session has already been delivered, so its photos cannot be removed here.',
            ], 422);
        }

        $photo->deleteMedia();
        $photo->delete();

        $remaining = $photoSession->photos()->orderBy('position')->get();
        foreach ($remaining as $index => $item) {
            $item->update(['position' => $index + 1]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentSession($photoSession->fresh()),
        ]);
    }

    public function reorderPhotos(Request $request, PhotoSession $photoSession): JsonResponse
    {
        if (!$this->authorizeRecordScope($photoSession)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ]);

        $ids = $photoSession->photos()->pluck('id')->all();

        foreach ($validated['order'] as $index => $photoId) {
            if (!in_array((int) $photoId, $ids, true)) {
                continue;
            }
            Photo::where('id', $photoId)->update(['position' => $index + 1]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->presentSession($photoSession->fresh()),
        ]);
    }

    public function waiverSearch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
        ]);

        if ($request->filled('location_id')
            && ($denied = $this->guardLocationAccess($request, $validated['location_id']))) {
            return $denied;
        }

        $term = trim($validated['q']);
        $digits = preg_replace('/[^0-9]/', '', $term);

        $query = Waiver::query()
            ->with('location')
            ->where('status', Waiver::STATUS_COMPLETED)
            ->where(function ($q) use ($term, $digits) {
                $q->where('adult_first_name', 'like', "%{$term}%")
                    ->orWhere('adult_last_name', 'like', "%{$term}%")
                    ->orWhere('adult_email', 'like', "%{$term}%")
                    ->orWhereRaw("CONCAT(COALESCE(adult_first_name, ''), ' ', COALESCE(adult_last_name, '')) LIKE ?", ["%{$term}%"]);

                if (strlen((string) $digits) >= 4) {
                    $q->orWhere('adult_phone', 'like', "%{$digits}%");
                }
            });

        $this->applyAuthScope($query, $request);

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->integer('location_id'));
        }

        $waivers = $query->orderByDesc('submitted_at')->limit(25)->get();

        return response()->json([
            'success' => true,
            'data' => $waivers->map(function (Waiver $waiver) {
                $onRecord = $this->deliveries->contactChannelsOnRecord($waiver);
                $deliverable = $this->deliveries->contactableChannels($waiver);

                return [
                    'id' => $waiver->id,
                    'name' => trim(($waiver->adult_first_name ?? '') . ' ' . ($waiver->adult_last_name ?? '')),
                    'email_masked' => $this->maskEmail($waiver->adult_email),
                    'phone_masked' => $this->maskPhone($waiver->adult_phone),
                    'has_email' => in_array(PhotoDelivery::CHANNEL_EMAIL, $onRecord, true),
                    'has_phone' => in_array(PhotoDelivery::CHANNEL_SMS, $onRecord, true),
                    'contactable' => $deliverable !== [],
                    'unavailable_reason' => $this->deliveries->unavailableReason($waiver),
                    'photo_video_consent' => $waiver->photo_video_consent,
                    'status' => $waiver->status,
                    'location_name' => $waiver->location?->name,
                    'signed_on' => $waiver->submitted_at?->toDateString(),
                    'visit_date' => $waiver->selected_date?->toDateString(),
                ];
            })->values(),
        ]);
    }

    public function deliver(Request $request, PhotoSession $photoSession): JsonResponse
    {
        if (!$this->authorizeRecordScope($photoSession)) {
            return $this->forbidden();
        }

        $validated = $request->validate([
            'method' => ['required', Rule::in([PhotoSession::DELIVERY_WAIVER_MESSAGE, PhotoSession::DELIVERY_STAFF_QR])],
            'schedule' => ['nullable', Rule::in([PhotoSession::SCHEDULE_IMMEDIATE, PhotoSession::SCHEDULE_NEXT_DAY])],
            'waiver_ids' => ['nullable', 'array'],
            'waiver_ids.*' => ['integer', 'exists:waivers,id'],
        ]);

        if ($photoSession->photos()->ready()->count() === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Capture at least one photo before choosing a delivery method.',
            ], 422);
        }
        if ($photoSession->verbal_consent_at === null) {
            return response()->json([
                'success' => false,
                'message' => 'Confirm the customer\'s verbal consent before delivering photos.',
            ], 422);
        }
        if ($photoSession->delivered_at !== null || $photoSession->delivery_method !== null) {
            return response()->json([
                'success' => false,
                'message' => 'A delivery method has already been chosen for this session.',
            ], 422);
        }

        $photoSession->startQrWindow();

        if ($validated['method'] === PhotoSession::DELIVERY_STAFF_QR) {
            $photoSession->forceFill([
                'delivery_method' => PhotoSession::DELIVERY_STAFF_QR,
                'delivery_schedule' => null,
                'status' => PhotoSession::STATUS_READY,
            ])->save();

            ActivityLog::log(
                'photo_staff_qr_created',
                'photos',
                sprintf('Displayed a direct staff QR code for photo session #%d', $photoSession->id),
                $this->resolveAuthUser($request)?->id,
                $photoSession->location_id,
                'photo_session',
                $photoSession->id
            );

            return response()->json([
                'success' => true,
                'data' => $this->presentSession($photoSession->fresh()),
            ]);
        }

        $waiverIds = array_values(array_unique($validated['waiver_ids'] ?? []));

        if ($waiverIds === []) {
            return response()->json([
                'success' => false,
                'message' => 'Select at least one waiver, or use the direct staff QR code instead.',
            ], 422);
        }

        $waiverQuery = Waiver::whereIn('id', $waiverIds)->where('status', Waiver::STATUS_COMPLETED);
        $this->applyAuthScope($waiverQuery, $request);
        $waivers = $waiverQuery->get();

        if ($waivers->count() !== count($waiverIds)) {
            return response()->json([
                'success' => false,
                'message' => 'One or more of the selected waivers is no longer available. Please search again.',
            ], 422);
        }

        $contactable = $waivers->filter(fn ($w) => $this->deliveries->contactableChannels($w) !== []);

        if ($contactable->isEmpty()) {
            $reason = $waivers->map(fn ($w) => $this->deliveries->unavailableReason($w))->filter()->first();

            return response()->json([
                'success' => false,
                'message' => ($reason ?? 'None of the selected waivers can be contacted.')
                    . ' Use the direct staff QR code instead.',
            ], 422);
        }

        $photoSession->status = PhotoSession::STATUS_READY;
        $photoSession->save();

        $photoSession->waivers()->syncWithoutDetaching($waivers->pluck('id')->all());

        $schedule = $validated['schedule'] ?? PhotoSession::SCHEDULE_IMMEDIATE;
        $result = $this->deliveries->queueWaiverDeliveries(
            $photoSession,
            $waivers,
            $schedule,
            $this->resolveAuthUser($request)?->id
        );

        ActivityLog::log(
            'photo_waiver_delivery',
            'photos',
            sprintf(
                '%s photo session #%d to %d waiver record(s)',
                $schedule === PhotoSession::SCHEDULE_NEXT_DAY ? 'Scheduled' : 'Sent',
                $photoSession->id,
                $waivers->count()
            ),
            $this->resolveAuthUser($request)?->id,
            $photoSession->location_id,
            'photo_session',
            $photoSession->id,
            [
                'waiver_ids' => $waivers->pluck('id')->all(),
                'schedule' => $schedule,
                'deliveries_created' => $result['created'],
                'skipped_waiver_ids' => $result['skipped_waiver_ids'],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => $schedule === PhotoSession::SCHEDULE_NEXT_DAY
                ? 'Scheduled for 9:00 AM tomorrow in this location\'s time zone.'
                : 'Sent through every available contact method.',
            'data' => $this->presentSession($photoSession->fresh()),
        ]);
    }

    public function destroy(Request $request, PhotoSession $photoSession): JsonResponse
    {
        if (!$this->authorizeRecordScope($photoSession)) {
            return $this->forbidden();
        }
        if ($photoSession->delivery_method !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This session has already been delivered and cannot be discarded.',
            ], 422);
        }

        foreach ($photoSession->photos as $photo) {
            $photo->deleteMedia();
        }

        $id = $photoSession->id;
        $locationId = $photoSession->location_id;
        $photoSession->delete();

        ActivityLog::log(
            'photo_session_discarded',
            'photos',
            sprintf('Discarded photo session #%d before delivery', $id),
            $this->resolveAuthUser($request)?->id,
            $locationId,
            'photo_session',
            $id
        );

        return response()->json(['success' => true, 'message' => 'Session discarded.']);
    }

    public function context(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
        ]);

        $location = $this->scopedLocation($request, $validated['location_id']);

        if (!$location instanceof Location) {
            return $location;
        }

        $location->loadMissing('company');
        $setting = LocationPhotoSetting::forLocation($location);
        $overlay = $this->processor->resolveOverlay($location);
        $queue = SlideshowQueue::activeFor($location);

        return response()->json([
            'success' => true,
            'data' => [
                'location' => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'city' => $location->city,
                    'state' => $location->state,
                    'timezone' => OperatingDay::timezoneFor($location),
                ],
                'operating_day' => OperatingDay::forLocation($location),
                'local_time' => OperatingDay::localNow($location)->toIso8601String(),
                'active_overlay' => $overlay ? ['id' => $overlay->id, 'name' => $overlay->name] : null,
                'has_overlay' => $overlay !== null,
                'slideshow_queue_id' => $queue->id,
                'limits' => [
                    'staff_max_photos' => PhotoSession::STAFF_MAX_PHOTOS,
                    'kiosk_max_photos' => PhotoSession::KIOSK_MAX_PHOTOS,
                    'qr_valid_hours' => PhotoSession::QR_VALID_HOURS,
                    'access_valid_days' => PhotoSession::ACCESS_VALID_DAYS,
                    'kiosk_countdown_seconds' => (int) $setting->kiosk_countdown_seconds,
                    'kiosk_idle_seconds' => PhotoSession::KIOSK_IDLE_SECONDS,
                ],
                'channels' => $this->deliveries->channelDiagnostics(),
                'retention_days' => $setting->retention_days,
            ],
        ]);
    }

    protected function decodeDataUrl(?string $value): string|false|null
    {
        if (!$value) {
            return null;
        }

        if (str_starts_with($value, 'data:')) {
            $comma = strpos($value, ',');
            if ($comma === false) {
                return null;
            }
            $value = substr($value, $comma + 1);
        }

        return base64_decode($value, true);
    }

    protected function maskEmail(?string $email): ?string
    {
        if (!$email) {
            return null;
        }

        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }

        $name = $parts[0];
        $visible = mb_substr($name, 0, 2);

        return $visible . str_repeat('*', max(1, mb_strlen($name) - 2)) . '@' . $parts[1];
    }

    protected function maskPhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $phone);

        return strlen((string) $digits) > 4
            ? '(***) ***-' . substr((string) $digits, -4)
            : $phone;
    }

    protected function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'You do not have access to this photo session.',
        ], 403);
    }
}
