<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PackageTimeSlot;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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
        $packageTimeSlot->delete();

        return response()->json([
            'success' => true,
            'message' => 'Time slot deleted successfully',
        ]);
    }

    /**
     * Get available time slots for a specific package, room, and date (SSE).
     */
    public function getAvailableSlots(int $packageId, int $roomId, string $date)
    {
        return response()->stream(function () use ($packageId, $roomId, $date) {
            // Set headers for SSE
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // Disable nginx buffering

            $package = Package::findOrFail($packageId);
            $lastHash = '';

            // Keep sending updates every 3 seconds
            while (true) {
                // Get current booked slots
                $bookedSlots = PackageTimeSlot::where('room_id', $roomId)
                    ->whereDate('booked_date', $date)
                    ->where('status', 'booked')
                    ->get(['time_slot_start', 'duration', 'duration_unit']);

                // Generate available slots
                $availableSlots = $this->generateAvailableSlots(
                    $package->time_slot_start,
                    $package->time_slot_end,
                    $package->time_slot_interval,
                    $package->duration,
                    $package->duration_unit,
                    $bookedSlots
                );

                $data = [
                    'available_slots' => $availableSlots,
                    'booked_slots' => $bookedSlots,
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
     * Generate available time slots for a package.
     */
    private function generateAvailableSlots($slotStart, $slotEnd, $interval, $duration, $durationUnit, $bookedSlots)
    {
        $availableSlots = [];
        $currentTime = Carbon::parse($slotStart);
        $endTime = Carbon::parse($slotEnd);

        // Calculate actual duration in minutes for slot generation
        $slotDuration = $durationUnit === 'hours' ? $duration * 60 : $duration;

        while ($currentTime->lt($endTime)) {
            $slotEndTime = (clone $currentTime)->addMinutes($slotDuration);

            // Check if this slot fits before the end time
            if ($slotEndTime->lte($endTime)) {
                $isBooked = false;

                // Check if this slot overlaps with any booked slot
                foreach ($bookedSlots as $booked) {
                    $bookedStart = Carbon::parse($booked->time_slot_start);
                    $bookedDuration = $booked->duration_unit === 'hours' ? $booked->duration * 60 : $booked->duration;
                    $bookedEnd = (clone $bookedStart)->addMinutes($bookedDuration);

                    if ($currentTime->lt($bookedEnd) && $slotEndTime->gt($bookedStart)) {
                        $isBooked = true;
                        break;
                    }
                }

                if (!$isBooked) {
                    $availableSlots[] = [
                        'start_time' => $currentTime->format('H:i'),
                        'end_time' => $slotEndTime->format('H:i'),
                        'duration' => $duration,
                        'duration_unit' => $durationUnit,
                    ];
                }
            }

            $currentTime->addMinutes($interval);
        }

        return $availableSlots;
    }
}
