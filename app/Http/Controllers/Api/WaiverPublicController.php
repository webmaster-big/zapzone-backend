<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Waiver;
use App\Models\WaiverBulkInvite;
use App\Models\WaiverInviteRecipient;
use App\Models\WaiverProfile;
use App\Models\WaiverSetting;
use App\Models\WaiverTemplate;
use App\Services\WaiverService;
use App\Support\PreviewImage;
use App\Support\UserAgentParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaiverPublicController extends Controller
{
    private const ADULT_AGE = 18;
    private const LOOKUP_TOKEN_TTL_MINUTES = 30;

    public function __construct(private WaiverService $waivers)
    {
    }

    /**
     * Fetch the form context for a token-addressed waiver link: the frozen legal body,
     * which sections/fields to show, prefilled known info, and whether it's already done.
     * No PII is ever carried in the URL — it is resolved server-side from the token.
     */
    public function show(string $token): JsonResponse
    {
        $waiver = Waiver::with(['template', 'version', 'company', 'location:id,name'])
            ->where('access_token', $token)
            ->first();

        if (!$waiver) {
            return response()->json(['success' => false, 'message' => 'Waiver link not found.'], 404);
        }

        $waiver = $this->waivers->upgradePendingBookingWaiver($waiver);

        if ($waiver->isCompleted()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'status' => 'completed',
                    'message' => 'Your waiver has already been completed for this booking date.',
                    'submitted_at' => $waiver->submitted_at,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formContext($waiver, prefill: true),
        ]);
    }

    /** Status-only endpoint for an already-completed waiver link. */
    public function status(string $token): JsonResponse
    {
        $waiver = Waiver::where('access_token', $token)->first();
        if (!$waiver) {
            return response()->json(['success' => false, 'message' => 'Waiver link not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $waiver->status,
                'completed' => $waiver->isCompleted(),
                'submitted_at' => $waiver->submitted_at,
            ],
        ]);
    }

    /** Submit a token-addressed waiver (post-booking / email / SMS / staff-sent link). */
    public function submit(Request $request, string $token): JsonResponse
    {
        $waiver = Waiver::with('template')->where('access_token', $token)->first();
        if (!$waiver) {
            return response()->json(['success' => false, 'message' => 'Waiver link not found.'], 404);
        }
        if (!$waiver->isCompleted() && $this->waivers->escapeRoomTemplateForPendingWaiver($waiver)) {
            return response()->json([
                'success' => false,
                'message' => 'This waiver has been updated. Please reload the page and review it before signing.',
            ], 409);
        }
        if ($waiver->isCompleted()) {
            return response()->json([
                'success' => false,
                'message' => 'Your waiver has already been completed for this booking date.',
            ], 409);
        }

        $template = $waiver->template;
        $data = $this->validateSubmission($request, $template);

        if ($refused = $this->requireTenDigitPhone($data)) {
            return $refused;
        }

        // duplicate prevention (against OTHER completed waivers for this person/date/template)
        if (!$template->isEscapeRoom()) {
            $duplicate = $this->waivers->findDuplicate(
                $template->id,
                $waiver->selected_date,
                $waiver->customer_id,
                $data['adult_email'] ?? null,
                $data['adult_phone'] ?? null
            );
            [$allowed, $reason] = $this->waivers->evaluateDuplicateRule($template, $duplicate, $waiver->is_manager_assigned);
            if (!$allowed) {
                return response()->json(['success' => false, 'message' => $reason], 409);
            }
        }

        $data = $this->applyGpsGate($data, $template->company_id);
        $ua = (string) $request->userAgent();

        $completed = $this->waivers->completeSubmission($waiver, $data, [
            'ip' => $request->ip(),
            'device' => substr($ua, 0, 255),
            'browser' => UserAgentParser::browser($ua),
            'operating_system' => UserAgentParser::os($ua),
            'user_agent' => $ua,
            'source' => $waiver->source ?: Waiver::SOURCE_CONFIRMATION_EMAIL,
        ]);

        if ($template->isEscapeRoom()) {
            try {
                $this->escapeRooms()->attachSignedBookingWaiver($completed);

                if ($completed->fresh()?->escape_room_session_id) {
                    $this->waivers->generateAndStoreSignedPdf($completed->fresh());
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Signed escape-room waiver could not be linked to its game', [
                    'waiver_id' => $completed->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->notifySigned($completed);

        $ad = $request->boolean('kiosk')
            ? app(\App\Services\WaiverAdService::class)
                ->selectForSubmission($template, $completed->location_id, $completed)
            : null;

        return response()->json([
            'success' => true,
            'message' => 'Waiver completed. Thank you!',
            'data' => [
                'id' => $completed->id,
                'reference_number' => $template->isEscapeRoom() ? null : $completed->reference_number,
                'status' => $completed->status,
                'ad' => $ad,
            ],
        ]);
    }

    /**
     * Kiosk start — a blank form for an active template. No prefill, no saved data;
     * the frontend disables browser autofill per settings.
     */
    public function kioskShow(Request $request, int $templateId): JsonResponse
    {
        $template = WaiverTemplate::active()->find($templateId);
        if (!$template) {
            return response()->json(['success' => false, 'message' => 'Waiver not available.'], 404);
        }
        if ($template->isEscapeRoom()) {
            return $this->escapeRoomOnly();
        }

        $version = $template->versions()->first();
        if (!$version) {
            return response()->json(['success' => false, 'message' => 'Waiver not available.'], 404);
        }

        $settings = WaiverSetting::forCompany($template->company_id);

        // Resolve static tokens that are known at display time (company, location, dates).
        $location = $template->location;

        $requestedLocationId = $request->input('location_id');
        if ($requestedLocationId) {
            $requestedLocation = \App\Models\Location::where('id', $requestedLocationId)
                ->where('company_id', $template->company_id)
                ->first();
            if ($requestedLocation) {
                $location = $requestedLocation;
            }
        }

        $staticVars = $this->waivers->staticContentVariables($template, $location);

        $activity = $this->resolveKioskActivity($request, $template);
        if (!empty($activity['manual_activity_name'])) {
            $staticVars['activity_name'] = $activity['manual_activity_name'];
        }

        $body = $this->waivers->render($version->body_text, $staticVars, true);

        return response()->json([
            'success' => true,
            'data' => [
                'kiosk' => true,
                'template' => $this->templatePayload($template, $version, $staticVars),
                'body' => $body,
                'settings' => [
                    'inactivity_timeout_seconds' => $settings->kiosk_inactivity_timeout_seconds,
                    'disable_autofill' => $settings->kiosk_disable_autofill,
                    'gps_capture_enabled' => (bool) $settings->gps_capture_enabled,
                    'returning_enabled' => (bool) config('waivers.returning_enabled'),
                ],
            ],
        ]);
    }

    /** Kiosk submit — creates a fresh completed waiver (walk-in). */
    public function kioskSubmit(Request $request, int $templateId): JsonResponse
    {
        $template = WaiverTemplate::active()->find($templateId);
        if (!$template) {
            return response()->json(['success' => false, 'message' => 'Waiver not available.'], 404);
        }
        if ($template->isEscapeRoom()) {
            return $this->escapeRoomOnly();
        }
        $version = $template->versions()->first();
        if (!$version) {
            return response()->json(['success' => false, 'message' => 'Waiver not available.'], 404);
        }

        $data = $this->validateSubmission($request, $template);

        $resolvedLocationId = $template->location_id;
        $requestedLocationId = $request->input('location_id');
        if ($requestedLocationId) {
            $requestedLocation = \App\Models\Location::where('id', $requestedLocationId)
                ->where('company_id', $template->company_id)
                ->first();
            if ($requestedLocation) {
                $resolvedLocationId = $requestedLocation->id;
            }
        }

        $activity = $this->resolveKioskActivity($request, $template);

        $profiles = app(\App\Services\WaiverProfileService::class);
        $profile = null;
        if (!empty($data['waiver_profile_id'])) {
            if (!config('waivers.returning_enabled')) {
                \Illuminate\Support\Facades\Log::warning('Returning-customer submit arrived while the feature is disabled', [
                    'waiver_template_id' => $template->id,
                    'company_id' => $template->company_id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Saved records are not available right now. Please continue as a new customer.',
                ], 409);
            }

            $profile = \App\Models\WaiverProfile::where('id', $data['waiver_profile_id'])
                ->where('company_id', $template->company_id)
                ->first();
            if (!$profile) {
                return response()->json(['success' => false, 'message' => 'That saved record could not be found. Please continue as a new customer.'], 404);
            }
            if ($profile->needs_staff_review) {
                return response()->json(['success' => false, 'message' => 'Please see the front desk to continue.'], 409);
            }

            if (!$this->lookupTokenMatches($data['lookup_token'] ?? null, $template, $profile->id)) {
                \Illuminate\Support\Facades\Log::warning('Returning-customer submit rejected; no valid lookup token', [
                    'waiver_profile_id' => $profile->id,
                    'waiver_template_id' => $template->id,
                    'company_id' => $template->company_id,
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Your session timed out. Please look up your number again.',
                ], 419);
            }

            if (!$this->signedNameCarriesSurname($data['typed_legal_name'] ?? null, $profile->last_name)) {
                \Illuminate\Support\Facades\Log::warning('Returning-customer submit rejected; signature name does not match the saved record', [
                    'waiver_profile_id' => $profile->id,
                    'company_id' => $template->company_id,
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'The name you signed does not match the name on this record. Please ask the front desk for help.',
                ], 422);
            }

            $data['adult_first_name'] = $profile->first_name;
            $data['adult_last_name'] = $profile->last_name;
            $data['adult_email'] = $profile->email ?: ($data['adult_email'] ?? null);
            $data['adult_phone'] = $this->phoneFromProfile($profile) ?? ($data['adult_phone'] ?? null);
            $savedDob = $profile->date_of_birth?->toDateString();
            if ($savedDob && substr((string) ($data['adult_dob'] ?? ''), 0, 10) !== $savedDob) {
                \Illuminate\Support\Facades\Log::warning('Returning-customer submit rejected; date of birth does not match the saved record', [
                    'waiver_profile_id' => $profile->id,
                    'company_id' => $template->company_id,
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'The date of birth you entered does not match this record. Please ask the front desk for help.',
                ], 422);
            }

            $data['adult_dob'] = $savedDob ?: ($data['adult_dob'] ?? null);

            if (!$data['adult_dob'] || \Illuminate\Support\Carbon::parse($data['adult_dob'])->age < self::ADULT_AGE) {
                \Illuminate\Support\Facades\Log::warning('Returning-customer submit rejected; saved signer is not an adult', [
                    'waiver_profile_id' => $profile->id,
                    'company_id' => $template->company_id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Please see the front desk to continue.',
                ], 422);
            }
            $cap = max(1, (int) $template->max_minors);

            try {
                $data['minors'] = \Illuminate\Support\Facades\DB::transaction(
                    function () use ($profiles, $profile, $data, $cap) {
                        $minors = $profiles->resolveMinorsForSubmission(
                            $profile,
                            $data['selected_dependent_ids'] ?? [],
                            $data['minors'] ?? []
                        );

                        if (count($minors) > $cap) {
                            throw new \App\Support\WaiverMinorCapExceeded($cap);
                        }

                        return $minors;
                    }
                );
            } catch (\App\Support\WaiverMinorCapExceeded $e) {
                \Illuminate\Support\Facades\Log::info('Returning-customer submit rejected; over the dependent cap', [
                    'waiver_profile_id' => $profile->id,
                    'company_id' => $template->company_id,
                    'cap' => $e->cap,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'This waiver covers up to ' . $e->cap . ' dependent' . ($e->cap === 1 ? '' : 's')
                        . ' per signer. Please ask the front desk for help.',
                ], 422);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Returning-customer dependents could not be resolved', [
                    'waiver_profile_id' => $profile->id,
                    'company_id' => $template->company_id,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'We could not confirm who is participating today. Please ask the front desk for help.',
                ], 422);
            }
        }
        unset($data['waiver_profile_id'], $data['selected_dependent_ids'], $data['lookup_token']);

        if (!$profile && ($refused = $this->requireTenDigitPhone($data))) {
            return $refused;
        }

        $waiver = Waiver::create(array_merge([
            'company_id' => $template->company_id,
            'location_id' => $resolvedLocationId,
            'waiver_template_id' => $template->id,
            'waiver_template_version_id' => $version->id,
            'status' => Waiver::STATUS_PENDING,
            'selected_date' => $request->date('selected_date') ?? now()->toDateString(),
            'source' => Waiver::SOURCE_KIOSK,
            'waiver_profile_id' => $profile?->id,
        ], $activity));

        $data = $this->applyGpsGate($data, $template->company_id);
        $ua = (string) $request->userAgent();

        $completed = $this->waivers->completeSubmission($waiver, $data, [
            'ip' => $request->ip(),
            'device' => 'kiosk',
            'browser' => UserAgentParser::browser($ua),
            'operating_system' => UserAgentParser::os($ua),
            'user_agent' => $ua,
            'source' => Waiver::SOURCE_KIOSK,
        ]);

        $this->notifySigned($completed);

        $ad = app(\App\Services\WaiverAdService::class)
            ->selectForSubmission($template, $resolvedLocationId, $completed);

        return response()->json([
            'success' => true,
            'message' => 'Waiver completed.',
            'data' => [
                'id' => $completed->id,
                'reference_number' => $completed->reference_number,
                'ad' => $ad,
            ],
        ], 201);
    }

    private function lookupTokenKey(string $token): string
    {
        return 'waiver-lookup-token:' . hash('sha256', $token);
    }

    private function issueLookupToken(WaiverTemplate $template, \App\Models\WaiverProfile $profile): string
    {
        $token = bin2hex(random_bytes(32));

        \Illuminate\Support\Facades\Cache::put(
            $this->lookupTokenKey($token),
            ['profile_id' => $profile->id, 'template_id' => $template->id],
            now()->addMinutes(self::LOOKUP_TOKEN_TTL_MINUTES)
        );

        return $token;
    }

    private function lookupTokenMatches(?string $token, WaiverTemplate $template, int $profileId): bool
    {
        if (!filled($token)) {
            return false;
        }

        $stored = \Illuminate\Support\Facades\Cache::get($this->lookupTokenKey($token));

        return is_array($stored)
            && (int) ($stored['profile_id'] ?? 0) === $profileId
            && (int) ($stored['template_id'] ?? 0) === $template->id;
    }

    public function kioskLookup(Request $request, int $templateId): JsonResponse
    {
        if (!config('waivers.returning_enabled')) {
            return response()->json(['success' => false, 'message' => 'Waiver not available.'], 404);
        }

        $template = WaiverTemplate::active()->find($templateId);
        if (!$template || $template->isEscapeRoom()) {
            return response()->json(['success' => false, 'message' => 'Waiver not available.'], 404);
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'last_name' => ['required', 'string', 'max:255'],
        ]);

        $profiles = app(\App\Services\WaiverProfileService::class);

        try {
            $result = $profiles->lookup($template->company_id, $validated['phone'], $validated['last_name']);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Returning-customer lookup errored out', [
                'waiver_template_id' => $template->id,
                'company_id' => $template->company_id,
                'ip' => $request->ip(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'We could not check that number right now. Please try again or continue as a new customer.',
            ], 503);
        }

        if (!empty($result['degraded'])) {
            return response()->json([
                'success' => false,
                'message' => 'We could not check that number right now. Please try again or continue as a new customer.',
            ], 503);
        }

        $payload = ['status' => $result['status']];
        if ($result['status'] === \App\Services\WaiverProfileService::STATUS_FOUND) {
            $payload['profile'] = $profiles->presentForKiosk($result['profile']);
            $payload['source'] = $result['source'] ?? null;
            $payload['lookup_token'] = $this->issueLookupToken($template, $result['profile']);
        }

        return response()->json(['success' => true, 'data' => $payload]);
    }

    public function escapeRoomKiosk(Request $request, int $locationId): JsonResponse
    {
        $service = $this->escapeRooms();
        $location = $service->isEnabled() ? \App\Models\Location::find($locationId) : null;

        if (!$location) {
            return response()->json(['success' => false, 'message' => 'Escape-room check-in is not available here.'], 404);
        }

        $today = $service->today($location);
        $linked = $this->linkedEscapeRoomGame($request, $location);
        $date = $linked['date'] ?? $today;
        $pendingImages = [];
        $roomImage = $this->roomImagePresenter($pendingImages);

        $rooms = $linked
            ? collect([[
                'id' => $linked['room']->id,
                'name' => $linked['room']->name,
                'duration_minutes' => max(1, $linked['room']->getDurationInMinutes()),
                ...$roomImage($linked['room']),
                'times' => $linked['times'],
            ]])
            : $service->roomsAt($location)
                ->filter(fn ($room) => $service->templateForRoom($location, $room) !== null)
                ->map(fn ($room) => [
                    'id' => $room->id,
                    'name' => $room->name,
                    'duration_minutes' => max(1, $room->getDurationInMinutes()),
                    ...$roomImage($room),
                    'times' => $service->guestTimes($location, $room, $request->boolean('recent') ? \App\Services\EscapeRoomSessionService::SUBMIT_GRACE_MINUTES : 0),
                ])
                ->values();

        PreviewImage::generateLater($pendingImages);

        $settings = WaiverSetting::forCompany($location->company_id);

        return response()->json([
            'success' => true,
            'data' => [
                'location' => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'logo_path' => $location->logo_path ?? null,
                ],
                'date' => $date,
                'date_label' => \Illuminate\Support\Carbon::parse($date)->format('l, F j'),
                'today' => $today,
                'ahead' => (bool) ($linked['ahead'] ?? false),
                'rooms' => $rooms,
                'settings' => [
                    'inactivity_timeout_seconds' => $settings->kiosk_inactivity_timeout_seconds,
                    'gps_capture_enabled' => (bool) $settings->gps_capture_enabled,
                ],
            ],
        ]);
    }

    public function escapeRoomForm(Request $request, int $locationId, int $packageId): JsonResponse
    {
        $service = $this->escapeRooms();
        $location = $service->isEnabled() ? \App\Models\Location::find($locationId) : null;
        $room = $location ? $service->findRoom($location, $packageId) : null;
        $template = $room ? $service->templateForRoom($location, $room) : null;
        $version = $template?->versions()->first();

        if (!$template || !$version) {
            return response()->json(['success' => false, 'message' => 'This room is not set up for check-in yet. Please see the front desk.'], 404);
        }

        $staticVars = $this->waivers->staticContentVariables($template, $location);
        $staticVars['activity_name'] = $room->name;
        $settings = WaiverSetting::forCompany($location->company_id);
        $linked = $this->linkedEscapeRoomGame($request, $location);

        return response()->json([
            'success' => true,
            'data' => [
                'kiosk' => true,
                'template' => $this->templatePayload($template, $version, $staticVars),
                'body' => $this->waivers->render($version->body_text, $staticVars, true),
                'room' => [
                    'id' => $room->id,
                    'name' => $room->name,
                    'duration_minutes' => max(1, $room->getDurationInMinutes()),
                ],
                'times' => $linked && (int) $linked['room']->id === (int) $room->id
                    ? $linked['times']
                    : $service->guestTimes($location, $room, $request->boolean('recent') ? \App\Services\EscapeRoomSessionService::SUBMIT_GRACE_MINUTES : 0),
                'settings' => [
                    'inactivity_timeout_seconds' => $settings->kiosk_inactivity_timeout_seconds,
                    'gps_capture_enabled' => (bool) $settings->gps_capture_enabled,
                ],
            ],
        ]);
    }

    public function escapeRoomSubmit(Request $request, int $locationId): JsonResponse
    {
        $service = $this->escapeRooms();
        $location = $service->isEnabled() ? \App\Models\Location::find($locationId) : null;

        if (!$location) {
            return response()->json(['success' => false, 'message' => 'Escape-room check-in is not available here.'], 404);
        }

        try {
            $choice = $service->resolveGuestChoice($location, $request->input('package_id'), $request->input('session_time'), $request->input('session_date'), $request->input('game_signature'));
        } catch (\App\Support\EscapeRoomException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [($e->field ?? 'session_time') => [$e->getMessage()]],
            ], $e->status);
        }

        $template = $choice['template'];
        $room = $choice['room'];
        $version = $template->versions()->first();

        if (!$version) {
            return response()->json(['success' => false, 'message' => 'This room is not set up for check-in yet. Please see the front desk.'], 404);
        }

        if (($request->filled('waiver_template_id') && (int) $request->input('waiver_template_id') !== (int) $template->id)
            || ($request->filled('waiver_template_version') && (int) $request->input('waiver_template_version') !== (int) $version->version)) {
            return response()->json([
                'success' => false,
                'message' => 'This waiver was updated while you were filling it in. Please read it again before signing.',
                'errors' => ['waiver_template_version' => ['This waiver was updated while you were filling it in. Please read it again before signing.']],
            ], 409);
        }

        $data = $this->validateSubmission($request, $template);
        unset($data['waiver_profile_id'], $data['selected_dependent_ids'], $data['lookup_token']);

        if ($refused = $this->requireTenDigitPhone($data)) {
            return $refused;
        }

        $session = $service->sessionFor($room, $choice['date'], $choice['time']);

        $waiver = Waiver::create([
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'waiver_template_id' => $template->id,
            'waiver_template_version_id' => $version->id,
            'status' => Waiver::STATUS_PENDING,
            'selected_date' => $choice['date'],
            'source' => Waiver::SOURCE_KIOSK,
            'package_id' => $room->id,
            'manual_activity_name' => $room->name,
            'booking_id' => $service->soleBookingId((int) $room->id, $choice['date'], $choice['time']),
            'escape_room_session_id' => $session->id,
        ]);

        $data = $this->applyGpsGate($data, $location->company_id);
        $ua = (string) $request->userAgent();

        $completed = $this->waivers->completeSubmission($waiver, $data, [
            'ip' => $request->ip(),
            'device' => 'kiosk',
            'browser' => UserAgentParser::browser($ua),
            'operating_system' => UserAgentParser::os($ua),
            'user_agent' => $ua,
            'source' => Waiver::SOURCE_KIOSK,
        ]);

        $this->notifySigned($completed);

        $ad = app(\App\Services\WaiverAdService::class)
            ->selectForSubmission($template, $location->id, $completed);

        return response()->json([
            'success' => true,
            'message' => 'Waiver completed.',
            'data' => [
                'id' => $completed->id,
                'reference_number' => null,
                'room_name' => $room->name,
                'session_time' => $choice['time'],
                'session_time_label' => $service->timeLabel($choice['time']),
                'ad' => $ad,
            ],
        ], 201);
    }

    private function linkedEscapeRoomGame(Request $request, \App\Models\Location $location): ?array
    {
        if (!$request->filled('date')) {
            return null;
        }

        return $this->escapeRooms()->linkedGame(
            $location,
            $request->query('room'),
            $request->query('time'),
            $request->query('date'),
            $request->query('sig')
        );
    }

    private function escapeRooms(): \App\Services\EscapeRoomSessionService
    {
        return app(\App\Services\EscapeRoomSessionService::class);
    }

    private function roomImagePresenter(array &$pendingImages): \Closure
    {
        return function (\App\Models\Package $room) use (&$pendingImages): array {
            $display = PreviewImage::forDisplay($room->image);

            if ($display['pending']) {
                $pendingImages[] = $display['source'];
            }

            return [
                'image' => $display['image'],
                'image_preview' => $display['preview'],
                'image_pending' => $display['pending'],
            ];
        };
    }

    private function escapeRoomOnly(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'This waiver is signed from the escape-room check-in, where you choose your room and time.',
        ], 404);
    }

    // ---- bulk / chaperone (public, manage-token addressed) ----

    /** Chaperone status view — only invited person + complete/not-complete + resend. */
    public function bulkShow(string $manageToken): JsonResponse
    {
        $invite = WaiverBulkInvite::with(['recipients:id,waiver_bulk_invite_id,name,email,phone,status,resent_count'])
            ->where('manage_token', $manageToken)
            ->first();

        if (!$invite) {
            return response()->json(['success' => false, 'message' => 'Invite not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'chaperone_name' => $invite->chaperone_name,
                'selected_date' => $invite->selected_date,
                'allow_shareable_link' => $invite->allow_shareable_link,
                'shareable_token' => $invite->activeShareableToken(),
                // chaperone sees only limited status info, never full waiver details
                'recipients' => $invite->recipients->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'status' => $r->status,
                    'complete' => $r->status === WaiverInviteRecipient::STATUS_COMPLETE,
                    'resent_count' => $r->resent_count,
                ] + ($r->status === WaiverInviteRecipient::STATUS_COMPLETE ? [] : [
                    'email' => $r->email,
                    'phone' => $r->phone,
                ])),
                'summary' => [
                    'total' => $invite->recipients->count(),
                    'complete' => $invite->recipients->where('status', WaiverInviteRecipient::STATUS_COMPLETE)->count(),
                    'failed' => $invite->recipients->where('status', WaiverInviteRecipient::STATUS_FAILED)->count(),
                ],
            ],
        ]);
    }

    /** Chaperone adds parent/guardian contacts (manual entry or uploaded list). */
    public function bulkAddRecipients(Request $request, string $manageToken): JsonResponse
    {
        $invite = WaiverBulkInvite::where('manage_token', $manageToken)->first();
        if (!$invite) {
            return response()->json(['success' => false, 'message' => 'Invite not found.'], 404);
        }

        $validated = $request->validate([
            'recipients' => 'required|array|min:1|max:500',
            'recipients.*.name' => 'nullable|string|max:255',
            'recipients.*.email' => 'nullable|email|max:255',
            'recipients.*.phone' => 'nullable|string|max:30',
        ]);

        // existing contacts in this batch, to skip duplicate uploads
        $existing = $invite->recipients()->get(['email', 'phone']);
        $knownEmails = $existing->pluck('email')->filter()->map(fn ($email) => strtolower(trim($email)))->all();
        $knownPhones = $existing->pluck('phone')->filter()->all();

        $added = 0;
        $unreachable = 0;
        $duplicates = 0;
        foreach ($validated['recipients'] as $row) {
            if (empty($row['email']) && empty($row['phone'])) {
                continue; // need at least one channel
            }

            $email = filled($row['email'] ?? null) ? trim($row['email']) : null;
            $phone = filled($row['phone'] ?? null) ? WaiverProfile::storedPhoneFor($row['phone']) : null;
            $phone = $phone !== null && str_starts_with($phone, '+') ? null : $phone;

            // A number SmsService cannot dial is not a channel. When it is the only one the row has,
            // the invite would be reported as sent and silently never arrive, so say so instead.
            if (!$email && !$phone) {
                $unreachable++;
                continue;
            }

            if ($email ? in_array(strtolower($email), $knownEmails, true) : in_array($phone, $knownPhones, true)) {
                $duplicates++;
                continue;
            }
            if ($email) {
                $knownEmails[] = strtolower($email);
            }
            if ($phone) {
                $knownPhones[] = $phone;
            }

            $invite->recipients()->create([
                'name' => $row['name'] ?? null,
                'email' => $email,
                'phone' => $phone,
                'status' => WaiverInviteRecipient::STATUS_NOT_SENT,
            ]);
            $added++;
        }

        $message = "{$added} contact(s) added.";
        if ($duplicates > 0) {
            $message .= " {$duplicates} already on your list.";
        }
        if ($unreachable > 0) {
            $message .= " {$unreachable} skipped — a 10-digit phone number is needed when there is no email address.";
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => ['added' => $added, 'skipped' => $unreachable, 'duplicates' => $duplicates, 'total' => $invite->recipients()->count()],
        ]);
    }

    /** Chaperone sends waiver invites to all not-yet-sent recipients. */
    public function bulkSend(string $manageToken): JsonResponse
    {
        $invite = WaiverBulkInvite::with('template')->where('manage_token', $manageToken)->first();
        if (!$invite) {
            return response()->json(['success' => false, 'message' => 'Invite not found.'], 404);
        }

        $recipients = $invite->recipients()
            ->whereIn('status', [WaiverInviteRecipient::STATUS_NOT_SENT, WaiverInviteRecipient::STATUS_FAILED])
            ->get();

        $sent = 0;
        $failed = 0;
        foreach ($recipients as $recipient) {
            $recipient->refresh();
            if ($recipient->status === WaiverInviteRecipient::STATUS_COMPLETE) {
                continue;
            }

            $waiver = $this->waivers->createForBulkRecipient($invite, $recipient);
            if ($waiver->isCompleted()) {
                continue;
            }

            if ($this->recordInviteAttempt($recipient, $this->notifyParentInvite($waiver) > 0)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        $message = "Invites sent to {$sent} recipient(s).";
        if ($failed > 0) {
            $message .= " {$failed} could not be delivered. Use Edit next to their name to fix the email or phone.";
        }

        return response()->json([
            'success' => $sent > 0 || $failed === 0,
            'message' => $message,
            'data' => ['sent' => $sent, 'failed' => $failed],
        ]);
    }

    /** Chaperone resends a single recipient's invite. */
    public function bulkResendRecipient(string $manageToken, int $recipientId): JsonResponse
    {
        $invite = WaiverBulkInvite::with('template')->where('manage_token', $manageToken)->first();
        if (!$invite) {
            return response()->json(['success' => false, 'message' => 'Invite not found.'], 404);
        }

        $recipient = $invite->recipients()->find($recipientId);
        if (!$recipient) {
            return response()->json(['success' => false, 'message' => 'Recipient not found.'], 404);
        }
        if ($recipient->status === WaiverInviteRecipient::STATUS_COMPLETE) {
            return response()->json(['success' => false, 'message' => 'This recipient already completed their waiver.'], 409);
        }

        $waiver = $this->waivers->createForBulkRecipient($invite, $recipient);
        if ($waiver->isCompleted()) {
            return response()->json(['success' => false, 'message' => 'This recipient already completed their waiver.'], 409);
        }

        if (!$this->recordInviteAttempt($recipient, $this->notifyParentInvite($waiver) > 0, resend: true)) {
            return response()->json([
                'success' => false,
                'message' => 'This invite could not be delivered. Use Edit to fix their email or phone.',
            ], 422);
        }

        return response()->json(['success' => true, 'message' => 'Invite resent.']);
    }

    public function bulkUpdateRecipient(Request $request, string $manageToken, int $recipientId): JsonResponse
    {
        $invite = WaiverBulkInvite::with('template')->where('manage_token', $manageToken)->first();
        if (!$invite) {
            return response()->json(['success' => false, 'message' => 'Invite not found.'], 404);
        }

        $recipient = $invite->recipients()->find($recipientId);
        if (!$recipient) {
            return response()->json(['success' => false, 'message' => 'Recipient not found.'], 404);
        }
        if ($recipient->status === WaiverInviteRecipient::STATUS_COMPLETE) {
            return response()->json(['success' => false, 'message' => 'This parent has already signed, so their details can no longer be changed.'], 409);
        }

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
        ]);

        $email = filled($validated['email'] ?? null) ? trim($validated['email']) : null;
        $phone = filled($validated['phone'] ?? null) ? WaiverProfile::storedPhoneFor($validated['phone']) : null;
        $phone = $phone !== null && str_starts_with($phone, '+') ? null : $phone;

        if (filled($validated['phone'] ?? null) && !$phone) {
            return $this->recipientContactRefused('phone', 'Enter a 10-digit US or Canadian mobile number.');
        }
        if (!$email && !$phone) {
            return $this->recipientContactRefused('email', 'Add an email address or a 10-digit phone number.');
        }

        $taken = $invite->recipients()
            ->whereKeyNot($recipient->id)
            ->where(function ($q) use ($email, $phone) {
                if ($email) {
                    $q->whereRaw('LOWER(email) = ?', [strtolower($email)]);
                } else {
                    $q->where('phone', $phone);
                }
            })
            ->exists();
        if ($taken) {
            return $this->recipientContactRefused($email ? 'email' : 'phone', 'That contact is already on your list.');
        }

        $updated = WaiverInviteRecipient::whereKey($recipient->id)
            ->where('status', '!=', WaiverInviteRecipient::STATUS_COMPLETE)
            ->update([
                'name' => filled($validated['name'] ?? null) ? trim($validated['name']) : null,
                'email' => $email,
                'phone' => $phone,
            ]);
        if ($updated === 0) {
            return response()->json(['success' => false, 'message' => 'This parent has already signed, so their details can no longer be changed.'], 409);
        }
        $recipient->refresh();
        $this->waivers->syncPendingBulkWaiver($recipient);

        $waiver = $this->waivers->createForBulkRecipient($invite, $recipient->fresh());
        if ($waiver->isCompleted()) {
            return response()->json(['success' => false, 'message' => 'This parent has already signed, so their details can no longer be changed.'], 409);
        }

        $delivered = $this->recordInviteAttempt($recipient, $this->notifyParentInvite($waiver) > 0, retried: true);

        return response()->json([
            'success' => $delivered,
            'message' => $delivered
                ? 'Saved and sent.'
                : 'Saved, but the invite still could not be delivered. Check the email or phone again.',
            'data' => ['status' => $recipient->fresh()->status],
        ]);
    }

    private function recordInviteAttempt(WaiverInviteRecipient $recipient, bool $delivered, bool $resend = false, bool $retried = false): bool
    {
        $open = WaiverInviteRecipient::whereKey($recipient->id)
            ->where('status', '!=', WaiverInviteRecipient::STATUS_COMPLETE);

        if ($delivered) {
            $open->update(array_merge(
                ['status' => WaiverInviteRecipient::STATUS_SENT, 'last_sent_at' => now()],
                $resend ? ['resent_count' => \Illuminate\Support\Facades\DB::raw('resent_count + 1')] : []
            ));

            return true;
        }

        if ($resend && !$retried) {
            $open->where('status', '!=', WaiverInviteRecipient::STATUS_SENT);
        }
        $open->update(['status' => WaiverInviteRecipient::STATUS_FAILED]);

        return false;
    }

    private function recipientContactRefused(string $field, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422);
    }

    public function groupShow(string $shareableToken): JsonResponse
    {
        $invite = $this->shareableInvite($shareableToken);
        if (!$invite) {
            return $this->groupLinkUnavailable();
        }

        $version = $this->waivers->currentVersion($invite->template);

        $waiver = new Waiver([
            'company_id' => $invite->company_id,
            'location_id' => $invite->location_id,
            'waiver_template_id' => $invite->waiver_template_id,
            'waiver_template_version_id' => $version->id,
            'booking_id' => $invite->booking_id,
            'event_id' => $invite->event_id,
            'bulk_invite_id' => $invite->id,
            'status' => Waiver::STATUS_PENDING,
            'selected_date' => $invite->selected_date,
            'source' => Waiver::SOURCE_BULK_INVITE,
        ]);
        $waiver->setRelation('template', $invite->template);
        $waiver->setRelation('version', $version);

        return response()->json([
            'success' => true,
            'data' => $this->formContext($waiver, prefill: false) + [
                'group' => ['chaperone_name' => $invite->chaperone_name],
            ],
        ]);
    }

    public function groupSubmit(Request $request, string $shareableToken): JsonResponse
    {
        $invite = $this->shareableInvite($shareableToken);
        if (!$invite) {
            return $this->groupLinkUnavailable();
        }

        $template = $invite->template;
        $version = $this->waivers->currentVersion($template);

        if ($request->filled('waiver_template_version') && (int) $request->input('waiver_template_version') !== (int) $version->version) {
            return response()->json([
                'success' => false,
                'message' => 'This waiver was updated while you were filling it in. Please reload the page and read it again before signing.',
            ], 409);
        }

        $data = $this->validateSubmission($request, $template);
        unset($data['waiver_profile_id'], $data['selected_dependent_ids'], $data['lookup_token']);

        if ($refused = $this->requireTenDigitPhone($data)) {
            return $refused;
        }

        $lock = \Illuminate\Support\Facades\Cache::lock(
            'waiver-group-submit:' . $invite->id . ':' . sha1(strtolower((string) ($data['adult_email'] ?? '')) . '|' . ($data['adult_phone'] ?? '')),
            30
        );
        if (!$lock->get()) {
            return response()->json(['success' => false, 'message' => 'Your waiver is already being submitted. Please wait a moment.'], 409);
        }

        try {
            $completed = $this->completeGroupSignature($request, $invite, $version, $data);
        } finally {
            $lock->release();
        }

        if ($completed instanceof JsonResponse) {
            return $completed;
        }

        $this->notifySigned($completed);

        return response()->json([
            'success' => true,
            'message' => 'Waiver completed. Thank you!',
            'data' => [
                'id' => $completed->id,
                'reference_number' => $completed->reference_number,
                'status' => $completed->status,
            ],
        ], 201);
    }

    private function completeGroupSignature(Request $request, WaiverBulkInvite $invite, \App\Models\WaiverTemplateVersion $version, array $data): Waiver|JsonResponse
    {
        $template = $invite->template;

        $duplicate = $this->waivers->findDuplicate(
            $template->id,
            $invite->selected_date,
            null,
            $data['adult_email'] ?? null,
            $data['adult_phone'] ?? null
        );
        [$allowed, $reason] = $this->waivers->evaluateDuplicateRule($template, $duplicate, false);
        if (!$allowed) {
            return response()->json(['success' => false, 'message' => $reason], 409);
        }

        $recipient = $this->invitedRecipientFor($invite, $data['adult_email'] ?? null, $data['adult_phone'] ?? null);
        $waiver = $this->waivers->createForBulkRecipient($invite, $recipient);

        if ($waiver->isCompleted()) {
            return response()->json(['success' => false, 'message' => 'A waiver has already been completed for this booking date.'], 409);
        }

        if ((int) $waiver->waiver_template_version_id !== (int) $version->id) {
            $waiver->forceFill(['waiver_template_version_id' => $version->id])->save();
            $waiver->unsetRelation('version');
        }

        $data = $this->applyGpsGate($data, $template->company_id);
        $ua = (string) $request->userAgent();

        $completed = $this->waivers->completeSubmission($waiver, $data, [
            'ip' => $request->ip(),
            'device' => substr($ua, 0, 255),
            'browser' => UserAgentParser::browser($ua),
            'operating_system' => UserAgentParser::os($ua),
            'user_agent' => $ua,
            'source' => Waiver::SOURCE_BULK_INVITE,
        ]);

        if (!$recipient) {
            $recipient = $invite->recipients()->create([
                'name' => trim($completed->adult_first_name . ' ' . $completed->adult_last_name) ?: null,
                'email' => $completed->adult_email,
                'phone' => $completed->adult_phone,
                'status' => WaiverInviteRecipient::STATUS_COMPLETE,
                'waiver_id' => $completed->id,
            ]);
            $completed->update(['bulk_invite_recipient_id' => $recipient->id]);
        }

        return $completed;
    }

    private function shareableInvite(string $shareableToken): ?WaiverBulkInvite
    {
        $invite = WaiverBulkInvite::with('template')
            ->where('shareable_token', $shareableToken)
            ->where('allow_shareable_link', true)
            ->first();

        return $invite?->template && !$invite->template->isEscapeRoom() ? $invite : null;
    }

    private function groupLinkUnavailable(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'This group waiver link is not available. Please ask your group leader for a new link.',
        ], 404);
    }

    private function invitedRecipientFor(WaiverBulkInvite $invite, ?string $email, ?string $phone): ?WaiverInviteRecipient
    {
        if (!$email && !$phone) {
            return null;
        }

        $open = fn () => $invite->recipients()
            ->where('status', '!=', WaiverInviteRecipient::STATUS_COMPLETE)
            ->orderBy('id');

        if ($email) {
            $byEmail = $open()->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->first();
            if ($byEmail) {
                return $byEmail;
            }
        }

        return $phone
            ? $open()->where(fn ($q) => $q->whereNull('email')->orWhere('email', ''))->where('phone', $phone)->first()
            : null;
    }

    // ---- helpers ----

    /** Fire the parent/guardian waiver invite (email + paired SMS); never blocks. */
    private function notifyParentInvite(Waiver $waiver): int
    {
        try {
            return app(\App\Services\EmailNotificationService::class)
                ->triggerWaiverNotification($waiver, \App\Models\EmailNotification::TRIGGER_WAIVER_PARENT_INVITE);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to send parent waiver invite', [
                'waiver_id' => $waiver->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /** Fire the "waiver signed" confirmation (email + paired SMS); never blocks the response. */
    private function notifySigned(Waiver $waiver): void
    {
        try {
            app(\App\Services\EmailNotificationService::class)
                ->triggerWaiverNotification($waiver, \App\Models\EmailNotification::TRIGGER_WAIVER_SIGNED);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to send waiver-signed notification', [
                'waiver_id' => $waiver->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formContext(Waiver $waiver, bool $prefill): array
    {
        $template = $waiver->template;
        $version = $waiver->version;
        $variables = $this->waivers->buildContentVariables($waiver);

        return [
            'status' => $waiver->status,
            'template' => $this->templatePayload($template, $version, $variables),
            // legal body with whatever is known so far applied (autofill preview)
            'body' => $this->waivers->render(
                $version?->body_text ?? $template->body_text ?? '',
                $variables,
                true
            ),
            'prefill' => $prefill ? $this->waivers->prefillFor($waiver) : [],
            'selected_date' => $waiver->selected_date,
            'settings' => [
                'gps_capture_enabled' => (bool) WaiverSetting::forCompany($waiver->company_id)->gps_capture_enabled,
            ],
            'escape_room' => $this->escapeRoomContextFor($waiver),
        ];
    }

    private function escapeRoomContextFor(Waiver $waiver): ?array
    {
        if (!$waiver->template?->isEscapeRoom()) {
            return null;
        }

        $service = $this->escapeRooms();

        if ($waiver->escape_room_session_id && ($session = $waiver->escapeRoomSession()->with('package:id,name')->first())) {
            return [
                'room_name' => $session->package?->name,
                'date' => $session->dateKey(),
                'time' => $session->timeKey(),
                'time_label' => $service->timeLabel($session->timeKey()),
            ];
        }

        $booking = $waiver->booking()->with('package:id,name')->first();
        $time = $booking ? $service->bookingTime($booking) : null;

        if (!$booking || !$time) {
            return null;
        }

        return [
            'room_name' => $booking->package?->name,
            'date' => $booking->booking_date?->toDateString(),
            'time' => $time,
            'time_label' => $service->timeLabel($time),
        ];
    }

    private function templatePayload(WaiverTemplate $template, $version, array $variables = []): array
    {
        $clauses = $version?->clause_config ?? [];

        return [
            'id' => $template->id,
            'title' => $template->title,
            'kind' => $template->isEscapeRoom() ? WaiverTemplate::KIND_ESCAPE_ROOM : WaiverTemplate::KIND_STANDARD,
            'version' => $version?->version,
            'highlight_points' => $this->waivers->render(
                ($clauses['highlight_points'] ?? null) ?: ($template->highlight_points ?? ''),
                $variables
            ),
            'max_minors' => $template->max_minors,
            'minor_section_enabled' => $template->minor_section_enabled,
            'dob_required' => $template->dob_required,
            'relationship_required' => $template->relationship_required,
            'photo_video_release_enabled' => $template->photo_video_release_enabled,
            'photo_video_release_text' => $this->waivers->render(
                ($clauses['photo_video_release_text'] ?? null)
                    ?: ($template->photo_video_release_text ?: WaiverService::defaultPhotoVideoReleaseText()),
                $variables
            ),
            'electronic_consent_enabled' => $template->electronic_consent_enabled,
            'marketing_consent_enabled' => $template->marketing_consent_enabled,
            'marketing_consent_text' => $template->marketing_consent_text
                ? $this->waivers->render($template->marketing_consent_text, $variables)
                : $template->marketing_consent_text,
            'marketing_helper_text' => $template->marketing_helper_text
                ? $this->waivers->render($template->marketing_helper_text, $variables)
                : $template->marketing_helper_text,
            'clause_config' => $clauses,
        ];
    }

    /** Drop GPS coordinates unless the company has opted into capturing them. */
    private function applyGpsGate(array $data, int $companyId): array
    {
        $settings = WaiverSetting::forCompany($companyId);
        if (!$settings->gps_capture_enabled) {
            unset($data['gps_latitude'], $data['gps_longitude'], $data['gps_accuracy']);
        }
        return $data;
    }

    private function resolveKioskActivity(Request $request, WaiverTemplate $template): array
    {
        $models = [
            'package_id' => \App\Models\Package::class,
            'attraction_id' => \App\Models\Attraction::class,
            'event_id' => \App\Models\Event::class,
        ];

        $links = [];
        $name = null;

        foreach ($models as $field => $model) {
            $id = (int) $request->input($field);
            if (!$id) {
                continue;
            }

            $record = $model::whereHas(
                'location',
                fn ($q) => $q->where('company_id', $template->company_id)
            )->find($id);

            if ($record) {
                $links[$field] = $record->id;
                $name = $name ?? $record->name;
            }
        }

        if ($name !== null) {
            $links['manual_activity_name'] = $name;
        }

        return $links;
    }

    private function signedNameCarriesSurname(?string $fullName, ?string $surname): bool
    {
        foreach (preg_split('/\s+/', trim((string) $fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (\App\Services\WaiverProfileService::nameMatches($surname, $token)) {
                return true;
            }
        }

        return false;
    }

    private function phoneFromProfile(WaiverProfile $profile): ?string
    {
        $raw = trim((string) $profile->phone_raw);
        $stored = $raw !== '' ? WaiverProfile::storedPhoneFor($raw) : null;

        if ($stored !== null && str_starts_with($stored, '+')) {
            return $stored;
        }

        if ($raw !== ''
            && WaiverProfile::needsCountryCode($raw)
            && Waiver::where('waiver_profile_id', $profile->id)->where('adult_phone', $raw)->exists()) {
            return $raw;
        }

        return $profile->phone_digits ?: ($profile->phone_e164 ?: ($raw !== '' ? $raw : null));
    }

    private function requireTenDigitPhone(array &$data): ?JsonResponse
    {
        $given = $data['adult_phone'] ?? null;

        // A North American number is stored as its ten digits, which is what the admin phone search and the
        // duplicate check compare against. Anything else has to be dialable to count, so a visiting family on a
        // foreign number can still sign while "555" still cannot.
        $dialable = WaiverProfile::storedPhoneFor($given);

        if (!$dialable) {
            $message = WaiverProfile::phoneProblem($given);

            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => ['adult_phone' => [$message]],
            ], 422);
        }

        $data['adult_phone'] = $dialable;

        return null;
    }

    private function validateSubmission(Request $request, WaiverTemplate $template): array
    {
        $rules = [
            'adult_first_name' => 'required|string|max:255',
            'adult_last_name' => 'required|string|max:255',
            'adult_email' => 'required|email|max:255',
            'adult_phone' => 'required|string|max:30',
            'waiver_profile_id' => 'nullable|integer',
            'lookup_token' => 'nullable|string|max:128',
            'selected_dependent_ids' => 'nullable|array|max:' . max(1, (int) $template->max_minors),
            'selected_dependent_ids.*' => 'integer',
            'adult_dob' => 'required|date|before_or_equal:' . now()->subYears(self::ADULT_AGE)->toDateString(),
            'relationship' => 'nullable|string|max:100',
            'typed_legal_name' => 'required|string|max:255',
            'signature_image' => 'nullable|string|starts_with:data:image/',
            'device_id' => 'nullable|string|max:64',
            'read_seconds' => 'nullable|integer|min:0',
            'gps_latitude' => 'nullable|numeric|between:-90,90',
            'gps_longitude' => 'nullable|numeric|between:-180,180',
            'gps_accuracy' => 'nullable|numeric|min:0',
            'audit_trail' => 'nullable|array|max:200',
            'audit_trail.*.event' => 'required_with:audit_trail|string|max:100',
            'audit_trail.*.at' => 'nullable|string|max:40',
            'audit_trail.*.meta' => 'nullable|array',
            'agreement_accepted' => 'accepted',
            'photo_video_consent' => 'nullable|boolean',
            'marketing_consent' => 'nullable|boolean',
            'minors' => 'nullable|array|max:' . max(1, (int) $template->max_minors),
            'minors.*.first_name' => 'required_with:minors|string|max:255',
            'minors.*.last_name' => 'required_with:minors|string|max:255',
            'minors.*.date_of_birth' => 'required_with:minors|date|before_or_equal:' . now()->toDateString(),
            'minors.*.relationship' => 'required_with:minors|string|max:100',
        ];

        // electronic consent is required only when the clause is enabled
        $rules['electronic_consent_accepted'] = $template->electronic_consent_enabled ? 'accepted' : 'nullable|boolean';

        $messages = [
            'adult_dob.before_or_equal' => 'Thanks for getting started! Because the signer is under ' . self::ADULT_AGE
                . ', we are not able to accept this signature. Please ask a parent or legal guardian to complete the'
                . ' waiver and add the minor in the Minors section.',
            'minors.*.date_of_birth.required_with' => 'Please add a date of birth for each child.',
            'minors.*.date_of_birth.before_or_equal' => 'A date of birth cannot be in the future.',
            'minors.*.relationship.required_with' => 'Please choose how each child is related to you.',
        ];

        return $request->validate($rules, $messages);
    }
}
