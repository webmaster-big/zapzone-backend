<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\AttractionPurchase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StreamController extends Controller
    {
        private const NOTIFICATIONS_RECONNECT_AFTER_MS = 20000;

        private const NOTIFICATIONS_BATCH_LIMIT = 20;

        private function sendSSE(string $data, ?string $event = null, ?string $id = null): void
        {
            if ($id) {
                echo "id: {$id}\n";
            }
            if ($event) {
                echo "event: {$event}\n";
            }
            echo "data: {$data}\n\n";

            $this->flushOutput();
        }

        private function flushOutput(): void
        {
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }

        private function notificationCursor(string $lastEventId): array
        {
            $latestBookingId = (int) Booking::withTrashed()->max('id');
            $latestPurchaseId = (int) AttractionPurchase::withTrashed()->max('id');

            if (! preg_match('/^b(\d{1,18})\.p(\d{1,18})$/', $lastEventId, $cursor)) {
                return [$latestBookingId, $latestPurchaseId];
            }

            return [
                min($latestBookingId, max((int) $cursor[1], $latestBookingId - self::NOTIFICATIONS_BATCH_LIMIT)),
                min($latestPurchaseId, max((int) $cursor[2], $latestPurchaseId - self::NOTIFICATIONS_BATCH_LIMIT)),
            ];
        }

        public function combinedNotifications(Request $request)
        {
            $locationId = $request->query('location_id');
            $userId = $request->query('user_id'); // Filter out user's own notifications
            $lastEventId = (string) $request->header('Last-Event-ID', '');

            return response()->stream(function () use ($locationId, $userId, $lastEventId) {
                echo 'retry: ' . self::NOTIFICATIONS_RECONNECT_AFTER_MS . "\n\n";
                $this->flushOutput();

                try {
                    [$lastBookingId, $lastPurchaseId] = $this->notificationCursor($lastEventId);

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

                    $bookings = $bookingQuery->orderBy('id', 'asc')->limit(self::NOTIFICATIONS_BATCH_LIMIT)->get();

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

                    $purchases = $purchaseQuery->orderBy('id', 'asc')->limit(self::NOTIFICATIONS_BATCH_LIMIT)->get();
                } catch (\Throwable $e) {
                    Log::warning('Notification feed poll failed', ['error' => $e->getMessage()]);

                    return;
                }

                foreach ($bookings as $booking) {
                    $lastBookingId = $booking->id;

                    $this->sendSSE(json_encode([
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
                        'created_at' => $booking->created_at?->toIso8601String(),
                        'timestamp' => now()->toIso8601String(),
                        'user_id' => $booking->created_by,
                    ]), 'notification', "b{$lastBookingId}.p{$lastPurchaseId}");
                }

                foreach ($purchases as $purchase) {
                    $lastPurchaseId = $purchase->id;

                    $this->sendSSE(json_encode([
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
                        'created_at' => $purchase->created_at?->toIso8601String(),
                        'timestamp' => now()->toIso8601String(),
                        'user_id' => $purchase->created_by,
                    ]), 'notification', "b{$lastBookingId}.p{$lastPurchaseId}");
                }

                echo "id: b{$lastBookingId}.p{$lastPurchaseId}\n\n";
                $this->flushOutput();
            }, 200, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'X-Accel-Buffering' => 'no',
                'Connection' => 'keep-alive',
            ]);
        }
    }
