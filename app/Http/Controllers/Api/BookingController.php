<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\BookingConfirmation;
use App\Models\Booking;
use App\Models\BookingAttraction;
use App\Models\BookingAddOn;
use App\Models\Location;
use App\Models\PackageTimeSlot;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BookingController extends Controller
{
    /**
     * Display a listing of bookings.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Booking::with(['customer', 'package', 'location', 'room', 'creator', 'attractions', 'addOns']);

        // Role-based filtering
        if ($request->has('user_id')) {
            $authUser = User::where('id', $request->user_id)->first();
            // log the auth user info
            if ($authUser->role === 'location_manager') {
                $query->byLocation($authUser->location_id);
            }
        }

        // Filter by location
        if ($request->has('location_id')) {
            $query->byLocation($request->location_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->byStatus($request->status);
        }

        // Filter by customer
        if ($request->has('customer_id')) {
            $query->byCustomer($request->customer_id);
        }

        // booking date
        if ($request->has('booking_date')) {
            $query->byDate($request->booking_date);
        }


        // Search by reference number, customer name, email, phone, or guest info
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference_number', 'like', "%{$search}%")
                  ->orWhere('guest_name', 'like', "%{$search}%")
                  ->orWhere('guest_email', 'like', "%{$search}%")
                  ->orWhere('guest_phone', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($customerQuery) use ($search) {
                      $customerQuery->where('first_name', 'like', "%{$search}%")
                                  ->orWhere('last_name', 'like', "%{$search}%")
                                  ->orWhere('email', 'like', "%{$search}%")
                                  ->orWhere('phone', 'like', "%{$search}%");
                  });
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'booking_date');
        $sortOrder = $request->get('sort_order', 'desc');

        if (in_array($sortBy, ['booking_date', 'booking_time', 'total_amount', 'status', 'created_at'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $perPage = min($request->get('per_page', 15), 100); // Max 100 items per page
        $bookings = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'bookings' => $bookings->items(),
                'pagination' => [
                    'current_page' => $bookings->currentPage(),
                    'last_page' => $bookings->lastPage(),
                    'per_page' => $bookings->perPage(),
                    'total' => $bookings->total(),
                    'from' => $bookings->firstItem(),
                    'to' => $bookings->lastItem(),
                ],
            ],
        ]);
    }


    /**
     * Store a newly created booking.
     */
    public function store(Request $request): JsonResponse
    {
        // Clean undefined values from request
        $data = $request->all();
        foreach ($data as $key => $value) {
            if ($value === 'undefined' || $value === 'null') {
                $data[$key] = null;
            }
        }
        $request->merge($data);

        $validated = $request->validate([
            'customer_id' => 'nullable|required_without:guest_name|exists:customers,id',
            'guest_name' => 'nullable|required_without:customer_id|string|max:255',
            'guest_email' => 'nullable|required_with:guest_name|email|max:255',
            'guest_phone' => 'nullable|string|max:20',
            'package_id' => 'nullable|exists:packages,id',
            'location_id' => 'required|exists:locations,id',
            'room_id' => 'nullable|exists:rooms,id',
            'created_by' => 'nullable|exists:users,id',
            'gift_card_id' => 'nullable|exists:gift_cards,id',
            'promo_id' => 'nullable|exists:promos,id',
            'type' => ['required', Rule::in(['package'])],
            'booking_date' => 'required|date|after_or_equal:today',
            'booking_time' => 'required|date_format:H:i',
            'participants' => 'required|integer|min:1',
            'duration' => 'required|integer|min:1',
            'duration_unit' => ['required', Rule::in(['hours', 'minutes'])],
            'total_amount' => 'required|numeric|min:0',
            'amount_paid' => 'numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'payment_method' => ['nullable', Rule::in(['credit', 'debit', 'cash'])],
            'payment_status' => ['sometimes', Rule::in(['paid', 'partial'])],
            'status' => ['sometimes', Rule::in(['pending', 'confirmed', 'checked-in', 'completed', 'cancelled'])],
            'notes' => 'nullable|string',
            'special_requests' => 'nullable|string',
            'attraction_ids' => 'nullable|array',
            'attraction_ids.*' => 'exists:attractions,id',
            'addon_ids' => 'nullable|array',
            'addon_ids.*' => 'exists:add_ons,id',
            'additional_attractions' => 'nullable|array',
            'additional_attractions.*.attraction_id' => 'required|exists:attractions,id',
            'additional_attractions.*.quantity' => 'required|integer|min:1',
            'additional_attractions.*.price_at_booking' => 'required|numeric|min:0',
            'additional_addons' => 'nullable|array',
            'additional_addons.*.addon_id' => 'required|exists:add_ons,id',
            'additional_addons.*.quantity' => 'required|integer|min:1',
            'additional_addons.*.price_at_booking' => 'required|numeric|min:0',
        ]);

        // Generate unique reference number
        do {
            $validated['reference_number'] = 'BK' . now()->format('Ymd') . strtoupper(Str::random(6));
        } while (Booking::where('reference_number', $validated['reference_number'])->exists());

        // Set payment status based on amount paid
        if (!isset($validated['payment_status'])) {
            $validated['payment_status'] = ($validated['amount_paid'] ?? 0) >= $validated['total_amount'] ? 'paid' : 'partial';
        }

        $booking = Booking::create($validated);

        // Attach attractions with individual quantity and price (new format)
        if (isset($validated['additional_attractions']) && is_array($validated['additional_attractions'])) {
            foreach ($validated['additional_attractions'] as $attraction) {
                BookingAttraction::create([
                    'booking_id' => $booking->id,
                    'attraction_id' => $attraction['attraction_id'],
                    'quantity' => $attraction['quantity'] ?? 1,
                    'price_at_booking' => $attraction['price_at_booking'] ?? 0,
                ]);
            }
        }

        // Attach add-ons with individual quantity and price (new format)
        if (isset($validated['additional_addons']) && is_array($validated['additional_addons'])) {
            foreach ($validated['additional_addons'] as $addon) {
                BookingAddOn::create([
                    'booking_id' => $booking->id,
                    'add_on_id' => $addon['addon_id'],
                    'quantity' => $addon['quantity'] ?? 1,
                    'price_at_booking' => $addon['price_at_booking'] ?? 0,
                ]);
            }
        }

        // Store time slot in package_time_slots table
        if ($validated['room_id']) {
            PackageTimeSlot::create([
                'package_id' => $validated['package_id'],
                'booking_id' => $booking->id,
                'room_id' => $validated['room_id'],
                'customer_id' => $validated['customer_id'] ?? null,
                'user_id' => $validated['created_by'] ?? null,
                'booked_date' => $validated['booking_date'],
                'time_slot_start' => $validated['booking_time'],
                'duration' => $validated['duration'],
                'duration_unit' => $validated['duration_unit'],
                'status' => 'booked',
                'notes' => $validated['notes'] ?? null,
            ]);
        }

        $booking->load(['customer', 'package', 'location', 'room', 'creator', 'attractions', 'addOns']);

        return response()->json([
            'success' => true,
            'message' => 'Booking created successfully',
            'data' => $booking,
        ], 201);
    }

    /**
     * Store QR code for a booking.
     */
    public function storeQrCode(Request $request, Booking $booking): JsonResponse
    {
        $validated = $request->validate([
            'qr_code' => 'required|string', // Base64 encoded QR code image
        ]);

        // Decode base64 QR code
        $qrCodeData = $validated['qr_code'];

        // Remove data:image/png;base64, prefix if present
        if (strpos($qrCodeData, 'data:image') === 0) {
            $qrCodeData = substr($qrCodeData, strpos($qrCodeData, ',') + 1);
        }

        $qrCodeImage = base64_decode($qrCodeData);

        // Create shorter filename using booking ID
        $fileName = 'qr_' . $booking->id . '.png';
        $qrCodePath = 'qrcodes/' . $fileName;

        // Store in public/storage/qrcodes
        $fullPath = storage_path('app/public/' . $qrCodePath);

        // Create directory if not exists
        $dir = dirname($fullPath);
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($fullPath, $qrCodeImage);

        // Update booking with QR code path
        $booking->update(['qr_code_path' => $qrCodePath]);

        // Send confirmation email with QR code
        $emailQrPath = $fullPath;

        // Get recipient email
        $recipientEmail = $booking->customer
            ? $booking->customer->email
            : $booking->guest_email;

        if ($recipientEmail) {
            try {
                $booking->load(['customer', 'package', 'location', 'room', 'creator', 'attractions', 'addOns']);
                Mail::to($recipientEmail)->send(new BookingConfirmation($booking, $emailQrPath));
            } catch (\Exception $e) {
                // Log error but don't fail the QR code storage
                Log::error('Failed to send booking confirmation email: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'QR code stored successfully',
            'data' => [
                'qr_code_path' => $qrCodePath,
                'qr_code_url' => asset('storage/' . $qrCodePath),
            ],
        ]);
    }

    /**
     * Display the specified booking.
     */
    public function show(Request $request, Booking $booking): JsonResponse
    {
        $booking->load(['customer', 'package', 'location', 'room', 'creator', 'giftCard', 'promo', 'attractions', 'addOns', 'payments']);

        if ($request->has('user_id')) {
            $authUser = User::where('id', $request->user_id)->first();
            if ($authUser->role !== "company_admin" && $booking->location_id !== $authUser->location_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this booking',
                ], 403);
            }
        }

        return response()->json([
            'success' => true,
            'data' => $booking,
        ]);
    }

    /**
     * Update the specified booking.
     */
    public function update(Request $request, Booking $booking): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'sometimes|nullable|exists:customers,id',
            'guest_name' => 'sometimes|nullable|string|max:255',
            'guest_email' => 'sometimes|nullable|email|max:255',
            'guest_phone' => 'sometimes|nullable|string|max:20',
            'package_id' => 'sometimes|nullable|exists:packages,id',
            'location_id' => 'sometimes|exists:locations,id',
            'room_id' => 'sometimes|nullable|exists:rooms,id',
            'booking_date' => 'sometimes|date',
            'booking_time' => 'sometimes|date_format:H:i',
            'participants' => 'sometimes|integer|min:1',
            'duration' => 'sometimes|integer|min:1',
            'duration_unit' => ['sometimes', Rule::in(['hours', 'minutes'])],
            'total_amount' => 'sometimes|numeric|min:0',
            'amount_paid' => 'sometimes|numeric|min:0',
            'discount_amount' => 'sometimes|nullable|numeric|min:0',
            'payment_method' => ['sometimes', 'nullable', Rule::in(['credit', 'debit', 'cash'])],
            'payment_status' => ['sometimes', Rule::in(['paid', 'partial'])],
            'status' => ['sometimes', Rule::in(['pending', 'confirmed', 'checked-in', 'completed', 'cancelled'])],
            'notes' => 'sometimes|nullable|string',
            'special_requests' => 'sometimes|nullable|string',
        ]);

        // Update timestamps based on status
        if (isset($validated['status'])) {
            switch ($validated['status']) {
                case 'checked-in':
                    $validated['checked_in_at'] = now();
                    break;
                case 'completed':
                    $validated['completed_at'] = now();
                    break;
                case 'cancelled':
                    $validated['cancelled_at'] = now();
                    break;
            }
        }

        $booking->update($validated);
        $booking->load(['customer', 'package', 'location', 'room', 'creator', 'attractions', 'addOns']);

        return response()->json([
            'success' => true,
            'message' => 'Booking updated successfully',
            'data' => $booking,
        ]);
    }

    /**
     * Cancel the specified booking.
     */
    public function cancel(Booking $booking): JsonResponse
    {
        if (in_array($booking->status, ['completed', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel a ' . $booking->status . ' booking',
            ], 400);
        }

        $booking->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        // Update time slot status
        PackageTimeSlot::where('booking_id', $booking->id)->update([
            'status' => 'cancelled',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Booking cancelled successfully',
            'data' => $booking,
        ]);
    }

    /**
     * Check in the booking.
     */
    public function checkIn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reference_number' => 'required|string|exists:bookings,reference_number',
        ]);

        $booking = Booking::where('reference_number', $validated['reference_number'])->first();

        // Role-based filtering
        if ($request->has('user_id')) {
            $authUser = User::where('id', $request->user_id)->first();
            if ($authUser->role !== "company_admin" && $booking->location_id !== $authUser->location_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to check in this booking',
                ], 403);
            }
        }

        if ($booking->status !== 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'Only confirmed bookings can be checked in',
                'current_status' => $booking->status,
            ], 400);
        }

        $booking->update([
            'status' => 'checked-in',
            'checked_in_at' => now(),
        ]);

        PackageTimeSlot::where('booking_id', $booking->id)->update([
            'status' => 'booked',
        ]);

        $booking->load(['customer', 'package', 'location', 'room', 'creator', 'attractions', 'addOns']);

        return response()->json([
            'success' => true,
            'message' => 'Booking checked in successfully',
            'data' => $booking,
        ]);
    }

    /**
     * Complete the booking.
     */
    public function complete(Booking $booking): JsonResponse
    {
        if ($booking->status !== 'checked-in') {
            return response()->json([
                'success' => false,
                'message' => 'Only checked-in bookings can be completed',
            ], 400);
        }

        $booking->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Update time slot status
        PackageTimeSlot::where('booking_id', $booking->id)->update([
            'status' => 'completed',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Booking completed successfully',
            'data' => $booking,
        ]);
    }

    /**
     * Get bookings by location and date.
     */
    public function getByLocationAndDate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'required|exists:locations,id',
            'date' => 'required|date',
        ]);

        $bookings = Booking::with(['customer', 'package', 'room'])
            ->byLocation($validated['location_id'])
            ->byDate($validated['date'])
            ->orderBy('booking_time')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $bookings,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|max:255',
        ]);

        $bookings = Booking::with(['customer', 'package', 'room'])
            ->where(function ($query) use ($validated) {
                $query->where('reference_number', 'like', "%{$validated['query']}%")
                    ->orWhere('guest_name', 'like', "%{$validated['query']}%")
                    ->orWhere('guest_email', 'like', "%{$validated['query']}%")
                    ->orWhere('guest_phone', 'like', "%{$validated['query']}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($validated) {
                        $customerQuery->where('first_name', 'like', "%{$validated['query']}%")
                            ->orWhere('last_name', 'like', "%{$validated['query']}%")
                            ->orWhere('email', 'like', "%{$validated['query']}%")
                            ->orWhere('phone', 'like', "%{$validated['query']}%");
                    });
            })
            ->orderBy('booking_time')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $bookings,
        ]);
    }
}


