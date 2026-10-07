<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\AttractionPurchase;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StreamController extends Controller
    {
        private const NOTIFICATIONS_BATCH_LIMIT = 20;

        private function notificationCursor(string $lastSeen): array
        {
            $latestBookingId = (int) Booking::withTrashed()->max('id');
            $latestPurchaseId = (int) AttractionPurchase::withTrashed()->max('id');

            if (! preg_match('/^b(\d{1,18})\.p(\d{1,18})$/', $lastSeen, $cursor)) {
                return [$latestBookingId, $latestPurchaseId];
            }

            return [
                min($latestBookingId, max((int) $cursor[1], $latestBookingId - self::NOTIFICATIONS_BATCH_LIMIT)),
                min($latestPurchaseId, max((int) $cursor[2], $latestPurchaseId - self::NOTIFICATIONS_BATCH_LIMIT)),
            ];
        }

        private function visibleLocationIds(Request $request): array
        {
            $user = $request->user();

            $locations = Location::query();

            if ($user->company_id) {
                $locations->where('company_id', $user->company_id);
            }

            if (in_array($user->role, ['location_manager', 'attendant'], true)) {
                $locations->whereKey((int) $user->location_id);
            }

            if ($request->filled('location_id')) {
                $locations->whereKey((int) $request->query('location_id'));
            }

            return $locations->pluck('id')->all();
        }

        public function liveNotifications(Request $request): JsonResponse
        {
            $after = $request->query('after');
            [$lastBookingId, $lastPurchaseId] = $this->notificationCursor(is_string($after) ? $after : '');
            $locationIds = $this->visibleLocationIds($request);
            $items = [];

            $bookings = Booking::select([
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
                ->where('id', '>', $lastBookingId)
                ->whereIn('location_id', $locationIds)
                ->orderBy('id', 'asc')
                ->limit(self::NOTIFICATIONS_BATCH_LIMIT)
                ->get();

            foreach ($bookings as $booking) {
                $lastBookingId = $booking->id;

                $items[] = [
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
                    'location_id' => $booking->location_id,
                ];
            }

            $purchases = AttractionPurchase::select([
                    'id', 'attraction_id', 'customer_id', 'guest_name', 'quantity',
                    'total_amount', 'status', 'payment_method', 'purchase_date',
                    'created_at', 'created_by'
                ])
                ->with([
                    'customer:id,first_name,last_name',
                    'attraction:id,name,location_id',
                    'attraction.location:id,name'
                ])
                ->where('id', '>', $lastPurchaseId)
                ->whereHas('attraction', fn ($attraction) => $attraction->whereIn('location_id', $locationIds))
                ->orderBy('id', 'asc')
                ->limit(self::NOTIFICATIONS_BATCH_LIMIT)
                ->get();

            foreach ($purchases as $purchase) {
                $lastPurchaseId = $purchase->id;

                $items[] = [
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
                    'location_id' => $purchase->attraction->location_id ?? null,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'cursor' => "b{$lastBookingId}.p{$lastPurchaseId}",
                    'items' => $items,
                ],
            ]);
        }
    }
