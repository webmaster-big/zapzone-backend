<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\CapturesChangeReason;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\ActivityLog;
use App\Models\PackageTimeSlot;
use App\Models\Package;
use App\Traits\GeneratesAvailableTimeSlots;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PackageTimeSlotController extends Controller
{
    use CapturesChangeReason;
    use GeneratesAvailableTimeSlots;
    use ScopesByAuthUser;

    private const SLOTS_RECONNECT_AFTER_MS = 30000;

    private const GUEST_HIDDEN_FIELDS = ['notes', 'customer_id', 'booking_id', 'user_id'];

    private function staffActor(Request $request): ?\App\Models\User
    {
        $actor = $request->user('sanctum');

        return app(\App\Services\AddOnRuleService::class)->isStaff($actor) ? $actor : null;
    }

    private function slotRelationsFor(Request $request): array
    {
        return $this->staffActor($request)
            ? ['package', 'room', 'booking', 'customer', 'user']
            : ['package', 'room'];
    }

    private function staffMaySee(\App\Models\User $staff, PackageTimeSlot $slot): bool
    {
        $location = $slot->package?->location;

        if (in_array($staff->role, ['location_manager', 'attendant'], true) && $staff->location_id) {
            return $location && (int) $location->id === (int) $staff->location_id;
        }

        return !$staff->company_id || ($location && (int) $location->company_id === (int) $staff->company_id);
    }

    public function index(Request $request): JsonResponse
    {
        $staff = $this->staffActor($request);
        $query = PackageTimeSlot::with($this->slotRelationsFor($request));

        if ($staff) {
            if (in_array($staff->role, ['location_manager', 'attendant'], true) && $staff->location_id) {
                $query->whereHas('package', fn($p) => $p->where('location_id', $staff->location_id));
            } elseif ($staff->company_id) {
                $query->whereHas('package.location', fn($l) => $l->where('company_id', $staff->company_id));
            }
        }

        if ($request->has('package_id')) {
            $query->byPackage($request->package_id);
        }

        if ($request->has('room_id')) {
            $query->byRoom($request->room_id);
        }

        if ($request->has('date')) {
            $query->byDate($request->date);
        }

        if ($staff && $request->has('customer_id')) {
            $query->byCustomer($request->customer_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $sortBy = in_array($request->get('sort_by'), ['booked_date', 'time_slot_start', 'created_at', 'id'], true) ? $request->get('sort_by') : 'booked_date';
        $sortOrder = strtolower((string) $request->get('sort_order', 'asc')) === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortBy, $sortOrder);

        $perPage = max(1, min((int) $request->get('per_page', 15), 100));
        $timeSlots = $query->paginate($perPage);

        if (!$staff) {
            foreach ($timeSlots->items() as $slot) {
                $slot->makeHidden(self::GUEST_HIDDEN_FIELDS);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'time_slots' => $timeSlots->items(),
                'pagination' => [
                    'current_page' => $timeSlots->currentPage(),
                    'last_page' => $timeSlots->lastPage(),
                    'per_page' => $timeSlots->perPage(),
                    'total' => $timeSlots->total(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->staffActor($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Time slots are booked through a booking.',
            ], 403);
        }

        $validated = $request->validate([
            'package_id' => 'required|exists:packages,id',
            'room_id' => 'required|exists:rooms,id',
            'booking_id' => 'required|exists:bookings,id',
            'customer_id' => 'required|exists:customers,id',
            'user_id' => 'nullable|exists:users,id',
            'booked_date' => 'required|date',
            'time_slot_start' => 'required|date_format:H:i',
            'duration' => 'required|numeric|min:0.25',
            'duration_unit' => 'required|in:hours,minutes,hours and minutes',
            'status' => 'sometimes|in:booked,completed,cancelled,no_show',
            'notes' => 'nullable|string',
        ]);

        $blockGuard = app(\App\Services\AvailabilityBlockGuard::class);
        $slotLocationId = (int) Package::query()->whereKey($validated['package_id'])->value('location_id');
        $isStaff = $blockGuard->isStaff($request, $slotLocationId);
        $booking = \App\Models\Booking::find($validated['booking_id']);

        if (! $booking) {
            return response()->json([
                'success' => false,
                'message' => 'That booking could not be found.',
            ], 422);
        }

        $existing = PackageTimeSlot::where('booking_id', $booking->id)->first();

        if ($existing) {
            $existing->load($isStaff ? ['package', 'room', 'booking', 'customer'] : ['package', 'room']);

            return response()->json([
                'success' => true,
                'message' => 'Time slot already booked',
                'data' => $isStaff ? $existing : $existing->makeHidden(['customer_id', 'user_id', 'notes']),
            ]);
        }

        if (! $isStaff) {
            $bookingTime = $booking->booking_time instanceof \DateTimeInterface
                ? $booking->booking_time->format('H:i')
                : substr((string) $booking->booking_time, 0, 5);

            $matchesBooking = (int) $booking->package_id === (int) $validated['package_id']
                && (int) $booking->customer_id === (int) $validated['customer_id']
                && \Carbon\Carbon::parse($booking->booking_date)->toDateString() === \Carbon\Carbon::parse($validated['booked_date'])->toDateString()
                && $bookingTime === $validated['time_slot_start']
                && $booking->status !== 'cancelled'
                && Package::query()->select(['id'])->whereKey($validated['package_id'])->first()?->rooms()->whereKey($validated['room_id'])->exists();

            if (! $matchesBooking) {
                return response()->json([
                    'success' => false,
                    'message' => 'This time slot does not match its booking.',
                ], 422);
            }

            $validated['duration'] = $booking->duration;
            $validated['duration_unit'] = $booking->duration_unit;
            $validated['user_id'] = null;
            $validated['status'] = 'booked';
        }

        $slotBlocks = $blockGuard->forPackageBooking(
            $slotLocationId,
            (int) $validated['package_id'],
            (int) $validated['room_id'],
            \Carbon\Carbon::parse($validated['booked_date'])->toDateString(),
            $validated['time_slot_start'],
            \Carbon\Carbon::parse($validated['time_slot_start'])
                ->addMinutes(\App\Services\AvailabilityBlockGuard::durationMinutes($validated['duration'], $validated['duration_unit']))
                ->format('H:i')
        );

        if ($refusal = $blockGuard->refusal($request, $slotBlocks, 'That space is closed at that time. Please choose another time.', 'This time slot')) {
            return $refusal;
        }

        $conflict = $this->checkTimeSlotConflict(
            $validated['room_id'],
            $validated['booked_date'],
            $validated['time_slot_start'],
            $validated['duration'],
            $validated['duration_unit']
        );

        if ($conflict) {
            return response()->json([
                'success' => false,
                'message' => 'That start time is not available any more. Please choose another time.',
            ], 422);
        }

        $staggerConflict = $this->checkAreaGroupStaggerConflict(
            $validated['room_id'],
            $validated['booked_date'],
            $validated['time_slot_start']
        );

        if ($staggerConflict) {
            return response()->json([
                'success' => false,
                'message' => 'Another group is already starting near that time. Please pick a start time a little earlier or later.',
            ], 422);
        }

        try {
            $timeSlot = PackageTimeSlot::create($validated);
        } catch (\App\Exceptions\SlotTakenException|\Illuminate\Database\UniqueConstraintViolationException) {
            return response()->json([
                'success' => false,
                'message' => 'That start time is not available any more. Please choose another time.',
            ], 422);
        }
        $blockGuard->recordBookedInside($request, $slotBlocks, 'package_time_slot', (int) $timeSlot->id, [
            'label' => 'Space time slot',
            'reference' => '#' . $timeSlot->id,
            'booking_id' => $booking->id,
            'room_id' => (int) $validated['room_id'],
            'booked_date' => \Carbon\Carbon::parse($validated['booked_date'])->toDateString(),
            'time_slot_start' => $validated['time_slot_start'],
        ]);
        $timeSlot->load(array_values(array_diff($this->slotRelationsFor($request), ['user'])));

        return response()->json([
            'success' => true,
            'message' => 'Time slot booked successfully',
            'data' => $isStaff ? $timeSlot : $timeSlot->makeHidden(['customer_id', 'user_id', 'notes']),
        ], 201);
    }

    public function show(Request $request, PackageTimeSlot $packageTimeSlot): JsonResponse
    {
        $staff = $this->staffActor($request);
        $packageTimeSlot->load($this->slotRelationsFor($request));

        if ($staff && !$this->staffMaySee($staff, $packageTimeSlot->loadMissing('package.location'))) {
            return response()->json(['success' => false, 'message' => 'Time slot not found'], 404);
        }

        if (!$staff) {
            $packageTimeSlot->makeHidden(self::GUEST_HIDDEN_FIELDS);
        }

        return response()->json([
            'success' => true,
            'data' => $packageTimeSlot,
        ]);
    }

    public function update(Request $request, PackageTimeSlot $packageTimeSlot): JsonResponse
    {
        $validated = $request->validate([
            'booked_date' => 'sometimes|date',
            'time_slot_start' => 'sometimes|date_format:H:i',
            'duration' => 'sometimes|numeric|min:0.25',
            'duration_unit' => 'sometimes|in:hours,minutes,hours and minutes',
            'status' => 'sometimes|in:booked,completed,cancelled,no_show',
            'notes' => 'nullable|string',
        ]);

        $blockGuard = app(\App\Services\AvailabilityBlockGuard::class);
        $slotLocationId = $this->slotLocationId($packageTimeSlot);

        if (! in_array($slotLocationId, $this->staffLocationIds($request), true)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: that time slot belongs to another location',
            ], 403);
        }

        $slotBlocks = collect();

        if (isset($validated['booked_date']) || isset($validated['time_slot_start']) ||
            isset($validated['duration']) || isset($validated['duration_unit'])) {

            $newDate = $validated['booked_date'] ?? $packageTimeSlot->booked_date;
            $newStartTime = $validated['time_slot_start'] ?? $packageTimeSlot->time_slot_start;
            $blockStart = substr((string) $newStartTime, 0, 5);

            $slotBlocks = $blockGuard->forPackageBooking(
                $slotLocationId,
                $packageTimeSlot->package_id ? (int) $packageTimeSlot->package_id : null,
                $packageTimeSlot->room_id ? (int) $packageTimeSlot->room_id : null,
                \Carbon\Carbon::parse($newDate)->toDateString(),
                $blockStart,
                \Carbon\Carbon::parse($blockStart)
                    ->addMinutes(\App\Services\AvailabilityBlockGuard::durationMinutes(
                        $validated['duration'] ?? $packageTimeSlot->duration,
                        $validated['duration_unit'] ?? $packageTimeSlot->duration_unit
                    ))
                    ->format('H:i')
            );

            if ($refusal = $blockGuard->refusal($request, $slotBlocks, 'That space is closed at that time. Please choose another time.', 'Moving this time slot')) {
                return $refusal;
            }

            $conflict = $this->checkTimeSlotConflict(
                $packageTimeSlot->room_id,
                $newDate,
                $newStartTime,
                $validated['duration'] ?? $packageTimeSlot->duration,
                $validated['duration_unit'] ?? $packageTimeSlot->duration_unit,
                $packageTimeSlot->id // Exclude current record
            );

            if ($conflict) {
                return response()->json([
                    'success' => false,
                    'message' => 'That space is not free then — either another booking runs into it, or it is still being reset from an earlier one.',
                ], 422);
            }

            $staggerConflict = $this->checkAreaGroupStaggerConflict(
                $packageTimeSlot->room_id,
                $newDate,
                $newStartTime,
                $packageTimeSlot->id // Exclude current record
            );

            if ($staggerConflict) {
                return response()->json([
                    'success' => false,
                    'message' => 'Another booking in the same area starts too close to this time. Spaces in one area hold their start times apart.',
                ], 422);
            }
        }

        // Capture the original slot before writing - this row defines when the booking occupies
        // its room, so a change here is a reschedule and belongs in the booking's history.
        $tracked = ['booked_date', 'time_slot_start', 'duration', 'duration_unit', 'status', 'notes'];
        $changes = [];
        foreach ($tracked as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }
            $before = $packageTimeSlot->getOriginal($field);
            $before = $before instanceof \DateTimeInterface ? $before->format('Y-m-d') : $before;
            if ((string) $before !== (string) $validated[$field]) {
                $changes[$field] = ['from' => $before, 'to' => $validated[$field]];
            }
        }

        $changeReason = $this->resolveChangeReason($request, self::CHANGE_GUEST_VISIBLE);

        try {
            $packageTimeSlot->update($validated);
        } catch (\App\Exceptions\SlotTakenException|\Illuminate\Database\UniqueConstraintViolationException) {
            return response()->json([
                'success' => false,
                'message' => 'That space is not free then — another booking already starts at that time.',
            ], 422);
        }
        $blockGuard->recordBookedInside($request, $slotBlocks, 'package_time_slot', (int) $packageTimeSlot->id, [
            'label' => 'Space time slot',
            'reference' => '#' . $packageTimeSlot->id,
            'booking_id' => $packageTimeSlot->booking_id,
            'room_id' => $packageTimeSlot->room_id,
            'moved' => true,
        ]);
        $packageTimeSlot->load(['package', 'room', 'booking', 'customer']);

        if ($changes !== [] && $packageTimeSlot->booking_id) {
            ActivityLog::log(
                action: 'Booking Slot Rescheduled',
                category: 'update',
                description: "Time slot updated for booking #{$packageTimeSlot->booking_id}. Changed: "
                    . implode(', ', array_keys($changes)),
                userId: auth()->id(),
                locationId: $packageTimeSlot->booking?->location_id,
                entityType: 'booking',
                entityId: $packageTimeSlot->booking_id,
                metadata: [
                    'package_time_slot_id' => $packageTimeSlot->id,
                    'reference_number' => $packageTimeSlot->booking?->reference_number,
                    'changes' => $changes,
                    'updated_fields' => array_keys($changes),
                ],
                reason: $changeReason
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Time slot updated successfully',
            'data' => $packageTimeSlot,
        ]);
    }

    private function slotLocationId(PackageTimeSlot $packageTimeSlot): int
    {
        return (int) (\App\Models\Booking::withTrashed()->whereKey($packageTimeSlot->booking_id)->value('location_id')
            ?? \App\Models\Room::query()->whereKey($packageTimeSlot->room_id)->value('location_id')
            ?? Package::query()->whereKey($packageTimeSlot->package_id)->value('location_id'));
    }

    public function destroy(Request $request, PackageTimeSlot $packageTimeSlot): JsonResponse
    {
        $slotLocationId = $this->slotLocationId($packageTimeSlot);

        if (! in_array($slotLocationId, $this->staffLocationIds($request), true)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: that time slot belongs to another location',
            ], 403);
        }

        $timeSlotId = $packageTimeSlot->id;
        $packageId = $packageTimeSlot->package_id;
        $roomId = $packageTimeSlot->room_id;

        $packageTimeSlot->delete();

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Package Time Slot Deleted',
            category: 'delete',
            description: "Package time slot was deleted",
            userId: auth()->id(),
            locationId: $slotLocationId ?: null,
            entityType: 'package_time_slot',
            entityId: $timeSlotId,
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'time_slot_details' => [
                    'time_slot_id' => $timeSlotId,
                    'package_id' => $packageId,
                    'room_id' => $roomId,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Time slot deleted successfully',
        ]);
    }

    public function getAvailableSlotsAuto(Request $request, int $packageId, string $date)
    {
        $includeBlocked = $request->boolean('include_blocked');

        return response()->stream(function () use ($packageId, $date, $includeBlocked) {
            echo "retry: " . self::SLOTS_RECONNECT_AFTER_MS . "\n\n";

            try {
                $package = Package::with('rooms')->findOrFail($packageId);

                $this->forgetSlotLookups();

                echo "data: " . json_encode([
                    'available_slots' => $this->generateAvailableSlotsWithRooms($package, $date, false, $includeBlocked),
                    'package' => [
                        'id' => $package->id,
                        'name' => $package->name,
                        'duration' => $package->duration,
                        'duration_unit' => $package->duration_unit,
                    ],
                    'timestamp' => now()->toIso8601String(),
                ]) . "\n\n";
            } catch (\Exception $e) {
                Log::error('Error in SSE available slots stream', [
                    'package_id' => $packageId,
                    'date' => $date,
                    'error' => $e->getMessage(),
                ]);

                echo "event: error\n";
                echo "data: " . json_encode([
                    'message' => 'Failed to load available time slots'
                ]) . "\n\n";
            }

            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

}
