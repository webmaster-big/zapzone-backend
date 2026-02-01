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
         * Maximum runtime for SSE streams in seconds (1 minute)
         * Client should reconnect after stream ends
         * Reduced from 5 minutes to prevent memory exhaustion
         */
        private const MAX_STREAM_RUNTIME = 60;

        /**
         * Interval between iterations in seconds
         */
        private const POLL_INTERVAL = 3;

        /**
         * Initialize SSE stream by clearing output buffers
         * This prevents memory accumulation in long-running streams
         */
        private function initializeStream(): void
        {
            // Disable time limit for long-running streams
            set_time_limit(0);

            // Turn off output buffering completely
            // Clear ALL output buffers to prevent memory buildup
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            // Start a clean output buffer that flushes immediately
            ob_implicit_flush(true);

            // Disable query logging to prevent memory buildup
            DB::connection()->disableQueryLog();
        }

        /**
         * Send SSE data and flush immediately
         */
        private function sendSSE(string $data, ?string $event = null, ?string $id = null): void
        {
            if ($id) {
                echo "id: {$id}\n";
            }
            if ($event) {
                echo "event: {$event}\n";
            }
            echo "data: {$data}\n\n";

            // Force immediate output - no buffering
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }

        /**
         * Send heartbeat to keep connection alive
         */
        private function sendHeartbeat(): void
        {
            echo ": heartbeat\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }

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
                // Initialize stream - clear all buffers to prevent memory buildup
                $this->initializeStream();

                $lastId = 0;
                $startTime = time();
                $iterations = 0;

                // Keep sending updates with timeout
                while (true) {
                    // Check timeout to prevent memory exhaustion
                    if ((time() - $startTime) >= self::MAX_STREAM_RUNTIME) {
                        $this->sendSSE(json_encode(['message' => 'Stream timeout, please reconnect']), 'timeout');
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

                            $this->sendSSE(json_encode($data), 'booking', (string)$booking->id);
                            $lastId = $booking->id;
                        }

                        // Clear the collection to free memory
                        unset($bookings);
                    } else {
                        // Send heartbeat to keep connection alive
                        $this->sendHeartbeat();
                    }

                    // Check if connection is still alive
                    if (connection_aborted()) {
                        break;
                    }

                    // Periodic memory cleanup (every 5 iterations)
                    $iterations++;
                    if ($iterations % 5 === 0) {
                        $this->cleanupMemory();
                    }

                    // Wait before next update
                    sleep(self::POLL_INTERVAL);
                }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
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
                // Initialize stream - clear all buffers to prevent memory buildup
                $this->initializeStream();

                $lastId = 0;
                $startTime = time();
                $iterations = 0;

                // Keep sending updates with timeout
                while (true) {
                    // Check timeout to prevent memory exhaustion
                    if ((time() - $startTime) >= self::MAX_STREAM_RUNTIME) {
                        $this->sendSSE(json_encode(['message' => 'Stream timeout, please reconnect']), 'timeout');
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

                            $this->sendSSE(json_encode($data), 'attraction_purchase', (string)$purchase->id);
                            $lastId = $purchase->id;
                        }

                        // Clear the collection to free memory
                        unset($purchases);
                    } else {
                        // Send heartbeat to keep connection alive
                        $this->sendHeartbeat();
                    }

                    // Check if connection is still alive
                    if (connection_aborted()) {
                        break;
                    }

                    // Periodic memory cleanup (every 5 iterations)
                    $iterations++;
                    if ($iterations % 5 === 0) {
                        $this->cleanupMemory();
                    }

                    // Wait before next update
                    sleep(self::POLL_INTERVAL);
                }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
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
                // Initialize stream - clear all buffers to prevent memory buildup
                $this->initializeStream();

                $lastBookingId = 0;
                $lastPurchaseId = 0;
                $startTime = time();
                $iterations = 0;

                // Keep sending updates with timeout
                while (true) {
                    // Check timeout to prevent memory exhaustion
                    if ((time() - $startTime) >= self::MAX_STREAM_RUNTIME) {
                        $this->sendSSE(json_encode(['message' => 'Stream timeout, please reconnect']), 'timeout');
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

                            $this->sendSSE(json_encode($data), 'notification', "booking_{$booking->id}");
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

                            $this->sendSSE(json_encode($data), 'notification', "purchase_{$purchase->id}");
                            $lastPurchaseId = $purchase->id;
                            $hasNewData = true;
                        }

                        // Clear the collection to free memory
                        unset($purchases);
                    }

                    // Send heartbeat if no new data
                    if (!$hasNewData) {
                        $this->sendHeartbeat();
                    }

                    // Check if connection is still alive
                    if (connection_aborted()) {
                        break;
                    }

                    // Periodic memory cleanup (every 5 iterations)
                    $iterations++;
                    if ($iterations % 5 === 0) {
                        $this->cleanupMemory();
                    }

                    // Wait before next update
                    sleep(self::POLL_INTERVAL);
                }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
            ]);
        }
    }
