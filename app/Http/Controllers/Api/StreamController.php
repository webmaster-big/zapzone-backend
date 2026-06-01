<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\AttractionPurchase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StreamController extends Controller
    {
        private const MAX_STREAM_RUNTIME = 60;

        private const POLL_INTERVAL = 3;

        private function initializeStream(): void
        {
            set_time_limit(0);

            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            ob_implicit_flush(true);

            DB::connection()->disableQueryLog();
        }

        private function sendSSE(string $data, ?string $event = null, ?string $id = null): void
        {
            if ($id) {
                echo "id: {$id}\n";
            }
            if ($event) {
                echo "event: {$event}\n";
            }
            echo "data: {$data}\n\n";

            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }

        private function sendHeartbeat(): void
        {
            echo ": heartbeat\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }

        private function cleanupMemory(): void
        {
            gc_collect_cycles();

            if (DB::connection()->logging()) {
                DB::connection()->flushQueryLog();
            }
        }

        public function bookingNotifications(Request $request)
        {
            $locationId = $request->query('location_id');
            $userId = $request->query('user_id'); // Filter out user's own notifications

            return response()->stream(function () use ($locationId, $userId) {
                $this->initializeStream();

                $lastId = 0;
                $startTime = time();
                $iterations = 0;

                while (true) {
                    if ((time() - $startTime) >= self::MAX_STREAM_RUNTIME) {
                        $this->sendSSE(json_encode(['message' => 'Stream timeout, please reconnect']), 'timeout');
                        break;
                    }

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

                    if ($locationId) {
                        $query->where('location_id', $locationId);
                    }

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

                        unset($bookings);
                    } else {
                        $this->sendHeartbeat();
                    }

                    if (connection_aborted()) {
                        break;
                    }

                    $iterations++;
                    if ($iterations % 5 === 0) {
                        $this->cleanupMemory();
                    }

                    sleep(self::POLL_INTERVAL);
                }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
            ]);
        }

        public function attractionPurchaseNotifications(Request $request)
        {
            $locationId = $request->query('location_id');
            $userId = $request->query('user_id'); // Filter out user's own notifications

            return response()->stream(function () use ($locationId, $userId) {
                $this->initializeStream();

                $lastId = 0;
                $startTime = time();
                $iterations = 0;

                while (true) {
                    if ((time() - $startTime) >= self::MAX_STREAM_RUNTIME) {
                        $this->sendSSE(json_encode(['message' => 'Stream timeout, please reconnect']), 'timeout');
                        break;
                    }

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

                    if ($locationId) {
                        $query->whereHas('attraction', function ($q) use ($locationId) {
                            $q->where('location_id', $locationId);
                        });
                    }

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

                        unset($purchases);
                    } else {
                        $this->sendHeartbeat();
                    }

                    if (connection_aborted()) {
                        break;
                    }

                    $iterations++;
                    if ($iterations % 5 === 0) {
                        $this->cleanupMemory();
                    }

                    sleep(self::POLL_INTERVAL);
                }
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
            ]);
        }

        public function combinedNotifications(Request $request)
        {
            $locationId = $request->query('location_id');
            $userId = $request->query('user_id'); // Filter out user's own notifications

            return response()->stream(function () use ($locationId, $userId) {
                $this->initializeStream();

                $lastBookingId = 0;
                $lastPurchaseId = 0;
                $startTime = time();
                $iterations = 0;

                while (true) {
                    if ((time() - $startTime) >= self::MAX_STREAM_RUNTIME) {
                        $this->sendSSE(json_encode(['message' => 'Stream timeout, please reconnect']), 'timeout');
                        break;
                    }

                    $hasNewData = false;

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

                        unset($bookings);
                    }

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

                        unset($purchases);
                    }

                    if (!$hasNewData) {
                        $this->sendHeartbeat();
                    }

                    if (connection_aborted()) {
                        break;
                    }

                    $iterations++;
                    if ($iterations % 5 === 0) {
                        $this->cleanupMemory();
                    }

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
