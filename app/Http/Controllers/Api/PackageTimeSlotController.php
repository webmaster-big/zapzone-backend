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
     * Get available time slots for a specific package, room, and date (SSE).
     */
    public function getAvailableSlots(int $packageId, int $roomId, string $date)
    {
        Log::info('=== SSE Time Slots Request Started ===', [
            'package_id' => $packageId,
            'room_id' => $roomId,
            'date' => $date,
            'timestamp' => now()->toIso8601String()
        ]);

        return response()->stream(function () use ($packageId, $roomId, $date) {
            // Set headers for SSE
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // Disable nginx buffering

            try {
                $package = Package::findOrFail($packageId);
                
                Log::info('Package found for time slots', [
                    'package_id' => $package->id,
                    'package_name' => $package->name,
                    'time_slot_start' => $package->time_slot_start,
                    'time_slot_end' => $package->time_slot_end,
                    'time_slot_interval' => $package->time_slot_interval,
                    'duration' => $package->duration,
                    'duration_unit' => $package->duration_unit
                ]);

                $lastHash = '';
                $iteration = 0;

                // Keep sending updates every 3 seconds
                while (true) {
                    $iteration++;
                    
                    Log::info("SSE iteration #{$iteration}", [
                        'package_id' => $packageId,
                        'room_id' => $roomId,
                        'date' => $date
                    ]);

                    // Get current booked slots
                    $bookedSlots = PackageTimeSlot::where('room_id', $roomId)
                        ->whereDate('booked_date', $date)
                        ->where('status', 'booked')
                        ->get(['time_slot_start', 'duration', 'duration_unit']);

                    Log::info('Booked slots retrieved', [
                        'count' => $bookedSlots->count(),
                        'slots' => $bookedSlots->toArray()
                    ]);

                    // Generate available slots
                    $availableSlots = $this->generateAvailableSlots(
                        $package->time_slot_start,
                        $package->time_slot_end,
                        $package->time_slot_interval,
                        $package->duration,
                        $package->duration_unit,
                        $bookedSlots
                    );

                    Log::info('Available slots generated', [
                        'count' => count($availableSlots),
                        'slots' => $availableSlots
                    ]);

                    $data = [
                        'available_slots' => $availableSlots,
                        'booked_slots' => $bookedSlots,
                        'timestamp' => now()->toIso8601String(),
                    ];

                    // Check if data has changed
                    $currentHash = md5(json_encode($data));

                    if ($currentHash !== $lastHash) {
                        // Send data only if changed
                        Log::info('Sending SSE data (changed)', [
                            'iteration' => $iteration,
                            'available_count' => count($availableSlots),
                            'booked_count' => $bookedSlots->count()
                        ]);
                        
                        echo "data: " . json_encode($data) . "\n\n";
                        ob_flush();
                        flush();
                        $lastHash = $currentHash;
                    } else {
                        Log::debug('SSE data unchanged', ['iteration' => $iteration]);
                    }

                    // Check if connection is still alive
                    if (connection_aborted()) {
                        Log::info('SSE connection aborted', [
                            'iteration' => $iteration,
                            'package_id' => $packageId,
                            'room_id' => $roomId
                        ]);
                        break;
                    }

                    // Wait 3 seconds before next update
                    sleep(3);
                }
            } catch (\Exception $e) {
                Log::error('Error in SSE time slots stream', [
                    'package_id' => $packageId,
                    'room_id' => $roomId,
                    'date' => $date,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Send error to client
                echo "event: error\n";
                echo "data: " . json_encode([
                    'error' => $e->getMessage(),
                    'message' => 'Failed to load time slots'
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
     * Generate available time slots for a package.
     */
    private function generateAvailableSlots($slotStart, $slotEnd, $interval, $duration, $durationUnit, $bookedSlots)
    {
        Log::info('=== Generating Available Slots ===', [
            'slot_start' => $slotStart,
            'slot_end' => $slotEnd,
            'interval' => $interval,
            'duration' => $duration,
            'duration_unit' => $durationUnit,
            'booked_slots_count' => count($bookedSlots)
        ]);

        $availableSlots = [];
        $currentTime = Carbon::parse($slotStart);
        $endTime = Carbon::parse($slotEnd);

        Log::info('Time boundaries parsed', [
            'current_time' => $currentTime->format('H:i'),
            'end_time' => $endTime->format('H:i')
        ]);

        // Calculate actual duration in minutes for slot generation
        $slotDuration = $durationUnit === 'hours' ? $duration * 60 : $duration;

        Log::info('Slot duration calculated', [
            'slot_duration_minutes' => $slotDuration,
            'interval_minutes' => $interval
        ]);

        $slotIteration = 0;
        while ($currentTime->lt($endTime)) {
            $slotIteration++;
            $slotEndTime = (clone $currentTime)->addMinutes($slotDuration);

            Log::debug("Checking slot #{$slotIteration}", [
                'start' => $currentTime->format('H:i'),
                'end' => $slotEndTime->format('H:i'),
                'fits_in_range' => $slotEndTime->lte($endTime)
            ]);

            // Check if this slot fits before the end time
            if ($slotEndTime->lte($endTime)) {
                $isBooked = false;

                // Check if this slot overlaps with any booked slot
                foreach ($bookedSlots as $bookedIndex => $booked) {
                    $bookedStart = Carbon::parse($booked->time_slot_start);
                    $bookedDuration = $booked->duration_unit === 'hours' ? $booked->duration * 60 : $booked->duration;
                    $bookedEnd = (clone $bookedStart)->addMinutes($bookedDuration);

                    $overlaps = $currentTime->lt($bookedEnd) && $slotEndTime->gt($bookedStart);

                    if ($overlaps) {
                        Log::debug("Slot #{$slotIteration} overlaps with booked slot #{$bookedIndex}", [
                            'slot_start' => $currentTime->format('H:i'),
                            'slot_end' => $slotEndTime->format('H:i'),
                            'booked_start' => $bookedStart->format('H:i'),
                            'booked_end' => $bookedEnd->format('H:i')
                        ]);
                    }

                    if ($overlaps) {
                        $isBooked = true;
                        break;
                    }
                }

                if (!$isBooked) {
                    $slot = [
                        'start_time' => $currentTime->format('H:i'),
                        'end_time' => $slotEndTime->format('H:i'),
                        'duration' => $duration,
                        'duration_unit' => $durationUnit,
                    ];
                    $availableSlots[] = $slot;
                    
                    Log::debug("Slot #{$slotIteration} is available", $slot);
                } else {
                    Log::debug("Slot #{$slotIteration} is booked", [
                        'start' => $currentTime->format('H:i'),
                        'end' => $slotEndTime->format('H:i')
                    ]);
                }
            } else {
                Log::debug("Slot #{$slotIteration} doesn't fit", [
                    'slot_end' => $slotEndTime->format('H:i'),
                    'range_end' => $endTime->format('H:i')
                ]);
            }

            $currentTime->addMinutes($interval);
        }

        Log::info('Slot generation complete', [
            'total_iterations' => $slotIteration,
            'available_slots_count' => count($availableSlots),
            'available_slots' => $availableSlots
        ]);

        return $availableSlots;
    }
}
