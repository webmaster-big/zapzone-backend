<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\EscapeRoomSession;
use App\Models\Location;
use App\Models\User;
use App\Models\Waiver;
use App\Services\EscapeRoomSessionService;
use App\Support\EscapeRoomException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EscapeRoomSessionController extends Controller
{
    use ScopesByAuthUser;

    public function __construct(protected EscapeRoomSessionService $service)
    {
    }

    public function day(Request $request): JsonResponse
    {
        if ($unavailable = $this->unavailable()) {
            return $unavailable;
        }

        $validated = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $location = $this->scopedLocation($request, $validated['location_id']);

        if (!$location instanceof Location) {
            return $location;
        }

        $date = $validated['date'] ?? $this->service->today($location);

        return response()->json([
            'success' => true,
            'data' => $this->service->daySummary($location, $date),
        ]);
    }

    public function rooms(Request $request): JsonResponse
    {
        if ($unavailable = $this->unavailable()) {
            return $unavailable;
        }

        $validated = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
        ]);

        $location = $this->scopedLocation($request, $validated['location_id']);

        if (!$location instanceof Location) {
            return $location;
        }

        return response()->json([
            'success' => true,
            'data' => $this->service->roomsAt($location, false)->map(fn ($room) => [
                'id' => $room->id,
                'name' => $room->name,
                'is_active' => (bool) $room->is_active,
                'duration_minutes' => max(1, $room->getDurationInMinutes()),
                'has_waiver' => $this->service->templateForRoom($location, $room) !== null,
            ])->values(),
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        if ($unavailable = $this->unavailable()) {
            return $unavailable;
        }

        $validated = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'package_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'string', 'max:8'],
        ]);

        $location = $this->scopedLocation($request, $validated['location_id']);

        if (!$location instanceof Location) {
            return $location;
        }

        $room = $this->service->findRoom($location, (int) $validated['package_id'], false);

        if (!$room) {
            return $this->fail('Choose an escape room at this location.');
        }

        $time = $this->service->normalizeTime($validated['time']);

        if ($time === null || !in_array($time, $this->service->timesForDay($room, $validated['date']), true)) {
            return $this->fail('That time is not on the schedule for this room on this day.');
        }

        $session = $this->service->sessionFor($room, $validated['date'], $time, $this->user($request)?->id);

        return $this->detailResponse($session, 201);
    }

    public function show(Request $request, EscapeRoomSession $escapeRoomSession): JsonResponse
    {
        if ($denied = $this->deny($escapeRoomSession)) {
            return $denied;
        }

        return $this->detailResponse($escapeRoomSession);
    }

    public function startPhoto(Request $request, EscapeRoomSession $escapeRoomSession): JsonResponse
    {
        if ($denied = $this->deny($escapeRoomSession)) {
            return $denied;
        }

        $request->validate(['verbal_consent' => ['required', 'accepted']], [
            'verbal_consent.accepted' => 'Confirm that the group agreed to have their photo taken.',
        ]);

        return $this->attempt(fn () => $this->service->ensurePhotoSession(
            $escapeRoomSession,
            $this->user($request),
            $request->boolean('verbal_consent')
        ));
    }

    public function complete(Request $request, EscapeRoomSession $escapeRoomSession): JsonResponse
    {
        if ($denied = $this->deny($escapeRoomSession)) {
            return $denied;
        }

        $validated = $request->validate([
            'escaped' => ['required', 'boolean'],
            'completion_time' => ['nullable', 'string', 'max:12'],
            'without_photo' => ['nullable', 'boolean'],
            'email_players' => ['nullable', 'boolean'],
        ]);

        $escaped = (bool) $validated['escaped'];
        $seconds = $escaped ? $this->service->parseCompletionTime($validated['completion_time'] ?? null) : null;

        if ($escaped && $seconds === null) {
            return $this->fail('Enter the time the group finished in minutes and seconds, for example 47:12.', 'completion_time');
        }

        return $this->attempt(fn () => $this->service->complete(
            $escapeRoomSession,
            $escaped,
            $seconds,
            $this->user($request),
            (bool) ($validated['without_photo'] ?? false),
            (bool) ($validated['email_players'] ?? false)
        ));
    }

    public function bookingGame(Request $request, \App\Models\Booking $booking): JsonResponse
    {
        if ($unavailable = $this->unavailable()) {
            return $unavailable;
        }

        if (!$this->authorizeRecordScope($booking) || $this->denyForeignRecord($booking, 'booking')) {
            return $this->fail('You do not have access to that booking.', null, 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->service->bookingGameSummary($booking),
        ]);
    }

    public function correctResult(Request $request, EscapeRoomSession $escapeRoomSession): JsonResponse
    {
        if ($denied = $this->deny($escapeRoomSession)) {
            return $denied;
        }

        $validated = $request->validate([
            'escaped' => ['required', 'boolean'],
            'completion_time' => ['nullable', 'string', 'max:12'],
        ]);

        $escaped = (bool) $validated['escaped'];
        $seconds = $escaped ? $this->service->parseCompletionTime($validated['completion_time'] ?? null) : null;

        if ($escaped && $seconds === null) {
            return $this->fail('Enter the time the group finished in minutes and seconds, for example 47:12.', 'completion_time');
        }

        return $this->attempt(fn () => $this->service->correctResult($escapeRoomSession, $escaped, $seconds, $this->user($request)));
    }

    public function resendToPlayer(Request $request, EscapeRoomSession $escapeRoomSession, Waiver $waiver): JsonResponse
    {
        if ($denied = $this->deny($escapeRoomSession)) {
            return $denied;
        }

        $validated = $request->validate([
            'email' => ['nullable', 'string', 'max:190'],
        ]);

        try {
            $session = $this->service->resendToPlayer($escapeRoomSession, $waiver, $validated['email'] ?? null, $this->user($request));
        } catch (EscapeRoomException $e) {
            return $this->fail($e->getMessage(), $e->field, $e->status);
        }

        return $this->detailResponse($session);
    }

    public function sendToNew(Request $request, EscapeRoomSession $escapeRoomSession): JsonResponse
    {
        if ($denied = $this->deny($escapeRoomSession)) {
            return $denied;
        }

        return $this->attempt(fn () => $this->service->sendToNewPlayers($escapeRoomSession, $this->user($request)));
    }

    public function moveWaiver(Request $request, EscapeRoomSession $escapeRoomSession, Waiver $waiver): JsonResponse
    {
        if ($denied = $this->deny($escapeRoomSession)) {
            return $denied;
        }

        $validated = $request->validate([
            'package_id' => ['required', 'integer'],
            'time' => ['required', 'string', 'max:8'],
        ]);

        try {
            $this->service->moveWaiver(
                $waiver,
                $escapeRoomSession,
                (int) $validated['package_id'],
                $validated['time'],
                $this->user($request)
            );
        } catch (EscapeRoomException $e) {
            return $this->fail($e->getMessage(), null, $e->status);
        }

        return $this->detailResponse($escapeRoomSession->fresh());
    }

    public function linkBooking(Request $request, EscapeRoomSession $escapeRoomSession, Waiver $waiver): JsonResponse
    {
        if ($denied = $this->deny($escapeRoomSession)) {
            return $denied;
        }

        $validated = $request->validate([
            'booking_id' => ['nullable', 'integer'],
        ]);

        try {
            $this->service->linkWaiverToBooking(
                $waiver,
                $escapeRoomSession,
                isset($validated['booking_id']) ? (int) $validated['booking_id'] : null,
                $this->user($request)
            );
        } catch (EscapeRoomException $e) {
            return $this->fail($e->getMessage(), null, $e->status);
        }

        return $this->detailResponse($escapeRoomSession->fresh());
    }

    public function removeWaiver(Request $request, EscapeRoomSession $escapeRoomSession, Waiver $waiver): JsonResponse
    {
        if ($denied = $this->deny($escapeRoomSession)) {
            return $denied;
        }

        try {
            $this->service->removeWaiver($waiver, $escapeRoomSession, $this->user($request));
        } catch (EscapeRoomException $e) {
            return $this->fail($e->getMessage(), null, $e->status);
        }

        return $this->detailResponse($escapeRoomSession->fresh());
    }

    protected function attempt(callable $action): JsonResponse
    {
        try {
            $session = $action();
        } catch (EscapeRoomException $e) {
            return $this->fail($e->getMessage(), null, $e->status);
        }

        return $this->detailResponse($session);
    }

    protected function detailResponse(EscapeRoomSession $session, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->detail($session),
        ], $status);
    }

    protected function deny(EscapeRoomSession $session): ?JsonResponse
    {
        if ($unavailable = $this->unavailable()) {
            return $unavailable;
        }

        if (!$this->authorizeRecordScope($session)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this escape-room game.',
            ], 403);
        }

        return null;
    }

    protected function unavailable(): ?JsonResponse
    {
        if ($this->service->isEnabled()) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Escape rooms are not set up on this site yet.',
        ], 404);
    }

    protected function fail(string $message, ?string $field = null, int $status = 422): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'message' => $message,
            'errors' => $field ? [$field => [$message]] : null,
        ], fn ($value) => $value !== null), $status);
    }

    protected function user(Request $request): ?User
    {
        return $this->resolveAuthUser($request);
    }
}
