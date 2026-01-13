<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\BookingConfirmation;
use App\Services\GmailApiService;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\BookingAttraction;
use App\Models\BookingAddOn;
use App\Models\Contact;
use App\Models\CustomerNotification;
use App\Models\Notification;
use App\Models\PackageTimeSlot;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Barryvdh\DomPDF\Facade\Pdf;

class BookingController extends Controller
{
    /**
     * Display a listing of bookings.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Booking::with(['customer', 'package', 'location', 'room', 'creator', 'attractions', 'addOns', 'payments']);

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

        // reference number
        if ($request->has('reference_number')) {
            $query->where('reference_number', $request->reference_number);
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

    // customer booking index based on customer id and guest email
    public function customerBookings(Request $request): JsonResponse
    {
        $query = Booking::with(['package', 'location', 'room', 'creator', 'attractions', 'addOns', 'payments']);

        // filter by search by location, reference number, package name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference_number', 'like', "%{$search}%")
                  ->orWhereHas('location', function ($locationQuery) use ($search) {
                      $locationQuery->where('name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('package', function ($packageQuery) use ($search) {
                      $packageQuery->where('name', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->has('guest_email')) {
            $guestEmail = $request->guest_email;
            $query->where(function ($q) use ($guestEmail) {
                $q->where('guest_email', $guestEmail)
                  ->orWhereHas('customer', function ($customerQuery) use ($guestEmail) {
                      $customerQuery->where('email', $guestEmail);
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
            'guest_address' => 'nullable|string|max:255',
            'guest_city' => 'nullable|string|max:100',
            'guest_state' => 'nullable|string|max:50',
            'guest_zip' => 'nullable|string|max:20',
            'guest_country' => 'nullable|string|max:100',
            'package_id' => [
                'nullable',
                'exists:packages,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $package = \App\Models\Package::find($value);
                        if ($package && !$package->is_active) {
                            $fail('The selected package is currently not available for booking.');
                        }
                    }
                },
            ],
            'location_id' => 'required|exists:locations,id',
            'room_id' => 'nullable|exists:rooms,id',
            'created_by' => 'nullable|exists:users,id',
            'gift_card_id' => 'nullable|exists:gift_cards,id',
            'promo_id' => 'nullable|exists:promos,id',
            'promo_code' => 'nullable|string',
            'gift_card_code' => 'nullable|string',
            'type' => ['required', Rule::in(['package'])],
            'booking_date' => 'required|date',
            'booking_time' => 'required|date_format:H:i',
            'participants' => 'required|integer|min:1',
            'duration' => 'required|numeric|min:0.01',
            'duration_unit' => ['required', Rule::in(['hours', 'minutes', 'hours and minutes'])],
            'total_amount' => 'required|numeric|min:0',
            'amount_paid' => 'numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'payment_method' => ['nullable', Rule::in(['card', 'cash', 'paylater'])],
            'payment_status' => ['sometimes', Rule::in(['paid', 'partial', 'pending'])],
            'status' => ['sometimes', Rule::in(['pending', 'confirmed', 'checked-in', 'completed', 'cancelled'])],
            'notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'send_notification' => 'nullable|boolean',
            'special_requests' => 'nullable|string',
            'guest_of_honor_name' => 'nullable|string|max:255',
            'guest_of_honor_age' => 'nullable|integer|min:0|max:150',
            'guest_of_honor_gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'transaction_id' => 'nullable|string|max:255',
            'attraction_ids' => 'nullable|array',
            'attraction_ids.*' => 'exists:attractions,id',
            'addon_ids' => 'nullable|array',
            'addon_ids.*' => 'exists:add_ons,id',
            'additional_attractions' => 'nullable|array',
            'additional_attractions.*.attraction_id' => 'required|exists:attractions,id',
            'additional_attractions.*.quantity' => 'required|integer|min:1',
            'additional_attractions.*.price_at_booking' => 'nullable|numeric|min:0',
            'additional_addons' => 'nullable|array',
            'additional_addons.*.addon_id' => 'required|exists:add_ons,id',
            'additional_addons.*.quantity' => 'required|integer|min:1',
            'additional_addons.*.price_at_booking' => 'nullable|numeric|min:0',
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

        // Create notification for customer
        if ($booking->customer_id) {
            CustomerNotification::create([
                'customer_id' => $booking->customer_id,
                'location_id' => $booking->location_id,
                'type' => 'booking',
                'priority' => 'high',
                'title' => 'Booking Confirmed',
                'message' => "Your booking {$booking->reference_number} has been confirmed for {$booking->booking_date} at {$booking->booking_time}.",
                'status' => 'unread',
                'action_url' => "/bookings/{$booking->id}",
                'action_text' => 'View Booking',
                'metadata' => [
                    'booking_id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'booking_date' => $booking->booking_date,
                    'booking_time' => $booking->booking_time,
                ],
            ]);
        }

        // Create notification for location staff
        $customerName = $booking->customer ? "{$booking->customer->first_name} {$booking->customer->last_name}" : $booking->guest_name;
        Notification::create([
            'location_id' => $booking->location_id,
            'type' => 'booking',
            'priority' => 'medium',
            'user_id' => $booking->created_by ?? auth()->id(),
            'title' => 'New Booking Received',
            'message' => "New booking {$booking->reference_number} from {$customerName} for {$booking->booking_date} at {$booking->booking_time}. Amount: $" . number_format($booking->total_amount, 2),
            'status' => 'unread',
            'action_url' => "/bookings/{$booking->id}",
            'action_text' => 'View Booking',
            'metadata' => [
                'booking_id' => $booking->id,
                'reference_number' => $booking->reference_number,
                'customer_id' => $booking->customer_id,
                'total_amount' => $booking->total_amount,
                'booking_date' => $booking->booking_date,
            ],
        ]);

        // Log booking creation activity
        // if created_by is not null
        if($booking->created_by){
            $booking->load('creator');

        ActivityLog::log(
            action: 'Booking Created',
            category: 'create',
            description: "Booking {$booking->reference_number} created for {$customerName}",
            userId: $booking->created_by,
            locationId: $booking->location_id,
            entityType: 'booking',
            entityId: $booking->id,
            metadata: [
                'reference_number' => $booking->reference_number,
                'customer_id' => $booking->customer_id,
                'total_amount' => $booking->total_amount,
                'booking_date' => $booking->booking_date,
            ]
          );
        }

        // Create or update contact from booking
        try {
            $contactEmail = null;
            $contactData = [];

            // Get email from customer or guest
            if ($booking->customer_id && $booking->customer) {
                $contactEmail = $booking->customer->email;
                $contactData = [
                    'email' => $booking->customer->email,
                    'first_name' => $booking->customer->first_name,
                    'last_name' => $booking->customer->last_name,
                    'phone' => $booking->customer->phone,
                    'address' => $booking->customer->address,
                    'city' => $booking->customer->city,
                    'state' => $booking->customer->state,
                    'zip' => $booking->customer->zip,
                    'country' => $booking->customer->country,
                ];
            } elseif ($booking->guest_email) {
                $contactEmail = $booking->guest_email;
                $contactData = [
                    'email' => $booking->guest_email,
                    'name' => $booking->guest_name,
                    'phone' => $booking->guest_phone,
                    'address' => $booking->guest_address,
                    'city' => $booking->guest_city,
                    'state' => $booking->guest_state,
                    'zip' => $booking->guest_zip,
                    'country' => $booking->guest_country,
                ];
            }

            if ($contactEmail && $booking->location && $booking->location->company_id) {
                Contact::createOrUpdateFromSource(
                    companyId: $booking->location->company_id,
                    data: $contactData,
                    source: 'booking',
                    tags: ['booking', 'customer'],
                    locationId: $booking->location_id,
                    createdBy: $booking->created_by ?? auth()->id()
                );
            }
        } catch (\Exception $e) {
            // Log error but don't fail the booking creation
            Log::warning('Failed to create/update contact from booking', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }


        return response()->json([
            'success' => true,
            'message' => 'Booking created successfully',
            'data' => $booking,
        ], 201);
    }

    // export index functionality with filters similar to index method but without pagination with date range, amount, status range too and other advanced filters
    public function exportIndex(Request $request): JsonResponse
    {
        $query = Booking::with(['customer', 'package', 'location', 'room', 'creator', 'attractions', 'addOns', 'payments']);

        // Role-based filtering
        if ($request->has('user_id')) {
            $authUser = User::where('id', $request->user_id)->first();
            // log the auth user info
            if ($authUser->role === 'location_manager') {
                $query->byLocation($authUser->location_id);
            }
        }

        // Filter by location, what if multiple locations are provided as in checklist
        if ($request->has('location_id')) {
            $locationIds = is_array($request->location_id) ? $request->location_id : explode(',', $request->location_id);
            $query->whereIn('location_id', $locationIds);
        }

        // reference number
        if ($request->has('reference_number')) {
            $query->where('reference_number', $request->reference_number);
        }

        // Filter by status, what if multiple statuses are provided as in checklist
        if ($request->has('status')) {
            $statuses = is_array($request->status) ? $request->status : explode(',', $request->status);
            $query->whereIn('status', $statuses);
        }

        // Filter by customer, in customer selection multiple customers can be selected
        if ($request->has('customer_id')) {
            $customerIds = is_array($request->customer_id) ? $request->customer_id : explode(',', $request->customer_id);
            $query->whereIn('customer_id', $customerIds);
        }

        // booking date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('booking_date', [$request->start_date, $request->end_date]);
        }

        // total amount range
        if ($request->has('min_amount') && $request->has('max_amount')) {
            $query->whereBetween('total_amount', [$request->min_amount, $request->max_amount]);
        }

        // Sort
        $sortBy = $request->get('sort_by', 'booking_date');
        $sortOrder = $request->get('sort_order', 'desc');

        if (in_array($sortBy, ['booking_date', 'booking_time', 'total_amount', 'status', 'created_at'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $bookings = $query->get();

        return response()->json([
            'success' => true,
            'data' => [
                'bookings' => $bookings,
            ],
        ]);
    }

    /**
     * Store QR code for a booking.
     */
    public function storeQrCode(Request $request, Booking $booking): JsonResponse
    {
        Log::info('QR Code storage initiated', [
            'booking_id' => $booking->id,
            'reference_number' => $booking->reference_number,
        ]);

        $validated = $request->validate([
            'qr_code' => 'required|string', // Base64 encoded QR code image
            'send_email' => 'nullable|boolean', // Optional flag to control email sending
        ]);

        // Check if email sending is disabled
        if (isset($validated['send_email']) && $validated['send_email'] === false) {
            Log::info('Email sending skipped per user request', [
                'booking_id' => $booking->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Booking created successfully. Email sending skipped.',
            ]);
        }

        // Decode base64 QR code
        $qrCodeData = $validated['qr_code'];

        // Remove data:image/png;base64, prefix if present
        if (strpos($qrCodeData, 'data:image') === 0) {
            $qrCodeData = substr($qrCodeData, strpos($qrCodeData, ',') + 1);
        }

        $qrCodeImage = base64_decode($qrCodeData);

        if (!$qrCodeImage) {
            Log::error('Failed to decode QR code base64 data', [
                'booking_id' => $booking->id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid QR code data',
            ], 400);
        }

        // Create shorter filename using booking ID
        $fileName = 'qr_' . $booking->id . '.png';
        $qrCodePath = 'qrcodes/' . $fileName;

        // Store in public/storage/qrcodes
        $fullPath = storage_path('app/public/' . $qrCodePath);

        // Create directory if not exists
        $dir = dirname($fullPath);
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
            Log::info('Created QR codes directory', ['path' => $dir]);
        }

