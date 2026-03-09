<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\EventPurchaseConfirmation;
use App\Models\EventPurchase;
use App\Models\Event;
use App\Services\GmailApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EventPurchaseController extends Controller
{
    /**
     * List event purchases.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = EventPurchase::with([
                'event:id,name,start_date,end_date,time_start,time_end,price',
                'customer:id,first_name,last_name,email,phone',
                'location:id,name',
                'addOns:id,name',
            ]);

            if ($request->has('event_id')) {
                $query->byEvent($request->event_id);
            }
            if ($request->has('location_id')) {
                $query->byLocation($request->location_id);
            }
            if ($request->has('status')) {
                $query->byStatus($request->status);
            }
            if ($request->has('customer_id')) {
                $query->byCustomer($request->customer_id);
            }
            if ($request->has('purchase_date')) {
                $query->byDate($request->purchase_date);
            }

            $purchases = $query->orderBy('purchase_date', 'desc')
                ->orderBy('purchase_time', 'asc')
                ->get();

            return response()->json($purchases);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to fetch event purchases', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Store a new event purchase.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'event_id' => 'required|exists:events,id',
                'customer_id' => 'nullable|exists:customers,id',
                'location_id' => 'required|exists:locations,id',
                'guest_name' => 'nullable|string|max:255',
                'guest_email' => 'nullable|email|max:255',
                'guest_phone' => 'nullable|string|max:50',
                'purchase_date' => 'required|date',
                'purchase_time' => 'required|date_format:H:i',
                'quantity' => 'required|integer|min:1',
                'total_amount' => 'nullable|numeric|min:0',
                'amount_paid' => 'nullable|numeric|min:0',
                'discount_amount' => 'nullable|numeric|min:0',
                'payment_method' => 'nullable|in:card,in-store,paylater,authorize.net',
                'payment_status' => 'in:paid,partial,pending',
                'transaction_id' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
                'special_requests' => 'nullable|string',
                'send_email' => 'nullable|boolean',
                'add_ons' => 'nullable|array',
                'add_ons.*.add_on_id' => 'required_with:add_ons|exists:add_ons,id',
                'add_ons.*.quantity' => 'required_with:add_ons|integer|min:1',
                'add_ons.*.price_at_purchase' => 'required_with:add_ons|numeric|min:0',
            ]);

            // Validate the event exists and is active
            $event = Event::findOrFail($validated['event_id']);
            if (!$event->is_active) {
                return response()->json(['message' => 'This event is not currently active'], 422);
            }

            // Validate date is valid for the event
            if (!$event->isDateValid($validated['purchase_date'])) {
                return response()->json(['message' => 'Selected date is not available for this event'], 422);
            }

            // Validate time slot is available
            $availableSlots = $event->getAvailableTimeSlotsForDate($validated['purchase_date']);
            if (!in_array($validated['purchase_time'], $availableSlots)) {
                return response()->json(['message' => 'Selected time slot is not available'], 422);
            }

            // --- Duplicate purchase prevention (time-window idempotency) ---
            // Same event, date, time, quantity, and customer/guest within 2 minutes
            $duplicateQuery = EventPurchase::where('event_id', $validated['event_id'])
                ->where('purchase_date', $validated['purchase_date'])
                ->where('purchase_time', $validated['purchase_time'])
                ->where('quantity', $validated['quantity'])
                ->where('created_at', '>=', now()->subMinutes(2));

            if (!empty($validated['customer_id'])) {
                $duplicateQuery->where('customer_id', $validated['customer_id']);
            } else {
                $duplicateQuery->where('guest_email', $validated['guest_email'] ?? null);
            }

            $existing = $duplicateQuery->first();
            if ($existing) {
                $existing->load(['event.location.company', 'customer', 'location:id,name', 'addOns']);
                Log::info('Duplicate event purchase prevented (time-window)', [
                    'existing_id' => $existing->id,
                    'event_id' => $validated['event_id'],
                    'customer_id' => $validated['customer_id'] ?? null,
                    'guest_email' => $validated['guest_email'] ?? null,
                ]);
                return response()->json($existing, 200);
            }
            // --- End duplicate prevention ---

            // Generate reference number
            $validated['reference_number'] = 'EVT-' . strtoupper(Str::random(8));

            // Extract add_ons and send_email before creating purchase
            $addOns = $validated['add_ons'] ?? [];
            $sendEmail = $validated['send_email'] ?? true;
            unset($validated['add_ons'], $validated['send_email']);

            $purchase = EventPurchase::create($validated);

            // Attach add-ons
            if (!empty($addOns)) {
                foreach ($addOns as $addOn) {
                    $purchase->addOns()->attach($addOn['add_on_id'], [
                        'quantity' => $addOn['quantity'],
                        'price_at_purchase' => $addOn['price_at_purchase'],
                    ]);
                }
            }

            $purchase->load(['event.location.company', 'customer', 'location:id,name', 'addOns']);

            // Send confirmation email
            if ($sendEmail) {
                try {
                    $recipientEmail = $purchase->customer?->email ?? $purchase->guest_email;
                    if ($recipientEmail) {
                        $this->sendConfirmationEmail($purchase, $recipientEmail);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to send event purchase confirmation email', [
                        'purchase_id' => $purchase->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json($purchase, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to create event purchase', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Show a single event purchase.
     */
    public function show(EventPurchase $eventPurchase): JsonResponse
    {
        return response()->json(
            $eventPurchase->load(['event', 'customer', 'location:id,name', 'addOns'])
        );
    }

    /**
     * Update an event purchase.
     */
    public function update(Request $request, EventPurchase $eventPurchase): JsonResponse
    {
        try {
            $validated = $request->validate([
                'guest_name' => 'nullable|string|max:255',
                'guest_email' => 'nullable|email|max:255',
                'guest_phone' => 'nullable|string|max:50',
                'purchase_date' => 'sometimes|date',
                'purchase_time' => 'sometimes|date_format:H:i',
                'quantity' => 'sometimes|integer|min:1',
                'total_amount' => 'nullable|numeric|min:0',
                'amount_paid' => 'nullable|numeric|min:0',
                'discount_amount' => 'nullable|numeric|min:0',
                'payment_method' => 'nullable|in:card,in-store,paylater,authorize.net',
                'payment_status' => 'in:paid,partial,pending',
                'status' => 'in:pending,confirmed,checked-in,completed,cancelled',
                'notes' => 'nullable|string',
                'special_requests' => 'nullable|string',
                'add_ons' => 'nullable|array',
                'add_ons.*.add_on_id' => 'required_with:add_ons|exists:add_ons,id',
                'add_ons.*.quantity' => 'required_with:add_ons|integer|min:1',
                'add_ons.*.price_at_purchase' => 'required_with:add_ons|numeric|min:0',
            ]);

            // Extract add_ons before updating
            $addOns = $validated['add_ons'] ?? null;
            unset($validated['add_ons']);

            // Handle status timestamps
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

            $eventPurchase->update($validated);

            // Sync add-ons if provided
            if ($addOns !== null) {
                $syncData = [];
                foreach ($addOns as $addOn) {
                    $syncData[$addOn['add_on_id']] = [
                        'quantity' => $addOn['quantity'],
                        'price_at_purchase' => $addOn['price_at_purchase'],
                    ];
                }
                $eventPurchase->addOns()->sync($syncData);
            }

            return response()->json(
                $eventPurchase->fresh()->load(['event:id,name', 'customer:id,first_name,last_name,email', 'location:id,name', 'addOns'])
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to update event purchase', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete an event purchase (soft delete).
     */
    public function destroy(EventPurchase $eventPurchase): JsonResponse
    {
        try {
            $eventPurchase->delete();
            return response()->json(['message' => 'Event purchase deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete event purchase', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Cancel an event purchase.
     */
    public function cancel(EventPurchase $eventPurchase): JsonResponse
    {
        $eventPurchase->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return response()->json($eventPurchase->fresh()->load(['event:id,name', 'addOns']));
    }

    /**
     * Update event purchase status.
     */
    public function updateStatus(Request $request, EventPurchase $eventPurchase): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,confirmed,checked-in,completed,cancelled',
        ]);

        $updates = ['status' => $validated['status']];

        switch ($validated['status']) {
            case 'checked-in':
                $updates['checked_in_at'] = now();
                break;
            case 'completed':
                $updates['completed_at'] = now();
                break;
            case 'cancelled':
                $updates['cancelled_at'] = now();
                break;
        }

        $eventPurchase->update($updates);

        return response()->json($eventPurchase->fresh());
    }

    /**
     * Get purchases for a customer.
     */
    public function customerPurchases(Request $request): JsonResponse
    {
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
        ]);

        $purchases = EventPurchase::with(['event:id,name,start_date,end_date,image', 'location:id,name', 'addOns'])
            ->byCustomer($request->customer_id)
            ->orderBy('purchase_date', 'desc')
            ->get();

        return response()->json($purchases);
    }

    /**
     * Send confirmation email for event purchase.
     */
    private function sendConfirmationEmail(EventPurchase $purchase, string $recipientEmail): void
    {
        $purchase->loadMissing(['event.location.company', 'customer', 'addOns']);

        $useGmailApi = config('gmail.enabled', false) &&
            (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

        $mailable = new EventPurchaseConfirmation($purchase);
        $emailBody = $mailable->render();
        $subject = 'Event Purchase Confirmation - ' . $purchase->reference_number;

        if ($useGmailApi) {
            $gmailService = new GmailApiService();
            $gmailService->sendEmail($recipientEmail, $subject, $emailBody);
        } else {
            Mail::send([], [], function ($message) use ($recipientEmail, $subject, $emailBody) {
                $message->to($recipientEmail)
                    ->subject($subject)
                    ->html($emailBody);
            });
        }

        Log::info('Event purchase confirmation email sent', [
            'purchase_id' => $purchase->id,
            'email' => $recipientEmail,
        ]);
    }
}
