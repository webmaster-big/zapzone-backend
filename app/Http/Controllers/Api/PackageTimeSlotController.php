<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PackageTimeSlot;
use App\Models\Package;
use App\Traits\GeneratesAvailableTimeSlots;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PackageTimeSlotController extends Controller
{
    use GeneratesAvailableTimeSlots;

    /**
     * Cleanup/buffer time in minutes between bookings.
     * This allows time for cleaning the room after a booking ends.
     */
    private const CLEANUP_BUFFER_MINUTES = 15;

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
            'duration' => 'required|numeric|min:0.25',
            'duration_unit' => 'required|in:hours,minutes,hours and minutes',
            'status' => 'sometimes|in:booked,completed,cancelled,no_show',
            'notes' => 'nullable|string',
        ]);

        // Check for booking conflicts
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

        // Check for area group stagger conflicts
        $staggerConflict = $this->checkAreaGroupStaggerConflict(
            $validated['room_id'],
            $validated['booked_date'],
            $validated['time_slot_start']
        );

        if ($staggerConflict) {
            return response()->json([
                'success' => false,
                'message' => 'This time slot conflicts with another booking in the same area. Please choose a different time.',
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
            'duration' => 'sometimes|numeric|min:0.25',
            'duration_unit' => 'sometimes|in:hours,minutes,hours and minutes',
            'status' => 'sometimes|in:booked,completed,cancelled,no_show',
            'notes' => 'nullable|string',
        ]);

        // If time/date is being updated, check for conflicts
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
                    'message' => 'This time slot is already booked for the selected room and date.',
                ], 422);
            }

            // Check for area group stagger conflicts
            $staggerConflict = $this->checkAreaGroupStaggerConflict(
                $packageTimeSlot->room_id,
                $newDate,
                $newStartTime,
                $packageTimeSlot->id // Exclude current record
            );

            if ($staggerConflict) {
                return response()->json([
                    'success' => false,
                    'message' => 'This time slot conflicts with another booking in the same area. Please choose a different time.',
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

}
