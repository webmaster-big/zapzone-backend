<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PackageTimeSlot;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PackageTimeSlotController extends Controller
{
    /**
     * Display a listing of time slots with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PackageTimeSlot::with(['package', 'room', 'booking', 'customer', 'user']);

        // Filter by package
        if ($request->has('package_id')) {
            $query->byPackage($request->package_id);
        }

        // Filter by room
        if ($request->has('room_id')) {
            $query->byRoom($request->room_id);
        }

        // Filter by date
        if ($request->has('date')) {
            $query->byDate($request->date);
        }

        // Filter by customer
        if ($request->has('customer_id')) {
            $query->byCustomer($request->customer_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Sort
        $sortBy = $request->get('sort_by', 'booked_date');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
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

    /**
     * Store a newly created time slot.
     */
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
            'duration' => 'required|integer|min:1',
            'duration_unit' => 'required|in:hours,minutes',
            'status' => 'sometimes|in:booked,completed,cancelled,no_show',
            'notes' => 'nullable|string',
        ]);

        // Check for conflicts
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
                'message' => 'This time slot is already booked for the selected room and date.',
            ], 422);
        }

        $timeSlot = PackageTimeSlot::create($validated);
        $timeSlot->load(['package', 'room', 'booking', 'customer']);

        return response()->json([
            'success' => true,
            'message' => 'Time slot booked successfully',
            'data' => $timeSlot,
        ], 201);
    }

    /**
     * Display the specified time slot.
     */
    public function show(PackageTimeSlot $packageTimeSlot): JsonResponse
    {
        $packageTimeSlot->load(['package', 'room', 'booking', 'customer', 'user']);

        return response()->json([
            'success' => true,
            'data' => $packageTimeSlot,
        ]);
    }

    /**
     * Update the specified time slot.
     */
    public function update(Request $request, PackageTimeSlot $packageTimeSlot): JsonResponse
    {
        $validated = $request->validate([
            'booked_date' => 'sometimes|date',
            'time_slot_start' => 'sometimes|date_format:H:i',
            'duration' => 'sometimes|integer|min:1',
            'duration_unit' => 'sometimes|in:hours,minutes',
            'status' => 'sometimes|in:booked,completed,cancelled,no_show',
            'notes' => 'nullable|string',
        ]);

        // If time/date is being updated, check for conflicts
        if (isset($validated['booked_date']) || isset($validated['time_slot_start']) ||
            isset($validated['duration']) || isset($validated['duration_unit'])) {

            $conflict = $this->checkTimeSlotConflict(
                $packageTimeSlot->room_id,
                $validated['booked_date'] ?? $packageTimeSlot->booked_date,
                $validated['time_slot_start'] ?? $packageTimeSlot->time_slot_start,
                $validated['duration'] ?? $packageTimeSlot->duration,
                $validated['duration_unit'] ?? $packageTimeSlot->duration_unit,
                $packageTimeSlot->id // Exclude current record
            );

            if ($conflict) {
                return response()->json([
                    'success' => false,
                    'message' => 'This time slot is already booked for the selected room and date.',
                ], 422);
            }
        }

        $packageTimeSlot->update($validated);
        $packageTimeSlot->load(['package', 'room', 'booking', 'customer']);

        return response()->json([
            'success' => true,
            'message' => 'Time slot updated successfully',
            'data' => $packageTimeSlot,
        ]);
    }

    /**
     * Remove the specified time slot.
     */
    public function destroy(PackageTimeSlot $packageTimeSlot): JsonResponse
    {
        $timeSlotId = $packageTimeSlot->id;
        $packageId = $packageTimeSlot->package_id;
        $roomId = $packageTimeSlot->room_id;

        $packageTimeSlot->delete();

        // Log time slot deletion
        ActivityLog::log(
            action: 'Package Time Slot Deleted',
            category: 'delete',
            description: "Package time slot was deleted",
            userId: auth()->id(),
            locationId: null,
            entityType: 'package_time_slot',
            entityId: $timeSlotId,
            metadata: ['package_id' => $packageId, 'room_id' => $roomId]
        );

        return response()->json([
            'success' => true,
            'message' => 'Time slot deleted successfully',
        ]);
    }

    /**
     * Get available time slots for a package and date (auto-finds available rooms) - SSE.
     */
    public function getAvailableSlotsAuto(int $packageId, string $date)
    {
        return response()->stream(function () use ($packageId, $date) {
            // Set headers for SSE
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // Disable nginx buffering

            try {
                $package = Package::with('rooms')->findOrFail($packageId);

                if ($package->rooms->isEmpty()) {
                    echo "event: error\n";
                    echo "data: " . json_encode([
                        'error' => 'No rooms available',
                        'message' => 'No rooms available for this package'
                    ]) . "\n\n";
                    ob_flush();
                    flush();
                    return;
                }

                $lastHash = '';

                // Keep sending updates every 3 seconds
                while (true) {
                    $availableSlots = $this->generateAvailableSlotsWithRooms(
                        $package,
                        $date
                    );

                    $data = [
                        'available_slots' => $availableSlots,
                        'package' => [
                            'id' => $package->id,
                            'name' => $package->name,
                            'duration' => $package->duration,
                            'duration_unit' => $package->duration_unit,
                        ],
                        'timestamp' => now()->toIso8601String(),
                    ];

                    // Check if data has changed
                    $currentHash = md5(json_encode($data));

                    if ($currentHash !== $lastHash) {
                        // Send data only if changed
                        echo "data: " . json_encode($data) . "\n\n";
                        ob_flush();
                        flush();
                        $lastHash = $currentHash;
                    }

                    // Check if connection is still alive
                    if (connection_aborted()) {
                        break;
                    }

                    // Wait 3 seconds before next update
                    sleep(3);
                }
            } catch (\Exception $e) {
                Log::error('Error in SSE available slots stream', [
                    'package_id' => $packageId,
                    'date' => $date,
                    'error' => $e->getMessage(),
                ]);

                // Send error to client
                echo "event: error\n";
                echo "data: " . json_encode([
                    'error' => $e->getMessage(),
                    'message' => 'Failed to load available time slots'
                ]) . "\n\n";
                ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Check if a time slot conflicts with existing bookings.
     */
    private function checkTimeSlotConflict($roomId, $date, $startTime, $duration, $durationUnit, $excludeId = null)
    {
        $start = Carbon::parse($date . ' ' . $startTime);
        $end = clone $start;

        if ($durationUnit === 'hours') {
            $end->addHours($duration);
        } else {
            $end->addMinutes($duration);
        }

        $query = PackageTimeSlot::where('room_id', $roomId)
            ->whereDate('booked_date', $date)
            ->where('status', 'booked');

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $existingSlots = $query->get();

        foreach ($existingSlots as $slot) {
            $existingStart = Carbon::parse($date . ' ' . $slot->time_slot_start);
            $existingEnd = clone $existingStart;

            if ($slot->duration_unit === 'hours') {
                $existingEnd->addHours($slot->duration);
            } else {
                $existingEnd->addMinutes($slot->duration);
            }

            // Check for overlap
            if ($start->lt($existingEnd) && $end->gt($existingStart)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find an available room for a specific date and time.
     */
    private function findAvailableRoom($packageId, $date, $startTime, $duration, $durationUnit)
    {
        $package = Package::with('rooms')->find($packageId);

        if (!$package || $package->rooms->isEmpty()) {
            return null;
        }

        // Check each room for availability
        foreach ($package->rooms as $room) {
            $hasConflict = $this->checkTimeSlotConflict(
                $room->id,
                $date,
                $startTime,
                $duration,
                $durationUnit
            );

            if (!$hasConflict) {
                return $room;
            }
        }

        return null;
    }

    /**
     * Generate available time slots considering all rooms for the package.
     * Uses the new availability schedules system.
     */
    private function generateAvailableSlotsWithRooms($package, $date)
    {
        $availableSlots = [];
        
        // Get time slots from availability schedules
        $timeSlots = $package->getTimeSlotsForDate($date);
        
        if (empty($timeSlots)) {
            return [];
        }
        
        $duration = $package->duration;
        $durationUnit = $package->duration_unit;

        // Calculate actual duration in minutes
        $slotDuration = $durationUnit === 'hours' ? $duration * 60 : $duration;

        // Iterate through each time slot from the schedule
        foreach ($timeSlots as $timeSlot) {
            $currentTime = Carbon::parse($date . ' ' . $timeSlot);
            $slotEndTime = (clone $currentTime)->addMinutes($slotDuration);

            // Check if ANY room is available for this slot
            $availableRoom = $this->findAvailableRoom(
                $package->id,
                $date,
                $currentTime->format('H:i'),
                $duration,
                $durationUnit
            );

            if ($availableRoom) {
                $availableSlots[] = [
                    'start_time' => $currentTime->format('H:i'),
                    'end_time' => $slotEndTime->format('H:i'),
                    'duration' => $duration,
                    'duration_unit' => $durationUnit,
                    'room_id' => $availableRoom->id,
                    'room_name' => $availableRoom->name,
                    'available_rooms_count' => $this->countAvailableRooms(
                        $package->id,
                        $date,
                        $currentTime->format('H:i'),
                        $duration,
                        $durationUnit
                    ),
                ];
            }
        }

        return $availableSlots;
    }

    /**
     * Count how many rooms are available for a specific time slot.
     */
    private function countAvailableRooms($packageId, $date, $startTime, $duration, $durationUnit)
    {
        $package = Package::with('rooms')->find($packageId);

        if (!$package || $package->rooms->isEmpty()) {
            return 0;
        }

        $count = 0;
        foreach ($package->rooms as $room) {
            $hasConflict = $this->checkTimeSlotConflict(
                $room->id,
                $date,
                $startTime,
                $duration,
                $durationUnit
            );

            if (!$hasConflict) {
                $count++;
            }
        }

        return $count;
    }

}
