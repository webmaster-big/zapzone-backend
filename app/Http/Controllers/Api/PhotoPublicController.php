<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\LocationPhotoSetting;
use App\Models\Photo;
use App\Models\PhotoDelivery;
use App\Models\PhotoSession;
use App\Models\SlideshowQueue;
use App\Services\PhotoDeliveryService;
use App\Services\PhotoDeviceTokenService;
use App\Services\PhotoProcessingService;
use App\Support\OperatingDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PhotoPublicController extends Controller
{
    public const KIOSK_SESSION_TTL_MINUTES = 20;

    public function __construct(
        protected PhotoDeviceTokenService $devices,
        protected PhotoProcessingService $processor,
        protected PhotoDeliveryService $deliveries
    ) {
    }

    public function kioskUnlock(Request $request, int $locationId): JsonResponse
    {
        $request->validate(['passcode' => ['required', 'string', 'max:32']]);

        $location = Location::find($locationId);

        if (!$location || !$location->is_active) {
            return $this->denyDevice();
        }

        $setting = LocationPhotoSetting::forLocation($location);

        if (!$setting->kiosk_enabled || !$setting->matchesKioskPasscode($request->input('passcode'))) {
            ActivityLog::log(
                'kiosk_passcode_rejected',
                'photos',
                'A device entered an incorrect kiosk passcode',
                null,
                $location->id,
                'location_photo_setting',
                $setting->id
            );

            return $this->denyDevice();
        }

        $issued = $this->devices->issue($location->id, PhotoDeviceTokenService::MODE_KIOSK);

        return response()->json([
            'success' => true,
            'data' => array_merge($issued, ['context' => $this->kioskContextPayload($location, $setting)]),
        ]);
    }

    public function kioskContext(Request $request, int $locationId): JsonResponse
    {
        $location = $this->deviceLocation($request, $locationId, PhotoDeviceTokenService::MODE_KIOSK);

        if (!$location) {
            return $this->denyDevice();
        }

        $setting = LocationPhotoSetting::forLocation($location);

        if (!$setting->kiosk_enabled) {
            return response()->json([
                'success' => false,
                'message' => 'Kiosk mode is turned off for this location.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->kioskContextPayload($location, $setting),
        ]);
    }

    public function kioskStartSession(Request $request, int $locationId): JsonResponse
    {
        $location = $this->deviceLocation($request, $locationId, PhotoDeviceTokenService::MODE_KIOSK);

        if (!$location) {
            return $this->denyDevice();
        }

        $setting = LocationPhotoSetting::forLocation($location);

        if (!$setting->kiosk_enabled) {
            return response()->json(['success' => false, 'message' => 'Kiosk mode is turned off for this location.'], 403);
        }

        $now = now();
        $session = PhotoSession::create([
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'source' => PhotoSession::SOURCE_KIOSK,
            'status' => PhotoSession::STATUS_IN_PROGRESS,
            'delivery_method' => PhotoSession::DELIVERY_KIOSK_QR,
            'slideshow_opt_in' => true,
            'capture_date' => OperatingDay::calendarDateFor($location, $now),
            'operating_day' => OperatingDay::forLocation($location, $now),
        ]);

        ActivityLog::log(
            'kiosk_session_started',
            'photos',
            'A kiosk visitor started a photo session',
            null,
            $location->id,
            'photo_session',
            $session->id
        );

        return response()->json([
            'success' => true,
            'data' => [
                'session_id' => $session->id,
                'session_secret' => $this->devices->sessionSecret($session->id, (string) $session->created_at),
                'countdown_seconds' => (int) $setting->kiosk_countdown_seconds,
                'idle_seconds' => PhotoSession::KIOSK_IDLE_SECONDS,
            ],
        ], 201);
    }

    public function kioskCapture(Request $request, int $locationId, PhotoSession $photoSession): JsonResponse
    {
        $guard = $this->guardKioskSession($request, $locationId, $photoSession);

        if ($guard) {
            return $guard;
        }

        $request->validate(['image' => ['required', 'string']]);

        if ($photoSession->photos()->count() >= PhotoSession::KIOSK_MAX_PHOTOS) {
            return response()->json([
                'success' => false,
                'message' => 'The kiosk takes one photo per session.',
            ], 422);
        }

        $binary = $this->decodeDataUrl($request->input('image'));

        if ($binary === null || $binary === false || strlen($binary) === 0) {
            return response()->json([
                'success' => false,
                'message' => 'The camera image could not be read. Please try again.',
            ], 422);
        }

        $photoSession->loadMissing('location');
        $location = $photoSession->location;
        $now = now();

        $photo = Photo::create([
            'photo_session_id' => $photoSession->id,
            'company_id' => $photoSession->company_id,
            'location_id' => $photoSession->location_id,
            'position' => 1,
            'source' => Photo::SOURCE_KIOSK,
            'processing_status' => Photo::PROCESSING_PENDING,
            'captured_at' => $now,
            'capture_date' => OperatingDay::calendarDateFor($location, $now),
            'operating_day' => OperatingDay::forLocation($location, $now),
        ]);

        $photo->update(['original_path' => $this->processor->storeSource($binary, $photo)]);

        $photoSession->update([
            'status' => PhotoSession::STATUS_PROCESSING,
            'captured_at' => $now,
        ]);

        $photo = $this->processor->process($photo);

        if ($photo->processing_status !== Photo::PROCESSING_READY) {
            $photo->deleteMedia();
            $photo->delete();
            $photoSession->update(['status' => PhotoSession::STATUS_IN_PROGRESS, 'captured_at' => null]);

            $this->deliveries->notifyBackend(
                $location,
                'Kiosk photo could not be processed',
                sprintf('A kiosk capture at %s failed while processing.', $location?->name),
                ['photo_session_id' => $photoSession->id]
            );

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong with that photo. Please try again.',
            ], 422);
        }

        $photoSession->update(['status' => PhotoSession::STATUS_AWAITING_PREVIEW]);

        return response()->json([
            'success' => true,
            'data' => [
                'photo_id' => $photo->id,
                'preview_url' => PhotoMediaController::signedUrl($photo, 'delivery'),
                'capture_date_label' => $photo->capture_date?->format('M j, Y'),
                'status' => PhotoSession::STATUS_AWAITING_PREVIEW,
            ],
        ], 201);
    }

    public function kioskRetake(Request $request, int $locationId, PhotoSession $photoSession): JsonResponse
    {
        $guard = $this->guardKioskSession($request, $locationId, $photoSession);

        if ($guard) {
            return $guard;
        }

        foreach ($photoSession->photos as $photo) {
            $photo->deleteMedia();
            $photo->delete();
        }

        $photoSession->update([
            'status' => PhotoSession::STATUS_IN_PROGRESS,
            'captured_at' => null,
        ]);

        ActivityLog::log(
            'kiosk_photo_retaken',
            'photos',
            'A kiosk visitor discarded the preview and retook the photo',
            null,
            $photoSession->location_id,
            'photo_session',
            $photoSession->id
        );

        return response()->json(['success' => true, 'message' => 'Temporary photo discarded.']);
    }

    public function kioskAccept(Request $request, int $locationId, PhotoSession $photoSession): JsonResponse
    {
        $guard = $this->guardKioskSession($request, $locationId, $photoSession);

        if ($guard) {
            return $guard;
        }

        $request->validate(['slideshow_opt_in' => ['required', 'boolean']]);

        $photo = $photoSession->photos()->ready()->first();

        if (!$photo) {
            return response()->json([
                'success' => false,
                'message' => 'There is no photo to accept yet.',
            ], 422);
        }

        $photoSession->loadMissing('location');
        $location = $photoSession->location;
        $optIn = $request->boolean('slideshow_opt_in');
        $now = now();

        $photoSession->startQrWindow();
        $photoSession->forceFill([
            'status' => PhotoSession::STATUS_READY,
            'slideshow_opt_in' => $optIn,
            'accepted_at' => $now,
        ])->save();

        $queue = SlideshowQueue::activeFor($location, OperatingDay::forLocation($location, $now));

        $photo->update([
            'slideshow_eligible' => $optIn,
            'slideshow_queue_id' => $optIn ? $queue->id : null,
            'slideshow_state' => Photo::SLIDESHOW_VISIBLE,
        ]);

        ActivityLog::log(
            'kiosk_photo_accepted',
            'photos',
            sprintf(
                'A kiosk visitor accepted their photo%s',
                $optIn ? ' and allowed it on the venue slideshow' : ' and kept it off the slideshow'
            ),
            null,
            $location->id,
            'photo_session',
            $photoSession->id,
            ['slideshow_opt_in' => $optIn, 'slideshow_queue_id' => $optIn ? $queue->id : null]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'qr_target_url' => rtrim((string) config('app.frontend_url'), '/') . '/photos/qr/' . $photoSession->qr_token,
                'qr_expires_at' => $photoSession->qr_expires_at?->toIso8601String(),
                'qr_valid_hours' => PhotoSession::QR_VALID_HOURS,
                'idle_seconds' => PhotoSession::KIOSK_IDLE_SECONDS,
            ],
        ]);
    }

    public function kioskTimeout(Request $request, int $locationId, PhotoSession $photoSession): JsonResponse
    {
        $guard = $this->guardKioskSession($request, $locationId, $photoSession, true);

        if ($guard) {
            return $guard;
        }

        ActivityLog::log(
            'kiosk_timeout_reset',
            'photos',
            'A kiosk page reset after 60 seconds without activity',
            null,
            $photoSession->location_id,
            'photo_session',
            $photoSession->id
        );

        if ($photoSession->accepted_at !== null) {
            return response()->json([
                'success' => true,
                'message' => 'Kiosk reset. The QR session stays valid for the rest of its 12-hour window.',
                'data' => ['qr_preserved' => true],
            ]);
        }

        foreach ($photoSession->photos as $photo) {
            $photo->deleteMedia();
            $photo->delete();
        }
        $photoSession->delete();

        return response()->json([
            'success' => true,
            'message' => 'Temporary photo discarded and the kiosk returned to the welcome screen.',
            'data' => ['qr_preserved' => false],
        ]);
    }

    public function slideshowUnlock(Request $request, int $locationId): JsonResponse
    {
        $request->validate(['passcode' => ['required', 'string', 'max:32']]);

        $location = Location::find($locationId);

        if (!$location || !$location->is_active) {
            return $this->denyDevice();
        }

        $setting = LocationPhotoSetting::forLocation($location);

        if (!$setting->slideshow_enabled || !$setting->matchesSlideshowPasscode($request->input('passcode'))) {
            ActivityLog::log(
                'slideshow_passcode_rejected',
                'photos',
                'A display entered an incorrect slideshow passcode',
                null,
                $location->id,
                'location_photo_setting',
                $setting->id
            );

            return $this->denyDevice();
        }

        $issued = $this->devices->issue($location->id, PhotoDeviceTokenService::MODE_SLIDESHOW);

        return response()->json([
            'success' => true,
            'data' => array_merge($issued, ['feed' => $this->slideshowPayload($location, $setting)]),
        ]);
    }

    public function slideshowFeed(Request $request, int $locationId): JsonResponse
    {
        $location = $this->deviceLocation($request, $locationId, PhotoDeviceTokenService::MODE_SLIDESHOW);

        if (!$location) {
            return $this->denyDevice();
        }

        $setting = LocationPhotoSetting::forLocation($location);

        if (!$setting->slideshow_enabled) {
            return response()->json(['success' => false, 'message' => 'The slideshow is turned off for this location.'], 403);
        }

        $setting->forceFill(['slideshow_seen_at' => now()])->save();

        return response()->json([
            'success' => true,
            'data' => $this->slideshowPayload($location, $setting),
        ]);
    }

    public function resolveQr(Request $request, string $qrToken): JsonResponse
    {
        $session = PhotoSession::with('location')->where('qr_token', $qrToken)->first();

        if (!$session) {
            return response()->json(['success' => false, 'message' => 'We could not find that code. Please scan it again, or ask a team member for help.'], 404);
        }

        if (!$session->qrIsActive()) {
            return response()->json([
                'success' => false,
                'state' => 'qr_expired',
                'message' => 'This QR code has expired. QR codes stay active for 12 hours.',
            ], 410);
        }

        $session->increment('qr_scan_count');
        if ($session->first_scanned_at === null) {
            $session->forceFill(['first_scanned_at' => now()])->save();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'mode' => $session->requiresContactBeforeAccess() ? 'contact_required' : 'direct',
                'access_token' => $session->access_token,
                'location_name' => $session->location?->name,
                'photo_count' => $session->photos()->ready()->count(),
                'source' => $session->source,
            ],
        ]);
    }

    public function photoPage(Request $request, string $accessToken): JsonResponse
    {
        $session = PhotoSession::with(['location.company', 'photos'])->where('access_token', $accessToken)->first();

        if (!$session) {
            return response()->json(['success' => false, 'message' => 'We could not find that photo link. Please check it and try again.'], 404);
        }

        if (!$session->accessIsActive()) {
            return response()->json([
                'success' => false,
                'state' => 'access_expired',
                'message' => 'This photo link has expired. Photo pages stay open for 30 days.',
            ], 410);
        }

        // Staff can delete a photo from the daily library. Say so plainly rather than
        // showing the customer an empty gallery under a link that still works.
        if ($session->photos()->ready()->count() === 0 && $session->captured_at !== null) {
            return response()->json([
                'success' => false,
                'state' => 'photos_removed',
                'message' => 'These photos are no longer available. If you think this is a mistake, please contact the venue and they can help.',
            ], 410);
        }

        if ($session->requiresContactBeforeAccess()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'state' => 'contact_required',
                    'location_name' => $session->location?->name,
                    'business_name' => $session->location?->company?->name,
                    'photo_count' => $session->photos()->ready()->count(),
                    'expires_at' => $session->access_expires_at?->toIso8601String(),
                ],
            ]);
        }

        $this->markOpened($session);

        return response()->json([
            'success' => true,
            'data' => $this->photoPagePayload($session),
        ]);
    }

    public function submitKioskContact(Request $request, string $accessToken): JsonResponse
    {
        $session = PhotoSession::with(['location.company', 'photos'])->where('access_token', $accessToken)->first();

        if (!$session) {
            return response()->json(['success' => false, 'message' => 'We could not find that photo link. Please check it and try again.'], 404);
        }

        if (!$session->accessIsActive()) {
            return response()->json([
                'success' => false,
                'state' => 'access_expired',
                'message' => 'This photo link has expired. Photo pages stay open for 30 days.',
            ], 410);
        }

        // Only a kiosk session ever collects contact details. Without this check any
        // access token — including a staff-QR one that asks for nothing — could be used
        // to make the venue send mail and SMS to arbitrary destinations.
        if ($session->source !== PhotoSession::SOURCE_KIOSK
            || $session->delivery_method !== PhotoSession::DELIVERY_KIOSK_QR) {
            return response()->json([
                'success' => false,
                'message' => 'This photo link does not need any details. Your photos are ready to view.',
            ], 422);
        }

        if ($session->kiosk_contact_at !== null) {
            $this->markOpened($session);

            return response()->json([
                'success' => true,
                'message' => 'Your details were already received.',
                'data' => $this->photoPagePayload($session),
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['required', 'string', 'min:10', 'max:40'],
            'marketing_consent' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'Please enter your name.',
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
            'phone.required' => 'Please enter your mobile number.',
            'phone.min' => 'Please enter a valid mobile number.',
        ]);

        if (!$this->deliveries->validPhone($validated['phone'])) {
            return response()->json([
                'success' => false,
                'message' => 'Please enter a valid mobile number.',
                'errors' => ['phone' => ['Please enter a valid mobile number.']],
            ], 422);
        }

        $session->forceFill([
            'kiosk_contact_name' => trim($validated['name']),
            'kiosk_contact_email' => strtolower(trim($validated['email'])),
            'kiosk_contact_phone' => trim($validated['phone']),
            'kiosk_marketing_consent' => $request->boolean('marketing_consent'),
            'kiosk_contact_at' => now(),
        ])->save();

        $this->deliveries->queueKioskDeliveries($session->fresh());

        ActivityLog::log(
            'kiosk_contact_submitted',
            'photos',
            'A kiosk visitor completed the contact form and unlocked their photo',
            null,
            $session->location_id,
            'photo_session',
            $session->id,
            ['marketing_consent' => $request->boolean('marketing_consent')]
        );

        $session = $session->fresh(['location.company', 'photos']);
        $this->markOpened($session);

        return response()->json([
            'success' => true,
            'data' => $this->photoPagePayload($session),
        ]);
    }

    public function downloadPhoto(Request $request, string $accessToken, int $photoId): SymfonyResponse
    {
        $session = PhotoSession::where('access_token', $accessToken)->first();

        if (!$session || !$session->accessIsActive() || $session->requiresContactBeforeAccess()) {
            return response()->json([
                'success' => false,
                'message' => 'This photo is not available.',
            ], 403);
        }

        $photo = $session->photos()->ready()->where('id', $photoId)->first();

        if (!$photo || !$photo->delivery_path) {
            return response()->json(['success' => false, 'message' => 'This photo is not available.'], 404);
        }

        $photo->increment('download_count');

        return Storage::disk(PhotoProcessingService::DISK)->download(
            $photo->delivery_path,
            sprintf('zapzone-%s.jpg', $photo->capture_date?->format('Y-m-d') ?? 'photo')
        );
    }

    protected function photoPagePayload(PhotoSession $session): array
    {
        $tz = OperatingDay::timezoneFor($session->location);
        $photos = $session->photos()->ready()->orderBy('position')->get();

        return [
            'state' => 'ready',
            'location_name' => $session->location?->name,
            'business_name' => $session->location?->company?->name,
            'source' => $session->source,
            'greeting_name' => $session->kiosk_contact_name
                ? trim(explode(' ', trim($session->kiosk_contact_name))[0] ?? '')
                : null,
            'asked_for_details' => $session->source === PhotoSession::SOURCE_KIOSK,
            'photo_date' => ($session->captured_at ?: $session->created_at)->copy()->setTimezone($tz)->format('M j, Y'),
            'expires_at' => $session->access_expires_at?->toIso8601String(),
            'expires_on_label' => $session->access_expires_at?->copy()->setTimezone($tz)->format('M j, Y'),
            'allow_download_all' => $photos->count() > 1,
            'photos' => $photos->map(fn (Photo $photo) => [
                'id' => $photo->id,
                'url' => PhotoMediaController::signedUrl($photo, 'delivery'),
                'width' => $photo->width,
                'height' => $photo->height,
            ])->values(),
        ];
    }

    protected function kioskContextPayload(Location $location, LocationPhotoSetting $setting): array
    {
        $overlay = $this->processor->resolveOverlay($location);

        return [
            'location' => [
                'id' => $location->id,
                'name' => $location->name,
                'city' => $location->city,
                'state' => $location->state,
                'timezone' => OperatingDay::timezoneFor($location),
            ],
            'business_name' => $location->company?->name ?? 'Zap Zone',
            'local_time' => OperatingDay::localNow($location)->toIso8601String(),
            'operating_day' => OperatingDay::forLocation($location),
            'countdown_seconds' => (int) $setting->kiosk_countdown_seconds,
            'idle_seconds' => PhotoSession::KIOSK_IDLE_SECONDS,
            'max_photos' => PhotoSession::KIOSK_MAX_PHOTOS,
            'qr_valid_hours' => PhotoSession::QR_VALID_HOURS,
            'access_valid_days' => PhotoSession::ACCESS_VALID_DAYS,
            'has_overlay' => $overlay !== null,
            'overlay_name' => $overlay?->name,
            'capture_date_label' => OperatingDay::localNow($location)->format($setting->date_format),
            'slideshow_tooltip' => 'When selected, this photo may appear on a public screen at this venue.',
            'consent_text' => 'By taking a photo you agree that ' . ($location->company?->name ?? 'Zap Zone')
                . ' may store it so you can download it, and send it to the contact details you provide on the next screen.',
        ];
    }

    protected function slideshowPayload(Location $location, LocationPhotoSetting $setting): array
    {
        $queue = SlideshowQueue::activeFor($location);
        $photos = $queue->visiblePhotos()->get();

        return [
            'location_name' => $location->name,
            'business_name' => $location->company?->name ?? 'Zap Zone',
            'queue_id' => $queue->id,
            'operating_day' => $queue->operating_day?->toDateString(),
            'is_paused' => $queue->is_paused,
            'duration_seconds' => $setting->slideshow_duration_seconds,
            'closes_at' => $queue->operating_day
                ? OperatingDay::cutoffAfter($location, $queue->operating_day->toDateString())->toIso8601String()
                : null,
            'local_time' => OperatingDay::localNow($location)->toIso8601String(),
            'photos' => $photos->map(fn (Photo $photo) => [
                'id' => $photo->id,
                'url' => PhotoMediaController::signedUrl($photo, 'slideshow'),
                'captured_at' => $photo->captured_at?->toIso8601String(),
            ])->values(),
        ];
    }

    protected function markOpened(PhotoSession $session): void
    {
        PhotoDelivery::where('photo_session_id', $session->id)
            ->whereNull('opened_at')
            ->where('status', PhotoDelivery::STATUS_SENT)
            ->update(['opened_at' => now()]);
    }

    protected function guardKioskSession(
        Request $request,
        int $locationId,
        PhotoSession $session,
        bool $allowAccepted = false
    ): ?JsonResponse {
        $location = $this->deviceLocation($request, $locationId, PhotoDeviceTokenService::MODE_KIOSK);

        if (!$location) {
            return $this->denyDevice();
        }

        if ($session->location_id !== $location->id || $session->source !== PhotoSession::SOURCE_KIOSK) {
            return response()->json(['success' => false, 'message' => 'That kiosk session is no longer available. Please start again.'], 404);
        }

        if (!$this->devices->verifySessionSecret(
            (string) $request->header('X-Kiosk-Session', $request->input('session_secret', '')),
            $session->id,
            (string) $session->created_at
        )) {
            return response()->json(['success' => false, 'message' => 'That kiosk session is no longer available. Please start again.'], 403);
        }

        if ($session->created_at !== null
            && $session->created_at->lessThan(now()->subMinutes(self::KIOSK_SESSION_TTL_MINUTES))) {
            return response()->json([
                'success' => false,
                'message' => 'This kiosk session timed out. Please start again.',
            ], 410);
        }

        if (!$allowAccepted && $session->accepted_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This photo has already been accepted.',
            ], 422);
        }

        return null;
    }

    protected function deviceLocation(Request $request, int $locationId, string $mode): ?Location
    {
        $token = $request->header('X-Photo-Device', $request->input('device_token'));

        if (!$this->devices->verify(is_string($token) ? $token : null, $locationId, $mode)) {
            return null;
        }

        $location = Location::with('company')->find($locationId);

        return $location && $location->is_active ? $location : null;
    }

    protected function denyDevice(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'That passcode is not valid for this location. Please check it with a manager and try again.',
        ], 403);
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
}
