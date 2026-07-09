<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\RecordsPageAnalytics;
use App\Http\Traits\ScopesByAuthUser;
use App\Models\Payment;
use App\Models\Booking;
use App\Models\AttractionPurchase;
use App\Models\EventPurchase;
use App\Models\Location;
use App\Models\Company;
use App\Models\Package;
use App\Models\AuthorizeNetAccount;
use App\Models\ActivityLog;
use App\Models\CustomerNotification;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use App\Mail\BookingCancellation;
use App\Mail\AttractionPurchaseCancellation;
use App\Mail\BookingConfirmation;
use App\Mail\AttractionPurchaseReceipt;
use App\Mail\StaffBookingNotification;
use App\Mail\EventPurchaseConfirmation;
use App\Services\GmailApiService;
use App\Services\EmailNotificationService;
use App\Models\EmailNotification;
use App\Services\GoogleCalendarService;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use net\authorize\api\constants\ANetEnvironment;

class PaymentController extends Controller
{
    use ScopesByAuthUser;
    use RecordsPageAnalytics;

    public function index(Request $request): JsonResponse
    {
        $query = Payment::with(['customer', 'location', 'booking', 'attractionPurchase', 'eventPurchase']);

        $authUser = $this->resolveAuthUser($request);
        if ($authUser) {
            if (in_array($authUser->role, ['location_manager', 'attendant'], true) && $authUser->location_id) {
                $query->where('location_id', $authUser->location_id);
            } elseif ($authUser->company_id) {
                $query->whereHas('location', fn($q) => $q->where('company_id', $authUser->company_id));
            }
        }

        if ($request->has('payable_id')) {
            $query->where('payable_id', $request->payable_id);
        }

        if ($request->has('payable_type')) {
            $query->where('payable_type', $request->payable_type);
        }

        if ($request->has('booking_id')) {
            $query->where('payable_id', $request->booking_id)
                  ->where('payable_type', Payment::TYPE_BOOKING);
        }

        if ($request->has('attraction_purchase_id')) {
            $query->where('payable_id', $request->attraction_purchase_id)
                  ->where('payable_type', Payment::TYPE_ATTRACTION_PURCHASE);
        }

        if ($request->has('event_purchase_id')) {
            $query->where('payable_id', $request->event_purchase_id)
                  ->where('payable_type', Payment::TYPE_EVENT_PURCHASE);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }

        if ($request->filled('method')) {
            $query->byMethod($request->method);
        }

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            $terms = preg_split('/\s+/', trim((string) $request->search), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                $query->where(function ($q) use ($like, $term) {
                    $q->where('transaction_id', 'like', $like)
                      ->orWhere('payment_id', 'like', $like)
                      ->orWhere('card_last_four', 'like', $like)
                      ->orWhere('notes', 'like', $like)
                      ->orWhereHas('customer', function ($c) use ($like) {
                          $c->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like)
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
                      });
                    if (ctype_digit($term)) {
                        $q->orWhere('id', (int) $term);
                    }
                    if (is_numeric($term)) {
                        $q->orWhere('amount', $term);
                    }
                });
            }
        }

        $allowedSorts = ['created_at', 'amount', 'status', 'method', 'paid_at', 'updated_at', 'id'];
        $sortBy = $request->get('sort_by', 'created_at');
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'created_at';
        }
        $sortOrder = strtolower((string) $request->get('sort_order', 'desc'));
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        $perPage = $request->get('per_page', 15);
        $payments = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'payments' => $payments->items(),
                'pagination' => [
                    'current_page' => $payments->currentPage(),
                    'last_page' => $payments->lastPage(),
                    'per_page' => $payments->perPage(),
                    'total' => $payments->total(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payable_id' => 'nullable|integer',
            'payable_type' => ['nullable', Rule::in([Payment::TYPE_BOOKING, Payment::TYPE_ATTRACTION_PURCHASE, Payment::TYPE_EVENT_PURCHASE])],
            'booking_id' => 'nullable|exists:bookings,id',
            'attraction_purchase_id' => 'nullable|exists:attraction_purchases,id',
            'event_purchase_id' => 'nullable|exists:event_purchases,id',
            'customer_id' => 'nullable|exists:customers,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'string|size:3',
            'method' => ['required', Rule::in(['card', 'cash', 'authorize.net', 'in-store'])],
            'status' => ['sometimes', Rule::in(['pending', 'completed', 'failed', 'refunded', 'voided'])],
            'notes' => 'nullable|string',
            'payment_id' => 'nullable|string|unique:payments,payment_id',
            'location_id' => 'nullable|exists:locations,id',
            'signature_image' => 'nullable|string',
            'terms_accepted' => 'nullable|boolean',
        ]);

        if (isset($validated['booking_id']) && !isset($validated['payable_id'])) {
            $validated['payable_id'] = $validated['booking_id'];
            $validated['payable_type'] = Payment::TYPE_BOOKING;
            unset($validated['booking_id']);
        }

        if (isset($validated['attraction_purchase_id']) && !isset($validated['payable_id'])) {
            $validated['payable_id'] = $validated['attraction_purchase_id'];
            $validated['payable_type'] = Payment::TYPE_ATTRACTION_PURCHASE;
            unset($validated['attraction_purchase_id']);
        }

        if (isset($validated['event_purchase_id']) && !isset($validated['payable_id'])) {
            $validated['payable_id'] = $validated['event_purchase_id'];
            $validated['payable_type'] = Payment::TYPE_EVENT_PURCHASE;
            unset($validated['event_purchase_id']);
        }

        if (!empty($validated['payable_id']) && !empty($validated['payable_type'])) {
            $existingPayment = Payment::where('payable_id', $validated['payable_id'])
                ->where('payable_type', $validated['payable_type'])
                ->where('amount', $validated['amount'])
                ->where('status', 'completed')
                ->where('created_at', '>=', now()->subMinutes(2))
                ->first();

            if ($existingPayment) {
                Log::info('Duplicate payment prevented (time-window check)', [
                    'existing_payment_id' => $existingPayment->id,
                    'payable_id' => $validated['payable_id'],
                    'payable_type' => $validated['payable_type'],
                    'amount' => $validated['amount'],
                ]);
                return response()->json([
                    'success' => true,
                    'message' => 'Payment already exists',
                    'data' => $existingPayment->load(['customer', 'location']),
                ], 200);
            }
        }

        $validated['transaction_id'] = 'TXN' . now()->format('YmdHis') . strtoupper(Str::random(6));

        if (isset($validated['signature_image']) && !empty($validated['signature_image'])) {
            $validated['signature_image'] = $this->handleSignatureUpload($validated['signature_image']);
        }

        if ($validated['status'] === 'completed') {
            $validated['paid_at'] = now();
        }

        $payment = Payment::create($validated);
        $payment->load(['customer', 'location']);

        if ($payment->payable_id && $payment->payable_type && $payment->status === 'completed') {
            if ($payment->payable_type === Payment::TYPE_BOOKING) {
                $payable = Booking::find($payment->payable_id);
                if ($payable) {
                    $totalPaid = Payment::where('payable_id', $payable->id)
                        ->where('payable_type', Payment::TYPE_BOOKING)
                        ->where('status', 'completed')
                        ->sum('amount');
                    $payable->update([
                        'amount_paid' => $totalPaid,
                        'payment_status' => $totalPaid >= $payable->total_amount ? 'paid' : 'partial',
                    ]);
                }
            } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE) {
                $payable = AttractionPurchase::find($payment->payable_id);
                if ($payable) {
                    $totalPaid = Payment::where('payable_id', $payable->id)
                        ->where('payable_type', Payment::TYPE_ATTRACTION_PURCHASE)
                        ->where('status', 'completed')
                        ->sum('amount');
                    $payable->update([
                        'amount_paid' => $totalPaid,
                        'status' => $totalPaid >= $payable->total_amount ? AttractionPurchase::STATUS_CONFIRMED : AttractionPurchase::STATUS_PENDING,
                    ]);
                }
            } elseif ($payment->payable_type === Payment::TYPE_EVENT_PURCHASE) {
                $payable = EventPurchase::find($payment->payable_id);
                if ($payable) {
                    $totalPaid = Payment::where('payable_id', $payable->id)
                        ->where('payable_type', Payment::TYPE_EVENT_PURCHASE)
                        ->where('status', 'completed')
                        ->sum('amount');
                    $payable->update([
                        'amount_paid' => $totalPaid,
                        'payment_status' => $totalPaid >= $payable->total_amount ? 'paid' : 'partial',
                    ]);
                }
            }
        }

        if ($payment->customer_id && $payment->status === 'completed') {
            CustomerNotification::create([
                'customer_id' => $payment->customer_id,
                'location_id' => $payment->location_id,
                'type' => 'payment',
                'priority' => 'medium',
                'title' => 'Payment Received',
                'message' => "Your payment of $" . number_format($payment->amount, 2) . " has been processed successfully.",
                'status' => 'unread',
                'action_url' => "/payments/{$payment->id}",
                'action_text' => 'View Payment',
                'metadata' => [
                    'payment_id' => $payment->id,
                    'transaction_id' => $payment->transaction_id,
                    'amount' => $payment->amount,
                    'method' => $payment->method,
                ],
            ]);
        }

        if ($payment->location_id && $payment->status === 'completed') {
            Notification::create([
                'location_id' => $payment->location_id,
                'type' => 'payment',
                'priority' => 'medium',
                'user_id' => auth()->id(),
                'title' => 'Payment Received',
                'message' => "Payment of $" . number_format($payment->amount, 2) . " received via {$payment->method}. Transaction: {$payment->transaction_id}",
                'status' => 'unread',
                'action_url' => "/payments/{$payment->id}",
                'action_text' => 'View Payment',
                'metadata' => [
                    'payment_id' => $payment->id,
                    'transaction_id' => $payment->transaction_id,
                    'amount' => $payment->amount,
                    'method' => $payment->method,
                    'customer_id' => $payment->customer_id,
                    'payable_id' => $payment->payable_id,
                    'payable_type' => $payment->payable_type,
                ],
            ]);
        }

        $customerName = $payment->customer
            ? "{$payment->customer->first_name} {$payment->customer->last_name}"
            : 'Guest';

        ActivityLog::log(
            action: 'Payment Recorded',
            category: 'create',
            description: "Payment of $" . number_format($payment->amount, 2) . " recorded via {$payment->method} for {$customerName}",
            userId: auth()->id(),
            locationId: $payment->location_id,
            entityType: 'payment',
            entityId: $payment->id,
            metadata: [
                'transaction_id' => $payment->transaction_id,
                'recorded_by' => auth()->user() ? auth()->user()->name ?? auth()->user()->email : 'System',
                'recorded_at' => now()->toIso8601String(),
                'payment_details' => [
                    'amount' => $payment->amount,
                    'currency' => $payment->currency ?? 'USD',
                    'method' => $payment->method,
                    'status' => $payment->status,
                ],
                'customer' => [
                    'id' => $payment->customer_id,
                    'name' => $customerName,
                ],
                'payable' => [
                    'type' => $payment->payable_type,
                    'id' => $payment->payable_id,
                ],
                'location_id' => $payment->location_id,
                'notes' => $payment->notes,
            ]
        );

        if ($payment->status === 'completed') {
            try {
                $emailService = app(EmailNotificationService::class);
                $emailService->triggerPaymentNotification($payment, EmailNotification::TRIGGER_PAYMENT_RECEIVED);
            } catch (\Exception $e) {
                Log::error('Failed to send payment received email', ['error' => $e->getMessage(), 'payment_id' => $payment->id]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment created successfully',
            'data' => $payment,
        ], 201);
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->load(['customer', 'location']);

        $payableDetails = $payment->getPayableDetails();

        return response()->json([
            'success' => true,
            'data' => $payment,
            'payable' => $payableDetails,
        ]);
    }

    public function destroy(Payment $payment): JsonResponse
    {
        $payment->delete();

        if ($payment->payable_id && $payment->payable_type && $payment->status === 'completed') {
            $this->recalculatePayableAmountPaid($payment->payable_id, $payment->payable_type);
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'soft_deleted',
            'subject_type' => 'payment',
            'subject_id' => $payment->id,
            'description' => "Soft deleted payment #{$payment->id} (amount: {$payment->amount}, method: {$payment->method})",
            'location_id' => $payment->location_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment soft deleted successfully',
        ]);
    }

    public function restore(int $id): JsonResponse
    {
        $payment = Payment::onlyTrashed()->findOrFail($id);
        $payment->restore();

        if ($payment->payable_id && $payment->payable_type && $payment->status === 'completed') {
            $this->recalculatePayableAmountPaid($payment->payable_id, $payment->payable_type);
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'restored',
            'subject_type' => 'payment',
            'subject_id' => $payment->id,
            'description' => "Restored payment #{$payment->id} (amount: {$payment->amount}, method: {$payment->method})",
            'location_id' => $payment->location_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment restored successfully',
            'data' => $payment->load(['customer', 'location']),
        ]);
    }

    public function forceDelete(int $id): JsonResponse
    {
        $payment = Payment::onlyTrashed()->findOrFail($id);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'force_deleted',
            'subject_type' => 'payment',
            'subject_id' => $payment->id,
            'description' => "Permanently deleted payment #{$payment->id} (amount: {$payment->amount}, method: {$payment->method})",
            'location_id' => $payment->location_id,
        ]);

        $payment->forceDelete();

        return response()->json([
            'success' => true,
            'message' => 'Payment permanently deleted',
        ]);
    }

    public function trashed(Request $request): JsonResponse
    {
        $query = Payment::onlyTrashed()->with(['customer', 'location', 'booking', 'attractionPurchase', 'eventPurchase']);

        if ($request->has('payable_type')) {
            $query->where('payable_type', $request->payable_type);
        }

        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }

        if ($request->filled('method')) {
            $query->byMethod($request->method);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            $terms = preg_split('/\s+/', trim((string) $request->search), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                $query->where(function ($q) use ($like, $term) {
                    $q->where('transaction_id', 'like', $like)
                      ->orWhere('payment_id', 'like', $like)
                      ->orWhere('card_last_four', 'like', $like)
                      ->orWhere('notes', 'like', $like)
                      ->orWhereHas('customer', function ($c) use ($like) {
                          $c->where('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like)
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
                      });
                    if (ctype_digit($term)) {
                        $q->orWhere('id', (int) $term);
                    }
                    if (is_numeric($term)) {
                        $q->orWhere('amount', $term);
                    }
                });
            }
        }

        $allowedSorts = ['deleted_at', 'created_at', 'amount', 'status', 'method', 'paid_at', 'updated_at', 'id'];
        $sortBy = $request->get('sort_by', 'deleted_at');
        if (!in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'deleted_at';
        }
        $sortOrder = strtolower((string) $request->get('sort_order', 'desc'));
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'desc';
        }

        $perPage = $request->get('per_page', 15);
        $payments = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'payments' => $payments->items(),
                'pagination' => [
                    'current_page' => $payments->currentPage(),
                    'last_page' => $payments->lastPage(),
                    'per_page' => $payments->perPage(),
                    'total' => $payments->total(),
                ],
            ],
        ]);
    }

    private function recalculatePayableAmountPaid(int $payableId, string $payableType): void
    {
        $totalPaid = Payment::where('payable_id', $payableId)
            ->where('payable_type', $payableType)
            ->where('status', 'completed')
            ->sum('amount');

        if ($payableType === Payment::TYPE_BOOKING) {
            $payable = Booking::find($payableId);
            if ($payable) {
                $payable->update([
                    'amount_paid' => $totalPaid,
                    'payment_status' => $totalPaid >= $payable->total_amount ? 'paid' : ($totalPaid > 0 ? 'partial' : 'pending'),
                ]);
            }
        } elseif ($payableType === Payment::TYPE_ATTRACTION_PURCHASE) {
            $payable = AttractionPurchase::find($payableId);
            if ($payable) {
                $payable->update([
                    'amount_paid' => $totalPaid,
                    'status' => $totalPaid >= $payable->total_amount ? AttractionPurchase::STATUS_CONFIRMED : AttractionPurchase::STATUS_PENDING,
                ]);
            }
        } elseif ($payableType === Payment::TYPE_EVENT_PURCHASE) {
            $payable = EventPurchase::find($payableId);
            if ($payable) {
                $payable->update([
                    'amount_paid' => $totalPaid,
                    'payment_status' => $totalPaid >= $payable->total_amount ? 'paid' : ($totalPaid > 0 ? 'partial' : 'pending'),
                ]);
            }
        }
    }

    public function updatePayable(Request $request, string $id): JsonResponse
    {
        $transactionId = $request->query('transaction_id');

        $payment = $id
            ? Payment::findOrFail($id)
            : Payment::where('transaction_id', $transactionId)->firstOrFail();

        $validated = $request->validate([
            'payable_id' => 'required|integer',
            'payable_type' => ['required', Rule::in([Payment::TYPE_BOOKING, Payment::TYPE_ATTRACTION_PURCHASE, Payment::TYPE_EVENT_PURCHASE])],
        ]);

        if ($validated['payable_type'] === Payment::TYPE_BOOKING) {
            $payable = Booking::find($validated['payable_id']);
            if (!$payable) {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking not found',
                ], 404);
            }
        } elseif ($validated['payable_type'] === Payment::TYPE_ATTRACTION_PURCHASE) {
            $payable = AttractionPurchase::find($validated['payable_id']);
            if (!$payable) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attraction purchase not found',
                ], 404);
            }
        } elseif ($validated['payable_type'] === Payment::TYPE_EVENT_PURCHASE) {
            $payable = EventPurchase::find($validated['payable_id']);
            if (!$payable) {
                return response()->json([
                    'success' => false,
                    'message' => 'Event purchase not found',
                ], 404);
            }
        }

        $previousPayableId = $payment->payable_id;
        $previousPayableType = $payment->payable_type;

        $payment->update($validated);

        if ($payment->status === 'completed') {
            $totalPaid = Payment::where('payable_id', $validated['payable_id'])
                ->where('payable_type', $validated['payable_type'])
                ->where('status', 'completed')
                ->sum('amount');

            $payable->update(['amount_paid' => $totalPaid]);

            if ($validated['payable_type'] === Payment::TYPE_ATTRACTION_PURCHASE) {
                if ($totalPaid >= $payable->total_amount && $payable->status === AttractionPurchase::STATUS_PENDING) {
                    $payable->update(['status' => AttractionPurchase::STATUS_CONFIRMED]);
                }
            }
        }

        if ($previousPayableId && $previousPayableType) {
            $previousTotalPaid = Payment::where('payable_id', $previousPayableId)
                ->where('payable_type', $previousPayableType)
                ->where('status', 'completed')
                ->sum('amount');

            if ($previousPayableType === Payment::TYPE_BOOKING) {
                $previousPayable = Booking::withTrashed()->find($previousPayableId);
            } elseif ($previousPayableType === Payment::TYPE_EVENT_PURCHASE) {
                $previousPayable = EventPurchase::withTrashed()->find($previousPayableId);
            } else {
                $previousPayable = AttractionPurchase::withTrashed()->find($previousPayableId);
            }

            if ($previousPayable) {
                $previousPayable->update(['amount_paid' => $previousTotalPaid]);
            }
        }

        ActivityLog::log(
            action: 'Payment Linked',
            category: 'update',
            description: "Payment #{$payment->id} (\${$payment->amount}) linked to {$validated['payable_type']} #{$validated['payable_id']}",
            userId: auth()->id(),
            locationId: $payment->location_id,
            entityType: 'payment',
            entityId: $payment->id,
            metadata: [
                'payment_id' => $payment->id,
                'payable_id' => $validated['payable_id'],
                'payable_type' => $validated['payable_type'],
                'previous_payable_id' => $previousPayableId,
                'previous_payable_type' => $previousPayableType,
                'amount' => $payment->amount,
                'linked_by' => auth()->user() ? auth()->user()->name ?? auth()->user()->email : 'System',
            ]
        );

        $payment->load(['customer', 'location']);

        return response()->json([
            'success' => true,
            'message' => 'Payment linked to ' . str_replace('_', ' ', $validated['payable_type']) . ' successfully',
            'data' => $payment,
            'payable' => $payable->fresh(),
        ]);
    }

    public function refund(Request $request, Payment $payment): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'sometimes|numeric|min:0.01',
            'notes' => 'sometimes|nullable|string',
            'cancel' => 'sometimes|boolean',
        ]);

        if ($payment->status !== 'completed') {
            return response()->json(['success' => false, 'message' => 'Only completed payments can be refunded'], 400);
        }

        if ($payment->method !== 'authorize.net') {
            return response()->json(['success' => false, 'message' => 'Only Authorize.Net payments can be refunded through this endpoint'], 400);
        }

        $refundAmount = $validated['amount'] ?? $payment->amount;

        $totalAlreadyRefunded = Payment::where('status', 'refunded')
            ->where('notes', 'like', '%Refund from Payment #' . $payment->id . ' %')
            ->sum('amount');

        $maxRefundable = round($payment->amount - $totalAlreadyRefunded, 2);

        if ($refundAmount > $maxRefundable) {
            return response()->json([
                'success' => false,
                'message' => $maxRefundable <= 0
                    ? 'This payment has already been fully refunded'
                    : 'Refund amount cannot exceed the remaining refundable balance of $' . number_format($maxRefundable, 2),
                'data' => [
                    'original_amount' => (float) $payment->amount,
                    'total_already_refunded' => $totalAlreadyRefunded,
                    'max_refundable' => $maxRefundable,
                ],
            ], 400);
        }

        try {
            $account = AuthorizeNetAccount::where('location_id', $payment->location_id)
                ->where('is_active', true)
                ->first();

            if (!$account) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active Authorize.Net account found for this location',
                ], 503);
            }

            $apiLoginId = trim($account->api_login_id);
            $transactionKey = trim($account->transaction_key);
            $environment = $account->isProduction() ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX;

            $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
            $merchantAuthentication->setName($apiLoginId);
            $merchantAuthentication->setTransactionKey($transactionKey);

            $lastFour = $payment->card_last_four;

            if (!$lastFour) {
                Log::info('card_last_four missing, fetching from Authorize.Net GetTransactionDetails', [
                    'payment_id' => $payment->id,
                    'transaction_id' => $payment->transaction_id,
                ]);

                try {
                    $detailsRequest = new AnetAPI\GetTransactionDetailsRequest();
                    $detailsRequest->setMerchantAuthentication($merchantAuthentication);
                    $detailsRequest->setTransId($payment->transaction_id);

                    $detailsController = new AnetController\GetTransactionDetailsController($detailsRequest);
                    $detailsResponse = $detailsController->executeWithApiResponse($environment);

                    if ($detailsResponse != null && $detailsResponse->getMessages()->getResultCode() == 'Ok') {
                        $txn = $detailsResponse->getTransaction();
                        if ($txn && $txn->getPayment() && $txn->getPayment()->getCreditCard()) {
                            $cardNumber = $txn->getPayment()->getCreditCard()->getCardNumber();
                            $lastFour = substr($cardNumber, -4);

                            $payment->update(['card_last_four' => $lastFour]);

                            Log::info('card_last_four retrieved and saved from Authorize.Net', [
                                'payment_id' => $payment->id,
                                'card_last_four' => $lastFour,
                            ]);
                        }
                    } else {
                        Log::warning('Failed to fetch transaction details from Authorize.Net', [
                            'payment_id' => $payment->id,
                            'transaction_id' => $payment->transaction_id,
                            'error' => $detailsResponse?->getMessages()->getMessage()[0]->getText() ?? 'No response',
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('Exception fetching transaction details', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (!$lastFour) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to determine card details for this transaction. The card last four digits could not be retrieved from Authorize.Net. Please try voiding the payment instead, or contact support.',
                    'error_code' => 'MISSING_CARD_LAST_FOUR',
                ], 400);
            }

            $creditCard = new AnetAPI\CreditCardType();
            $creditCard->setCardNumber('XXXX' . $lastFour);
            $creditCard->setExpirationDate('XXXX');

            $paymentType = new AnetAPI\PaymentType();
            $paymentType->setCreditCard($creditCard);

            $transactionRequestType = new AnetAPI\TransactionRequestType();
            $transactionRequestType->setTransactionType('refundTransaction');
            $transactionRequestType->setAmount($refundAmount);
            $transactionRequestType->setPayment($paymentType);
            $transactionRequestType->setRefTransId($payment->transaction_id);

            $apiRequest = new AnetAPI\CreateTransactionRequest();
            $apiRequest->setMerchantAuthentication($merchantAuthentication);
            $apiRequest->setTransactionRequest($transactionRequestType);

            $controller = new AnetController\CreateTransactionController($apiRequest);
            $response = $controller->executeWithApiResponse($environment);

            if ($response != null && $response->getMessages()->getResultCode() == 'Ok') {
                $tresponse = $response->getTransactionResponse();

                if ($tresponse != null && $tresponse->getMessages() != null) {
                    $refundTransactionId = $tresponse->getTransId();
                    $isFullRefund = ($refundAmount + $totalAlreadyRefunded) >= $payment->amount;

                    $refundPayment = Payment::create([
                        'payable_id' => $payment->payable_id,
                        'payable_type' => $payment->payable_type,
                        'customer_id' => $payment->customer_id,
                        'transaction_id' => $refundTransactionId,
                        'amount' => $refundAmount,
                        'currency' => $payment->currency ?? 'USD',
                        'method' => 'authorize.net',
                        'status' => 'refunded',
                        'notes' => "Refund from Payment #{$payment->id} "
                            . ($validated['notes'] ?? '' ? " — {$validated['notes']}" : ''),
                        'refunded_at' => now(),
                        'payment_id' => $refundTransactionId,
                        'location_id' => $payment->location_id,
                    ]);

                    $payment->update([
                        'notes' => trim(($payment->notes ?? '') . "\nRefund of $" . number_format($refundAmount, 2) . " issued → Refund Payment #{$refundPayment->id} (TXN: {$refundTransactionId})"),
                    ]);

                    $this->recordConversion(
                        'refund_issued',
                        $refundPayment,
                        -1 * (float) $refundAmount,
                        ['tracking_id' => 'srv:payment:'.$refundPayment->id.':refund']
                    );

                    Log::info('💰 Authorize.Net refund successful', [
                        'original_payment_id' => $payment->id,
                        'original_transaction_id' => $payment->transaction_id,
                        'refund_payment_id' => $refundPayment->id,
                        'refund_transaction_id' => $refundTransactionId,
                        'refund_amount' => $refundAmount,
                        'original_amount' => $payment->amount,
                        'total_refunded' => $totalAlreadyRefunded + $refundAmount,
                        'is_full_refund' => $isFullRefund,
                        'location_id' => $payment->location_id,
                    ]);

                    if ($payment->customer_id) {
                        CustomerNotification::create([
                            'customer_id' => $payment->customer_id,
                            'location_id' => $payment->location_id,
                            'type' => 'payment',
                            'priority' => 'high',
                            'title' => $isFullRefund ? 'Payment Refunded' : 'Partial Refund Processed',
                            'message' => "A refund of $" . number_format($refundAmount, 2) . " has been processed for your payment.",
                            'status' => 'unread',
                            'action_url' => "/payments/{$refundPayment->id}",
                            'action_text' => 'View Refund',
                            'metadata' => [
                                'original_payment_id' => $payment->id,
                                'refund_payment_id' => $refundPayment->id,
                                'transaction_id' => $payment->transaction_id,
                                'refund_transaction_id' => $refundTransactionId,
                                'refund_amount' => $refundAmount,
                                'original_amount' => $payment->amount,
                                'is_full_refund' => $isFullRefund,
                                'refunded_at' => now()->toDateTimeString(),
                            ],
                        ]);
                    }

                    if ($payment->location_id) {
                        Notification::create([
                            'location_id' => $payment->location_id,
                            'type' => 'payment',
                            'priority' => 'high',
                            'user_id' => auth()->id(),
                            'title' => $isFullRefund ? 'Payment Refunded' : 'Partial Refund Processed',
                            'message' => "Refund of $" . number_format($refundAmount, 2) . " for Payment #{$payment->id} (TXN: {$payment->transaction_id}). Refund Payment #{$refundPayment->id}",
                            'status' => 'unread',
                            'action_url' => "/payments/{$refundPayment->id}",
                            'action_text' => 'View Refund',
                            'metadata' => [
                                'original_payment_id' => $payment->id,
                                'refund_payment_id' => $refundPayment->id,
                                'transaction_id' => $payment->transaction_id,
                                'refund_transaction_id' => $refundTransactionId,
                                'refund_amount' => $refundAmount,
                                'original_amount' => $payment->amount,
                                'customer_id' => $payment->customer_id,
                                'is_full_refund' => $isFullRefund,
                                'refunded_at' => now()->toDateTimeString(),
                            ],
                        ]);
                    }

                    $customerName = $payment->customer
                        ? "{$payment->customer->first_name} {$payment->customer->last_name}"
                        : 'Guest';

                    ActivityLog::log(
                        action: $isFullRefund ? 'Payment Refunded' : 'Payment Partially Refunded',
                        category: 'create',
                        description: "Refund of $" . number_format($refundAmount, 2) . " processed via Authorize.Net for {$customerName}. Refund Payment #{$refundPayment->id}",
                        userId: auth()->id(),
                        locationId: $payment->location_id,
                        entityType: 'payment',
                        entityId: $refundPayment->id,
                        metadata: [
                            'original_payment_id' => $payment->id,
                            'refund_payment_id' => $refundPayment->id,
                            'transaction_id' => $payment->transaction_id,
                            'refund_transaction_id' => $refundTransactionId,
                            'refunded_by' => auth()->user() ? auth()->user()->name ?? auth()->user()->email : 'System',
                            'refunded_at' => now()->toIso8601String(),
                            'payment_details' => [
                                'original_amount' => $payment->amount,
                                'refund_amount' => $refundAmount,
                                'total_refunded' => $totalAlreadyRefunded + $refundAmount,
                                'remaining_balance' => $maxRefundable - $refundAmount,
                                'is_full_refund' => $isFullRefund,
                                'method' => $payment->method,
                            ],
                            'customer' => [
                                'id' => $payment->customer_id,
                                'name' => $customerName,
                            ],
                            'payable' => [
                                'type' => $payment->payable_type,
                                'id' => $payment->payable_id,
                            ],
                            'notes' => $validated['notes'] ?? null,
                        ]
                    );

                    $isCancelled = $validated['cancel'] ?? $isFullRefund;
                    $payable = null;

                    if ($payment->payable_type === Payment::TYPE_BOOKING && $payment->payable_id) {
                        $payable = Booking::withTrashed()->find($payment->payable_id);
                        if ($payable) {
                            if ($isCancelled) {
                                $payable->update([
                                    'status' => 'cancelled',
                                    'payment_status' => 'refunded',
                                    'cancelled_at' => now(),
                                    'amount_paid' => max(0, $payable->amount_paid - $refundAmount),
                                ]);
                            } else {
                                $payable->update([
                                    'amount_paid' => max(0, $payable->amount_paid - $refundAmount),
                                    'payment_status' => ($payable->amount_paid - $refundAmount) <= 0 ? 'refunded' : 'partial',
                                ]);
                            }

                            try {
                                $gcalService = new GoogleCalendarService($payable->location_id);
                                if ($gcalService->isConnected()) {
                                    $gcalService->updateEventFromBooking($payable->fresh());
                                }
                            } catch (\Exception $e) {
                                Log::warning('Google Calendar sync failed on refund', ['booking_id' => $payable->id, 'error' => $e->getMessage()]);
                            }

                            if ($isCancelled) {
                                try {
                                    $emailService = app(EmailNotificationService::class);
                                    $emailService->triggerBookingNotification($payable, EmailNotification::TRIGGER_BOOKING_CANCELLED);
                                    Log::info('Booking cancellation email sent via service', ['booking_id' => $payable->id]);
                                } catch (\Exception $e) {
                                    Log::error('Failed to send booking cancellation email', ['error' => $e->getMessage(), 'booking_id' => $payable->id]);
                                }
                            }
                        }
                    } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE && $payment->payable_id) {
                        $payable = AttractionPurchase::withTrashed()->find($payment->payable_id);
                        if ($payable) {
                            if ($isCancelled) {
                                $payable->update([
                                    'status' => $isFullRefund ? AttractionPurchase::STATUS_REFUNDED : AttractionPurchase::STATUS_CONFIRMED,
                                    'amount_paid' => max(0, $payable->amount_paid - $refundAmount),
                                ]);
                            } else {
                                $newAmountPaid = max(0, $payable->amount_paid - $refundAmount);
                                $payable->update([
                                    'amount_paid' => $newAmountPaid,
                                    'status' => $newAmountPaid <= 0 ? AttractionPurchase::STATUS_REFUNDED : AttractionPurchase::STATUS_CONFIRMED,
                                ]);
                            }

                            if ($isCancelled) {
                                try {
                                    $emailService = app(EmailNotificationService::class);
                                    $emailService->triggerPurchaseNotification($payable, EmailNotification::TRIGGER_PURCHASE_CANCELLED);
                                    Log::info('Attraction purchase cancellation email sent via service', ['purchase_id' => $payable->id]);
                                } catch (\Exception $e) {
                                    Log::error('Failed to send attraction purchase cancellation email', ['error' => $e->getMessage(), 'purchase_id' => $payable->id]);
                                }
                            }
                        }
                    } elseif ($payment->payable_type === Payment::TYPE_EVENT_PURCHASE && $payment->payable_id) {
                        $payable = EventPurchase::withTrashed()->find($payment->payable_id);
                        if ($payable) {
                            $newAmountPaid = max(0, $payable->amount_paid - $refundAmount);
                            $payable->update([
                                'amount_paid' => $newAmountPaid,
                                'payment_status' => $newAmountPaid <= 0 ? 'refunded' : 'partial',
                                'status' => $isCancelled ? 'cancelled' : $payable->status,
                                'cancelled_at' => $isCancelled ? now() : $payable->cancelled_at,
                            ]);
                        }
                    }

                    if ($isCancelled && $payable) {
                        try {
                            app(\App\Services\MembershipBenefitService::class)
                                ->reverseForRedeemable($payable, 'payment_refunded');
                        } catch (\Throwable $e) {
                            Log::warning('Failed to reverse membership redemptions on refund', [
                                'payable_type' => $payment->payable_type,
                                'payable_id'   => $payment->payable_id,
                                'error'        => $e->getMessage(),
                            ]);
                        }
                    }

                    try {
                        $emailService = app(EmailNotificationService::class);
                        $emailService->triggerPaymentNotification($refundPayment, EmailNotification::TRIGGER_PAYMENT_REFUNDED);
                    } catch (\Exception $e) {
                        Log::error('Failed to send payment refunded email', ['error' => $e->getMessage(), 'payment_id' => $refundPayment->id]);
                    }

                    return response()->json([
                        'success' => true,
                        'message' => $isFullRefund ? 'Full refund processed successfully' : 'Partial refund processed successfully',
                        'data' => [
                            'original_payment' => $payment->fresh(),
                            'refund_payment' => $refundPayment->fresh(),
                        ],
                        'refund_transaction_id' => $refundTransactionId,
                        'refund_amount' => $refundAmount,
                        'total_refunded' => $totalAlreadyRefunded + $refundAmount,
                        'remaining_balance' => $maxRefundable - $refundAmount,
                        'is_full_refund' => $isFullRefund,
                        'payable_cancelled' => $isCancelled,
                        'payable' => $payable?->fresh(),
                    ]);
                } else {
                    $errorMessage = 'Refund transaction failed';
                    $errorCode = null;
                    if ($tresponse && $tresponse->getErrors() != null) {
                        $errorCode = $tresponse->getErrors()[0]->getErrorCode();
                        $errorMessage = $tresponse->getErrors()[0]->getErrorText();
                    }

                    Log::warning('Authorize.Net refund transaction failed', [
                        'error' => $errorMessage,
                        'error_code' => $errorCode,
                        'transaction_id' => $payment->transaction_id,
                        'location_id' => $payment->location_id,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => $errorMessage,
                        'error_code' => $errorCode,
                    ], 400);
                }
            } else {
                $errorMessage = 'Unknown error';
                $errorCode = null;
                $transactionErrorCode = null;
                $transactionErrorText = null;

                if ($response != null) {
                    $errorMessages = $response->getMessages()->getMessage();
                    $errorCode = $errorMessages[0]->getCode();
                    $errorMessage = $errorMessages[0]->getText();

                    $tresponse = $response->getTransactionResponse();
                    if ($tresponse && $tresponse->getErrors() != null) {
                        $transactionErrorCode = $tresponse->getErrors()[0]->getErrorCode();
                        $transactionErrorText = $tresponse->getErrors()[0]->getErrorText();
                    }
                }

                Log::error('Authorize.Net refund API error', [
                    'error' => $errorMessage,
                    'error_code' => $errorCode,
                    'transaction_error_code' => $transactionErrorCode,
                    'transaction_error_text' => $transactionErrorText,
                    'transaction_id' => $payment->transaction_id,
                    'location_id' => $payment->location_id,
                ]);

                $isUnsettledError = $errorCode === 'E00027';
                $isPartialRefund = $refundAmount < $payment->amount;

                if ($isUnsettledError && !$isPartialRefund && $totalAlreadyRefunded <= 0) {
                    Log::info('Refund failed (likely unsettled transaction), attempting void instead', [
                        'payment_id' => $payment->id,
                        'transaction_id' => $payment->transaction_id,
                    ]);

                    $voidTransactionRequestType = new AnetAPI\TransactionRequestType();
                    $voidTransactionRequestType->setTransactionType('voidTransaction');
                    $voidTransactionRequestType->setRefTransId($payment->transaction_id);

                    $voidApiRequest = new AnetAPI\CreateTransactionRequest();
                    $voidApiRequest->setMerchantAuthentication($merchantAuthentication);
                    $voidApiRequest->setTransactionRequest($voidTransactionRequestType);

                    $voidController = new AnetController\CreateTransactionController($voidApiRequest);
                    $voidResponse = $voidController->executeWithApiResponse($environment);

                    if ($voidResponse != null && $voidResponse->getMessages()->getResultCode() == 'Ok') {
                        $voidTresponse = $voidResponse->getTransactionResponse();
                        if ($voidTresponse != null && $voidTresponse->getMessages() != null) {
                            Log::info('Void fallback successful (unsettled refund → void)', [
                                'payment_id' => $payment->id,
                                'transaction_id' => $payment->transaction_id,
                            ]);

                            $payment->update([
                                'status' => 'voided',
                                'refunded_at' => now(),
                            ]);

                            $voidPayment = Payment::create([
                                'payable_id' => $payment->payable_id,
                                'payable_type' => $payment->payable_type,
                                'customer_id' => $payment->customer_id,
                                'transaction_id' => 'VOID-' . $payment->transaction_id,
                                'amount' => $payment->amount,
                                'currency' => $payment->currency ?? 'USD',
                                'method' => 'authorize.net',
                                'status' => 'voided',
                                'notes' => "Void of Payment #{$payment->id} (auto: refund failed, transaction unsettled)",
                                'refunded_at' => now(),
                                'payment_id' => 'VOID-' . ($payment->payment_id ?? $payment->transaction_id),
                                'location_id' => $payment->location_id,
                            ]);

                            $payment->update([
                                'notes' => trim(($payment->notes ?? '') . "\nTransaction voided (auto-fallback from refund) → Void Payment #{$voidPayment->id}"),
                            ]);

                            $payable = null;
                            if ($payment->payable_type === Payment::TYPE_BOOKING && $payment->payable_id) {
                                $payable = Booking::withTrashed()->find($payment->payable_id);
                                if ($payable) {
                                    $payable->update([
                                        'status' => 'cancelled',
                                        'payment_status' => 'voided',
                                        'cancelled_at' => now(),
                                        'amount_paid' => max(0, $payable->amount_paid - $payment->amount),
                                    ]);
                                }
                            } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE && $payment->payable_id) {
                                $payable = AttractionPurchase::withTrashed()->find($payment->payable_id);
                                if ($payable) {
                                    $payable->update([
                                        'status' => AttractionPurchase::STATUS_REFUNDED,
                                        'amount_paid' => max(0, $payable->amount_paid - $payment->amount),
                                    ]);
                                }
                            } elseif ($payment->payable_type === Payment::TYPE_EVENT_PURCHASE && $payment->payable_id) {
                                $payable = EventPurchase::withTrashed()->find($payment->payable_id);
                                if ($payable) {
                                    $payable->update([
                                        'amount_paid' => max(0, $payable->amount_paid - $payment->amount),
                                        'payment_status' => 'voided',
                                        'status' => 'cancelled',
                                        'cancelled_at' => now(),
                                    ]);
                                }
                            }

                            if ($payable) {
                                try {
                                    app(\App\Services\MembershipBenefitService::class)
                                        ->reverseForRedeemable($payable, 'payment_voided');
                                } catch (\Throwable $e) {
                                    Log::warning('Failed to reverse membership redemptions on void fallback', [
                                        'payable_type' => $payment->payable_type,
                                        'payable_id'   => $payment->payable_id,
                                        'error'        => $e->getMessage(),
                                    ]);
                                }
                            }

                            return response()->json([
                                'success' => true,
                                'message' => 'Transaction was unsettled — voided successfully instead of refunded',
                                'data' => [
                                    'original_payment' => $payment->fresh(),
                                    'void_payment' => $voidPayment->fresh(),
                                ],
                                'void_amount' => $payment->amount,
                                'was_void_fallback' => true,
                                'payable_cancelled' => true,
                                'payable' => $payable?->fresh(),
                            ]);
                        }
                    }

                    $voidErrorMessage = 'Unknown void error';
                    $voidErrorCode = null;
                    if ($voidResponse != null) {
                        $voidMessages = $voidResponse->getMessages()->getMessage();
                        $voidErrorCode = $voidMessages[0]->getCode();
                        $voidErrorMessage = $voidMessages[0]->getText();
                        $voidTresponse = $voidResponse->getTransactionResponse();
                        if ($voidTresponse && $voidTresponse->getErrors() != null) {
                            $voidErrorCode = $voidTresponse->getErrors()[0]->getErrorCode();
                            $voidErrorMessage = $voidTresponse->getErrors()[0]->getErrorText();
                        }
                    }

                    Log::warning('Void fallback also failed', [
                        'payment_id' => $payment->id,
                        'transaction_id' => $payment->transaction_id,
                        'void_error' => $voidErrorMessage,
                        'void_error_code' => $voidErrorCode,
                    ]);
                }

                $userMessage = $errorMessage;
                if ($isUnsettledError && $isPartialRefund) {
                    $userMessage = 'This transaction has not settled yet (takes up to 24-48 hours). Partial refunds are not available for unsettled transactions. You can void the full payment instead.';
                } elseif ($isUnsettledError) {
                    $userMessage = 'This transaction has not settled yet and could not be voided automatically. Please try voiding the payment manually or wait 24-48 hours and retry the refund.';
                }

                return response()->json([
                    'success' => false,
                    'message' => $userMessage,
                    'error_code' => $errorCode,
                    'transaction_error_code' => $transactionErrorCode,
                    'is_unsettled' => $isUnsettledError,
                ], 400);
            }
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            Log::error('Refund - credential decryption failed', [
                'error' => $e->getMessage(),
                'location_id' => $payment->location_id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment configuration error. Please contact support.',
                'error_code' => 'DECRYPTION_FAILED',
            ], 500);
        } catch (\Exception $e) {
            Log::error('Refund processing exception', [
                'error' => $e->getMessage(),
                'transaction_id' => $payment->transaction_id,
                'location_id' => $payment->location_id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Refund processing failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function manualRefund(Request $request, Payment $payment): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'sometimes|numeric|min:0.01',
            'notes' => 'required|string|max:1000',
            'cancel' => 'sometimes|boolean',
        ]);

        if ($payment->status !== 'completed') {
            return response()->json(['success' => false, 'message' => 'Only completed payments can be refunded'], 400);
        }

        if ($payment->method === 'authorize.net') {
            return response()->json(['success' => false, 'message' => 'Authorize.Net payments must be refunded through the /refund endpoint'], 400);
        }

        $refundAmount = $validated['amount'] ?? $payment->amount;

        $totalAlreadyRefunded = Payment::where('status', 'refunded')
            ->where('notes', 'like', '%Refund from Payment #' . $payment->id . ' %')
            ->sum('amount');

        $maxRefundable = round($payment->amount - $totalAlreadyRefunded, 2);

        if ($refundAmount > $maxRefundable) {
            return response()->json([
                'success' => false,
                'message' => $maxRefundable <= 0
                    ? 'This payment has already been fully refunded'
                    : 'Refund amount cannot exceed the remaining refundable balance of $' . number_format($maxRefundable, 2),
                'data' => [
                    'original_amount' => (float) $payment->amount,
                    'total_already_refunded' => $totalAlreadyRefunded,
                    'max_refundable' => $maxRefundable,
                ],
            ], 400);
        }

        $isFullRefund = ($refundAmount + $totalAlreadyRefunded) >= $payment->amount;

        $refundPayment = Payment::create([
            'payable_id' => $payment->payable_id,
            'payable_type' => $payment->payable_type,
            'customer_id' => $payment->customer_id,
            'transaction_id' => 'REFUND-' . $payment->transaction_id . '-' . strtoupper(Str::random(4)),
            'amount' => $refundAmount,
            'currency' => $payment->currency ?? 'USD',
            'method' => $payment->method,
            'status' => 'refunded',
            'notes' => "Refund from Payment #{$payment->id} (Original TXN: {$payment->transaction_id}) — {$validated['notes']}",
            'refunded_at' => now(),
            'payment_id' => null,
            'location_id' => $payment->location_id,
        ]);

        $payment->update([
            'notes' => trim(($payment->notes ?? '') . "\nManual refund of $" . number_format($refundAmount, 2) . " issued → Refund Payment #{$refundPayment->id}"),
        ]);

        $this->recordConversion(
            'refund_issued',
            $refundPayment,
            -1 * (float) $refundAmount,
            ['tracking_id' => 'srv:payment:'.$refundPayment->id.':manual_refund']
        );

        Log::info('💰 Manual refund processed', [
            'original_payment_id' => $payment->id,
            'refund_payment_id' => $refundPayment->id,
            'refund_amount' => $refundAmount,
            'original_amount' => $payment->amount,
            'total_refunded' => $totalAlreadyRefunded + $refundAmount,
            'is_full_refund' => $isFullRefund,
            'method' => $payment->method,
            'location_id' => $payment->location_id,
        ]);

        if ($payment->customer_id) {
            CustomerNotification::create([
                'customer_id' => $payment->customer_id,
                'location_id' => $payment->location_id,
                'type' => 'payment',
                'priority' => 'high',
                'title' => $isFullRefund ? 'Payment Refunded' : 'Partial Refund Processed',
                'message' => "A refund of $" . number_format($refundAmount, 2) . " has been processed for your {$payment->method} payment.",
                'status' => 'unread',
                'action_url' => "/payments/{$refundPayment->id}",
                'action_text' => 'View Refund',
                'metadata' => [
                    'original_payment_id' => $payment->id,
                    'refund_payment_id' => $refundPayment->id,
                    'refund_amount' => $refundAmount,
                    'original_amount' => $payment->amount,
                    'is_full_refund' => $isFullRefund,
                    'method' => $payment->method,
                    'refunded_at' => now()->toDateTimeString(),
                ],
            ]);
        }

        if ($payment->location_id) {
            Notification::create([
                'location_id' => $payment->location_id,
                'type' => 'payment',
                'priority' => 'high',
                'user_id' => auth()->id(),
                'title' => $isFullRefund ? 'Manual Refund Processed' : 'Manual Partial Refund Processed',
                'message' => "Manual refund of $" . number_format($refundAmount, 2) . " ({$payment->method}) for Payment #{$payment->id}. Refund Payment #{$refundPayment->id}",
                'status' => 'unread',
                'action_url' => "/payments/{$refundPayment->id}",
                'action_text' => 'View Refund',
                'metadata' => [
                    'original_payment_id' => $payment->id,
                    'refund_payment_id' => $refundPayment->id,
                    'refund_amount' => $refundAmount,
                    'original_amount' => $payment->amount,
                    'customer_id' => $payment->customer_id,
                    'is_full_refund' => $isFullRefund,
                    'method' => $payment->method,
                    'refunded_at' => now()->toDateTimeString(),
                ],
            ]);
        }

        $customerName = $payment->customer
            ? "{$payment->customer->first_name} {$payment->customer->last_name}"
            : 'Guest';

        ActivityLog::log(
            action: $isFullRefund ? 'Manual Payment Refund' : 'Manual Partial Payment Refund',
            category: 'create',
            description: "Manual refund of $" . number_format($refundAmount, 2) . " ({$payment->method}) for {$customerName}. Refund Payment #{$refundPayment->id}",
            userId: auth()->id(),
            locationId: $payment->location_id,
            entityType: 'payment',
            entityId: $refundPayment->id,
            metadata: [
                'original_payment_id' => $payment->id,
                'refund_payment_id' => $refundPayment->id,
                'transaction_id' => $payment->transaction_id,
                'refunded_by' => auth()->user() ? auth()->user()->name ?? auth()->user()->email : 'System',
                'refunded_at' => now()->toIso8601String(),
                'payment_details' => [
                    'original_amount' => $payment->amount,
                    'refund_amount' => $refundAmount,
                    'total_refunded' => $totalAlreadyRefunded + $refundAmount,
                    'remaining_balance' => $maxRefundable - $refundAmount,
                    'is_full_refund' => $isFullRefund,
                    'method' => $payment->method,
                ],
                'customer' => [
                    'id' => $payment->customer_id,
                    'name' => $customerName,
                ],
                'payable' => [
                    'type' => $payment->payable_type,
                    'id' => $payment->payable_id,
                ],
                'notes' => $validated['notes'],
            ]
        );

        $isCancelled = $validated['cancel'] ?? $isFullRefund;
        $payable = null;

        if ($payment->payable_type === Payment::TYPE_BOOKING && $payment->payable_id) {
            $payable = Booking::withTrashed()->find($payment->payable_id);
            if ($payable) {
                if ($isCancelled) {
                    $payable->update([
                        'status' => 'cancelled',
                        'payment_status' => 'refunded',
                        'cancelled_at' => now(),
                        'amount_paid' => max(0, $payable->amount_paid - $refundAmount),
                    ]);
                } else {
                    $payable->update([
                        'amount_paid' => max(0, $payable->amount_paid - $refundAmount),
                        'payment_status' => ($payable->amount_paid - $refundAmount) <= 0 ? 'refunded' : 'partial',
                    ]);
                }

                try {
                    $gcalService = new GoogleCalendarService($payable->location_id);
                    if ($gcalService->isConnected()) {
                        $gcalService->updateEventFromBooking($payable->fresh());
                    }
                } catch (\Exception $e) {
                    Log::warning('Google Calendar sync failed on manual refund', ['booking_id' => $payable->id, 'error' => $e->getMessage()]);
                }

                if ($isCancelled) {
                    try {
                        $emailService = app(EmailNotificationService::class);
                        $emailService->triggerBookingNotification($payable, EmailNotification::TRIGGER_BOOKING_CANCELLED);
                        Log::info('Booking cancellation email sent via service (manual refund)', ['booking_id' => $payable->id]);
                    } catch (\Exception $e) {
                        Log::error('Failed to send booking cancellation email (manual refund)', ['error' => $e->getMessage(), 'booking_id' => $payable->id]);
                    }
                }
            }
        } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE && $payment->payable_id) {
            $payable = AttractionPurchase::withTrashed()->find($payment->payable_id);
            if ($payable) {
                if ($isCancelled) {
                    $payable->update([
                        'status' => $isFullRefund ? AttractionPurchase::STATUS_REFUNDED : AttractionPurchase::STATUS_CONFIRMED,
                        'amount_paid' => max(0, $payable->amount_paid - $refundAmount),
                    ]);
                } else {
                    $newAmountPaid = max(0, $payable->amount_paid - $refundAmount);
                    $payable->update([
                        'amount_paid' => $newAmountPaid,
                        'status' => $newAmountPaid <= 0 ? AttractionPurchase::STATUS_REFUNDED : AttractionPurchase::STATUS_CONFIRMED,
                    ]);
                }

                if ($isCancelled) {
                    try {
                        $emailService = app(EmailNotificationService::class);
                        $emailService->triggerPurchaseNotification($payable, EmailNotification::TRIGGER_PURCHASE_CANCELLED);
                        Log::info('Attraction purchase cancellation email sent via service (manual refund)', ['purchase_id' => $payable->id]);
                    } catch (\Exception $e) {
                        Log::error('Failed to send attraction purchase cancellation email (manual refund)', ['error' => $e->getMessage(), 'purchase_id' => $payable->id]);
                    }
                }
            }
        } elseif ($payment->payable_type === Payment::TYPE_EVENT_PURCHASE && $payment->payable_id) {
            $payable = EventPurchase::withTrashed()->find($payment->payable_id);
            if ($payable) {
                $newAmountPaid = max(0, $payable->amount_paid - $refundAmount);
                $payable->update([
                    'amount_paid' => $newAmountPaid,
                    'payment_status' => $newAmountPaid <= 0 ? 'refunded' : 'partial',
                    'status' => $isCancelled ? 'cancelled' : $payable->status,
                    'cancelled_at' => $isCancelled ? now() : $payable->cancelled_at,
                ]);
            }
        }

        try {
            $emailService = app(EmailNotificationService::class);
            $emailService->triggerPaymentNotification($refundPayment, EmailNotification::TRIGGER_PAYMENT_REFUNDED);
        } catch (\Exception $e) {
            Log::error('Failed to send payment refunded email (manual refund)', ['error' => $e->getMessage(), 'payment_id' => $refundPayment->id]);
        }

        return response()->json([
            'success' => true,
            'message' => $isFullRefund ? 'Full manual refund processed successfully' : 'Partial manual refund processed successfully',
            'data' => [
                'original_payment' => $payment->fresh(),
                'refund_payment' => $refundPayment->fresh(),
            ],
            'refund_amount' => $refundAmount,
            'total_refunded' => $totalAlreadyRefunded + $refundAmount,
            'remaining_balance' => $maxRefundable - $refundAmount,
            'is_full_refund' => $isFullRefund,
            'payable_cancelled' => $isCancelled,
            'payable' => $payable?->fresh(),
        ]);
    }

    public function voidTransaction(Payment $payment): JsonResponse
    {
        if (!in_array($payment->status, ['completed', 'pending'])) {
            return response()->json(['success' => false, 'message' => 'Only completed or pending payments can be voided'], 400);
        }

        if ($payment->method !== 'authorize.net') {
            return response()->json(['success' => false, 'message' => 'Only Authorize.Net payments can be voided through this endpoint'], 400);
        }

        try {
            $account = AuthorizeNetAccount::where('location_id', $payment->location_id)
                ->where('is_active', true)
                ->first();

            if (!$account) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active Authorize.Net account found for this location',
                ], 503);
            }

            $apiLoginId = trim($account->api_login_id);
            $transactionKey = trim($account->transaction_key);
            $environment = $account->isProduction() ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX;

            $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
            $merchantAuthentication->setName($apiLoginId);
            $merchantAuthentication->setTransactionKey($transactionKey);

            $transactionRequestType = new AnetAPI\TransactionRequestType();
            $transactionRequestType->setTransactionType('voidTransaction');
            $transactionRequestType->setRefTransId($payment->transaction_id);

            $apiRequest = new AnetAPI\CreateTransactionRequest();
            $apiRequest->setMerchantAuthentication($merchantAuthentication);
            $apiRequest->setTransactionRequest($transactionRequestType);

            $controller = new AnetController\CreateTransactionController($apiRequest);
            $response = $controller->executeWithApiResponse($environment);

            if ($response != null && $response->getMessages()->getResultCode() == 'Ok') {
                $tresponse = $response->getTransactionResponse();

                if ($tresponse != null && $tresponse->getMessages() != null) {
                    $previousStatus = $payment->status;
                    $voidAmount = $payment->amount;

                    $payment->update([
                        'status' => 'voided',
                        'refunded_at' => now(),
                    ]);

                    $voidPayment = Payment::create([
                        'payable_id' => $payment->payable_id,
                        'payable_type' => $payment->payable_type,
                        'customer_id' => $payment->customer_id,
                        'transaction_id' => 'VOID-' . $payment->transaction_id,
                        'amount' => $voidAmount,
                        'currency' => $payment->currency ?? 'USD',
                        'method' => 'authorize.net',
                        'status' => 'voided',
                        'notes' => "Void of Payment #{$payment->id} (Original TXN: {$payment->transaction_id})",
                        'refunded_at' => now(),
                        'payment_id' => 'VOID-' . ($payment->payment_id ?? $payment->transaction_id),
                        'location_id' => $payment->location_id,
                    ]);

                    $payment->update([
                        'notes' => trim(($payment->notes ?? '') . "\nTransaction voided → Void Payment #{$voidPayment->id}"),
                    ]);

                    Log::info('🚫 Authorize.Net void successful', [
                        'original_payment_id' => $payment->id,
                        'void_payment_id' => $voidPayment->id,
                        'transaction_id' => $payment->transaction_id,
                        'amount' => $voidAmount,
                        'location_id' => $payment->location_id,
                    ]);

                    if ($payment->customer_id) {
                        CustomerNotification::create([
                            'customer_id' => $payment->customer_id,
                            'location_id' => $payment->location_id,
                            'type' => 'payment',
                            'priority' => 'high',
                            'title' => 'Payment Voided',
                            'message' => "Your payment of $" . number_format($voidAmount, 2) . " has been voided.",
                            'status' => 'unread',
                            'action_url' => "/payments/{$voidPayment->id}",
                            'action_text' => 'View Details',
                            'metadata' => [
                                'original_payment_id' => $payment->id,
                                'void_payment_id' => $voidPayment->id,
                                'transaction_id' => $payment->transaction_id,
                                'amount' => $voidAmount,
                                'voided_at' => now()->toDateTimeString(),
                            ],
                        ]);
                    }

                    if ($payment->location_id) {
                        Notification::create([
                            'location_id' => $payment->location_id,
                            'type' => 'payment',
                            'priority' => 'high',
                            'user_id' => auth()->id(),
                            'title' => 'Payment Voided',
                            'message' => "Payment #{$payment->id} of $" . number_format($voidAmount, 2) . " has been voided. Void Payment #{$voidPayment->id}",
                            'status' => 'unread',
                            'action_url' => "/payments/{$voidPayment->id}",
                            'action_text' => 'View Details',
                            'metadata' => [
                                'original_payment_id' => $payment->id,
                                'void_payment_id' => $voidPayment->id,
                                'transaction_id' => $payment->transaction_id,
                                'amount' => $voidAmount,
                                'customer_id' => $payment->customer_id,
                                'voided_at' => now()->toDateTimeString(),
                            ],
                        ]);
                    }

                    $customerName = $payment->customer
                        ? "{$payment->customer->first_name} {$payment->customer->last_name}"
                        : 'Guest';

                    ActivityLog::log(
                        action: 'Payment Voided',
                        category: 'create',
                        description: "Payment #{$payment->id} of $" . number_format($voidAmount, 2) . " voided via Authorize.Net for {$customerName}. Void Payment #{$voidPayment->id}",
                        userId: auth()->id(),
                        locationId: $payment->location_id,
                        entityType: 'payment',
                        entityId: $voidPayment->id,
                        metadata: [
                            'original_payment_id' => $payment->id,
                            'void_payment_id' => $voidPayment->id,
                            'transaction_id' => $payment->transaction_id,
                            'voided_by' => auth()->user() ? auth()->user()->name ?? auth()->user()->email : 'System',
                            'voided_at' => now()->toIso8601String(),
                            'payment_details' => [
                                'amount' => $voidAmount,
                                'method' => $payment->method,
                                'original_status' => $previousStatus,
                                'new_status' => 'voided',
                            ],
                            'customer' => [
                                'id' => $payment->customer_id,
                                'name' => $customerName,
                            ],
                            'payable' => [
                                'type' => $payment->payable_type,
                                'id' => $payment->payable_id,
                            ],
                        ]
                    );

                    $payable = null;

                    if ($payment->payable_type === Payment::TYPE_BOOKING && $payment->payable_id) {
                        $payable = Booking::withTrashed()->find($payment->payable_id);
                        if ($payable) {
                            $payable->update([
                                'status' => 'cancelled',
                                'payment_status' => 'voided',
                                'cancelled_at' => now(),
                                'amount_paid' => max(0, $payable->amount_paid - $voidAmount),
                            ]);

                            try {
                                $gcalService = new GoogleCalendarService($payable->location_id);
                                if ($gcalService->isConnected()) {
                                    $gcalService->updateEventFromBooking($payable->fresh());
                                }
                            } catch (\Exception $e) {
                                Log::warning('Google Calendar sync failed on void', ['booking_id' => $payable->id, 'error' => $e->getMessage()]);
                            }

                            try {
                                $emailService = app(EmailNotificationService::class);
                                $emailService->triggerBookingNotification($payable, EmailNotification::TRIGGER_BOOKING_CANCELLED);
                                Log::info('Booking void cancellation email sent via service', ['booking_id' => $payable->id]);
                            } catch (\Exception $e) {
                                Log::error('Failed to send booking void cancellation email', ['error' => $e->getMessage(), 'booking_id' => $payable->id]);
                            }
                        }
                    } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE && $payment->payable_id) {
                        $payable = AttractionPurchase::withTrashed()->find($payment->payable_id);
                        if ($payable) {
                            $newAmountPaid = max(0, $payable->amount_paid - $voidAmount);
                            $payable->update([
                                'status' => $newAmountPaid <= 0 ? AttractionPurchase::STATUS_REFUNDED : AttractionPurchase::STATUS_PENDING,
                                'amount_paid' => $newAmountPaid,
                            ]);

                            try {
                                $emailService = app(EmailNotificationService::class);
                                $emailService->triggerPurchaseNotification($payable, EmailNotification::TRIGGER_PURCHASE_CANCELLED);
                                Log::info('Attraction purchase void cancellation email sent via service', ['purchase_id' => $payable->id]);
                            } catch (\Exception $e) {
                                Log::error('Failed to send attraction purchase void cancellation email', ['error' => $e->getMessage(), 'purchase_id' => $payable->id]);
                            }
                        }
                    } elseif ($payment->payable_type === Payment::TYPE_EVENT_PURCHASE && $payment->payable_id) {
                        $payable = EventPurchase::withTrashed()->find($payment->payable_id);
                        if ($payable) {
                            $newAmountPaid = max(0, $payable->amount_paid - $voidAmount);
                            $payable->update([
                                'amount_paid' => $newAmountPaid,
                                'payment_status' => 'voided',
                                'status' => 'cancelled',
                                'cancelled_at' => now(),
                            ]);
                        }
                    }

                    if ($payable) {
                        try {
                            app(\App\Services\MembershipBenefitService::class)
                                ->reverseForRedeemable($payable, 'payment_voided');
                        } catch (\Throwable $e) {
                            Log::warning('Failed to reverse membership redemptions on void', [
                                'payable_type' => $payment->payable_type,
                                'payable_id'   => $payment->payable_id,
                                'error'        => $e->getMessage(),
                            ]);
                        }
                    }

                    try {
                        $emailService = app(EmailNotificationService::class);
                        $emailService->triggerPaymentNotification($voidPayment, EmailNotification::TRIGGER_PAYMENT_REFUNDED);
                    } catch (\Exception $e) {
                        Log::error('Failed to send payment refunded email (void)', ['error' => $e->getMessage(), 'payment_id' => $voidPayment->id]);
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Payment voided successfully',
                        'data' => [
                            'original_payment' => $payment->fresh(),
                            'void_payment' => $voidPayment->fresh(),
                        ],
                        'void_amount' => $voidAmount,
                        'payable_cancelled' => true,
                        'payable' => $payable?->fresh(),
                    ]);
                } else {
                    $errorMessage = 'Void transaction failed';
                    $errorCode = null;
                    if ($tresponse && $tresponse->getErrors() != null) {
                        $errorCode = $tresponse->getErrors()[0]->getErrorCode();
                        $errorMessage = $tresponse->getErrors()[0]->getErrorText();
                    }

                    Log::warning('Authorize.Net void transaction failed', [
                        'error' => $errorMessage,
                        'error_code' => $errorCode,
                        'transaction_id' => $payment->transaction_id,
                        'location_id' => $payment->location_id,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => $errorMessage,
                        'error_code' => $errorCode,
                    ], 400);
                }
            } else {
                $errorMessage = 'Unknown error';
                $errorCode = null;
                if ($response != null) {
                    $errorMessages = $response->getMessages()->getMessage();
                    $errorCode = $errorMessages[0]->getCode();
                    $errorMessage = $errorMessages[0]->getText();
                }

                Log::error('Authorize.Net void API error', [
                    'error' => $errorMessage,
                    'error_code' => $errorCode,
                    'transaction_id' => $payment->transaction_id,
                    'location_id' => $payment->location_id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                    'error_code' => $errorCode,
                ], 400);
            }
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            Log::error('Void - credential decryption failed', [
                'error' => $e->getMessage(),
                'location_id' => $payment->location_id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment configuration error. Please contact support.',
                'error_code' => 'DECRYPTION_FAILED',
            ], 500);
        } catch (\Exception $e) {
            Log::error('Void processing exception', [
                'error' => $e->getMessage(),
                'transaction_id' => $payment->transaction_id,
                'location_id' => $payment->location_id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Void processing failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function charge(Request $request): JsonResponse
    {
        Log::info('💳 Payment charge request received', [
            'location_id' => $request->location_id,
            'amount' => $request->amount,
            'has_customer_data' => $request->has('customer'),
            'customer_data' => $request->customer ?? 'NOT PROVIDED',
            'all_request_data' => $request->except(['opaqueData']), // Don't log sensitive payment data
        ]);

        $request->validate([
            'location_id' => 'required|exists:locations,id',
            'opaqueData' => 'required|array',
            'opaqueData.dataDescriptor' => 'required|string',
            'opaqueData.dataValue' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'order_id' => 'nullable|string',
            'customer_id' => 'nullable|exists:customers,id',
            'description' => 'nullable|string',
            'customer' => 'nullable|array',
            'customer.first_name' => 'nullable|string|max:50',
            'customer.last_name' => 'nullable|string|max:50',
            'customer.email' => 'nullable|email|max:255',
            'customer.phone' => 'nullable|string|max:25',
            'customer.address' => 'nullable|string|max:60',
            'customer.city' => 'nullable|string|max:40',
            'customer.state' => 'nullable|string|max:40',
            'customer.zip' => 'nullable|string|max:20',
            'customer.country' => 'nullable|string|max:60',
            'signature_image' => 'nullable|string',
            'terms_accepted' => 'nullable|boolean',
            'payable_id' => 'nullable|integer',
            'payable_type' => ['nullable', Rule::in([Payment::TYPE_BOOKING, Payment::TYPE_ATTRACTION_PURCHASE, Payment::TYPE_EVENT_PURCHASE])],
            'send_email' => 'nullable|boolean',
            'qr_code' => 'nullable|string', // Base64 encoded QR code for email attachment
        ]);

        try {
            if ($request->payable_id && $request->payable_type) {
                $existingPayment = Payment::where('payable_id', $request->payable_id)
                    ->where('payable_type', $request->payable_type)
                    ->where('amount', $request->amount)
                    ->where('status', 'completed')
                    ->where('created_at', '>=', now()->subMinutes(2))
                    ->first();

                if ($existingPayment) {
                    Log::info('Duplicate card charge prevented (time-window check)', [
                        'existing_payment_id' => $existingPayment->id,
                        'payable_id' => $request->payable_id,
                        'payable_type' => $request->payable_type,
                        'amount' => $request->amount,
                    ]);
                    return response()->json([
                        'success' => true,
                        'message' => 'Payment already processed',
                        'transaction_id' => $existingPayment->transaction_id,
                        'payment' => $existingPayment,
                        'email_sent' => false,
                    ], 200);
                }
            }

            $account = AuthorizeNetAccount::where('location_id', $request->location_id)
                ->where('is_active', true)
                ->first();

            if (!$account) {
                $this->forceDeletePayableOnFailure($request->payable_id ?? null, $request->payable_type ?? null);

                return response()->json([
                    'success' => false,
                    'message' => 'No active Authorize.Net account found for this location'
                ], 503);
            }

            $apiLoginId = trim($account->api_login_id);
            $transactionKey = trim($account->transaction_key);
            $environment = $account->isProduction() ? ANetEnvironment::PRODUCTION : ANetEnvironment::SANDBOX;

            Log::info('🔐 Authorize.Net credentials loaded', [
                'location_id' => $request->location_id,
                'account_id' => $account->id,
                'environment' => $account->environment,
                'environment_constant' => $account->isProduction() ? 'PRODUCTION' : 'SANDBOX',
                'api_login_id_preview' => substr($apiLoginId, 0, 4) . '...' . substr($apiLoginId, -2),
                'api_login_id_length' => strlen($apiLoginId),
                'transaction_key_length' => strlen($transactionKey),
                'has_whitespace' => [
                    'api_login_id' => $apiLoginId !== $account->api_login_id,
                    'transaction_key' => $transactionKey !== $account->transaction_key,
                ],
            ]);

            $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
            $merchantAuthentication->setName($apiLoginId);
            $merchantAuthentication->setTransactionKey($transactionKey);

            $opaqueData = new AnetAPI\OpaqueDataType();
            $opaqueData->setDataDescriptor($request->opaqueData['dataDescriptor']);
            $opaqueData->setDataValue($request->opaqueData['dataValue']);

            Log::info('🎫 Opaque data received from Accept.js', [
                'dataDescriptor' => $request->opaqueData['dataDescriptor'],
                'dataValue_length' => strlen($request->opaqueData['dataValue']),
                'dataValue_preview' => substr($request->opaqueData['dataValue'], 0, 50) . '...',
                'backend_environment' => $account->environment,
                'backend_api_login_id' => substr($apiLoginId, 0, 4) . '...' . substr($apiLoginId, -2),
                'note' => 'Token MUST be created with same API Login ID and matching Public Client Key',
            ]);

            $paymentOne = new AnetAPI\PaymentType();
            $paymentOne->setOpaqueData($opaqueData);

            $transactionRequestType = new AnetAPI\TransactionRequestType();
            $transactionRequestType->setTransactionType("authCaptureTransaction");
            $transactionRequestType->setAmount($request->amount);
            $transactionRequestType->setPayment($paymentOne);

            if ($request->has('customer')) {
                $customerData = $request->customer;

                Log::info('🔍 Processing customer billing data', [
                    'has_first_name' => !empty($customerData['first_name']),
                    'has_last_name' => !empty($customerData['last_name']),
                    'has_email' => !empty($customerData['email']),
                    'has_phone' => !empty($customerData['phone']),
                    'has_address' => !empty($customerData['address']),
                    'has_city' => !empty($customerData['city']),
                    'has_state' => !empty($customerData['state']),
                    'has_zip' => !empty($customerData['zip']),
                    'has_country' => !empty($customerData['country']),
                    'customer_data_keys' => array_keys($customerData),
                ]);

                $billTo = new AnetAPI\CustomerAddressType();

                if (!empty($customerData['first_name'])) {
                    $billTo->setFirstName(substr($customerData['first_name'], 0, 50));
                }
                if (!empty($customerData['last_name'])) {
                    $billTo->setLastName(substr($customerData['last_name'], 0, 50));
                }
                if (!empty($customerData['email'])) {
                    $billTo->setEmail(substr($customerData['email'], 0, 255));
                }
                if (!empty($customerData['phone'])) {
                    $billTo->setPhoneNumber(substr($customerData['phone'], 0, 25));
                }
                if (!empty($customerData['address'])) {
                    $billTo->setAddress(substr($customerData['address'], 0, 60));
                }
                if (!empty($customerData['city'])) {
                    $billTo->setCity(substr($customerData['city'], 0, 40));
                }
                if (!empty($customerData['state'])) {
                    $billTo->setState(substr($customerData['state'], 0, 40));
                }
                if (!empty($customerData['zip'])) {
                    $billTo->setZip(substr($customerData['zip'], 0, 20));
                }
                if (!empty($customerData['country'])) {
                    $billTo->setCountry(substr($customerData['country'], 0, 60));
                }

                $transactionRequestType->setBillTo($billTo);

                Log::info('✅ Customer billing data successfully added to transaction', [
                    'customer_name' => ($customerData['first_name'] ?? '') . ' ' . ($customerData['last_name'] ?? ''),
                    'email' => $customerData['email'] ?? null,
                    'phone' => $customerData['phone'] ?? null,
                    'address' => $customerData['address'] ?? null,
                    'city' => $customerData['city'] ?? null,
                    'state' => $customerData['state'] ?? null,
                    'zip' => $customerData['zip'] ?? null,
                    'country' => $customerData['country'] ?? null,
                ]);
            } else {
                Log::warning('⚠️ No customer billing data provided in payment request');
            }

            if ($request->order_id) {
                $order = new AnetAPI\OrderType();
                $invoiceNumber = substr($request->order_id, 0, 20);
                $order->setInvoiceNumber($invoiceNumber);
                if ($request->description) {
                    $order->setDescription(substr($request->description, 0, 255)); // Max 255 chars
                }
                $transactionRequestType->setOrder($order);

                if (strlen($request->order_id) > 20) {
                    Log::warning('Order ID truncated for Authorize.Net', [
                        'original' => $request->order_id,
                        'truncated' => $invoiceNumber,
                        'original_length' => strlen($request->order_id)
                    ]);
                }
            }

            $apiRequest = new AnetAPI\CreateTransactionRequest();
            $apiRequest->setMerchantAuthentication($merchantAuthentication);
            $apiRequest->setTransactionRequest($transactionRequestType);

            $controller = new AnetController\CreateTransactionController($apiRequest);
            $response = $controller->executeWithApiResponse($environment);

            if ($response != null && $response->getMessages()->getResultCode() == "Ok") {
                $tresponse = $response->getTransactionResponse();

                if ($tresponse != null && $tresponse->getMessages() != null) {
                    $transactionId = $tresponse->getTransId();

                    if (empty($transactionId) || $transactionId == '0') {
                        Log::error('Authorize.Net returned success but no valid transaction ID', [
                            'transaction_id' => $transactionId,
                            'response_code' => $tresponse->getResponseCode(),
                            'auth_code' => $tresponse->getAuthCode(),
                            'location_id' => $request->location_id,
                            'amount' => $request->amount,
                            'environment' => $account->environment,
                        ]);

                        $this->forceDeletePayableOnFailure($request->payable_id ?? null, $request->payable_type ?? null);

                        return response()->json([
                            'success' => false,
                            'message' => 'Payment processing error: Invalid transaction ID received',
                            'error_code' => 'INVALID_TRANSACTION_ID',
                        ], 400);
                    }

                    $cardLastFour = null;
                    if ($tresponse->getAccountNumber()) {
                        $cardLastFour = substr($tresponse->getAccountNumber(), -4);
                    }

                    $avsResultCode = $tresponse->getAvsResultCode();
                    $cvvResultCode = $tresponse->getCvvResultCode();

                    Log::info('AVS/CVV verification results', [
                        'transaction_id' => $transactionId,
                        'avs_result_code' => $avsResultCode,
                        'cvv_result_code' => $cvvResultCode,
                        'location_id' => $request->location_id,
                    ]);

                    $avsRejectCodes = ['N']; // Only hard-reject on full mismatch
                    $avsWarnCodes = ['A', 'W', 'Z']; // Partial matches: log warning but allow

                    if ($avsResultCode && in_array($avsResultCode, $avsRejectCodes)) {
                        Log::warning('AVS verification FAILED - auto-voiding transaction for fraud prevention', [
                            'transaction_id' => $transactionId,
                            'avs_result_code' => $avsResultCode,
                            'location_id' => $request->location_id,
                            'amount' => $request->amount,
                            'customer_zip' => $request->customer['zip'] ?? 'not provided',
                        ]);

                        try {
                            $voidRequest = new AnetAPI\TransactionRequestType();
                            $voidRequest->setTransactionType('voidTransaction');
                            $voidRequest->setRefTransId($transactionId);

                            $voidApiRequest = new AnetAPI\CreateTransactionRequest();
                            $voidApiRequest->setMerchantAuthentication($merchantAuthentication);
                            $voidApiRequest->setTransactionRequest($voidRequest);

                            $voidController = new AnetController\CreateTransactionController($voidApiRequest);
                            $voidResponse = $voidController->executeWithApiResponse($environment);

                            if ($voidResponse != null && $voidResponse->getMessages()->getResultCode() == 'Ok') {
                                Log::info('AVS-failed transaction voided successfully', ['transaction_id' => $transactionId]);
                            } else {
                                Log::error('Failed to void AVS-failed transaction', ['transaction_id' => $transactionId]);
                            }
                        } catch (\Exception $e) {
                            Log::error('Exception voiding AVS-failed transaction', [
                                'transaction_id' => $transactionId,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        $this->forceDeletePayableOnFailure($request->payable_id ?? null, $request->payable_type ?? null);

                        return response()->json([
                            'success' => false,
                            'message' => 'Payment declined: billing address verification failed. Please ensure your billing ZIP code matches the one on file with your card issuer.',
                            'error_code' => 'AVS_MISMATCH',
                            'avs_result_code' => $avsResultCode,
                        ], 400);
                    }

                    if ($avsResultCode && in_array($avsResultCode, $avsWarnCodes)) {
                        Log::warning('AVS partial match - allowing transaction but logging for review', [
                            'transaction_id' => $transactionId,
                            'avs_result_code' => $avsResultCode,
                            'location_id' => $request->location_id,
                        ]);
                    }

                    $payment = Payment::create([
                        'customer_id' => $request->customer_id,
                        'location_id' => $request->location_id,
                        'amount' => $request->amount,
                        'currency' => 'USD',
                        'method' => 'authorize.net',
                        'status' => 'completed',
                        'transaction_id' => $transactionId,
                        'payment_id' => $transactionId,
                        'card_last_four' => $cardLastFour,
                        'avs_result_code' => $avsResultCode,
                        'cvv_result_code' => $cvvResultCode,
                        'payable_id' => $request->payable_id,
                        'payable_type' => $request->payable_type,
                        'notes' => $request->description ?? 'Authorize.Net payment via Accept.js',
                        'paid_at' => now(),
                        'signature_image' => $request->signature_image ? $this->handleSignatureUpload($request->signature_image) : null,
                        'terms_accepted' => $request->boolean('terms_accepted', false),
                    ]);

                    $payable = null;
                    if ($payment->payable_id && $payment->payable_type) {
                        if ($payment->payable_type === Payment::TYPE_BOOKING) {
                            $payable = Booking::withTrashed()->find($payment->payable_id);
                            if ($payable) {
                                $totalPaid = Payment::where('payable_id', $payable->id)
                                    ->where('payable_type', Payment::TYPE_BOOKING)
                                    ->where('status', 'completed')
                                    ->sum('amount');
                                $payable->update([
                                    'amount_paid' => $totalPaid,
                                    'payment_status' => $totalPaid >= $payable->total_amount ? 'paid' : 'partial',
                                    'payment_method' => 'authorize.net',
                                    'transaction_id' => $transactionId,
                                    'status' => 'confirmed',
                                ]);

                                try {
                                    $gcalService = new GoogleCalendarService($payable->location_id);
                                    if ($gcalService->isConnected()) {
                                        $payable->load(['customer', 'package', 'location', 'room', 'attractions', 'addOns']);
                                        $gcalService->updateEventFromBooking($payable);
                                    }
                                } catch (\Exception $e) {
                                    Log::warning('Google Calendar sync failed on charge', ['booking_id' => $payable->id, 'error' => $e->getMessage()]);
                                }
                            }
                        } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE) {
                            $payable = AttractionPurchase::withTrashed()->find($payment->payable_id);
                            if ($payable) {
                                $totalPaid = Payment::where('payable_id', $payable->id)
                                    ->where('payable_type', Payment::TYPE_ATTRACTION_PURCHASE)
                                    ->where('status', 'completed')
                                    ->sum('amount');
                                $payable->update([
                                    'amount_paid' => $totalPaid,
                                    'payment_method' => 'authorize.net',
                                    'transaction_id' => $transactionId,
                                    'status' => AttractionPurchase::STATUS_CONFIRMED,
                                ]);
                            }
                        } elseif ($payment->payable_type === Payment::TYPE_EVENT_PURCHASE) {
                            $payable = EventPurchase::withTrashed()->find($payment->payable_id);
                            if ($payable) {
                                $totalPaid = Payment::where('payable_id', $payable->id)
                                    ->where('payable_type', Payment::TYPE_EVENT_PURCHASE)
                                    ->where('status', 'completed')
                                    ->sum('amount');
                                $payable->update([
                                    'amount_paid' => $totalPaid,
                                    'payment_method' => 'authorize.net',
                                    'transaction_id' => $transactionId,
                                    'payment_status' => $totalPaid >= $payable->total_amount ? 'paid' : 'partial',
                                    'status' => 'confirmed',
                                ]);
                            }
                        }
                    }

                    Log::info('Authorize.Net payment successful with customer data', [
                        'transaction_id' => $transactionId,
                        'amount' => $request->amount,
                        'location_id' => $request->location_id,
                        'avs_result_code' => $avsResultCode,
                        'cvv_result_code' => $cvvResultCode,
                        'customer_name' => $request->has('customer') ?
                            ($request->customer['first_name'] ?? '') . ' ' . ($request->customer['last_name'] ?? '') :
                            'Not provided',
                        'customer_email' => $request->customer['email'] ?? 'Not provided',
                    ]);

                    if ($payment->customer_id) {
                        CustomerNotification::create([
                            'customer_id' => $payment->customer_id,
                            'location_id' => $payment->location_id,
                            'type' => 'payment',
                            'priority' => 'medium',
                            'title' => 'Payment Successful',
                            'message' => "Your payment of $" . number_format($payment->amount, 2) . " has been processed successfully via credit card.",
                            'status' => 'unread',
                            'action_url' => "/payments/{$payment->id}",
                            'action_text' => 'View Receipt',
                            'metadata' => [
                                'payment_id' => $payment->id,
                                'transaction_id' => $transactionId,
                                'auth_code' => $tresponse->getAuthCode(),
                                'amount' => $payment->amount,
                                'method' => 'card',
                            ],
                        ]);
                    }

                    Notification::create([
                        'location_id' => $payment->location_id,
                        'type' => 'payment',
                        'priority' => 'medium',
                        'user_id' => auth()->id(),
                        'title' => 'Online Payment Received',
                        'message' => "Online payment of $" . number_format($payment->amount, 2) . " received via Authorize.Net. Auth Code: {$tresponse->getAuthCode()}",
                        'status' => 'unread',
                        'action_url' => "/payments/{$payment->id}",
                        'action_text' => 'View Payment',
                        'metadata' => [
                            'payment_id' => $payment->id,
                            'transaction_id' => $transactionId,
                            'auth_code' => $tresponse->getAuthCode(),
                            'amount' => $payment->amount,
                            'method' => 'card',
                            'customer_id' => $payment->customer_id,
                            'payable_id' => $payment->payable_id,
                            'payable_type' => $payment->payable_type,
                        ],
                    ]);

                    try {
                        $emailService = app(EmailNotificationService::class);
                        $emailService->triggerPaymentNotification($payment, EmailNotification::TRIGGER_PAYMENT_RECEIVED);
                    } catch (\Exception $e) {
                        Log::error('Failed to send payment received email (charge)', ['error' => $e->getMessage(), 'payment_id' => $payment->id]);
                    }

                    $emailSent = false;
                    $emailError = null;
                    $sendEmail = $request->boolean('send_email', false);

                    if ($sendEmail && $payable) {
                        try {
                            $qrCode = $request->qr_code;

                            if ($payment->payable_type === Payment::TYPE_BOOKING) {
                                $booking = $payable;
                                $booking->load(['customer', 'package', 'location.company', 'room', 'creator', 'attractions', 'addOns']);

                                if ($qrCode) {
                                    $qrCodeData = $qrCode;
                                    if (strpos($qrCodeData, 'data:image') === 0) {
                                        $qrCodeData = substr($qrCodeData, strpos($qrCodeData, ',') + 1);
                                    }

                                    $qrCodeImage = base64_decode($qrCodeData);
                                    if ($qrCodeImage) {
                                        $fileName = 'qr_' . $booking->id . '.png';
                                        $qrCodePath = 'qrcodes/' . $fileName;
                                        $fullPath = storage_path('app/public/' . $qrCodePath);

                                        $dir = dirname($fullPath);
                                        if (!file_exists($dir)) {
                                            mkdir($dir, 0755, true);
                                        }
                                        file_put_contents($fullPath, $qrCodeImage);
                                        $booking->update(['qr_code_path' => $qrCodePath]);
                                    }
                                }

                                $emailService = app(EmailNotificationService::class);
                                $emailService->triggerBookingNotification($booking, EmailNotification::TRIGGER_BOOKING_CONFIRMED);
                                $emailSent = true;

                                Log::info('Booking confirmation email sent from charge() via service', [
                                    'booking_id' => $booking->id,
                                ]);
                            } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE) {
                                $purchase = $payable;
                                $purchase->load(['attraction.location', 'customer', 'createdBy']);

                                $recipientEmail = $purchase->customer
                                    ? $purchase->customer->email
                                    : $purchase->guest_email;

                                if ($recipientEmail && $qrCode) {
                                    $qrCodeBase64 = $qrCode;
                                    if (strpos($qrCodeBase64, 'data:image') === 0) {
                                        $qrCodeBase64 = substr($qrCodeBase64, strpos($qrCodeBase64, ',') + 1);
                                    }

                                    if ($qrCodeBase64) {
                                        $useGmailApi = config('gmail.enabled', false) &&
                                            (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

                                        if ($useGmailApi) {
                                            $gmailService = new GmailApiService();
                                            $mailable = new AttractionPurchaseReceipt($purchase, $qrCodeBase64);
                                            $emailBody = $mailable->render();

                                            $gmailService->sendEmail(
                                                $recipientEmail,
                                                'Your Attraction Purchase Receipt - Zap Zone',
                                                $emailBody,
                                                'Zap Zone',
                                                [['data' => $qrCodeBase64, 'filename' => 'qrcode.png', 'mime_type' => 'image/png']]
                                            );
                                        } else {
                                            $qrCodeImage = base64_decode($qrCodeBase64);
                                            $tempPath = storage_path('app/temp/qr_' . $purchase->id . '_' . time() . '.png');
                                            if (!file_exists(storage_path('app/temp'))) {
                                                mkdir(storage_path('app/temp'), 0755, true);
                                            }
                                            file_put_contents($tempPath, $qrCodeImage);

                                            Mail::send([], [], function ($message) use ($purchase, $tempPath, $recipientEmail, $qrCodeBase64) {
                                                $mailable = new AttractionPurchaseReceipt($purchase, $qrCodeBase64);
                                                $emailBody = $mailable->render();
                                                $message->to($recipientEmail)
                                                    ->subject('Your Attraction Purchase Receipt - Zap Zone')
                                                    ->html($emailBody)
                                                    ->attach($tempPath, ['as' => 'qrcode.png', 'mime' => 'image/png']);
                                            });

                                            if (file_exists($tempPath)) {
                                                unlink($tempPath);
                                            }
                                        }

                                        $emailSent = true;
                                        Log::info('Attraction purchase receipt sent from charge()', [
                                            'purchase_id' => $purchase->id,
                                            'email' => $recipientEmail,
                                        ]);
                                    }
                                }

                                // Pair an SMS with the receipt (fail-safe; never blocks the response).
                                try {
                                    app(\App\Services\SmsNotificationService::class)
                                        ->triggerPurchaseNotification($purchase, \App\Models\SmsNotification::TRIGGER_PURCHASE_CONFIRMED);
                                } catch (\Throwable $e) {
                                    Log::warning('Attraction confirmation SMS failed from charge()', ['error' => $e->getMessage()]);
                                }
                            } elseif ($payment->payable_type === Payment::TYPE_EVENT_PURCHASE) {
                                $eventPurchase = $payable;

                                // Event confirmation goes through the engine so the admin-editable
                                // Email + SMS Notifications templates are the single source of truth.
                                try {
                                    app(EmailNotificationService::class)
                                        ->triggerEventNotification($eventPurchase, EmailNotification::TRIGGER_EVENT_CONFIRMED);
                                    $emailSent = true;
                                    Log::info('Event purchase confirmation notification sent from charge()', [
                                        'event_purchase_id' => $eventPurchase->id,
                                    ]);
                                } catch (\Throwable $e) {
                                    Log::warning('Event confirmation notification failed from charge()', ['error' => $e->getMessage()]);
                                }
                            }
                        } catch (\Exception $e) {
                            $emailError = $e->getMessage();
                            Log::error('Failed to send confirmation email from charge()', [
                                'payment_id' => $payment->id,
                                'payable_type' => $payment->payable_type,
                                'payable_id' => $payment->payable_id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    if ($payable) {
                        try {
                            if ($payment->payable_type === Payment::TYPE_BOOKING) {
                                $booking = $payable;
                                $booking->loadMissing(['customer', 'package', 'location.company', 'room', 'creator', 'attractions', 'addOns']);


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

                                $bookingCustomerName = $booking->customer ? "{$booking->customer->first_name} {$booking->customer->last_name}" : $booking->guest_name;
                                $formattedDate = \Carbon\Carbon::parse($booking->booking_date)->format('m-d');
                                $formattedTime = \Carbon\Carbon::parse($booking->booking_time)->format('g:i A');
                                Notification::create([
                                    'location_id' => $booking->location_id,
                                    'type' => 'booking',
                                    'priority' => 'medium',
                                    'user_id' => $booking->created_by ?? auth()->id(),
                                    'title' => 'New Booking Received',
                                    'message' => "{$bookingCustomerName} — {$formattedDate} at {$formattedTime} • $" . number_format($booking->total_amount, 2) . " ({$booking->reference_number})",
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
                            } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE) {
                                $purchase = $payable;
                                $purchase->loadMissing(['attraction.location', 'customer', 'createdBy', 'addOns']);

                                $emailNotificationService = new EmailNotificationService();
                                $emailNotificationService->processPurchaseCreated($purchase);

                                if ($purchase->customer_id) {
                                    CustomerNotification::create([
                                        'customer_id' => $purchase->customer_id,
                                        'location_id' => $purchase->attraction->location_id ?? null,
                                        'type' => 'payment',
                                        'priority' => 'medium',
                                        'title' => 'Attraction Purchase Confirmed',
                                        'message' => "Your purchase of {$purchase->quantity} x {$purchase->attraction->name} has been confirmed. Total: $" . number_format($purchase->total_amount, 2),
                                        'status' => 'unread',
                                        'action_url' => "/attractions/purchases/{$purchase->id}",
                                        'action_text' => 'View Purchase',
                                        'metadata' => [
                                            'purchase_id' => $purchase->id,
                                            'attraction_id' => $purchase->attraction_id,
                                            'quantity' => $purchase->quantity,
                                            'total_amount' => $purchase->total_amount,
                                        ],
                                    ]);
                                }

                                $attractionCustomerName = $purchase->customer ? "{$purchase->customer->first_name} {$purchase->customer->last_name}" : $purchase->guest_name;
                                if ($purchase->attraction->location_id) {
                                    Notification::create([
                                        'location_id' => $purchase->attraction->location_id,
                                        'type' => 'payment',
                                        'priority' => 'medium',
                                        'user_id' => $purchase->created_by ?? auth()->id(),
                                        'title' => 'New Attraction Purchase',
                                        'message' => "{$attractionCustomerName} — {$purchase->quantity}x {$purchase->attraction->name} • $" . number_format($purchase->total_amount, 2),
                                        'status' => 'unread',
                                        'action_url' => "/attractions/purchases/{$purchase->id}",
                                        'action_text' => 'View Purchase',
                                        'metadata' => [
                                            'purchase_id' => $purchase->id,
                                            'attraction_id' => $purchase->attraction_id,
                                            'customer_id' => $purchase->customer_id,
                                            'quantity' => $purchase->quantity,
                                            'total_amount' => $purchase->total_amount,
                                        ],
                                    ]);
                                }
                            } elseif ($payment->payable_type === Payment::TYPE_EVENT_PURCHASE) {
                                $eventPurchase = $payable;
                                $eventPurchase->loadMissing(['event', 'customer', 'location']);

                                if ($eventPurchase->customer_id) {
                                    CustomerNotification::create([
                                        'customer_id' => $eventPurchase->customer_id,
                                        'location_id' => $eventPurchase->location_id,
                                        'type' => 'payment',
                                        'priority' => 'medium',
                                        'title' => 'Event Purchase Confirmed',
                                        'message' => "Your purchase of {$eventPurchase->quantity} ticket(s) for {$eventPurchase->event->name} has been confirmed. Total: $" . number_format($eventPurchase->total_amount, 2),
                                        'status' => 'unread',
                                        'action_url' => "/events/purchases/{$eventPurchase->id}",
                                        'action_text' => 'View Purchase',
                                        'metadata' => [
                                            'purchase_id' => $eventPurchase->id,
                                            'event_id' => $eventPurchase->event_id,
                                            'quantity' => $eventPurchase->quantity,
                                            'total_amount' => $eventPurchase->total_amount,
                                        ],
                                    ]);
                                }

                                $eventCustomerName = $eventPurchase->customer ? "{$eventPurchase->customer->first_name} {$eventPurchase->customer->last_name}" : $eventPurchase->guest_name;
                                $eventFormattedDate = \Carbon\Carbon::parse($eventPurchase->purchase_date)->format('m-d');
                                $eventFormattedTime = \Carbon\Carbon::parse($eventPurchase->purchase_time)->format('g:i A');
                                Notification::create([
                                    'location_id' => $eventPurchase->location_id,
                                    'type' => 'payment',
                                    'priority' => 'medium',
                                    'user_id' => auth()->id(),
                                    'title' => 'New Event Purchase',
                                    'message' => "{$eventCustomerName} — {$eventPurchase->quantity}x {$eventPurchase->event->name} on {$eventFormattedDate} at {$eventFormattedTime} • $" . number_format($eventPurchase->total_amount, 2),
                                    'status' => 'unread',
                                    'action_url' => "/events/purchases/{$eventPurchase->id}",
                                    'action_text' => 'View Purchase',
                                    'metadata' => [
                                        'purchase_id' => $eventPurchase->id,
                                        'event_id' => $eventPurchase->event_id,
                                        'customer_id' => $eventPurchase->customer_id,
                                        'quantity' => $eventPurchase->quantity,
                                        'total_amount' => $eventPurchase->total_amount,
                                    ],
                                ]);
                            }
                        } catch (\Exception $e) {
                            Log::warning('Failed to send staff notifications from charge()', [
                                'payment_id' => $payment->id,
                                'payable_type' => $payment->payable_type,
                                'payable_id' => $payment->payable_id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Payment processed successfully',
                        'transaction_id' => $transactionId,
                        'auth_code' => $tresponse->getAuthCode(),
                        'avs_result_code' => $avsResultCode,
                        'cvv_result_code' => $cvvResultCode,
                        'payment' => $payment,
                        'email_sent' => $emailSent,
                        'email_error' => $emailError,
                    ]);
                } else {
                    $errorMessage = 'Transaction failed';
                    $errorCode = null;
                    if ($tresponse->getErrors() != null) {
                        $errorCode = $tresponse->getErrors()[0]->getErrorCode();
                        $errorMessage = $tresponse->getErrors()[0]->getErrorText();
                    }

                    Log::warning('Authorize.Net transaction failed', [
                        'error' => $errorMessage,
                        'error_code' => $errorCode,
                        'location_id' => $request->location_id,
                        'environment' => $account->environment,
                        'response_code' => $tresponse->getResponseCode(),
                    ]);

                    $this->forceDeletePayableOnFailure($request->payable_id ?? null, $request->payable_type ?? null);

                    return response()->json([
                        'success' => false,
                        'message' => $errorMessage,
                        'error_code' => $errorCode,
                    ], 400);
                }
            } else {
                $errorMessage = 'Unknown error';
                $errorCode = null;
                $allErrors = [];
                if ($response != null) {
                    $errorMessages = $response->getMessages()->getMessage();
                    $errorCode = $errorMessages[0]->getCode();
                    $errorMessage = $errorMessages[0]->getText();

                    foreach ($errorMessages as $msg) {
                        $allErrors[] = [
                            'code' => $msg->getCode(),
                            'text' => $msg->getText(),
                        ];
                    }
                }

                Log::error('Authorize.Net API error', [
                    'error' => $errorMessage,
                    'error_code' => $errorCode,
                    'all_errors' => $allErrors,
                    'location_id' => $request->location_id,
                    'environment' => $account->environment,
                    'account_id' => $account->id,
                    'response_null' => $response === null,
                    'is_auth_error' => $errorCode === 'E00007',
                    'suggestion' => $errorCode === 'E00007' ? 'Check if credentials match environment. Run test-connection endpoint.' : null,
                ]);

                $this->forceDeletePayableOnFailure($request->payable_id ?? null, $request->payable_type ?? null);

                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                    'error_code' => $errorCode,
                    'environment' => $account->environment,
                    'help' => $errorCode === 'E00007' ? 'Authentication failed. Please verify your Authorize.Net credentials match the selected environment (sandbox/production).' : null,
                ], 400);
            }

        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            Log::error('Payment processing - credential decryption failed', [
                'error' => $e->getMessage(),
                'location_id' => $request->location_id,
            ]);

            $this->forceDeletePayableOnFailure($request->payable_id ?? null, $request->payable_type ?? null);

            return response()->json([
                'success' => false,
                'message' => 'Payment configuration error. Please contact support.',
                'error_code' => 'DECRYPTION_FAILED'
            ], 500);
        } catch (\Exception $e) {
            Log::error('Payment processing exception', [
                'error' => $e->getMessage(),
                'location_id' => $request->location_id,
                'trace' => $e->getTraceAsString(),
            ]);

            $this->forceDeletePayableOnFailure($request->payable_id ?? null, $request->payable_type ?? null);

            return response()->json([
                'success' => false,
                'message' => 'Payment processing failed: ' . $e->getMessage()
            ], 500);
        }
    }

    private function forceDeletePayableOnFailure(?int $payableId, ?string $payableType): void
    {
        if (!$payableId || !$payableType) {
            return;
        }

        try {
            $payable = null;

            if ($payableType === Payment::TYPE_BOOKING) {
                $payable = Booking::find($payableId);
            } elseif ($payableType === Payment::TYPE_ATTRACTION_PURCHASE) {
                $payable = AttractionPurchase::find($payableId);
            } elseif ($payableType === Payment::TYPE_EVENT_PURCHASE) {
                $payable = EventPurchase::find($payableId);
            }

            if (!$payable) {
                return;
            }

            $isPending = $payableType === Payment::TYPE_ATTRACTION_PURCHASE
                ? $payable->status === AttractionPurchase::STATUS_PENDING
                : $payable->status === 'pending';

            if ($isPending) {
                $payable->forceDelete();
                Log::info('Force deleted pending entity after payment failure', [
                    'payable_type' => $payableType,
                    'payable_id' => $payableId,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Force delete failed, falling back to soft delete', [
                'payable_id' => $payableId,
                'payable_type' => $payableType,
                'error' => $e->getMessage(),
            ]);

            try {
                $fallback = null;
                if ($payableType === Payment::TYPE_BOOKING) {
                    $fallback = Booking::find($payableId);
                    if ($fallback && $fallback->status === 'pending') {
                        $fallback->delete();
                    }
                } elseif ($payableType === Payment::TYPE_ATTRACTION_PURCHASE) {
                    $fallback = AttractionPurchase::find($payableId);
                    if ($fallback && $fallback->status === AttractionPurchase::STATUS_PENDING) {
                        $fallback->delete();
                    }
                } elseif ($payableType === Payment::TYPE_EVENT_PURCHASE) {
                    $fallback = EventPurchase::find($payableId);
                    if ($fallback && $fallback->status === 'pending') {
                        $fallback->delete();
                    }
                }

                Log::info('Soft deleted pending entity as fallback after force delete failure', [
                    'payable_type' => $payableType,
                    'payable_id' => $payableId,
                ]);
            } catch (\Exception $fallbackError) {
                Log::error('Soft delete fallback also failed', [
                    'payable_id' => $payableId,
                    'payable_type' => $payableType,
                    'error' => $fallbackError->getMessage(),
                ]);
            }
        }
    }

    public function invoice(Payment $payment)
    {
        $payment->load(['customer', 'location']);

        $payable = $payment->getPayableDetails();

        if ($payable) {
            if ($payment->payable_type === Payment::TYPE_BOOKING) {
                $payable->load(['package', 'customer']);
            } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE) {
                $payable->load(['attraction', 'customer']);
            } elseif ($payment->payable_type === Payment::TYPE_EVENT_PURCHASE) {
                $payable->load(['event', 'customer', 'location']);
            }
        }

        $location = $payment->location;

        $company = null;
        $companyName = 'ZapZone';
        if ($location && $location->company_id) {
            $company = Company::find($location->company_id);
            if ($company) {
                $companyName = $company->name;
            }
        }

        $customer = $payment->customer;

        $timezone = $location->timezone ?? 'UTC';

        $pdf = Pdf::loadView('exports.payment-invoice', [
            'payment' => $payment,
            'payable' => $payable,
            'customer' => $customer,
            'location' => $location,
            'company' => $company,
            'companyName' => $companyName,
            'timezone' => $timezone,
        ]);

        $pdf->setPaper('A4', 'portrait');

        $filename = 'invoice_' . str_pad($payment->id, 6, '0', STR_PAD_LEFT) . '_' . date('Ymd') . '.pdf';

        return $pdf->download($filename);
    }

    public function invoiceView(Payment $payment)
    {
        $payment->load(['customer', 'location']);

        $payable = $payment->getPayableDetails();

        if ($payable) {
            if ($payment->payable_type === Payment::TYPE_BOOKING) {
                $payable->load(['package', 'customer', 'room', 'location', 'addOns', 'attractions']);
            } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE) {
                $payable->load(['attraction', 'customer', 'location']);
            } elseif ($payment->payable_type === Payment::TYPE_EVENT_PURCHASE) {
                $payable->load(['event', 'customer', 'location', 'addOns']);
            }
        }

        $location = $payment->location;

        $company = null;
        $companyName = 'ZapZone';
        if ($location && $location->company_id) {
            $company = Company::find($location->company_id);
            if ($company) {
                $companyName = $company->name;
            }
        }

        $customer = $payment->customer;

        $timezone = $location->timezone ?? 'UTC';

        $pdf = Pdf::loadView('exports.payment-invoice', [
            'payment' => $payment,
            'payable' => $payable,
            'customer' => $customer,
            'location' => $location,
            'company' => $company,
            'companyName' => $companyName,
            'timezone' => $timezone,
        ]);

        $pdf->setPaper('A4', 'portrait');

        return $pdf->stream('invoice_' . str_pad($payment->id, 6, '0', STR_PAD_LEFT) . '.pdf');
    }

    public function invoicesReport(Request $request)
    {
        $request->validate([
            'location_id' => 'nullable|exists:locations,id',
            'status' => ['nullable', Rule::in(['pending', 'completed', 'failed', 'refunded', 'voided'])],
            'method' => ['nullable', Rule::in(['card', 'cash', 'authorize.net', 'in-store'])],
            'payable_type' => ['nullable', Rule::in([Payment::TYPE_BOOKING, Payment::TYPE_ATTRACTION_PURCHASE, Payment::TYPE_EVENT_PURCHASE])],
            'customer_id' => 'nullable|exists:customers,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'payment_ids' => 'nullable|array',
            'payment_ids.*' => 'exists:payments,id',
        ]);

        $query = Payment::with(['customer', 'location']);

        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('method')) {
            $query->where('method', $request->method);
        }

        if ($request->has('payable_type')) {
            $query->where('payable_type', $request->payable_type);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $endDate = Carbon::parse($request->end_date)->endOfDay();
            $query->whereBetween('created_at', [$startDate, $endDate]);
        } elseif ($request->has('start_date')) {
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $query->where('created_at', '>=', $startDate);
        } elseif ($request->has('end_date')) {
            $endDate = Carbon::parse($request->end_date)->endOfDay();
            $query->where('created_at', '<=', $endDate);
        }

        if ($request->has('payment_ids') && is_array($request->payment_ids)) {
            $query->whereIn('id', $request->payment_ids);
        }

        $payments = $query->orderBy('created_at', 'desc')->get();

        foreach ($payments as $payment) {
            if ($payment->payable_type === Payment::TYPE_BOOKING) {
                $payment->payable = Booking::withTrashed()->with('package')->find($payment->payable_id);
            } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE) {
                $payment->payable = AttractionPurchase::withTrashed()->with('attraction')->find($payment->payable_id);
            } elseif ($payment->payable_type === Payment::TYPE_EVENT_PURCHASE) {
                $payment->payable = EventPurchase::withTrashed()->with('event')->find($payment->payable_id);
            }
        }

        $summary = [
            'total_count' => $payments->count(),
            'total_amount' => $payments->sum('amount'),
            'completed_count' => $payments->where('status', 'completed')->count(),
            'completed_amount' => $payments->where('status', 'completed')->sum('amount'),
            'pending_count' => $payments->where('status', 'pending')->count(),
            'pending_amount' => $payments->where('status', 'pending')->sum('amount'),
            'refunded_count' => $payments->where('status', 'refunded')->count(),
            'refunded_amount' => $payments->where('status', 'refunded')->sum('amount'),
        ];

        $locationName = 'All Locations';
        $companyName = 'ZapZone';
        $company = null;

        if ($request->has('location_id')) {
            $location = Location::find($request->location_id);
            if ($location) {
                $locationName = $location->name;
                if ($location->company_id) {
                    $company = Company::find($location->company_id);
                    if ($company) {
                        $companyName = $company->name;
                    }
                }
            }
        }

        $filters = [];
        if ($request->has('start_date') && $request->has('end_date')) {
            $filters['date_range'] = Carbon::parse($request->start_date)->format('M d, Y') .
                                     ' - ' . Carbon::parse($request->end_date)->format('M d, Y');
        } elseif ($request->has('start_date')) {
            $filters['date_range'] = 'From ' . Carbon::parse($request->start_date)->format('M d, Y');
        } elseif ($request->has('end_date')) {
            $filters['date_range'] = 'Until ' . Carbon::parse($request->end_date)->format('M d, Y');
        }

        if ($request->has('status')) {
            $filters['status'] = $request->status;
        }
        if ($request->has('method')) {
            $filters['method'] = $request->method;
        }
        if ($request->has('payable_type')) {
            $filters['payable_type'] = $request->payable_type;
        }

        $timezone = isset($location) && $location ? ($location->timezone ?? 'UTC') : 'UTC';

        $pdf = Pdf::loadView('exports.payment-invoices-report', [
            'payments' => $payments,
            'summary' => $summary,
            'company' => $company,
            'companyName' => $companyName,
            'locationName' => $locationName,
            'filters' => count($filters) > 0 ? $filters : null,
            'reportTitle' => 'Payment Invoices Report',
            'timezone' => $timezone,
        ]);

        $pdf->setPaper('A4', 'portrait');

        $filename = 'invoices_report_' . date('Ymd_His') . '.pdf';

        if ($request->get('download', false)) {
            return $pdf->download($filename);
        }

        return $pdf->stream($filename);
    }

    public function invoicesBulk(Request $request)
    {
        $request->validate([
            'payment_ids' => 'required|array|min:1',
            'payment_ids.*' => 'exists:payments,id',
        ]);

        $payments = Payment::with(['customer', 'location'])
            ->whereIn('id', $request->payment_ids)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($payments->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No payments found for the provided IDs'
            ], 404);
        }

        $html = '';
        $totalPayments = $payments->count();
        $index = 0;

        foreach ($payments as $payment) {
            $index++;

            $payable = $payment->getPayableDetails();

            if ($payable) {
                if ($payment->payable_type === Payment::TYPE_BOOKING) {
                    $payable->load(['package', 'customer', 'room', 'location', 'addOns', 'attractions']);
                } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE) {
                    $payable->load(['attraction', 'customer', 'location']);
                } elseif ($payment->payable_type === Payment::TYPE_EVENT_PURCHASE) {
                    $payable->load(['event', 'customer', 'location', 'addOns']);
                }
            }

            $location = $payment->location;

            $company = null;
            $companyName = 'ZapZone';
            if ($location && $location->company_id) {
                $company = Company::find($location->company_id);
                if ($company) {
                    $companyName = $company->name;
                }
            }

            $customer = $payment->customer;
            $timezone = $location->timezone ?? 'UTC';

            $invoiceHtml = view('exports.payment-invoice', [
                'payment' => $payment,
                'payable' => $payable,
                'customer' => $customer,
                'location' => $location,
                'company' => $company,
                'companyName' => $companyName,
                'timezone' => $timezone,
            ])->render();

            $html .= $invoiceHtml;

            if ($index < $totalPayments) {
                $html .= '<div style="page-break-after: always;"></div>';
            }
        }

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('A4', 'portrait');

        $filename = 'invoices_bulk_' . date('Ymd_His') . '.pdf';

        if ($request->get('download', true)) {
            return $pdf->download($filename);
        }

        return $pdf->stream($filename);
    }

    public function invoicesDay(Request $request, string $date)
    {
        $request->merge([
            'start_date' => $date,
            'end_date' => $date,
        ]);

        return $this->invoicesExport($request);
    }

    public function invoicesWeek(Request $request, string $week = 'current')
    {
        if ($week === 'current') {
            $startOfWeek = now()->startOfWeek();
            $endOfWeek = now()->endOfWeek();
        } elseif ($week === 'next') {
            $startOfWeek = now()->addWeek()->startOfWeek();
            $endOfWeek = now()->addWeek()->endOfWeek();
        } else {
            $date = Carbon::parse($week);
            $startOfWeek = $date->startOfWeek();
            $endOfWeek = $date->copy()->endOfWeek();
        }

        $request->merge([
            'start_date' => $startOfWeek->format('Y-m-d'),
            'end_date' => $endOfWeek->format('Y-m-d'),
        ]);

        return $this->invoicesExport($request);
    }

    public function invoicesExport(Request $request)
    {
        $request->validate([
            'payment_ids' => 'nullable|array',
            'payment_ids.*' => 'exists:payments,id',
            'payable_type' => ['nullable', Rule::in([Payment::TYPE_BOOKING, Payment::TYPE_ATTRACTION_PURCHASE, Payment::TYPE_EVENT_PURCHASE])],
            'date' => 'nullable|date',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'week' => 'nullable|string',
            'location_id' => 'nullable|exists:locations,id',
            'customer_id' => 'nullable|exists:customers,id',
            'status' => ['nullable', Rule::in(['pending', 'completed', 'failed', 'refunded', 'voided'])],
            'method' => ['nullable', Rule::in(['card', 'cash', 'authorize.net', 'in-store'])],
            'view_mode' => ['nullable', Rule::in(['report', 'individual'])],
        ]);

        $query = Payment::with(['customer', 'location']);
        $dateRange = null;

        if ($request->has('payment_ids')) {
            $ids = is_array($request->payment_ids)
                ? $request->payment_ids
                : explode(',', $request->payment_ids);
            $query->whereIn('id', $ids);
        }

        if ($request->has('payable_type')) {
            $query->where('payable_type', $request->payable_type);
        }

        if ($request->has('date')) {
            $date = $request->date;
            $query->whereDate('created_at', $date);
            $dateRange = ['start' => $date, 'end' => $date];
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $endDate = Carbon::parse($request->end_date)->endOfDay();
            $query->whereBetween('created_at', [$startDate, $endDate]);
            $dateRange = ['start' => $request->start_date, 'end' => $request->end_date];
        } elseif ($request->has('start_date')) {
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $query->where('created_at', '>=', $startDate);
            $dateRange = ['start' => $request->start_date, 'end' => now()->format('Y-m-d')];
        } elseif ($request->has('end_date')) {
            $endDate = Carbon::parse($request->end_date)->endOfDay();
            $query->where('created_at', '<=', $endDate);
            $dateRange = ['start' => 'Beginning', 'end' => $request->end_date];
        }

        if ($request->has('week') && !$request->has('start_date') && !$request->has('date')) {
            $weekParam = $request->week;

            if ($weekParam === 'current') {
                $startOfWeek = now()->startOfWeek();
                $endOfWeek = now()->endOfWeek();
            } elseif ($weekParam === 'next') {
                $startOfWeek = now()->addWeek()->startOfWeek();
                $endOfWeek = now()->addWeek()->endOfWeek();
            } else {
                $date = Carbon::parse($weekParam);
                $startOfWeek = $date->startOfWeek();
                $endOfWeek = $date->copy()->endOfWeek();
            }

            $query->whereBetween('created_at', [$startOfWeek, $endOfWeek]);
            $dateRange = ['start' => $startOfWeek->format('Y-m-d'), 'end' => $endOfWeek->format('Y-m-d')];
        }

        if ($request->has('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('method')) {
            $query->where('method', $request->method);
        }

        $query->orderBy('created_at', 'desc');

        $payments = $query->get();

        if ($payments->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No payments found for the specified criteria'
            ], 404);
        }

        foreach ($payments as $payment) {
            if ($payment->payable_type === Payment::TYPE_BOOKING) {
                $payment->payable = Booking::with(['package', 'customer', 'room', 'location', 'addOns', 'attractions'])->find($payment->payable_id);
            } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE) {
                $payment->payable = AttractionPurchase::with(['attraction.location', 'customer'])->find($payment->payable_id);
            } elseif ($payment->payable_type === Payment::TYPE_EVENT_PURCHASE) {
                $payment->payable = EventPurchase::with(['event', 'customer', 'location', 'addOns'])->find($payment->payable_id);
            }
        }

        $viewMode = $request->get('view_mode', 'individual');

        $location = null;
        $locationName = 'All Locations';
        $company = null;
        $companyName = 'ZapZone';

        if ($request->has('location_id')) {
            $location = Location::find($request->location_id);
            if ($location) {
                $locationName = $location->name;
                if ($location->company_id) {
                    $company = Company::find($location->company_id);
                    if ($company) {
                        $companyName = $company->name;
                    }
                }
            }
        }

        if ($viewMode === 'report') {
            $summary = [
                'total_count' => $payments->count(),
                'total_amount' => $payments->sum('amount'),
                'completed_count' => $payments->where('status', 'completed')->count(),
                'completed_amount' => $payments->where('status', 'completed')->sum('amount'),
                'pending_count' => $payments->where('status', 'pending')->count(),
                'pending_amount' => $payments->where('status', 'pending')->sum('amount'),
                'refunded_count' => $payments->where('status', 'refunded')->count(),
                'refunded_amount' => $payments->where('status', 'refunded')->sum('amount'),
                'booking_count' => $payments->where('payable_type', Payment::TYPE_BOOKING)->count(),
                'booking_amount' => $payments->where('payable_type', Payment::TYPE_BOOKING)->sum('amount'),
                'attraction_count' => $payments->where('payable_type', Payment::TYPE_ATTRACTION_PURCHASE)->count(),
                'attraction_amount' => $payments->where('payable_type', Payment::TYPE_ATTRACTION_PURCHASE)->sum('amount'),
                'event_purchase_count' => $payments->where('payable_type', Payment::TYPE_EVENT_PURCHASE)->count(),
                'event_purchase_amount' => $payments->where('payable_type', Payment::TYPE_EVENT_PURCHASE)->sum('amount'),
            ];

            $filters = [];
            if ($dateRange) {
                if ($dateRange['start'] === $dateRange['end']) {
                    $filters['date_range'] = Carbon::parse($dateRange['start'])->format('l, F j, Y');
                } else {
                    $filters['date_range'] = Carbon::parse($dateRange['start'])->format('M d, Y') .
                                             ' - ' . Carbon::parse($dateRange['end'])->format('M d, Y');
                }
            }
            if ($request->has('payable_type')) {
                $filters['payable_type'] = $request->payable_type;
            }
            if ($request->has('status')) {
                $filters['status'] = $request->status;
            }
            if ($request->has('method')) {
                $filters['method'] = $request->method;
            }

            $reportTimezone = isset($location) && $location ? ($location->timezone ?? 'UTC') : 'UTC';

            $pdf = Pdf::loadView('exports.payment-invoices-report', [
                'payments' => $payments,
                'summary' => $summary,
                'company' => $company,
                'companyName' => $companyName,
                'locationName' => $locationName,
                'filters' => count($filters) > 0 ? $filters : null,
                'reportTitle' => $this->getReportTitle($request, $dateRange),
                'timezone' => $reportTimezone,
            ]);
        } else {
            $html = '';
            $totalPayments = $payments->count();
            $index = 0;

            foreach ($payments as $payment) {
                $index++;

                $payable = $payment->payable;
                $paymentLocation = $payment->location;
                if (!$paymentLocation && $payable) {
                    if ($payment->payable_type === Payment::TYPE_BOOKING) {
                        $paymentLocation = $payable->location ?? null;
                    } elseif ($payment->payable_type === Payment::TYPE_ATTRACTION_PURCHASE) {
                        $paymentLocation = $payable->attraction->location ?? null;
                    } elseif ($payment->payable_type === Payment::TYPE_EVENT_PURCHASE) {
                        $paymentLocation = $payable->location ?? null;
                    }
                }
                $paymentLocation = $paymentLocation ?? $location;
                $paymentCompany = null;
                $paymentCompanyName = $companyName;

                if ($paymentLocation && $paymentLocation->company_id) {
                    $paymentCompany = Company::find($paymentLocation->company_id);
                    if ($paymentCompany) {
                        $paymentCompanyName = $paymentCompany->name;
                    }
                }

                $invoiceHtml = view('exports.payment-invoice', [
                    'payment' => $payment,
                    'payable' => $payable,
                    'customer' => $payment->customer,
                    'location' => $paymentLocation,
                    'company' => $paymentCompany,
                    'companyName' => $paymentCompanyName,
                    'timezone' => $paymentLocation->timezone ?? 'UTC',
                ])->render();

                $html .= $invoiceHtml;

                if ($index < $totalPayments) {
                    $html .= '<div style="page-break-after: always;"></div>';
                }
            }

            $pdf = Pdf::loadHTML($html);
        }

        $pdf->setPaper('A4', 'portrait');

        $filename = 'invoices';
        if ($request->has('payable_type')) {
            $filename .= '-' . str_replace('_', '-', $request->payable_type);
        }
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

    private function getReportTitle(Request $request, ?array $dateRange): string
    {
        $title = 'Payment Invoices';

        if ($request->has('payable_type')) {
            if ($request->payable_type === Payment::TYPE_BOOKING) {
                $title = 'Package Booking Invoices';
            } elseif ($request->payable_type === Payment::TYPE_ATTRACTION_PURCHASE) {
                $title = 'Attraction Purchase Invoices';
            } elseif ($request->payable_type === Payment::TYPE_EVENT_PURCHASE) {
                $title = 'Event Purchase Invoices';
            }
        }

        if ($dateRange) {
            if ($dateRange['start'] === $dateRange['end']) {
                $title .= ' - ' . Carbon::parse($dateRange['start'])->format('F j, Y');
            }
        }

        return $title;
    }

    public function packageInvoicesExport(Request $request)
    {
        $request->validate([
            'package_id' => 'required|exists:packages,id',
            'date' => 'nullable|date',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'location_id' => 'nullable|exists:locations,id',
            'status' => ['nullable', Rule::in(['pending', 'completed', 'failed', 'refunded', 'voided'])],
        ]);

        $user = auth()->user();

        $package = Package::findOrFail($request->package_id);

        $query = Payment::with(['customer', 'location'])
            ->where('payable_type', Payment::TYPE_BOOKING)
            ->whereHas('booking', function ($q) use ($request) {
                $q->where('package_id', $request->package_id);
            });

        if ($user->location_id) {
            $query->where('location_id', $user->location_id);
        } else {
            if ($request->has('location_id')) {
                $location = Location::where('id', $request->location_id)
                    ->where('company_id', $user->company_id)
                    ->first();

                if (!$location) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Location not found or access denied'
                    ], 403);
                }

                $query->where('location_id', $request->location_id);
            } else {
                $companyLocationIds = Location::where('company_id', $user->company_id)->pluck('id');
                $query->whereIn('location_id', $companyLocationIds);
            }
        }

        $dateRange = null;

        if ($request->has('date')) {
            $date = $request->date;
            $query->whereDate('created_at', $date);
            $dateRange = ['start' => $date, 'end' => $date];
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $endDate = Carbon::parse($request->end_date)->endOfDay();
            $query->whereBetween('created_at', [$startDate, $endDate]);
            $dateRange = ['start' => $request->start_date, 'end' => $request->end_date];
        } elseif ($request->has('start_date')) {
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $query->where('created_at', '>=', $startDate);
            $dateRange = ['start' => $request->start_date, 'end' => now()->format('Y-m-d')];
        } elseif ($request->has('end_date')) {
            $endDate = Carbon::parse($request->end_date)->endOfDay();
            $query->where('created_at', '<=', $endDate);
            $dateRange = ['start' => 'Beginning', 'end' => $request->end_date];
        }


        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $query->orderBy('created_at', 'desc');

        $payments = $query->get();

        if ($payments->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No payments found for this package with the specified criteria'
            ], 404);
        }

        foreach ($payments as $payment) {
            $payment->payable = Booking::with([
                'package',
                'customer',
                'room',
                'location',
                'addOns',
                'attractions'
            ])->find($payment->payable_id);
        }

        $location = null;
        $locationName = 'All Locations';
        $company = null;
        $companyName = 'ZapZone';

        if ($user->company_id) {
            $company = Company::find($user->company_id);
            if ($company) {
                $companyName = $company->name;
            }
        }

        if ($user->location_id) {
            $location = Location::find($user->location_id);
            if ($location) {
                $locationName = $location->name;
            }
        } elseif ($request->has('location_id')) {
            $location = Location::find($request->location_id);
            if ($location) {
                $locationName = $location->name;
            }
        }

        $summary = [
            'total_invoices' => $payments->count(),
            'total_amount' => $payments->sum('amount'),
            'completed_count' => $payments->where('status', 'completed')->count(),
            'completed_amount' => $payments->where('status', 'completed')->sum('amount'),
            'pending_count' => $payments->where('status', 'pending')->count(),
            'pending_amount' => $payments->where('status', 'pending')->sum('amount'),
            'refunded_count' => $payments->where('status', 'refunded')->count(),
            'refunded_amount' => $payments->where('status', 'refunded')->sum('amount'),
            'total_bookings' => $payments->unique('payable_id')->count(),
        ];

        $packageTimezone = isset($location) && $location ? ($location->timezone ?? 'UTC') : 'UTC';

        $pdf = Pdf::loadView('exports.package-invoices-report', [
            'payments' => $payments,
            'package' => $package,
            'summary' => $summary,
            'company' => $company,
            'companyName' => $companyName,
            'locationName' => $locationName,
            'dateRange' => $dateRange,
            'timezone' => $packageTimezone,
        ]);

        $pdf->setPaper('A4', 'portrait');

        $filename = 'invoices-' . \Illuminate\Support\Str::slug($package->name);
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

    private function handleSignatureUpload(string $image): string
    {
        if (is_string($image) && strpos($image, 'data:image') === 0) {
            preg_match('/data:image\/(\w+);base64,/', $image, $matches);
            $imageType = $matches[1] ?? 'png';
            $base64Data = substr($image, strpos($image, ',') + 1);
            $imageData = base64_decode($base64Data, true);

            $filename = uniqid() . '.' . $imageType;
            $path = 'images/signatures';
            $fullPath = storage_path('app/public/' . $path);

            if (!file_exists($fullPath)) {
                mkdir($fullPath, 0755, true);
            }

            file_put_contents($fullPath . '/' . $filename, $imageData);

            return $path . '/' . $filename;
        }

        return $image;
    }
}
