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

    private function slotRelationsFor(Request $request): array
    {
        return app(\App\Services\AddOnRuleService::class)->isStaff($request->user('sanctum'))
            ? ['package', 'room', 'booking', 'customer', 'user']
            : ['package', 'room'];
    }

    public function index(Request $request): JsonResponse
    {
        $query = PackageTimeSlot::with($this->slotRelationsFor($request));

        $authUser = $this->resolveAuthUser($request);
        if ($authUser) {
            if (in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
                $query->whereHas('package', fn($p) => $p->where('location_id', $authUser->location_id));
            } elseif ($authUser->company_id) {
                $query->whereHas('package.location', fn($l) => $l->where('company_id', $authUser->company_id));
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

        if ($request->has('customer_id')) {
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

        $timeSlot = PackageTimeSlot::create($validated);
        $timeSlot->load(array_values(array_diff($this->slotRelationsFor($request), ['user'])));

        return response()->json([
            'success' => true,
            'message' => 'Time slot booked successfully',
            'data' => $timeSlot,
        ], 201);
    }

    public function show(Request $request, PackageTimeSlot $packageTimeSlot): JsonResponse
    {
        $packageTimeSlot->load($this->slotRelationsFor($request));

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

        if (isset($validated['booked_date']) || isset($validated['time_slot_start']) ||
            isset($validated['duration']) || isset($validated['duration_unit'])) {

            $newDate = $validated['booked_date'] ?? $packageTimeSlot->booked_date;
            $newStartTime = $validated['time_slot_start'] ?? $packageTimeSlot->time_slot_start;

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

        $packageTimeSlot->update($validated);
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

    public function destroy(PackageTimeSlot $packageTimeSlot): JsonResponse
    {
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
            locationId: null,
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

    public function getAvailableSlotsAuto(int $packageId, string $date)
    {
        return response()->stream(function () use ($packageId, $date) {
            echo "retry: " . self::SLOTS_RECONNECT_AFTER_MS . "\n\n";

            try {
                $package = Package::with('rooms')->findOrFail($packageId);

                $this->forgetSlotLookups();

                echo "data: " . json_encode([
                    'available_slots' => $this->generateAvailableSlotsWithRooms($package, $date),
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
                    'error' => $e->getMessage(),
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
