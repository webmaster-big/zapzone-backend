<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\AttractionPurchase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StreamController extends Controller
    {
        /**
         * Maximum runtime for SSE streams in seconds (5 minutes)
         * Client should reconnect after stream ends
         */
        private const MAX_STREAM_RUNTIME = 300;

        /**
         * Interval between iterations in seconds
         */
        private const POLL_INTERVAL = 3;

        /**
         * Clean up memory to prevent exhaustion in long-running SSE streams
         */
        private function cleanupMemory(): void
        {
            // Force garbage collection
            gc_collect_cycles();

            // Clear Eloquent's query log if enabled
            if (DB::connection()->logging()) {
                DB::connection()->flushQueryLog();
            }
        }

        /**
         * Stream booking notifications using Server-Sent Events (SSE)
         *
         * @param Request $request
         * @return \Symfony\Component\HttpFoundation\StreamedResponse
         */
        public function bookingNotifications(Request $request)
        {
            $locationId = $request->query('location_id');
            $userId = $request->query('user_id'); // Filter out user's own notifications

            Log::info('=== SSE Booking Stream Started ===', [
                'location_id' => $locationId,
                'user_id' => $userId,
                'timestamp' => now()->toDateTimeString(),
            ]);

return response()->stream(function () use ($locationId, $userId) {
            // Disable query logging to prevent memory buildup
            DB::connection()->disableQueryLog();

            // Set headers for SSE
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // Disable nginx buffering
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');

            $lastId = 0;
            $startTime = time();
            $iterations = 0;

            // Keep sending updates every 3 seconds, with timeout
            while (true) {
                // Check timeout to prevent memory exhaustion
                if ((time() - $startTime) >= self::MAX_STREAM_RUNTIME) {
                    echo "event: timeout\n";
                    echo "data: {\"message\": \"Stream timeout, please reconnect\"}\n\n";
                    ob_flush();
                    flush();
                    break;
                }

                // Query for new bookings - select only needed fields
                $query = Booking::select([
                        'id', 'reference_number', 'customer_id', 'package_id', 'location_id',
                        'room_id', 'guest_name', 'booking_date', 'booking_time', 'status',
                        'total_amount', 'created_at', 'created_by'
                    ])
                    ->with([
                        'customer:id,first_name,last_name',
                        'package:id,name',
                        'location:id,name',
                        'room:id,name'
                    ])
                    ->where('id', '>', $lastId);

                // Filter by location if provided
                if ($locationId) {
                    $query->where('location_id', $locationId);
                }

                // Filter out user's own bookings (only if created_by is not null)
                if ($userId) {
                    $query->where(function($q) use ($userId) {
                        $q->whereNull('created_by')
                          ->orWhere('created_by', '!=', $userId);
                    });
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
                                'user_id' => $booking->created_by,
                            ];

                            echo "id: {$booking->id}\n";
                            echo "event: booking\n";
                            echo "data: " . json_encode($data) . "\n\n";
                            ob_flush();
                            flush();

                        $lastId = $booking->id;
                    }

                    // Clear the collection to free memory
                    unset($bookings);
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

                // Periodic memory cleanup (every 10 iterations)
                $iterations++;
                if ($iterations % 10 === 0) {
                    $this->cleanupMemory();
                }

                // Wait before next update
                sleep(self::POLL_INTERVAL);
            }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
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
            $userId = $request->query('user_id'); // Filter out user's own notifications

            Log::info('=== SSE Attraction Purchase Stream Started ===', [
                'location_id' => $locationId,
                'user_id' => $userId,
                'timestamp' => now()->toDateTimeString(),
            ]);

return response()->stream(function () use ($locationId, $userId) {
            // Disable query logging to prevent memory buildup
            DB::connection()->disableQueryLog();

            // Set headers for SSE
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // Disable nginx buffering
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');

            $lastId = 0;
            $startTime = time();
            $iterations = 0;

            // Keep sending updates with timeout
            while (true) {
                // Check timeout to prevent memory exhaustion
                if ((time() - $startTime) >= self::MAX_STREAM_RUNTIME) {
                    echo "event: timeout\n";
                    echo "data: {\"message\": \"Stream timeout, please reconnect\"}\n\n";
                    ob_flush();
                    flush();
                    break;
                }

                // Query for new attraction purchases - select only needed fields
                $query = AttractionPurchase::select([
                        'id', 'attraction_id', 'customer_id', 'guest_name', 'quantity',
                        'total_amount', 'status', 'payment_method', 'purchase_date',
                        'created_at', 'created_by'
                    ])
                    ->with([
                        'customer:id,first_name,last_name',
                        'attraction:id,name,location_id',
                        'attraction.location:id,name'
                    ])
                    ->where('id', '>', $lastId);

                // Filter by location if provided
                if ($locationId) {
                    $query->whereHas('attraction', function ($q) use ($locationId) {
                        $q->where('location_id', $locationId);
                    });
                }

                // Filter out user's own purchases (only if created_by is not null)
                if ($userId) {
                    $query->where(function($q) use ($userId) {
                        $q->whereNull('created_by')
                          ->orWhere('created_by', '!=', $userId);
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
                                'user_id' => $purchase->created_by,
                            ];

                            echo "id: {$purchase->id}\n";
                            echo "event: attraction_purchase\n";
                            echo "data: " . json_encode($data) . "\n\n";
                            ob_flush();
                            flush();

                        $lastId = $purchase->id;
                    }

                    // Clear the collection to free memory
                    unset($purchases);
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

                // Periodic memory cleanup (every 10 iterations)
                $iterations++;
                if ($iterations % 10 === 0) {
                    $this->cleanupMemory();
                }

                // Wait before next update
                sleep(self::POLL_INTERVAL);
            }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
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
            $userId = $request->query('user_id'); // Filter out user's own notifications

            Log::info('=== SSE Combined Stream Started ===', [
                'location_id' => $locationId,
                'user_id' => $userId,
                'timestamp' => now()->toDateTimeString(),
            ]);

return response()->stream(function () use ($locationId, $userId) {
            // Disable query logging to prevent memory buildup
            DB::connection()->disableQueryLog();

            // Set headers for SSE
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // Disable nginx buffering
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');

            $lastBookingId = 0;
            $lastPurchaseId = 0;
            $startTime = time();
            $iterations = 0;

            // Keep sending updates with timeout
            while (true) {
                // Check timeout to prevent memory exhaustion
                if ((time() - $startTime) >= self::MAX_STREAM_RUNTIME) {
                    echo "event: timeout\n";
                    echo "data: {\"message\": \"Stream timeout, please reconnect\"}\n\n";
                    ob_flush();
                    flush();
                    break;
                }

                $hasNewData = false;

                // Query for new bookings - select only needed fields
                $bookingQuery = Booking::select([
                        'id', 'reference_number', 'customer_id', 'package_id', 'location_id',
                        'room_id', 'guest_name', 'booking_date', 'booking_time', 'status',
                        'total_amount', 'created_at', 'created_by'
                    ])
                    ->with([
                        'customer:id,first_name,last_name',
                        'package:id,name',
                        'location:id,name',
                        'room:id,name'
                    ])
                    ->where('id', '>', $lastBookingId);

                if ($locationId) {
                    $bookingQuery->where('location_id', $locationId);
                }

                // Filter out user's own bookings (only if created_by is not null)
                if ($userId) {
                    $bookingQuery->where(function($q) use ($userId) {
                        $q->whereNull('created_by')
                          ->orWhere('created_by', '!=', $userId);
                    });
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
                                'user_id' => $booking->created_by,
                            ];

                            echo "id: booking_{$booking->id}\n";
                            echo "event: notification\n";
                            echo "data: " . json_encode($data) . "\n\n";
                            ob_flush();
                            flush();

                        $lastBookingId = $booking->id;
                        $hasNewData = true;
                    }

                    // Clear the collection to free memory
                    unset($bookings);
                }

                // Query for new attraction purchases - select only needed fields
                $purchaseQuery = AttractionPurchase::select([
                        'id', 'attraction_id', 'customer_id', 'guest_name', 'quantity',
                        'total_amount', 'status', 'payment_method', 'purchase_date',
                        'created_at', 'created_by'
                    ])
                    ->with([
                        'customer:id,first_name,last_name',
                        'attraction:id,name,location_id',
                        'attraction.location:id,name'
                    ])
                    ->where('id', '>', $lastPurchaseId);

                if ($locationId) {
                        $purchaseQuery->whereHas('attraction', function ($q) use ($locationId) {
                            $q->where('location_id', $locationId);
                        });
                    }

                // Filter out user's own purchases (only if created_by is not null)
                if ($userId) {
                    $purchaseQuery->where(function($q) use ($userId) {
                        $q->whereNull('created_by')
                          ->orWhere('created_by', '!=', $userId);
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
                            'user_id' => $purchase->created_by,
                        ];

                            echo "id: purchase_{$purchase->id}\n";
                            echo "event: notification\n";
                            echo "data: " . json_encode($data) . "\n\n";
                            ob_flush();
                            flush();

                        $lastPurchaseId = $purchase->id;
                        $hasNewData = true;
                    }

                    // Clear the collection to free memory
                    unset($purchases);
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

                // Periodic memory cleanup (every 10 iterations)
                $iterations++;
                if ($iterations % 10 === 0) {
                    $this->cleanupMemory();
                }

                // Wait before next update
                sleep(self::POLL_INTERVAL);
            }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            ]);
        }
    }
