<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\AttractionPurchase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StreamController extends Controller
{
    /**
     * Stream booking notifications using Server-Sent Events (SSE)
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function bookingNotifications(Request $request)
    {
        $locationId = $request->query('location_id');

        Log::info('=== SSE Booking Stream Started ===', [
            'location_id' => $locationId,
            'timestamp' => now()->toDateTimeString(),
        ]);

        return response()->stream(function () use ($locationId) {
            // Set headers for SSE
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // Disable nginx buffering

            $lastId = 0;

            // Keep sending updates every 3 seconds
            while (true) {
                // Query for new bookings
                $query = Booking::with(['customer', 'package', 'location', 'room'])
                    ->where('id', '>', $lastId);

                // Filter by location if provided
                if ($locationId) {
                    $query->where('location_id', $locationId);
                }

                $bookings = $query->orderBy('id', 'asc')
                    ->limit(10)
                    ->get();

                if ($bookings->isNotEmpty()) {
                    foreach ($bookings as $booking) {
                        $data = [
                            'id' => $booking->id,
                            'type' => 'booking',
                            'reference_number' => $booking->reference_number,
                            'customer_name' => $booking->customer
                                ? $booking->customer->first_name . ' ' . $booking->customer->last_name
                                : $booking->guest_name,
                            'package_name' => $booking->package->name ?? null,
                            'location_name' => $booking->location->name ?? null,
                            'booking_date' => $booking->booking_date,
                            'booking_time' => $booking->booking_time,
                            'status' => $booking->status,
                            'total_amount' => $booking->total_amount,
                            'created_at' => $booking->created_at->toIso8601String(),
                            'timestamp' => now()->toIso8601String(),
                        ];

                        echo "id: {$booking->id}\n";
                        echo "event: booking\n";
                        echo "data: " . json_encode($data) . "\n\n";
                        ob_flush();
                        flush();

                        $lastId = $booking->id;
                    }
                } else {
                    // Send heartbeat to keep connection alive
                    echo ": heartbeat\n\n";
                    ob_flush();
                    flush();
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
     * Stream attraction purchase notifications using Server-Sent Events (SSE)
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function attractionPurchaseNotifications(Request $request)
    {
        $locationId = $request->query('location_id');

        Log::info('=== SSE Attraction Purchase Stream Started ===', [
            'location_id' => $locationId,
            'timestamp' => now()->toDateTimeString(),
        ]);

        return response()->stream(function () use ($locationId) {
            // Set headers for SSE
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // Disable nginx buffering

            $lastId = 0;
            $lastHash = '';

            // Keep sending updates every 3 seconds
            while (true) {
                // Query for new attraction purchases
                $query = AttractionPurchase::with(['customer', 'attraction', 'createdBy'])
                    ->where('id', '>', $lastId);

                // Filter by location if provided
                if ($locationId) {
                    $query->whereHas('attraction', function ($q) use ($locationId) {
                        $q->where('location_id', $locationId);
                    });
                }

                $purchases = $query->orderBy('id', 'asc')
                    ->limit(10)
                    ->get();

                if ($purchases->isNotEmpty()) {
                    foreach ($purchases as $purchase) {
                        $data = [
                            'id' => $purchase->id,
                            'type' => 'attraction_purchase',
                            'customer_name' => $purchase->customer
                                ? $purchase->customer->first_name . ' ' . $purchase->customer->last_name
                                : $purchase->guest_name,
                            'attraction_name' => $purchase->attraction->name ?? null,
                            'location_name' => $purchase->attraction->location->name ?? null,
                            'quantity' => $purchase->quantity,
                            'total_amount' => $purchase->total_amount,
                            'status' => $purchase->status,
                            'payment_method' => $purchase->payment_method,
                            'purchase_date' => $purchase->purchase_date,
                            'created_at' => $purchase->created_at->toIso8601String(),
                            'timestamp' => now()->toIso8601String(),
                        ];

                        echo "id: {$purchase->id}\n";
                        echo "event: attraction_purchase\n";
                        echo "data: " . json_encode($data) . "\n\n";
                        ob_flush();
                        flush();

                        $lastId = $purchase->id;
                    }
                } else {
                    // Send heartbeat to keep connection alive
                    echo ": heartbeat\n\n";
                    ob_flush();
                    flush();
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
     * Stream combined notifications (bookings and attraction purchases) using Server-Sent Events (SSE)
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function combinedNotifications(Request $request)
    {
        $locationId = $request->query('location_id');

        Log::info('=== SSE Combined Stream Started ===', [
            'location_id' => $locationId,
            'timestamp' => now()->toDateTimeString(),
        ]);

        return response()->stream(function () use ($locationId) {
            // Set headers for SSE
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // Disable nginx buffering

            $lastBookingId = 0;
            $lastPurchaseId = 0;

            // Keep sending updates every 3 seconds
            while (true) {
                $hasNewData = false;

                // Query for new bookings
                $bookingQuery = Booking::with(['customer', 'package', 'location', 'room'])
                    ->where('id', '>', $lastBookingId);

                if ($locationId) {
                    $bookingQuery->where('location_id', $locationId);
                }

                $bookings = $bookingQuery->orderBy('id', 'asc')->limit(5)->get();

                if ($bookings->isNotEmpty()) {
                    foreach ($bookings as $booking) {
                        $data = [
                            'id' => $booking->id,
                            'type' => 'booking',
                            'reference_number' => $booking->reference_number,
                            'customer_name' => $booking->customer
                                ? $booking->customer->first_name . ' ' . $booking->customer->last_name
                                : $booking->guest_name,
                            'package_name' => $booking->package->name ?? null,
                            'location_name' => $booking->location->name ?? null,
                            'booking_date' => $booking->booking_date,
                            'booking_time' => $booking->booking_time,
                            'status' => $booking->status,
                            'total_amount' => $booking->total_amount,
                            'created_at' => $booking->created_at->toIso8601String(),
                            'timestamp' => now()->toIso8601String(),
                        ];

                        echo "id: booking_{$booking->id}\n";
                        echo "event: notification\n";
                        echo "data: " . json_encode($data) . "\n\n";
                        ob_flush();
                        flush();

                        $lastBookingId = $booking->id;
                        $hasNewData = true;
                    }
                }

                // Query for new attraction purchases
                $purchaseQuery = AttractionPurchase::with(['customer', 'attraction', 'createdBy'])
                    ->where('id', '>', $lastPurchaseId);

                if ($locationId) {
                    $purchaseQuery->whereHas('attraction', function ($q) use ($locationId) {
                        $q->where('location_id', $locationId);
                    });
                }

                $purchases = $purchaseQuery->orderBy('id', 'asc')->limit(5)->get();

                if ($purchases->isNotEmpty()) {
                    foreach ($purchases as $purchase) {
                        $data = [
                            'id' => $purchase->id,
                            'type' => 'attraction_purchase',
                            'customer_name' => $purchase->customer
                                ? $purchase->customer->first_name . ' ' . $purchase->customer->last_name
                                : $purchase->guest_name,
                            'attraction_name' => $purchase->attraction->name ?? null,
                            'location_name' => $purchase->attraction->location->name ?? null,
                            'quantity' => $purchase->quantity,
                            'total_amount' => $purchase->total_amount,
                            'status' => $purchase->status,
                            'payment_method' => $purchase->payment_method,
                            'purchase_date' => $purchase->purchase_date,
                            'created_at' => $purchase->created_at->toIso8601String(),
                            'timestamp' => now()->toIso8601String(),
                        ];

                        echo "id: purchase_{$purchase->id}\n";
                        echo "event: notification\n";
                        echo "data: " . json_encode($data) . "\n\n";
                        ob_flush();
                        flush();

                        $lastPurchaseId = $purchase->id;
                        $hasNewData = true;
                    }
                }

                // Send heartbeat if no new data
                if (!$hasNewData) {
                    echo ": heartbeat\n\n";
                    ob_flush();
                    flush();
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
}