        $writeResult = file_put_contents($fullPath, $qrCodeImage);

        if ($writeResult === false) {
            Log::error('Failed to write QR code file', [
                'booking_id' => $booking->id,
                'path' => $fullPath,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to save QR code file',
            ], 500);
        }

        Log::info('QR code file saved successfully', [
            'booking_id' => $booking->id,
            'path' => $fullPath,
            'size' => $writeResult,
        ]);

        // Update booking with QR code path
        $booking->update(['qr_code_path' => $qrCodePath]);

        // Send confirmation email with QR code
        $emailQrPath = $fullPath;

        // Get recipient email
        $recipientEmail = $booking->customer
            ? $booking->customer->email
            : $booking->guest_email;

        Log::info('Preparing to send booking confirmation email', [
            'booking_id' => $booking->id,
            'recipient_email' => $recipientEmail,
            'has_customer' => $booking->customer ? true : false,
            'customer_email' => $booking->customer ? $booking->customer->email : null,
            'guest_email' => $booking->guest_email,
        ]);

        $emailSent = false;
        $emailError = null;

        if ($recipientEmail) {
            try {
                Log::info('Loading booking relationships for email', [
                    'booking_id' => $booking->id,
                ]);

                $booking->load(['customer', 'package', 'location', 'room', 'creator', 'attractions', 'addOns']);

                // Verify QR code file exists before reading
                if (!file_exists($emailQrPath)) {
                    throw new \Exception("QR code file not found at path: {$emailQrPath}");
                }

                // Get QR code as base64 for attachment
                $qrCodeBase64 = base64_encode(file_get_contents($emailQrPath));

                if (!$qrCodeBase64) {
                    throw new \Exception("Failed to read QR code file for email attachment");
                }

                Log::info('Preparing email', [
                    'booking_id' => $booking->id,
                    'qr_code_size' => strlen($qrCodeBase64),
                    'use_gmail_api' => config('gmail.enabled', false),
                ]);

                // Check if Gmail API should be used
                $useGmailApi = config('gmail.enabled', false) &&
                              (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

                if ($useGmailApi) {
                    Log::info('Using Gmail API for email sending', [
                        'booking_id' => $booking->id,
                    ]);

                    // Send using Gmail API
                    $gmailService = new GmailApiService();
                    $mailable = new BookingConfirmation($booking, $emailQrPath);
                    $emailBody = $mailable->render();

                    $attachments = [[
                        'data' => $qrCodeBase64,
                        'filename' => 'booking-qrcode.png',
                        'mime_type' => 'image/png'
                    ]];

                    $gmailService->sendEmail(
                        $recipientEmail,
                        'Your Booking Confirmation - Zap Zone',
                        $emailBody,
                        'Zap Zone',
                        $attachments
                    );
                } else {
                    Log::info('Using Laravel Mail (SMTP) for email sending', [
                        'booking_id' => $booking->id,
                        'mail_driver' => config('mail.default'),
                    ]);

                    // Send using Laravel Mail (SMTP)
                    \Illuminate\Support\Facades\Mail::send([], [], function ($message) use ($booking, $emailQrPath, $recipientEmail) {
                        $mailable = new BookingConfirmation($booking, $emailQrPath);
                        $emailBody = $mailable->render();

                        $message->to($recipientEmail)
                            ->subject('Your Booking Confirmation - Zap Zone')
                            ->html($emailBody)
                            ->attach($emailQrPath, [
                                'as' => 'booking-qrcode.png',
                                'mime' => 'image/png',
                            ]);
                    });
                }

                $emailSent = true;

                Log::info('✅ Booking confirmation email sent successfully', [
                    'email' => $recipientEmail,
                    'booking_id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'method' => $useGmailApi ? 'Gmail API' : 'SMTP',
                ]);

            } catch (\Exception $e) {
                $emailError = $e->getMessage();

                // Log detailed error information
                Log::error('❌ Failed to send booking confirmation email', [
                    'email' => $recipientEmail,
                    'booking_id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        } else {
            Log::warning('No recipient email available for booking confirmation', [
                'booking_id' => $booking->id,
                'reference_number' => $booking->reference_number,
                'has_customer' => $booking->customer_id ? true : false,
                'guest_email' => $booking->guest_email,
            ]);
            $emailError = 'No recipient email address available';
        }

        return response()->json([
            'success' => true,
            'message' => 'QR code stored successfully',
            'data' => [
                'qr_code_path' => $qrCodePath,
                'qr_code_url' => asset('storage/' . $qrCodePath),
                'email_sent' => $emailSent,
                'email_error' => $emailError,
                'recipient_email' => $recipientEmail,
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
        // Clean undefined values from request
        $data = $request->all();
        foreach ($data as $key => $value) {
            if ($value === 'undefined' || $value === 'null') {
                $data[$key] = null;
            }
        }
        $request->merge($data);

        $validated = $request->validate([
            'customer_id' => 'sometimes|nullable|exists:customers,id',
            'guest_name' => 'sometimes|nullable|string|max:255',
            'guest_email' => 'sometimes|nullable|email|max:255',
            'guest_phone' => 'sometimes|nullable|string|max:20',
            'package_id' => [
                'sometimes',
                'nullable',
                'exists:packages,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $package = \App\Models\Package::find($value);
                        if ($package && !$package->is_active) {
                            $fail('The selected package is currently not available for booking.');
                        }
                    }
                },
            ],
            'location_id' => 'sometimes|exists:locations,id',
            'room_id' => 'sometimes|nullable|exists:rooms,id',
            'gift_card_id' => 'sometimes|nullable|exists:gift_cards,id',
            'promo_id' => 'sometimes|nullable|exists:promos,id',
            'booking_date' => 'sometimes|date',
            'booking_time' => 'sometimes|date_format:H:i',
            'participants' => 'sometimes|integer|min:1',
            'duration' => 'sometimes|numeric|min:0.01',
            'duration_unit' => ['sometimes', Rule::in(['hours', 'minutes', 'hours and minutes'])],
            'total_amount' => 'sometimes|numeric|min:0',
            'amount_paid' => 'sometimes|numeric|min:0',
            'discount_amount' => 'sometimes|nullable|numeric|min:0',
            'payment_method' => ['sometimes', 'nullable', Rule::in(['card', 'cash', 'paylater'])],
            'payment_status' => ['sometimes', Rule::in(['paid', 'partial', 'pending'])],
            'status' => ['sometimes', Rule::in(['pending', 'confirmed', 'checked-in', 'completed', 'cancelled'])],
            'notes' => 'sometimes|nullable|string',
            'internal_notes' => 'sometimes|nullable|string',
            'send_notification' => 'sometimes|nullable|boolean',
            'special_requests' => 'sometimes|nullable|string',
            'guest_of_honor_name' => 'sometimes|nullable|string|max:255',
            'guest_of_honor_age' => 'sometimes|nullable|integer|min:0|max:150',
            'guest_of_honor_gender' => ['sometimes', 'nullable', Rule::in(['male', 'female', 'other'])],
            'additional_attractions' => 'sometimes|nullable|array',
            'additional_attractions.*.attraction_id' => 'required|exists:attractions,id',
            'additional_attractions.*.quantity' => 'required|integer|min:1',
            'additional_attractions.*.price_at_booking' => 'nullable|numeric|min:0',
            'additional_addons' => 'sometimes|nullable|array',
            'additional_addons.*.addon_id' => 'required|exists:add_ons,id',
            'additional_addons.*.quantity' => 'required|integer|min:1',
            'additional_addons.*.price_at_booking' => 'nullable|numeric|min:0',
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

        // Update payment status based on amount paid if not explicitly set
        if (isset($validated['amount_paid']) && isset($validated['total_amount']) && !isset($validated['payment_status'])) {
            $validated['payment_status'] = $validated['amount_paid'] >= $validated['total_amount'] ? 'paid' : 'partial';
        }

        $booking->update($validated);

        // Update attractions if provided
        if (isset($validated['additional_attractions'])) {
            // Delete existing attractions
            BookingAttraction::where('booking_id', $booking->id)->delete();

            // Add new attractions
            if (is_array($validated['additional_attractions'])) {
                foreach ($validated['additional_attractions'] as $attraction) {
                    BookingAttraction::create([
                        'booking_id' => $booking->id,
                        'attraction_id' => $attraction['attraction_id'],
                        'quantity' => $attraction['quantity'] ?? 1,
                        'price_at_booking' => $attraction['price_at_booking'] ?? 0,
                    ]);
                }
            }
        }

        // Update add-ons if provided
        if (isset($validated['additional_addons'])) {
            // Delete existing add-ons
            BookingAddOn::where('booking_id', $booking->id)->delete();

            // Add new add-ons
            if (is_array($validated['additional_addons'])) {
                foreach ($validated['additional_addons'] as $addon) {
                    BookingAddOn::create([
                        'booking_id' => $booking->id,
                        'add_on_id' => $addon['addon_id'],
                        'quantity' => $addon['quantity'] ?? 1,
                        'price_at_booking' => $addon['price_at_booking'] ?? 0,
                    ]);
                }
            }
        }

        // Update time slot if room, date, or time changed
        if (isset($validated['room_id']) || isset($validated['booking_date']) || isset($validated['booking_time']) || isset($validated['duration']) || isset($validated['duration_unit'])) {
            $timeSlot = PackageTimeSlot::where('booking_id', $booking->id)->first();

            if ($timeSlot) {
                $timeSlot->update([
                    'room_id' => $validated['room_id'] ?? $timeSlot->room_id,
                    'booked_date' => $validated['booking_date'] ?? $timeSlot->booked_date,
                    'time_slot_start' => $validated['booking_time'] ?? $timeSlot->time_slot_start,
                    'duration' => $validated['duration'] ?? $timeSlot->duration,
                    'duration_unit' => $validated['duration_unit'] ?? $timeSlot->duration_unit,
                    'notes' => $validated['notes'] ?? $timeSlot->notes,
                ]);
            } elseif (isset($validated['room_id']) && $validated['room_id']) {
                // Create time slot if it doesn't exist but room is provided
                PackageTimeSlot::create([
                    'package_id' => $validated['package_id'] ?? $booking->package_id,
                    'booking_id' => $booking->id,
                    'room_id' => $validated['room_id'],
                    'customer_id' => $validated['customer_id'] ?? $booking->customer_id,
                    'user_id' => $booking->created_by,
                    'booked_date' => $validated['booking_date'] ?? $booking->booking_date,
                    'time_slot_start' => $validated['booking_time'] ?? $booking->booking_time,
                    'duration' => $validated['duration'] ?? $booking->duration,
                    'duration_unit' => $validated['duration_unit'] ?? $booking->duration_unit,
                    'status' => 'booked',
                    'notes' => $validated['notes'] ?? null,
                ]);
            }
        }

        $booking->load(['customer', 'package', 'location', 'room', 'creator', 'attractions', 'addOns']);

        // Send notification email if requested
        if (isset($validated['send_notification']) && $validated['send_notification'] === true) {
            $this->sendNotificationEmail($booking, 'updated');
        }

        // Log booking update activity
        $customerName = $booking->customer ? "{$booking->customer->first_name} {$booking->customer->last_name}" : $booking->guest_name;
        ActivityLog::log(
            action: 'Booking Updated',
            category: 'update',
            description: "Booking {$booking->reference_number} updated for {$customerName}",
            userId: auth()->id(),
            locationId: $booking->location_id,
            entityType: 'booking',
            entityId: $booking->id,
            metadata: [
                'reference_number' => $booking->reference_number,
                'updated_fields' => array_keys($validated),
            ]
        );

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

    // update status depending on the status sent
    public function updateStatus(Request $request, Booking $booking): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'confirmed', 'checked-in', 'completed', 'cancelled'])],
        ]);

        $notificationData = null;

        // Update timestamps based on status
        switch ($validated['status']) {
            case 'checked-in':
                $booking->update([
                    'status' => 'checked-in',
                    'checked_in_at' => now(),
                ]);
                PackageTimeSlot::where('booking_id', $booking->id)->update([
                    'status' => 'booked',
                ]);
                $notificationData = [
                    'title' => 'Checked In',
                    'message' => "You have been checked in for booking {$booking->reference_number}. Enjoy your experience!",
                    'priority' => 'medium',
                ];
                break;
            case 'completed':
                $booking->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
                PackageTimeSlot::where('booking_id', $booking->id)->update([
                    'status' => 'completed',
                ]);
                $notificationData = [
                    'title' => 'Booking Completed',
                    'message' => "Thank you for visiting! Your booking {$booking->reference_number} has been completed.",
                    'priority' => 'low',
                ];
                break;
            case 'cancelled':
                $booking->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);
                PackageTimeSlot::where('booking_id', $booking->id)->update([
                    'status' => 'cancelled',
                ]);
                $notificationData = [
                    'title' => 'Booking Cancelled',
                    'message' => "Your booking {$booking->reference_number} has been cancelled.",
                    'priority' => 'high',
                ];
                break;
            case 'confirmed':
                $booking->update([
                    'status' => $validated['status'],
                ]);
                $notificationData = [
                    'title' => 'Booking Confirmed',
                    'message' => "Your booking {$booking->reference_number} has been confirmed for {$booking->booking_date} at {$booking->booking_time}.",
                    'priority' => 'high',
                ];
                break;
            default:
                $booking->update([
                    'status' => $validated['status'],
                ]);
                break;
        }

        // Create notification for customer if status changed to important states
        if ($booking->customer_id && $notificationData) {
            CustomerNotification::create([
                'customer_id' => $booking->customer_id,
                'location_id' => $booking->location_id,
                'type' => 'booking',
                'priority' => $notificationData['priority'],
                'title' => $notificationData['title'],
                'message' => $notificationData['message'],
                'status' => 'unread',
                'action_url' => "/bookings/{$booking->id}",
                'action_text' => 'View Booking',
                'metadata' => [
                    'booking_id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'status' => $validated['status'],
                ],
            ]);
        }

        // Create notification for location staff on cancelled bookings
        if ($validated['status'] === 'cancelled') {
            $customerName = $booking->customer ? "{$booking->customer->first_name} {$booking->customer->last_name}" : $booking->guest_name;
            Notification::create([
                'location_id' => $booking->location_id,
                'type' => 'booking',
                'priority' => 'high',
                'user_id' => $booking->created_by ?? auth()->id(),
                'title' => 'Booking Cancelled',
                'message' => "Booking {$booking->reference_number} for {$customerName} has been cancelled.",
                'status' => 'unread',
                'action_url' => "/bookings/{$booking->id}",
                'action_text' => 'View Booking',
                'metadata' => [
                    'booking_id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'status' => 'cancelled',
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Booking status updated successfully',
            'data' => $booking,
        ]);
    }

        public function updatePaymentStatus(Request $request, Booking $booking): JsonResponse
    {
        $validated = $request->validate([
            'payment_status' => ['required', Rule::in(['paid', 'partial'])],
        ]);

        $previousStatus = $booking->payment_status;

        $booking->update([
            'payment_status' => $validated['payment_status'],
        ]);

        // Create customer notification if payment status changed to paid
        if ($validated['payment_status'] === 'paid' && $previousStatus !== 'paid' && $booking->customer_id) {
            CustomerNotification::create([
                'customer_id' => $booking->customer_id,
                'location_id' => $booking->location_id,
                'type' => 'payment',
                'priority' => 'medium',
                'title' => 'Payment Complete',
                'message' => "Your booking {$booking->reference_number} is now fully paid.",
                'status' => 'unread',
                'action_url' => "/bookings/{$booking->id}",
                'action_text' => 'View Booking',
                'metadata' => [
                    'booking_id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'payment_status' => 'paid',
                    'total_amount' => $booking->total_amount,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Booking payment status updated successfully',
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

    /**
     * Bulk delete bookings
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:bookings,id',
        ]);

        $bookings = Booking::whereIn('id', $validated['ids'])->get();
        $deletedCount = 0;
        $locationIds = [];

        foreach ($bookings as $booking) {
            $locationIds[] = $booking->location_id;
            $booking->delete();
            $deletedCount++;
        }

        // Log bulk deletion
        ActivityLog::log(
            action: 'Bulk Bookings Deleted',
            category: 'delete',
            description: "{$deletedCount} bookings deleted in bulk operation",
            userId: auth()->id(),
            locationId: $locationIds[0] ?? null,
            entityType: 'booking',
            metadata: ['deleted_count' => $deletedCount, 'ids' => $validated['ids']]
        );

        return response()->json([
            'success' => true,
            'message' => "{$deletedCount} bookings deleted successfully",
            'data' => ['deleted_count' => $deletedCount],
        ]);
    }

    /**
     * Update internal notes only for a booking
     */
    public function updateInternalNotes(Request $request, Booking $booking): JsonResponse
    {
        $validated = $request->validate([
            'internal_notes' => 'required|string',
        ]);

        $booking->update([
            'internal_notes' => $validated['internal_notes'],
        ]);

        // Log internal notes update
        ActivityLog::log(
            action: 'Booking Internal Notes Updated',
            category: 'update',
            description: "Internal notes updated for booking {$booking->reference_number}",
            userId: auth()->id(),
            locationId: $booking->location_id,
            entityType: 'booking',
            entityId: $booking->id,
            metadata: ['reference_number' => $booking->reference_number]
        );

        return response()->json([
            'success' => true,
            'message' => 'Internal notes updated successfully',
            'data' => $booking,
        ]);
    }

    /**
     * Send notification email to customer
     */
    private function sendNotificationEmail(Booking $booking, string $action = 'updated'): void
    {
        // Ensure location.company relationship is loaded for email template
        $booking->loadMissing(['location.company', 'customer', 'package']);

        // Get recipient email
        $recipientEmail = $booking->customer
            ? $booking->customer->email
            : $booking->guest_email;

        if (!$recipientEmail) {
            Log::warning('No recipient email available for booking notification', [
                'booking_id' => $booking->id,
                'reference_number' => $booking->reference_number,
            ]);
            return;
        }

        try {
            Log::info('Sending booking notification email', [
                'booking_id' => $booking->id,
                'recipient_email' => $recipientEmail,
                'action' => $action,
            ]);

            // Prepare email data
            $customerName = $booking->customer
                ? "{$booking->customer->first_name} {$booking->customer->last_name}"
                : $booking->guest_name;

            $subject = $action === 'updated'
                ? 'Your Booking Has Been Updated - Zap Zone'
                : 'Booking Notification - Zap Zone';

            $emailBody = view('emails.booking-update', [
                'booking' => $booking,
                'customerName' => $customerName,
                'action' => $action,
            ])->render();

            // Check if Gmail API should be used
            $useGmailApi = config('gmail.enabled', false) &&
                          (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

            if ($useGmailApi) {
                // Send using Gmail API
                $gmailService = new GmailApiService();
                $gmailService->sendEmail(
                    $recipientEmail,
                    $subject,
                    $emailBody,
                    'Zap Zone'
                );
            } else {
                // Send using Laravel Mail (SMTP)
                \Illuminate\Support\Facades\Mail::send([], [], function ($message) use ($recipientEmail, $subject, $emailBody) {
                    $message->to($recipientEmail)
                        ->subject($subject)
                        ->html($emailBody);
                });
            }

            Log::info('Booking notification email sent successfully', [
                'email' => $recipientEmail,
                'booking_id' => $booking->id,
                'action' => $action,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send booking notification email', [
                'email' => $recipientEmail,
                'booking_id' => $booking->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // detroy method
    public function destroy($id): JsonResponse
    {
        $booking = Booking::findOrFail($id);

        // deleted by whom?
        $deletedBy = auth()->id();
        $user = User::findOrFail($deletedBy);

        $booking->delete();

        // Log deletion
        ActivityLog::log(
            action: 'Booking Deleted',
            category: 'delete',
            description: "Booking {$booking->reference_number} deleted by {$user->first_name} {$user->last_name}",
            userId: auth()->id(),
            locationId: $booking->location_id,
            entityType: 'booking',
            entityId: $booking->id,
            metadata: ['reference_number' => $booking->reference_number]
        );
        return response()->json([
            'success' => true,
            'message' => 'Booking deleted successfully',
        ]);
    }

    /**
     * Generate a single booking summary PDF (download)
     */
    public function summary(Booking $booking)
    {
        $booking->load(['customer', 'package', 'location', 'room', 'attractions', 'addOns', 'payments']);

        $pdf = Pdf::loadView('exports.booking-summary', [
            'booking' => $booking,
            'customer' => $booking->customer,
            'location' => $booking->location,
            'companyName' => config('app.name', 'ZapZone'),
        ]);

        $filename = 'booking-summary-' . $booking->reference_number . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * View a single booking summary PDF in browser
     */
    public function summaryView(Booking $booking)
    {
        $booking->load(['customer', 'package', 'location', 'room', 'attractions', 'addOns', 'payments']);

        $pdf = Pdf::loadView('exports.booking-summary', [
            'booking' => $booking,
            'customer' => $booking->customer,
            'location' => $booking->location,
            'companyName' => config('app.name', 'ZapZone'),
        ]);

        return $pdf->stream('booking-summary-' . $booking->reference_number . '.pdf');
    }

    /**
     * Export booking summaries (by date range, specific bookings, or filters)
     *
     * Query params:
     * - booking_ids: comma-separated booking IDs (optional)
     * - date: specific date (Y-m-d) for single day export
     * - start_date: start date for date range
     * - end_date: end date for date range
     * - week: export entire week ('current', 'next', or date string for week containing that date)
     * - location_id: filter by location
     * - status: filter by status
     * - view_mode: 'compact' for cards, 'full' for one page per booking (default: full)
     */
    public function summariesExport(Request $request)
    {
        $query = Booking::with(['customer', 'package', 'location', 'room', 'attractions', 'addOns', 'payments']);

        $dateRange = null;
        $location = null;

        // Filter by specific booking IDs
        if ($request->has('booking_ids')) {
            $ids = is_array($request->booking_ids)
                ? $request->booking_ids
                : explode(',', $request->booking_ids);
            $query->whereIn('id', $ids);
        }

        // Filter by single date
        if ($request->has('date')) {
            $date = $request->date;
            $query->whereDate('booking_date', $date);
            $dateRange = ['start' => $date, 'end' => $date];
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('booking_date', [$request->start_date, $request->end_date]);
            $dateRange = ['start' => $request->start_date, 'end' => $request->end_date];
        }

        // Filter by week
        if ($request->has('week')) {
            $weekParam = $request->week;

            if ($weekParam === 'current') {
                $startOfWeek = now()->startOfWeek();
                $endOfWeek = now()->endOfWeek();
            } elseif ($weekParam === 'next') {
                $startOfWeek = now()->addWeek()->startOfWeek();
                $endOfWeek = now()->addWeek()->endOfWeek();
            } else {
                // Treat as a date and get the week containing that date
                $date = \Carbon\Carbon::parse($weekParam);
                $startOfWeek = $date->startOfWeek();
                $endOfWeek = $date->copy()->endOfWeek();
            }

            $query->whereBetween('booking_date', [$startOfWeek, $endOfWeek]);
            $dateRange = ['start' => $startOfWeek->format('Y-m-d'), 'end' => $endOfWeek->format('Y-m-d')];
        }

        // Filter by location
        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
            $location = \App\Models\Location::find($request->location_id);
        }

        // Role-based filtering
        if ($request->has('user_id')) {
            $authUser = User::find($request->user_id);
            if ($authUser && $authUser->role === 'location_manager') {
                $query->where('location_id', $authUser->location_id);
                $location = $location ?? \App\Models\Location::find($authUser->location_id);
            }
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Exclude cancelled by default unless explicitly requested
        if (!$request->has('include_cancelled')) {
            $query->where('status', '!=', 'cancelled');
        }

        // Sort by date and time
        $query->orderBy('booking_date', 'asc')->orderBy('booking_time', 'asc');

        $bookings = $query->get();

        if ($bookings->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No bookings found for the specified criteria'
            ], 404);
        }

        // Determine view mode
        $viewMode = $request->get('view_mode', 'full'); // 'compact' or 'full'

        $pdf = Pdf::loadView('exports.booking-summaries-report', [
            'bookings' => $bookings,
            'dateRange' => $dateRange,
            'location' => $location,
            'viewMode' => $viewMode,
            'companyName' => config('app.name', 'ZapZone'),
        ]);

        // Set paper size and orientation
        $pdf->setPaper('A4', 'portrait');

        // Generate filename
        $filename = 'party-summaries';
        if ($dateRange) {
            if ($dateRange['start'] === $dateRange['end']) {
                $filename .= '-' . $dateRange['start'];
            } else {
                $filename .= '-' . $dateRange['start'] . '-to-' . $dateRange['end'];
            }
        } else {
            $filename .= '-' . now()->format('Y-m-d');
        }
        $filename .= '.pdf';

        // Stream or download based on request
        if ($request->get('stream', false)) {
            return $pdf->stream($filename);
        }

        return $pdf->download($filename);
    }

    /**
     * Export bookings for a specific day (shortcut method)
     */
    public function summariesDay(Request $request, string $date)
    {
        $request->merge(['date' => $date]);
        return $this->summariesExport($request);
    }

    /**
     * Export bookings for current week (shortcut method)
     */
    public function summariesWeek(Request $request, string $week = 'current')
    {
        $request->merge(['week' => $week]);
        return $this->summariesExport($request);
    }

}


