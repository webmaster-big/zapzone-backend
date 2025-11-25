<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Payment::with(['booking', 'customer']);

        if ($request->has('booking_id')) {
            $query->where('booking_id', $request->booking_id);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('status')) {
            $query->byStatus($request->status);
        }

        if ($request->has('method')) {
            $query->byMethod($request->method);
        }

        $perPage = $request->get('per_page', 15);
        $payments = $query->orderBy('created_at', 'desc')->paginate($perPage);

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
            'booking_id' => 'nullable|exists:bookings,id',
            'customer_id' => 'required|exists:customers,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'string|size:3',
            'method' => ['required', Rule::in(['credit', 'debit', 'cash', 'e-wallet', 'bank_transfer'])],
            'status' => ['sometimes', Rule::in(['pending', 'completed', 'failed', 'refunded'])],
            'notes' => 'nullable|string',
        ]);

        $validated['transaction_id'] = 'TXN' . now()->format('YmdHis') . strtoupper(Str::random(6));

        if ($validated['status'] === 'completed') {
            $validated['paid_at'] = now();
        }

        $payment = Payment::create($validated);
        $payment->load(['booking', 'customer']);

        return response()->json([
            'success' => true,
            'message' => 'Payment created successfully',
            'data' => $payment,
        ], 201);
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->load(['booking', 'customer']);
        return response()->json(['success' => true, 'data' => $payment]);
    }

    public function update(Request $request, Payment $payment): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'completed', 'failed', 'refunded'])],
            'notes' => 'sometimes|nullable|string',
        ]);

        if (isset($validated['status'])) {
            if ($validated['status'] === 'completed' && $payment->status !== 'completed') {
                $validated['paid_at'] = now();
            } elseif ($validated['status'] === 'refunded' && $payment->status !== 'refunded') {
                $validated['refunded_at'] = now();
            }
        }

        $payment->update($validated);
        return response()->json(['success' => true, 'message' => 'Payment updated successfully', 'data' => $payment]);
    }

    public function refund(Payment $payment): JsonResponse
    {
        if ($payment->status !== 'completed') {
            return response()->json(['success' => false, 'message' => 'Only completed payments can be refunded'], 400);
        }

        $payment->update(['status' => 'refunded', 'refunded_at' => now()]);
        return response()->json(['success' => true, 'message' => 'Payment refunded successfully', 'data' => $payment]);
    }
}
