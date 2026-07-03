<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\RecordsPageAnalytics;
use App\Http\Traits\ScopesByAuthUser;
use App\Mail\BookingConfirmation;
use App\Mail\BookingReminder;
use App\Mail\StaffBookingNotification;
use App\Services\EmailNotificationService;
use App\Services\GmailApiService;
use App\Services\GoogleCalendarService;
use App\Models\ActivityLog;
use App\Models\EmailNotification;
use App\Models\Booking;
use App\Models\BookingAttraction;
use App\Models\BookingAddOn;
use App\Models\Contact;
use App\Models\CustomerNotification;
use App\Models\Notification;
use App\Models\PackageTimeSlot;
use App\Models\User;
use App\Models\Membership;
use App\Services\MembershipBenefitService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Barryvdh\DomPDF\Facade\Pdf;

class BookingController extends Controller
{
    use ScopesByAuthUser;
    use RecordsPageAnalytics;

    public function index(Request $request): JsonResponse
    {
        try {
            $query = Booking::select([
                    'id', 'reference_number', 'customer_id', 'package_id', 'location_id', 'room_id',
                    'created_by', 'guest_name', 'guest_email', 'guest_phone', 'booking_date', 'booking_time',
                    'participants', 'duration', 'duration_unit', 'total_amount', 'amount_paid',
                    'discount_amount', 'applied_fees', 'payment_method', 'payment_status', 'status', 'notes',
                    'guest_of_honor_name', 'guest_of_honor_age', 'created_at', 'updated_at'
                ])
                ->with([
                    'customer:id,first_name,last_name,email,phone',
                    'package:id,name,price',
                    'location:id,name',
                    'room:id,name',
                    'creator:id,first_name,last_name,email',
                    'attractions:id,name',  // BelongsToMany - pivot data loaded automatically
                    'addOns:id,name',       // BelongsToMany - pivot data loaded automatically
                ]);

            $this->applyAuthScope($query, $request);

            if ($request->has('location_id')) {
                $query->byLocation($request->location_id);
            }

            if ($request->has('reference_number')) {
                $query->where('reference_number', $request->reference_number);
            }

            if ($request->has('status')) {
                $query->byStatus($request->status);
            }

            if ($request->has('customer_id')) {
                $query->byCustomer($request->customer_id);
            }

            if ($request->has('booking_date')) {
                $query->byDate($request->booking_date);
            }

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
        } catch (\Exception $e) {
            Log::error('Error in bookings index', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bookings',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    public function customerBookings(Request $request): JsonResponse
    {
        $query = Booking::select([
                'id', 'reference_number', 'customer_id', 'package_id', 'location_id', 'room_id',
                'created_by', 'guest_name', 'guest_email', 'guest_phone',
                'guest_address', 'guest_city', 'guest_state', 'guest_zip', 'guest_country',
                'booking_date', 'booking_time',
                'participants', 'duration', 'duration_unit',
                'total_amount', 'amount_paid', 'discount_amount',
                'payment_method', 'payment_status', 'status',
                'notes', 'special_requests',
                'guest_of_honor_name', 'guest_of_honor_age', 'guest_of_honor_gender',
                'transaction_id', 'created_at', 'updated_at'
            ])
            ->with([
                'customer:id,first_name,last_name,email,phone',
                'package:id,name,price,duration,duration_unit',
                'location:id,name',
                'room:id,name',
                'creator:id,first_name,last_name',
                'attractions:id,name',
                'addOns:id,name',
            ]);

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


    public function store(Request $request): JsonResponse
    {
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
            'membership_id' => 'nullable|exists:memberships,id',
            'membership_applied' => 'nullable|array',
            'membership_applied.*.membership_plan_benefit_id' => 'nullable|integer',
            'membership_applied.*.benefit_type' => 'nullable|string',
            'membership_applied.*.value_mode' => 'nullable|string',
            'membership_applied.*.value_applied' => 'nullable|numeric',
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
            'applied_fees' => 'nullable|array',
            'applied_fees.*.fee_name' => 'required_with:applied_fees|string|max:255',
            'applied_fees.*.fee_amount' => 'required_with:applied_fees|numeric|min:0',
            'applied_fees.*.fee_application_type' => ['required_with:applied_fees', Rule::in(['additive', 'inclusive'])],
            'applied_discounts' => 'nullable|array',
            'applied_discounts.*.discount_name' => 'required_with:applied_discounts|string|max:255',
            'applied_discounts.*.discount_amount' => 'required_with:applied_discounts|numeric|min:0',
            'applied_discounts.*.discount_type' => ['required_with:applied_discounts', Rule::in(['fixed', 'percentage'])],
            'applied_discounts.*.original_price' => 'required_with:applied_discounts|numeric|min:0',
            'applied_discounts.*.special_pricing_id' => 'nullable|integer',
            'payment_method' => ['nullable', Rule::in(['card', 'in-store', 'paylater', 'authorize.net'])],
            'notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'send_notification' => 'nullable|boolean',
            'send_email' => 'nullable|boolean',
            'sent_email_to_staff' => 'nullable|boolean',
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
            'sms_consent' => 'nullable|boolean',
        ]);

        $duplicateQuery = Booking::where('package_id', $validated['package_id'] ?? null)
            ->where('booking_date', $validated['booking_date'])
            ->where('booking_time', $validated['booking_time'])
            ->whereIn('status', ['pending', 'confirmed']);

        if (!empty($validated['customer_id'])) {
            $duplicateQuery->where('customer_id', $validated['customer_id']);
        } else {
            $duplicateQuery->where('guest_email', $validated['guest_email'] ?? null);
        }

        $existingPending = $duplicateQuery->first();
        if ($existingPending) {
            $existingPending->load(['customer', 'package', 'location', 'room', 'creator', 'attractions', 'addOns']);
            Log::info('Duplicate booking prevented (existing pending found)', [
                'existing_booking_id' => $existingPending->id,
                'package_id' => $validated['package_id'] ?? null,
                'customer_id' => $validated['customer_id'] ?? null,
                'guest_email' => $validated['guest_email'] ?? null,
            ]);
            return response()->json([
                'success' => true,
                'message' => 'Booking already exists',
                'data' => $existingPending,
            ], 200);
        }

        do {
            $validated['reference_number'] = 'BK' . now()->format('Ymd') . strtoupper(Str::random(6));
        } while (Booking::where('reference_number', $validated['reference_number'])->exists());

        if (!isset($validated['payment_method'])) {
            $validated['payment_method'] = 'paylater';
        }

        if (in_array($validated['payment_method'], ['in-store', 'card'])) {
            $validated['status'] = 'confirmed';
            $validated['payment_status'] = $validated['amount_paid'] >= $validated['total_amount'] ? 'paid' : 'partial';
        } else {
            $validated['status'] = 'pending';
            $validated['payment_status'] = 'pending';
        }

        // Derive membership_discount from applied_discounts entries
        if (! empty($validated['applied_discounts'])) {
            $membershipDiscount = collect($validated['applied_discounts'])
                ->filter(fn($d) => str_starts_with($d['discount_name'] ?? '', 'Member Savings'))
                ->sum('discount_amount');
            if ($membershipDiscount > 0) {
                $validated['membership_discount'] = $membershipDiscount;
            }
        }

        $booking = Booking::create($validated);

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

        // Create a pending waiver if a template covers this booking's package/attractions,
        // so the confirmation email/SMS can include the {{waiver_link}}. Non-fatal.
        // The signing URL is also surfaced in the create response so the post-checkout
        // "Complete Waiver Now" prompt can link the customer straight to their waiver.
        $bookingWaiver = null;
        try {
            $bookingWaiver = app(\App\Services\WaiverService::class)->ensureForBooking($booking);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to create waiver for booking', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

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

        $this->recordMembershipRedemptions($booking, $validated);

        if ($booking->customer_id && $booking->status === 'confirmed' && (float) ($booking->amount_paid ?? 0) > 0) {
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

        $customerName = $booking->customer ? "{$booking->customer->first_name} {$booking->customer->last_name}" : $booking->guest_name;
        if ($booking->status === 'confirmed' && (float) ($booking->amount_paid ?? 0) > 0) {
            $formattedDate = \Carbon\Carbon::parse($booking->booking_date)->format('m-d');
            $formattedTime = \Carbon\Carbon::parse($booking->booking_time)->format('g:i A');
            Notification::create([
                'location_id' => $booking->location_id,
                'type' => 'booking',
                'priority' => 'medium',
                'user_id' => $booking->created_by ?? auth()->id(),
                'title' => 'New Booking Received',
                'message' => "{$customerName} — {$formattedDate} at {$formattedTime} • $" . number_format($booking->total_amount, 2) . " ({$booking->reference_number})",
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
        }

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
                'customer_name' => $customerName,
                'customer_id' => $booking->customer_id,
                'guest_email' => $booking->guest_email,
                'created_by' => $booking->creator ? $booking->creator->name ?? $booking->creator->email : 'System',
                'created_at' => $booking->created_at->toIso8601String(),
                'booking_details' => [
                    'booking_date' => $booking->booking_date,
                    'booking_time' => $booking->booking_time,
                    'duration' => $booking->duration,
                    'duration_unit' => $booking->duration_unit,
                    'participants' => $booking->participants,
                ],
                'package' => $booking->package ? [
                    'id' => $booking->package->id,
                    'name' => $booking->package->name,
                ] : null,
                'room' => $booking->room ? [
                    'id' => $booking->room->id,
                    'name' => $booking->room->name,
                ] : null,
                'location' => $booking->location ? [
                    'id' => $booking->location->id,
                    'name' => $booking->location->name,
                ] : null,
                'financial' => [
                    'total_amount' => $booking->total_amount,
                    'amount_paid' => $booking->amount_paid,
                    'discount_amount' => $booking->discount_amount,
                    'payment_method' => $booking->payment_method,
                    'payment_status' => $booking->payment_status,
                ],
                'status' => $booking->status,
                'addons_count' => $booking->addOns->count(),
                'attractions_count' => $booking->attractions->count(),
            ]
          );
        }

        try {
            $contactEmail = null;
            $contactData = [];

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
                    'sms_consent' => $validated['sms_consent'] ?? false,
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
                    'sms_consent' => $validated['sms_consent'] ?? false,
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
            Log::warning('Failed to create/update contact from booking', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }


        if (config('google_calendar.auto_sync', true)) {
            try {
                $gcalService = new GoogleCalendarService($booking->location_id);
                if ($gcalService->isConnected()) {
                    $gcalService->createEventFromBooking($booking);
                }
            } catch (\Exception $e) {
                Log::warning('Google Calendar auto-sync failed for new booking', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->recordConversion('booking_completed', $booking, (float) ($booking->total_amount ?? 0));

        // Surface the pending waiver link for the post-checkout "Complete Waiver Now" prompt.
        if ($bookingWaiver && $bookingWaiver->status === \App\Models\Waiver::STATUS_PENDING) {
            $booking->setAttribute('waiver_signing_url', $bookingWaiver->signing_url);
            $booking->setAttribute('waiver_status', $bookingWaiver->status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Booking created successfully',
            'data' => $booking,
        ], 201);
    }

    private function recordMembershipRedemptions(Booking $booking, array $validated): void
    {
        if (empty($validated['membership_id'])) {
            return;
        }

        try {
            $membership = Membership::find($validated['membership_id']);
            if (! $membership) {
                return;
            }

            // Use pre-computed applied data if sent by frontend (correct original prices)
            if (! empty($validated['membership_applied'])) {
                app(MembershipBenefitService::class)->recordPurchaseRedemptions(
                    $membership,
                    $booking,
                    $validated['membership_applied'],
                    $booking->location_id,
                    auth()->id()
                );
                return;
            }

            $qty       = max(1, (int) ($booking->participants ?? 1));
            $unitPrice = $qty > 0 ? (float) $booking->total_amount / $qty : (float) $booking->total_amount;

            $quote = app(MembershipBenefitService::class)->quote($membership, $booking->location_id, [[
                'type'       => 'package',
                'id'         => $booking->package_id,
                'unit_price' => $unitPrice,
                'quantity'   => $qty,
            ]]);

            if (! empty($quote['applied'])) {
                app(MembershipBenefitService::class)->recordPurchaseRedemptions(
                    $membership,
                    $booking,
                    $quote['applied'],
                    $booking->location_id,
                    auth()->id()
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to record membership redemptions for booking', [
                'booking_id' => $booking->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    public function exportIndex(Request $request): JsonResponse
    {
        $query = Booking::select([
                'id', 'reference_number', 'customer_id', 'package_id', 'location_id', 'room_id',
                'created_by', 'guest_name', 'guest_email', 'guest_phone', 'booking_date', 'booking_time',
                'participants', 'duration', 'duration_unit', 'total_amount', 'amount_paid',
                'discount_amount', 'payment_method', 'payment_status', 'status', 'notes',
                'guest_of_honor_name', 'guest_of_honor_age', 'created_at'
            ])
            ->with([
                'customer:id,first_name,last_name,email,phone',
                'package:id,name,price',
                'location:id,name',
                'room:id,name',
                'creator:id,first_name,last_name,email',
                'attractions:id,name',  // BelongsToMany - pivot data loaded automatically
                'addOns:id,name',       // BelongsToMany - pivot data loaded automatically
            ]);

        $this->applyAuthScope($query, $request);

        if ($request->has('location_id')) {
            $locationIds = is_array($request->location_id) ? $request->location_id : explode(',', $request->location_id);
            $query->whereIn('location_id', $locationIds);
        }

        if ($request->has('reference_number')) {
            $query->where('reference_number', $request->reference_number);
        }

        if ($request->has('status')) {
            $statuses = is_array($request->status) ? $request->status : explode(',', $request->status);
            $query->whereIn('status', $statuses);
        }

        if ($request->has('customer_id')) {
            $customerIds = is_array($request->customer_id) ? $request->customer_id : explode(',', $request->customer_id);
            $query->whereIn('customer_id', $customerIds);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('booking_date', [$request->start_date, $request->end_date]);
        }

        if ($request->has('min_amount') && $request->has('max_amount')) {
            $query->whereBetween('total_amount', [$request->min_amount, $request->max_amount]);
        }

        $sortBy = $request->get('sort_by', 'booking_date');
        $sortOrder = $request->get('sort_order', 'desc');

        if (in_array($sortBy, ['booking_date', 'booking_time', 'total_amount', 'status', 'created_at'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $bookings = $query->limit(1000)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'bookings' => $bookings,
                'limited' => $bookings->count() >= 1000,
            ],
        ]);
    }

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

        $sendEmail = !isset($validated['send_email']) || $validated['send_email'] !== false;

        $qrCodeData = $validated['qr_code'];

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

        $fileName = 'qr_' . $booking->id . '.png';
        $qrCodePath = 'qrcodes/' . $fileName;

        $fullPath = storage_path('app/public/' . $qrCodePath);

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

        $booking->update(['qr_code_path' => $qrCodePath]);

        $emailSent = false;
        $emailError = null;

        if ($sendEmail) {
            $booking->load(['customer', 'package', 'location.company', 'room', 'creator', 'attractions', 'addOns']);
            $recipientEmail = $booking->customer?->email ?? $booking->guest_email;

            if ($recipientEmail) {
                try {
                    $emailService = app(EmailNotificationService::class);
                    $emailService->triggerBookingNotification($booking, EmailNotification::TRIGGER_BOOKING_CONFIRMED);
                    $emailSent = true;

                    Log::info('Booking confirmation email sent via EmailNotificationService', [
                        'booking_id' => $booking->id,
                        'recipient' => $recipientEmail,
                    ]);
                } catch (\Exception $e) {
                    $emailError = $e->getMessage();
                    Log::error('Failed to send booking confirmation email', [
                        'booking_id' => $booking->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                Log::warning('No recipient email available for booking confirmation', [
                    'booking_id' => $booking->id,
                ]);
                $emailError = 'No recipient email address available';
            }
        } else {
            Log::info('Email sending skipped per user request', [
                'booking_id' => $booking->id,
            ]);
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

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $booking->load(['customer', 'package', 'location', 'room', 'creator', 'giftCard', 'promo', 'attractions', 'addOns', 'payments']);

        if (!$this->authorizeRecordScope($booking)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to view this booking',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $booking,
        ]);
    }

    public function update(Request $request, Booking $booking): JsonResponse
    {
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
            'applied_fees' => 'sometimes|nullable|array',
            'applied_fees.*.fee_name' => 'required_with:applied_fees|string|max:255',
            'applied_fees.*.fee_amount' => 'required_with:applied_fees|numeric|min:0',
            'applied_fees.*.fee_application_type' => ['required_with:applied_fees', Rule::in(['additive', 'inclusive'])],
            'applied_discounts' => 'sometimes|nullable|array',
            'applied_discounts.*.discount_name' => 'required_with:applied_discounts|string|max:255',
            'applied_discounts.*.discount_amount' => 'required_with:applied_discounts|numeric|min:0',
            'applied_discounts.*.discount_type' => ['required_with:applied_discounts', Rule::in(['fixed', 'percentage'])],
            'applied_discounts.*.original_price' => 'required_with:applied_discounts|numeric|min:0',
            'applied_discounts.*.special_pricing_id' => 'nullable|integer',
            'payment_method' => ['sometimes', 'nullable', Rule::in(['card', 'cash', 'paylater', 'authorize.net'])],
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

        $originalValues = $booking->only([
            'status', 'payment_status', 'total_amount', 'amount_paid', 'discount_amount',
            'applied_fees', 'booking_date', 'booking_time', 'participants', 'duration', 'duration_unit',
            'room_id', 'package_id', 'notes', 'internal_notes', 'special_requests',
            'guest_of_honor_name', 'guest_of_honor_age', 'guest_of_honor_gender'
        ]);
        $originalAddons = $booking->addOns()->get()->map(fn($a) => [
            'addon_id' => $a->id,
            'name' => $a->name ?? 'N/A',
            'quantity' => $a->pivot->quantity,
            'price' => $a->pivot->price_at_booking,
        ])->toArray();
        $originalAttractions = $booking->attractions()->get()->map(fn($a) => [
            'attraction_id' => $a->id,
            'name' => $a->name ?? 'N/A',
            'quantity' => $a->pivot->quantity,
            'price' => $a->pivot->price_at_booking,
        ])->toArray();

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

        if (isset($validated['amount_paid']) && isset($validated['total_amount']) && !isset($validated['payment_status'])) {
            $validated['payment_status'] = $validated['amount_paid'] >= $validated['total_amount'] ? 'paid' : 'partial';
        }

        $booking->update($validated);

        if (isset($validated['additional_attractions'])) {
            BookingAttraction::where('booking_id', $booking->id)->delete();

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

        if (isset($validated['additional_addons'])) {
            BookingAddOn::where('booking_id', $booking->id)->delete();

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

        if ($request->boolean('send_notification')) {
            $this->sendNotificationEmail($booking, 'updated');
        }

        $changes = [];
        foreach ($validated as $field => $newValue) {
            if (in_array($field, ['additional_attractions', 'additional_addons', 'send_notification'])) {
                continue; // Handle separately
            }
            $oldValue = $originalValues[$field] ?? null;
            if ($oldValue !== $newValue) {
                $changes[$field] = [
                    'from' => $oldValue,
                    'to' => $newValue,
                ];
            }
        }

        $newAddons = [];
        if (isset($validated['additional_addons'])) {
            $newAddons = $booking->addOns()->with('addOn')->get()->map(fn($a) => [
                'addon_id' => $a->add_on_id,
                'name' => $a->addOn->name ?? 'N/A',
                'quantity' => $a->quantity,
                'price' => $a->price_at_booking,
            ])->toArray();
            if ($originalAddons !== $newAddons) {
                $changes['addons'] = [
                    'from' => $originalAddons,
                    'to' => $newAddons,
                ];
            }
        }

        $newAttractions = [];
        if (isset($validated['additional_attractions'])) {
            $newAttractions = $booking->attractions()->with('attraction')->get()->map(fn($a) => [
                'attraction_id' => $a->attraction_id,
                'name' => $a->attraction->name ?? 'N/A',
                'quantity' => $a->quantity,
                'price' => $a->price_at_booking,
            ])->toArray();
            if ($originalAttractions !== $newAttractions) {
                $changes['attractions'] = [
                    'from' => $originalAttractions,
                    'to' => $newAttractions,
                ];
            }
        }

        $customerName = $booking->customer ? "{$booking->customer->first_name} {$booking->customer->last_name}" : $booking->guest_name;
        $changedFieldsList = array_keys($changes);
        $changesSummary = count($changedFieldsList) > 0 ? implode(', ', $changedFieldsList) : 'no fields';

        ActivityLog::log(
            action: 'Booking Edited',
            category: 'update',
            description: "Booking {$booking->reference_number} edited for {$customerName}. Changed: {$changesSummary}",
            userId: auth()->id(),
            locationId: $booking->location_id,
            entityType: 'booking',
            entityId: $booking->id,
            metadata: [
                'reference_number' => $booking->reference_number,
                'customer_name' => $customerName,
                'customer_id' => $booking->customer_id,
                'updated_by' => auth()->user() ? auth()->user()->name ?? auth()->user()->email : 'System',
                'updated_at' => now()->toIso8601String(),
                'changes' => $changes,
                'updated_fields' => $changedFieldsList,
                'booking_date' => $booking->booking_date,
                'booking_time' => $booking->booking_time,
                'total_amount' => $booking->total_amount,
                'amount_paid' => $booking->amount_paid,
                'status' => $booking->status,
                'payment_status' => $booking->payment_status,
            ]
        );

        try {
            $gcalService = new GoogleCalendarService($booking->location_id);
            if ($gcalService->isConnected()) {
                $gcalService->updateEventFromBooking($booking);
            }
        } catch (\Exception $e) {
            Log::warning('Google Calendar auto-sync failed for booking update', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Booking updated successfully',
            'data' => $booking,
        ]);
    }

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

        PackageTimeSlot::where('booking_id', $booking->id)->update([
            'status' => 'cancelled',
        ]);

        try {
            $gcalService = new GoogleCalendarService($booking->location_id);
            if ($gcalService->isConnected() && $booking->google_calendar_event_id) {
                $gcalService->deleteEvent($booking);
            }
        } catch (\Exception $e) {
            Log::warning('Google Calendar delete failed for cancelled booking', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $emailService = app(EmailNotificationService::class);
            $emailService->triggerBookingNotification($booking, EmailNotification::TRIGGER_BOOKING_CANCELLED);
        } catch (\Exception $e) {
            Log::warning('Failed to send booking cancellation email', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->recordConversion(
            'booking_cancelled',
            $booking,
            -1 * (float) ($booking->total_amount ?? 0),
            ['tracking_id' => 'srv:booking:'.$booking->id.':cancelled']
        );

        return response()->json([
            'success' => true,
            'message' => 'Booking cancelled successfully',
            'data' => $booking,
        ]);
    }



    public function checkIn(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reference_number' => 'required|string|exists:bookings,reference_number',
        ]);

        $booking = Booking::where('reference_number', $validated['reference_number'])->first();

        if (!$this->authorizeRecordScope($booking)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to check in this booking',
            ], 403);
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
            'checked_in_by' => $authUser ? $authUser->id : null,
        ]);

        PackageTimeSlot::where('booking_id', $booking->id)->update([
            'status' => 'booked',
        ]);

        $booking->load(['customer', 'package', 'location', 'room', 'creator', 'attractions', 'addOns']);

        $customerName = $booking->customer
            ? ($booking->customer->first_name . ' ' . $booking->customer->last_name)
            : ($booking->guest_name ?? 'Guest');

        ActivityLog::log(
            action: 'Booking Checked In',
            category: 'check-in',
            description: "Booking {$booking->reference_number} checked in for {$customerName}",
            userId: $authUser ? $authUser->id : null,
            locationId: $booking->location_id,
            entityType: 'booking',
            entityId: $booking->id,
            metadata: [
                'reference_number' => $booking->reference_number,
                'customer_name' => $customerName,
                'checked_in_at' => now()->toIso8601String(),
                'checked_in_by' => $authUser ? ($authUser->name ?? $authUser->email) : 'System',
                'package' => $booking->package ? [
                    'id' => $booking->package->id,
                    'name' => $booking->package->name,
                ] : null,
            ]
        );

        try {
            $gcalService = new GoogleCalendarService($booking->location_id);
            if ($gcalService->isConnected()) {
                $gcalService->updateEventFromBooking($booking);
            }
        } catch (\Exception $e) {
            Log::warning('Google Calendar sync failed on check-in', ['booking_id' => $booking->id, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Booking checked in successfully',
            'data' => $booking,
        ]);
    }

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

        PackageTimeSlot::where('booking_id', $booking->id)->update([
            'status' => 'completed',
        ]);

        try {
            $gcalService = new GoogleCalendarService($booking->location_id);
            if ($gcalService->isConnected()) {
                $gcalService->updateEventFromBooking($booking);
            }
        } catch (\Exception $e) {
            Log::warning('Google Calendar sync failed on complete', ['booking_id' => $booking->id, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Booking completed successfully',
            'data' => $booking,
        ]);
    }

    public function updateStatus(Request $request, Booking $booking): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'confirmed', 'checked-in', 'completed', 'cancelled'])],
        ]);

        $previousStatus = $booking->status;
        $notificationData = null;

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
                app(MembershipBenefitService::class)->reverseForRedeemable($booking, 'booking_cancelled');
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

        if ($validated['status'] === 'cancelled') {
            $customerName = $booking->customer ? "{$booking->customer->first_name} {$booking->customer->last_name}" : $booking->guest_name;
            Notification::create([
                'location_id' => $booking->location_id,
                'type' => 'booking',
                'priority' => 'high',
                'user_id' => $booking->created_by ?? auth()->id(),
                'title' => 'Booking Cancelled',
                'message' => "{$customerName} — Booking {$booking->reference_number} cancelled",
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

        try {
            $gcalService = new GoogleCalendarService($booking->location_id);
            if ($gcalService->isConnected()) {
                if ($validated['status'] === 'cancelled') {
                    $gcalService->deleteEvent($booking);
                } else {
                    $gcalService->updateEventFromBooking($booking);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Google Calendar sync failed on status update', ['booking_id' => $booking->id, 'error' => $e->getMessage()]);
        }

        $customerName = $booking->customer ? "{$booking->customer->first_name} {$booking->customer->last_name}" : $booking->guest_name;
        ActivityLog::log(
            action: 'Booking Status Changed',
            category: 'update',
            description: "Booking {$booking->reference_number} status changed from '{$previousStatus}' to '{$validated['status']}'",
            userId: auth()->id(),
            locationId: $booking->location_id,
            entityType: 'booking',
            entityId: $booking->id,
            metadata: [
                'reference_number' => $booking->reference_number,
                'customer_name' => $customerName,
                'customer_id' => $booking->customer_id,
                'changed_by' => auth()->user() ? auth()->user()->name ?? auth()->user()->email : 'System',
                'changed_at' => now()->toIso8601String(),
                'status_change' => [
                    'from' => $previousStatus,
                    'to' => $validated['status'],
                ],
                'booking_date' => $booking->booking_date,
                'booking_time' => $booking->booking_time,
                'total_amount' => $booking->total_amount,
                'amount_paid' => $booking->amount_paid,
            ]
        );

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

        $customerName = $booking->customer ? "{$booking->customer->first_name} {$booking->customer->last_name}" : $booking->guest_name;
        ActivityLog::log(
            action: 'Payment Status Changed',
            category: 'update',
            description: "Booking {$booking->reference_number} payment status changed from '{$previousStatus}' to '{$validated['payment_status']}'",
            userId: auth()->id(),
            locationId: $booking->location_id,
            entityType: 'booking',
            entityId: $booking->id,
            metadata: [
                'reference_number' => $booking->reference_number,
                'customer_name' => $customerName,
                'customer_id' => $booking->customer_id,
                'changed_by' => auth()->user() ? auth()->user()->name ?? auth()->user()->email : 'System',
                'changed_at' => now()->toIso8601String(),
                'payment_status_change' => [
                    'from' => $previousStatus,
                    'to' => $validated['payment_status'],
                ],
                'total_amount' => $booking->total_amount,
                'amount_paid' => $booking->amount_paid,
                'booking_date' => $booking->booking_date,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Booking payment status updated successfully',
            'data' => $booking,
        ]);
    }


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

            try {
                $gcalService = new GoogleCalendarService($booking->location_id);
                if ($gcalService->isConnected() && $booking->google_calendar_event_id) {
                    $gcalService->deleteEvent($booking);
                }
            } catch (\Exception $e) {
                Log::warning('Google Calendar event removal failed on bulk delete', ['booking_id' => $booking->id, 'error' => $e->getMessage()]);
            }

            $booking->delete();
            $deletedCount++;
        }

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

    public function updateInternalNotes(Request $request, string $id): JsonResponse
    {
        $booking = Booking::findOrFail($id);

        $validated = $request->validate([
            'internal_notes' => 'nullable|string',
        ]);

        $booking->internal_notes = $validated['internal_notes'] ?? null;
        $booking->save();

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
            'data' => [
                'id' => $booking->id,
                'internal_notes' => $booking->internal_notes,
            ],
        ]);
    }

    private function sendNotificationEmail(Booking $booking, string $action = 'updated'): void
    {
        try {
            $triggerType = match ($action) {
                'updated' => EmailNotification::TRIGGER_BOOKING_UPDATED,
                'cancelled' => EmailNotification::TRIGGER_BOOKING_CANCELLED,
                default => EmailNotification::TRIGGER_BOOKING_UPDATED,
            };

            $emailService = app(EmailNotificationService::class);
            $emailService->triggerBookingNotification($booking, $triggerType);

            Log::info('Booking notification email sent via service', [
                'booking_id' => $booking->id,
                'action' => $action,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send booking notification email', [
                'booking_id' => $booking->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function destroy($id): JsonResponse
    {
        Log::info('Booking delete request received', ['id' => $id, 'ip' => request()->ip()]);

        try {
            $booking = Booking::find($id);

            if (!$booking) {
                Log::warning('Booking delete failed: not found', ['id' => $id]);
                return response()->json([
                    'success' => false,
                    'message' => 'Booking not found',
                ], 404);
            }

            $deletedBy = null;
            $user = null;
            try {
                $authUser = auth()->user();
                if ($authUser instanceof User) {
                    $deletedBy = $authUser->id;
                    $user = $authUser;
                }
            } catch (\Exception $e) {
                Log::info('Auth resolution skipped on booking delete', ['error' => $e->getMessage()]);
            }

            try {
                $gcalService = new GoogleCalendarService($booking->location_id);
                if ($gcalService->isConnected() && $booking->google_calendar_event_id) {
                    $gcalService->deleteEvent($booking);
                }
            } catch (\Exception $e) {
                Log::warning('Google Calendar event removal failed on delete', ['booking_id' => $booking->id, 'error' => $e->getMessage()]);
            }

            $refNumber = $booking->reference_number;
            $locationId = $booking->location_id;
            $bookingId = $booking->id;

            $deleted = $booking->delete();

            $verify = Booking::withTrashed()->find($bookingId);
            Log::info('Booking delete verification', [
                'id' => $bookingId,
                'delete_returned' => $deleted,
                'deleted_at' => $verify?->deleted_at,
                'trashed' => $verify?->trashed(),
            ]);

            if (!$deleted || !$verify?->trashed()) {
                Log::warning('Booking soft delete did not persist, forcing via query', ['id' => $bookingId]);
                Booking::where('id', $bookingId)->update(['deleted_at' => now()]);
            }

            $deletedByName = $user ? "{$user->first_name} {$user->last_name}" : 'system/public';
            ActivityLog::log(
                action: 'Booking Deleted',
                category: 'delete',
                description: "Booking {$refNumber} deleted by {$deletedByName}",
                userId: $deletedBy,
                locationId: $locationId,
                entityType: 'booking',
                entityId: $bookingId,
                metadata: ['reference_number' => $refNumber]
            );

            Log::info('Booking deleted successfully', ['id' => $bookingId, 'reference_number' => $refNumber]);

            return response()->json([
                'success' => true,
                'message' => 'Booking deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Booking delete failed with exception', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete booking: ' . $e->getMessage(),
            ], 500);
        }
    }

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

    public function summariesExport(Request $request)
    {
        $query = Booking::with(['customer', 'package', 'location', 'room', 'attractions', 'addOns', 'payments']);

        $dateRange = null;
        $location = null;

        if ($request->has('booking_ids')) {
            $ids = is_array($request->booking_ids)
                ? $request->booking_ids
                : explode(',', $request->booking_ids);
            $query->whereIn('id', $ids);
        }

        if ($request->has('date')) {
            $date = $request->date;
            $query->whereDate('booking_date', $date);
            $dateRange = ['start' => $date, 'end' => $date];
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('booking_date', [$request->start_date, $request->end_date]);
            $dateRange = ['start' => $request->start_date, 'end' => $request->end_date];
        }

        if ($request->has('week')) {
            $weekParam = $request->week;

            if ($weekParam === 'current') {
                $startOfWeek = now()->startOfWeek();
                $endOfWeek = now()->endOfWeek();
            } elseif ($weekParam === 'next') {
                $startOfWeek = now()->addWeek()->startOfWeek();
                $endOfWeek = now()->addWeek()->endOfWeek();
            } else {
                $date = \Carbon\Carbon::parse($weekParam);
                $startOfWeek = $date->startOfWeek();
                $endOfWeek = $date->copy()->endOfWeek();
            }

            $query->whereBetween('booking_date', [$startOfWeek, $endOfWeek]);
            $dateRange = ['start' => $startOfWeek->format('Y-m-d'), 'end' => $endOfWeek->format('Y-m-d')];
        }

        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
            $location = \App\Models\Location::find($request->location_id);
        }

        $this->applyAuthScope($query, $request);
        $authUser = $this->resolveAuthUser($request);
        if ($authUser && in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
            $location = $location ?? \App\Models\Location::find($authUser->location_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if (!$request->has('include_cancelled')) {
            $query->where('status', '!=', 'cancelled');
        }

        $query->orderBy('booking_date', 'asc')->orderBy('booking_time', 'asc');

        $bookings = $query->get();

        if ($bookings->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No bookings found for the specified criteria'
            ], 404);
        }

        $viewMode = $request->get('view_mode', 'full'); // 'compact' or 'full'

        $pdf = Pdf::loadView('exports.booking-summaries-report', [
            'bookings' => $bookings,
            'dateRange' => $dateRange,
            'location' => $location,
            'viewMode' => $viewMode,
            'companyName' => config('app.name', 'ZapZone'),
        ]);

        $pdf->setPaper('A4', 'portrait');

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

        if ($request->get('stream', false)) {
            return $pdf->stream($filename);
        }

        return $pdf->download($filename);
    }

    public function summariesDay(Request $request, string $date)
    {
        $request->merge(['date' => $date]);
        return $this->summariesExport($request);
    }

    public function summariesWeek(Request $request, string $week = 'current')
    {
        $request->merge(['week' => $week]);
        return $this->summariesExport($request);
    }

    public function bookingDetailsReport(Request $request)
    {
        Log::info('Booking details report generation initiated', [
            'user_id' => $request->user_id ?? auth()->id(),
            'request_params' => $request->all(),
        ]);

        $validated = $request->validate([
            'package_ids' => 'required',
            'period_type' => ['required', Rule::in(['today', 'weekly', 'monthly', 'custom'])],
            'week_of_month' => 'required_if:period_type,weekly|integer|min:1|max:5',
            'month' => 'required_if:period_type,monthly,weekly|integer|min:1|max:12',
            'year' => 'required_if:period_type,monthly,weekly|integer|min:2020|max:2050',
            'start_date' => 'required_if:period_type,custom|date',
            'end_date' => 'required_if:period_type,custom|date|after_or_equal:start_date',
            'view_mode' => ['sometimes', Rule::in(['list', 'individual'])],
            'location_id' => 'sometimes|exists:locations,id',
            'status' => 'sometimes|string',
            'include_cancelled' => 'sometimes|boolean',
        ]);

        Log::info('Report parameters validated', [
            'package_ids' => $validated['package_ids'],
            'period_type' => $validated['period_type'],
            'view_mode' => $validated['view_mode'] ?? 'individual',
        ]);

        $query = Booking::with(['customer', 'package', 'location', 'location.company', 'room', 'attractions', 'addOns', 'payments']);

        if ($validated['package_ids'] !== 'all') {
            $packageIds = is_array($validated['package_ids'])
                ? $validated['package_ids']
                : explode(',', $validated['package_ids']);
            $query->whereIn('package_id', $packageIds);
        }

        $dateRange = null;
        switch ($validated['period_type']) {
            case 'today':
                $today = Carbon::today()->toDateString();
                $query->whereDate('booking_date', $today);
                $dateRange = ['start' => $today, 'end' => $today];
                break;

            case 'weekly':
                $year = $validated['year'];
                $month = $validated['month'];
                $weekOfMonth = $validated['week_of_month'];

                $firstDayOfMonth = Carbon::create($year, $month, 1);
                $startOfWeek = $firstDayOfMonth->copy()->addWeeks($weekOfMonth - 1)->startOfWeek();
                $endOfWeek = $startOfWeek->copy()->endOfWeek();

                $lastDayOfMonth = $firstDayOfMonth->copy()->endOfMonth();
                if ($endOfWeek->gt($lastDayOfMonth)) {
                    $endOfWeek = $lastDayOfMonth;
                }

                $query->whereBetween('booking_date', [
                    $startOfWeek->toDateString(),
                    $endOfWeek->toDateString()
                ]);
                $dateRange = [
                    'start' => $startOfWeek->toDateString(),
                    'end' => $endOfWeek->toDateString()
                ];
                break;

            case 'monthly':
                $year = $validated['year'];
                $month = $validated['month'];
                $startOfMonth = Carbon::create($year, $month, 1);
                $endOfMonth = $startOfMonth->copy()->endOfMonth();

                $query->whereBetween('booking_date', [
                    $startOfMonth->toDateString(),
                    $endOfMonth->toDateString()
                ]);
                $dateRange = [
                    'start' => $startOfMonth->toDateString(),
                    'end' => $endOfMonth->toDateString()
                ];
                break;

            case 'custom':
                $query->whereBetween('booking_date', [
                    $validated['start_date'],
                    $validated['end_date']
                ]);
                $dateRange = [
                    'start' => $validated['start_date'],
                    'end' => $validated['end_date']
                ];
                break;
        }

        if (isset($validated['location_id'])) {
            $query->where('location_id', $validated['location_id']);
        }

        $this->applyAuthScope($query, $request);

        if (isset($validated['status'])) {
            $statuses = is_array($validated['status'])
                ? $validated['status']
                : explode(',', $validated['status']);
            $query->whereIn('status', $statuses);
        }

        if (!($validated['include_cancelled'] ?? false)) {
            $query->where('status', '!=', 'cancelled');
        }

        $query->orderBy('booking_date', 'asc')->orderBy('booking_time', 'asc');

        Log::info('SQL Query for booking details report', [
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings(),
        ]);

        $bookings = $query->get();

        Log::info('Bookings queried for report', [
            'total_bookings_found' => $bookings->count(),
            'date_range' => $dateRange,
            'period_type' => $validated['period_type'],
            'package_ids' => $validated['package_ids'],
            'user_id' => $request->user_id ?? null,
        ]);

        if ($bookings->isEmpty()) {
            Log::warning('No bookings found for report criteria', [
                'period_type' => $validated['period_type'],
                'date_range' => $dateRange,
                'package_ids' => $validated['package_ids'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No bookings found for the specified criteria'
            ], 404);
        }

        $packageNames = 'All Packages';
        if ($validated['package_ids'] !== 'all') {
            $packageIds = is_array($validated['package_ids'])
                ? $validated['package_ids']
                : explode(',', $validated['package_ids']);
            $packages = \App\Models\Package::whereIn('id', $packageIds)->pluck('name');
            $packageNames = $packages->join(', ');
        }

        $viewMode = $validated['view_mode'] ?? 'individual';

        $view = $viewMode === 'list'
            ? 'exports.booking-details-list'
            : 'exports.booking-details-individual';

        $pdf = Pdf::loadView($view, [
            'bookings' => $bookings,
            'dateRange' => $dateRange,
            'periodType' => $validated['period_type'],
            'packageNames' => $packageNames,
            'viewMode' => $viewMode,
            'companyName' => config('app.name', 'ZapZone'),
        ]);

        $pdf->setPaper('A4', 'portrait');

        $filename = 'booking-details';
        if ($validated['period_type'] === 'today') {
            $filename .= '-today-' . Carbon::today()->format('Y-m-d');
        } elseif ($validated['period_type'] === 'weekly') {
            $filename .= '-week' . $validated['week_of_month'] . '-' . $validated['year'] . '-' . str_pad($validated['month'], 2, '0', STR_PAD_LEFT);
        } elseif ($validated['period_type'] === 'monthly') {
            $filename .= '-' . $validated['year'] . '-' . str_pad($validated['month'], 2, '0', STR_PAD_LEFT);
        } else {
            $filename .= '-' . $validated['start_date'] . '-to-' . $validated['end_date'];
        }
        $filename .= '-' . $viewMode . '.pdf';

        Log::info('Booking details report generated successfully', [
            'filename' => $filename,
            'total_bookings' => $bookings->count(),
            'view_mode' => $viewMode,
            'package_names' => $packageNames,
            'date_range' => $dateRange,
            'stream' => $request->get('stream', false),
        ]);

        if ($request->get('stream', false)) {
            return $pdf->stream($filename);
        }

        return $pdf->download($filename);
    }

    private function sendBookingReminders(): void
    {
        $today = Carbon::now()->toDateString();
        $tomorrow = Carbon::tomorrow()->toDateString();

        Log::info('Checking for booking reminders', [
            'current_date' => $today,
            'tomorrow_date' => $tomorrow,
            'checking_for_bookings_on' => $tomorrow,
        ]);

        $bookingsToRemind = Booking::with(['customer', 'package', 'location', 'location.company', 'room'])
            ->where('booking_date', $tomorrow)
            ->where('reminder_sent', false)
            ->whereIn('status', ['confirmed', 'pending']) // Only remind for active bookings
            ->get();

        Log::info('Bookings found for reminder check', [
            'count' => $bookingsToRemind->count(),
            'tomorrow_date' => $tomorrow,
        ]);

        if ($bookingsToRemind->isEmpty()) {
            Log::info('No bookings require reminders at this time', [
                'tomorrow_date' => $tomorrow,
            ]);
            return;
        }

        foreach ($bookingsToRemind as $booking) {
            Log::info('Processing booking for reminder', [
                'booking_id' => $booking->id,
                'reference_number' => $booking->reference_number,
                'booking_date' => $booking->booking_date,
                'is_tomorrow' => $booking->booking_date === $tomorrow,
                'reminder_sent' => $booking->reminder_sent,
                'status' => $booking->status,
            ]);
            $recipientEmail = $booking->customer?->email ?? $booking->guest_email;

            if (!$recipientEmail) {
                Log::warning('Booking reminder skipped - no email address', [
                    'booking_id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                ]);
                continue;
            }

            try {
                $emailService = app(EmailNotificationService::class);
                $emailService->triggerBookingNotification($booking, EmailNotification::TRIGGER_BOOKING_REMINDER);

                $booking->update([
                    'reminder_sent' => true,
                    'reminder_sent_at' => Carbon::now(),
                ]);

                Log::info('Booking reminder sent successfully', [
                    'booking_id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'recipient' => $recipientEmail,
                    'booking_date' => $booking->booking_date,
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to send booking reminder', [
                    'booking_id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'recipient' => $recipientEmail,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function trashed(Request $request): JsonResponse
    {
        try {
            $query = Booking::onlyTrashed()->with(['customer', 'package', 'location', 'room', 'creator']);

            $this->applyAuthScope($query, $request);

            if ($request->has('location_id')) {
                $query->where('location_id', $request->location_id);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('reference_number', 'like', "%{$search}%")
                      ->orWhere('guest_name', 'like', "%{$search}%")
                      ->orWhere('guest_email', 'like', "%{$search}%")
                      ->orWhereHas('customer', function ($subQ) use ($search) {
                          $subQ->where('first_name', 'like', "%{$search}%")
                               ->orWhere('last_name', 'like', "%{$search}%")
                               ->orWhere('email', 'like', "%{$search}%");
                      });
                });
            }

            $sortBy = $request->get('sort_by', 'deleted_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $perPage = min($request->get('per_page', 15), 100);
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
        } catch (\Exception $e) {
            Log::error('Error fetching trashed bookings', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch trashed bookings',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error',
            ], 500);
        }
    }

    public function restore(int $id): JsonResponse
    {
        $booking = Booking::onlyTrashed()->findOrFail($id);

        $booking->restore();
        $booking->load(['customer', 'package', 'location', 'room', 'creator', 'attractions', 'addOns']);

        try {
            $gcalService = new GoogleCalendarService($booking->location_id);
            if ($gcalService->isConnected() && !$booking->google_calendar_event_id) {
                $gcalService->createEventFromBooking($booking);
            }
        } catch (\Exception $e) {
            Log::warning('Google Calendar event creation failed on restore', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Booking Restored',
            category: 'update',
            description: "Booking {$booking->reference_number} restored",
            userId: auth()->id(),
            locationId: $booking->location_id,
            entityType: 'booking',
            entityId: $booking->id,
            metadata: [
                'restored_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'restored_at' => now()->toIso8601String(),
                'reference_number' => $booking->reference_number,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Booking restored successfully',
            'data' => $booking,
        ]);
    }

    public function forceDelete(int $id): JsonResponse
    {
        $booking = Booking::onlyTrashed()->findOrFail($id);

        $referenceNumber = $booking->reference_number;
        $bookingId = $booking->id;
        $locationId = $booking->location_id;

        try {
            $gcalService = new GoogleCalendarService($booking->location_id);
            if ($gcalService->isConnected() && $booking->google_calendar_event_id) {
                $gcalService->deleteEvent($booking);
            }
        } catch (\Exception $e) {
            Log::warning('Google Calendar event removal failed on force delete', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        $booking->forceDelete();

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Booking Permanently Deleted',
            category: 'delete',
            description: "Booking {$referenceNumber} permanently deleted",
            userId: auth()->id(),
            locationId: $locationId,
            entityType: 'booking',
            entityId: $bookingId,
            metadata: [
                'deleted_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'deleted_at' => now()->toIso8601String(),
                'reference_number' => $referenceNumber,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Booking permanently deleted',
        ]);
    }

    public function bulkRestore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer',
        ]);

        $bookings = Booking::onlyTrashed()->whereIn('id', $validated['ids'])->get();
        $restoredCount = 0;

        foreach ($bookings as $booking) {
            $booking->restore();
            $restoredCount++;

            try {
                $gcalService = new GoogleCalendarService($booking->location_id);
                if ($gcalService->isConnected() && !$booking->google_calendar_event_id) {
                    $gcalService->createEventFromBooking($booking);
                }
            } catch (\Exception $e) {
                Log::warning('Google Calendar event creation failed on bulk restore', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $currentUser = auth()->user();
        ActivityLog::log(
            action: 'Bulk Bookings Restored',
            category: 'update',
            description: "{$restoredCount} bookings restored in bulk operation",
            userId: auth()->id(),
            entityType: 'booking',
            metadata: [
                'restored_by' => [
                    'user_id' => auth()->id(),
                    'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                    'email' => $currentUser?->email,
                ],
                'restored_at' => now()->toIso8601String(),
                'restored_count' => $restoredCount,
                'booking_ids' => $validated['ids'],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "{$restoredCount} bookings restored successfully",
            'data' => ['restored_count' => $restoredCount],
        ]);
    }

    public function publicForceDelete($id): JsonResponse
    {
        Log::info('Booking public force delete request', ['id' => $id, 'ip' => request()->ip()]);

        try {
            $booking = Booking::withTrashed()->find($id);

            if (!$booking) {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking not found',
                ], 404);
            }

            if (!$booking->trashed() && $booking->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending bookings can be force deleted',
                ], 403);
            }

            $referenceNumber = $booking->reference_number;
            $bookingId = $booking->id;
            $locationId = $booking->location_id;

            try {
                $gcalService = new GoogleCalendarService($booking->location_id);
                if ($gcalService->isConnected() && $booking->google_calendar_event_id) {
                    $gcalService->deleteEvent($booking);
                }
            } catch (\Exception $e) {
                Log::warning('Google Calendar cleanup failed during public force delete', [
                    'booking_id' => $bookingId,
                    'error' => $e->getMessage(),
                ]);
            }

            $booking->forceDelete();

            ActivityLog::log(
                action: 'Booking Force Deleted (Payment Error)',
                category: 'delete',
                description: "Pending booking {$referenceNumber} force deleted due to payment error",
                userId: null,
                locationId: $locationId,
                entityType: 'booking',
                entityId: $bookingId,
                metadata: [
                    'reason' => 'payment_error_cleanup',
                    'reference_number' => $referenceNumber,
                    'deleted_at' => now()->toIso8601String(),
                ]
            );

            Log::info('Booking force deleted successfully', ['id' => $bookingId, 'reference' => $referenceNumber]);

            return response()->json([
                'success' => true,
                'message' => 'Booking permanently deleted',
            ]);
        } catch (\Exception $e) {
            Log::error('Booking public force delete failed', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to force delete booking: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function bulkImportCsv(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240', // 10MB max
            'location_id' => 'required|exists:locations,id',
            'skip_duplicates' => 'nullable|boolean',
        ]);

        $locationId = (int) $request->location_id;
        $createdBy = auth()->id();

        try {
            $service = new \App\Services\BookingCsvImportService();
            $file = $request->file('file');

            $rows = $service->parseFile(
                $file->getRealPath(),
                $file->getClientOriginalName()
            );

            if (empty($rows)) {
                return response()->json([
                    'success' => false,
                    'message' => 'CSV file is empty or contains no data rows.',
                ], 422);
            }

            \Illuminate\Support\Facades\DB::beginTransaction();

            try {
                $result = $service->processRows($rows, $locationId, $createdBy);

                \Illuminate\Support\Facades\DB::commit();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\DB::rollBack();
                throw $e;
            }

            $currentUser = auth()->user();
            ActivityLog::log(
                action: 'Bookings Bulk Import (CSV)',
                category: 'create',
                description: "Imported {$result['imported']} bookings from CSV, skipped {$result['skipped']}",
                userId: auth()->id(),
                locationId: $locationId,
                metadata: [
                    'imported_by' => [
                        'user_id' => auth()->id(),
                        'name' => $currentUser ? $currentUser->first_name . ' ' . $currentUser->last_name : null,
                        'email' => $currentUser?->email,
                    ],
                    'imported_at' => now()->toIso8601String(),
                    'import_details' => [
                        'location_id' => $locationId,
                        'total_rows' => count($rows),
                        'imported_count' => $result['imported'],
                        'skipped_count' => $result['skipped'],
                        'errors_count' => count($result['errors']),
                    ],
                ]
            );

            return response()->json([
                'success' => true,
                'message' => "Imported {$result['imported']} bookings successfully",
                'data' => [
                    'imported' => $result['imported'],
                    'skipped' => $result['skipped'],
                    'errors' => $result['errors'],
                    'total_rows' => count($rows),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Booking CSV bulk import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
            ], 500);
        }
    }

}

