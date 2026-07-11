<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Location;
use App\Models\LocationChangeRequest;
use App\Models\Notification;
use App\Models\PackageTimeSlot;
use App\Models\Room;
use App\Models\User;
use App\Services\SmsService;
use App\Traits\GeneratesAvailableTimeSlots;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class LocationChangeRequestController extends Controller
{
    use ScopesByAuthUser;
    use GeneratesAvailableTimeSlots;

    private const RELATIONS = ['booking.customer', 'booking.package', 'fromLocation', 'toLocation', 'room', 'requester', 'reviewer'];

    public function index(Request $request): JsonResponse
    {
        $authUser = $this->resolveAuthUser($request);
        if (!$authUser) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $query = LocationChangeRequest::with(self::RELATIONS)->latest();

        if ($authUser->company_id) {
            $companyId = (int) $authUser->company_id;
            $query->whereHas('toLocation', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            });
        }

        if ($authUser->role !== 'company_admin') {
            if ($authUser->location_id) {
                $locationId = (int) $authUser->location_id;
                $query->where(function ($q) use ($locationId, $authUser) {
                    $q->where('to_location_id', $locationId)
                        ->orWhere('from_location_id', $locationId)
                        ->orWhere('requested_by', $authUser->id);
                });
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request, Booking $booking): JsonResponse
    {
        $validated = $request->validate([
            'to_location_id' => ['required', 'integer', 'exists:locations,id'],
            'room_id' => ['sometimes', 'nullable', 'integer', 'exists:rooms,id'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        if (!$this->authorizeRecordScope($booking)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: this booking is outside your scope',
            ], 403);
        }

        $toLocationId = (int) $validated['to_location_id'];
        if ($toLocationId === (int) $booking->location_id) {
            return response()->json([
                'success' => false,
                'message' => 'The destination location is the same as the current location.',
            ], 422);
        }

        $toLocation = Location::find($toLocationId);
        if ($guard = $this->guardCompanyAccess($request, $toLocation?->company_id)) {
            return $guard;
        }

        $roomId = $validated['room_id'] ?? null;
        if ($roomId) {
            $room = Room::find($roomId);
            if (!$room || (int) $room->location_id !== $toLocationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected room does not belong to the destination location.',
                ], 422);
            }
        }

        $existing = LocationChangeRequest::where('booking_id', $booking->id)
            ->where('status', LocationChangeRequest::STATUS_PENDING)
            ->first();
        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'A pending location change request already exists for this booking.',
                'data' => $existing->load(self::RELATIONS),
            ], 409);
        }

        $lcr = LocationChangeRequest::create([
            'booking_id' => $booking->id,
            'from_location_id' => $booking->location_id,
            'to_location_id' => $toLocationId,
            'room_id' => $roomId,
            'reason' => $validated['reason'] ?? null,
            'status' => LocationChangeRequest::STATUS_PENDING,
            'requested_by' => $this->resolveAuthUser($request)?->id,
        ]);

        $lcr->load(self::RELATIONS);
        try {
            $this->notifyCreated($lcr);
        } catch (\Throwable $e) {
            Log::info('Location change notify (created) skipped', ['request_id' => $lcr->id, 'error' => $e->getMessage()]);
        }

        ActivityLog::log(
            'booking.location_change_requested',
            'booking',
            "Requested moving booking #{$booking->id} to location {$toLocationId}",
            $this->resolveAuthUser($request)?->id,
            $booking->location_id,
            'booking',
            $booking->id,
            [
                'request_id' => $lcr->id,
                'from_location_id' => $booking->location_id,
                'to_location_id' => $toLocationId,
                'room_id' => $roomId,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Location change request submitted.',
            'data' => $lcr,
        ], 201);
    }

    public function approve(Request $request, LocationChangeRequest $locationChangeRequest): JsonResponse
    {
        $request->validate([
            'force' => ['sometimes', 'boolean'],
            'review_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'room_id' => ['sometimes', 'nullable', 'integer', 'exists:rooms,id'],
        ]);

        $authUser = $this->resolveAuthUser($request);
        if (!$authUser || $authUser->role !== 'company_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Only a company admin can review location change requests.',
            ], 403);
        }
        if ($guard = $this->guardCompanyAccess($request, $locationChangeRequest->toLocation?->company_id ?? $locationChangeRequest->fromLocation?->company_id)) {
            return $guard;
        }

        if ($locationChangeRequest->status !== LocationChangeRequest::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'This request has already been reviewed.',
            ], 422);
        }

        $booking = $locationChangeRequest->booking;
        if (!$booking) {
            return response()->json(['success' => false, 'message' => 'Booking no longer exists.'], 404);
        }

        $newLocationId = (int) $locationChangeRequest->to_location_id;
        $requestedRoomId = $request->has('room_id') ? $request->input('room_id') : $locationChangeRequest->room_id;
        if ($requestedRoomId) {
            $requestedRoom = Room::find($requestedRoomId);
            if (!$requestedRoom || (int) $requestedRoom->location_id !== $newLocationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected room does not belong to the destination location.',
                ], 422);
            }
        }
        $roomId = $this->resolveDestinationRoom($booking, $newLocationId, $requestedRoomId);

        if ($booking->room_id && $roomId === null) {
            return response()->json([
                'success' => false,
                'message' => 'This booking is assigned a room. Choose a room at the destination location before approving so it is not left unassigned.',
            ], 422);
        }

        $conflicts = $this->collectRoomConflicts($booking, $roomId);
        if (!empty($conflicts) && !$request->boolean('force')) {
            return response()->json([
                'success' => false,
                'conflict' => true,
                'conflicts' => $conflicts,
                'message' => 'The destination location has a scheduling conflict for this booking\'s date and time.',
            ], 409);
        }

        $fromLocationId = $booking->location_id;
        $reviewerId = $authUser->id;
        $reviewNotes = $request->input('review_notes');
        try {
            DB::transaction(function () use ($booking, $newLocationId, $roomId, $locationChangeRequest, $reviewerId, $reviewNotes) {
                $booking->location_id = $newLocationId;
                $booking->room_id = $roomId;
                $booking->save();
                $this->syncTimeSlot($booking, $roomId);

                $locationChangeRequest->update([
                    'status' => LocationChangeRequest::STATUS_APPROVED,
                    'room_id' => $roomId,
                    'reviewed_by' => $reviewerId,
                    'reviewed_at' => now(),
                    'review_notes' => $reviewNotes,
                ]);
            });
        } catch (QueryException $e) {
            Log::warning('Location change approval failed', ['request_id' => $locationChangeRequest->id, 'error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'conflict' => true,
                'message' => 'The destination room is no longer available for this booking\'s date and time.',
            ], 409);
        }

        $locationChangeRequest->load(self::RELATIONS);
        try {
            $this->notifyReviewed($locationChangeRequest, true);
        } catch (\Throwable $e) {
            Log::info('Location change notify (approved) skipped', ['request_id' => $locationChangeRequest->id, 'error' => $e->getMessage()]);
        }

        ActivityLog::log(
            'booking.location_change_approved',
            'booking',
            "Approved moving booking #{$booking->id} from {$fromLocationId} to {$newLocationId}",
            $this->resolveAuthUser($request)?->id,
            $newLocationId,
            'booking',
            $booking->id,
            [
                'request_id' => $locationChangeRequest->id,
                'from_location_id' => $fromLocationId,
                'to_location_id' => $newLocationId,
                'room_id' => $roomId,
                'forced' => $request->boolean('force') && !empty($conflicts),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Location change approved.',
            'data' => $locationChangeRequest,
            'had_conflict' => !empty($conflicts),
        ]);
    }

    public function reject(Request $request, LocationChangeRequest $locationChangeRequest): JsonResponse
    {
        $validated = $request->validate([
            'review_notes' => ['required', 'string', 'max:1000'],
        ]);

        $authUser = $this->resolveAuthUser($request);
        if (!$authUser || $authUser->role !== 'company_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Only a company admin can review location change requests.',
            ], 403);
        }
        if ($guard = $this->guardCompanyAccess($request, $locationChangeRequest->toLocation?->company_id ?? $locationChangeRequest->fromLocation?->company_id)) {
            return $guard;
        }

        if ($locationChangeRequest->status !== LocationChangeRequest::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'This request has already been reviewed.',
            ], 422);
        }

        $locationChangeRequest->update([
            'status' => LocationChangeRequest::STATUS_REJECTED,
            'review_notes' => $validated['review_notes'],
            'reviewed_by' => $this->resolveAuthUser($request)?->id,
            'reviewed_at' => now(),
        ]);

        $locationChangeRequest->load(self::RELATIONS);
        try {
            $this->notifyReviewed($locationChangeRequest, false);
        } catch (\Throwable $e) {
            Log::info('Location change notify (rejected) skipped', ['request_id' => $locationChangeRequest->id, 'error' => $e->getMessage()]);
        }

        ActivityLog::log(
            'booking.location_change_rejected',
            'booking',
            "Rejected location change request #{$locationChangeRequest->id} for booking #{$locationChangeRequest->booking_id}",
            $this->resolveAuthUser($request)?->id,
            $locationChangeRequest->from_location_id,
            'booking',
            $locationChangeRequest->booking_id,
            [
                'request_id' => $locationChangeRequest->id,
                'reason' => $validated['review_notes'],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Location change request rejected.',
            'data' => $locationChangeRequest,
        ]);
    }

    private function resolveDestinationRoom(Booking $booking, int $newLocationId, $requestedRoomId): ?int
    {
        if ($requestedRoomId) {
            $room = Room::find($requestedRoomId);
            if ($room && (int) $room->location_id === $newLocationId) {
                return (int) $room->id;
            }
            return null;
        }

        $roomId = $booking->room_id;
        if ($roomId) {
            $currentRoom = Room::find($roomId);
            if (!$currentRoom || (int) $currentRoom->location_id !== $newLocationId) {
                return null;
            }
        }
        return $roomId ? (int) $roomId : null;
    }

    private function collectRoomConflicts(Booking $booking, ?int $roomId): array
    {
        if (!$roomId) {
            return [];
        }

        $bookingDate = $booking->booking_date instanceof Carbon
            ? $booking->booking_date->format('Y-m-d')
            : Carbon::parse($booking->booking_date)->format('Y-m-d');
        $startTime = $booking->booking_time instanceof Carbon
            ? $booking->booking_time->format('H:i')
            : substr((string) $booking->booking_time, 0, 5);
        $duration = $booking->duration ?? 2;
        $durationUnit = $booking->duration_unit ?? 'hours';
        $excludeSlotId = PackageTimeSlot::where('booking_id', $booking->id)->value('id');

        $conflicts = [];
        if ($this->checkTimeSlotConflict($roomId, $bookingDate, $startTime, $duration, $durationUnit, $excludeSlotId)) {
            $conflicts[] = ['type' => 'time_slot', 'message' => 'Another booking already occupies this room at the selected date and time.'];
        }
        if ($this->checkAreaGroupStaggerConflict($roomId, $bookingDate, $startTime, $excludeSlotId)) {
            $conflicts[] = ['type' => 'area_stagger', 'message' => 'This time is too close to another booking in the same area group.'];
        }
        if ($this->checkBreakTimeConflict($roomId, $bookingDate, $startTime, $duration, $durationUnit)) {
            $conflicts[] = ['type' => 'break_time', 'message' => 'This time overlaps a scheduled break for the selected room.'];
        }
        return $conflicts;
    }

    private function syncTimeSlot(Booking $booking, ?int $roomId): void
    {
        $timeSlot = PackageTimeSlot::where('booking_id', $booking->id)->first();
        if ($roomId) {
            if ($timeSlot) {
                $timeSlot->update(['room_id' => $roomId]);
            } else {
                PackageTimeSlot::create([
                    'package_id' => $booking->package_id,
                    'booking_id' => $booking->id,
                    'room_id' => $roomId,
                    'customer_id' => $booking->customer_id,
                    'user_id' => $booking->created_by,
                    'booked_date' => $booking->booking_date,
                    'time_slot_start' => $booking->booking_time,
                    'duration' => $booking->duration,
                    'duration_unit' => $booking->duration_unit,
                    'status' => 'booked',
                ]);
            }
        } elseif ($timeSlot) {
            $timeSlot->delete();
        }
    }

    private function notifyCreated(LocationChangeRequest $lcr): void
    {
        $ref = $lcr->booking?->reference_number ?? "#{$lcr->booking_id}";
        $toName = $lcr->toLocation?->name ?? "Location #{$lcr->to_location_id}";
        $fromName = $lcr->fromLocation?->name ?? "Location #{$lcr->from_location_id}";
        $requester = $lcr->requester?->name ?? 'A staff member';

        Notification::create([
            'location_id' => $lcr->to_location_id,
            'type' => 'booking',
            'priority' => 'high',
            'user_id' => $lcr->requested_by,
            'title' => 'Location Change Request',
            'message' => "{$requester} requested moving booking {$ref} from {$fromName} to {$toName}.",
            'status' => 'unread',
            'action_url' => '/location-change-requests',
            'action_text' => 'Review Request',
            'metadata' => [
                'request_id' => $lcr->id,
                'booking_id' => $lcr->booking_id,
                'from_location_id' => $lcr->from_location_id,
                'to_location_id' => $lcr->to_location_id,
            ],
        ]);

        $recipients = $this->staffForLocation($lcr->to_location_id)
            ->merge($this->companyAdmins($lcr->toLocation?->company_id))
            ->unique('id');
        $this->sendStaffAlert(
            $recipients,
            'Location change request',
            "{$requester} requested moving booking {$ref} from {$fromName} to {$toName}. Review it in ZapZone."
        );
    }

    private function notifyReviewed(LocationChangeRequest $lcr, bool $approved): void
    {
        $ref = $lcr->booking?->reference_number ?? "#{$lcr->booking_id}";
        $toName = $lcr->toLocation?->name ?? "Location #{$lcr->to_location_id}";
        $reviewer = $lcr->reviewer?->name ?? 'A reviewer';
        $verb = $approved ? 'approved' : 'rejected';
        $extra = !$approved && $lcr->review_notes ? " Reason: {$lcr->review_notes}" : '';

        Notification::create([
            'location_id' => $lcr->from_location_id,
            'type' => 'booking',
            'priority' => $approved ? 'medium' : 'high',
            'user_id' => $lcr->reviewed_by,
            'title' => $approved ? 'Location Change Approved' : 'Location Change Rejected',
            'message' => "{$reviewer} {$verb} the request to move booking {$ref} to {$toName}.{$extra}",
            'status' => 'unread',
            'action_url' => "/bookings/{$lcr->booking_id}",
            'action_text' => 'View Booking',
            'metadata' => [
                'request_id' => $lcr->id,
                'booking_id' => $lcr->booking_id,
                'status' => $lcr->status,
            ],
        ]);

        $recipients = collect();
        if ($lcr->requester) {
            $recipients->push($lcr->requester);
        }
        $this->sendStaffAlert(
            $recipients,
            "Location change {$verb}",
            "Your request to move booking {$ref} to {$toName} was {$verb}.{$extra}"
        );
    }

    private function staffForLocation($locationId)
    {
        if (!$locationId) {
            return collect();
        }
        return User::where('location_id', $locationId)
            ->whereIn('role', ['location_manager', 'attendant'])
            ->get();
    }

    private function companyAdmins($companyId)
    {
        if (!$companyId) {
            return collect();
        }
        return User::where('company_id', $companyId)
            ->where('role', 'company_admin')
            ->get();
    }

    private function sendStaffAlert($users, string $subject, string $message): void
    {
        $sms = null;
        foreach ($users as $user) {
            if (!empty($user->email)) {
                try {
                    Mail::raw($message, function ($mail) use ($user, $subject) {
                        $mail->to($user->email)->subject($subject);
                    });
                } catch (\Throwable $e) {
                    Log::info('Location change email skipped', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }

            if (!empty($user->phone)) {
                try {
                    $sms = $sms ?? new SmsService();
                    $sms->sendSms($user->phone, $message);
                } catch (\Throwable $e) {
                    Log::info('Location change SMS skipped', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }
        }
    }
}
