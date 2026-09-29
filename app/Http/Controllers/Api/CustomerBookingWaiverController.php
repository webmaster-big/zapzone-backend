<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\EscapeRoomSession;
use App\Models\PhotoSession;
use App\Models\Waiver;
use App\Services\EscapeRoomSessionService;
use App\Services\PhotoDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class CustomerBookingWaiverController extends Controller
{
    public const BOOKER_SOURCES = [
        Waiver::SOURCE_CONFIRMATION_EMAIL,
        Waiver::SOURCE_CHECKOUT,
        Waiver::SOURCE_SMS_LINK,
    ];

    public function __construct(
        protected EscapeRoomSessionService $escapeRooms,
        protected PhotoDeliveryService $deliveries
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $customer = Auth::guard('sanctum')->user();

        if (!$customer instanceof Customer) {
            return response()->json([
                'success' => false,
                'message' => 'Sign in to your customer account to see your waivers.',
            ], 403);
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer'],
        ]);

        $email = strtolower(trim((string) $customer->email));

        $bookings = Booking::with(['package', 'location'])
            ->whereIn('id', array_values(array_unique(array_map('intval', $validated['ids']))))
            ->where(function ($query) use ($customer, $email) {
                $query->where('customer_id', $customer->id);

                if ($email !== '') {
                    $query->orWhere('guest_email', $email);
                }
            })
            ->get();

        $bookerWaivers = $this->bookerWaivers($bookings);

        $rows = $bookings->map(fn (Booking $booking) => [
            'booking_id' => $booking->id,
            'waiver' => $this->presentWaiver($booking, $bookerWaivers->get($booking->id)),
            'escape_room' => $this->presentGame($booking, $bookerWaivers->get($booking->id)),
        ])->values();

        return response()->json([
            'success' => true,
            'data' => ['bookings' => $rows],
        ]);
    }

    protected function bookerWaivers(Collection $bookings): Collection
    {
        if ($bookings->isEmpty()) {
            return collect();
        }

        return Waiver::withoutHeavyColumns()
            ->whereIn('booking_id', $bookings->pluck('id'))
            ->exceptEscapeRoomSignIns()
            ->whereNull('bulk_invite_id')
            ->where('is_manager_assigned', false)
            ->whereIn('source', self::BOOKER_SOURCES)
            ->whereNotIn('status', [Waiver::STATUS_REPLACED, Waiver::STATUS_DELETED])
            ->orderBy('id')
            ->get()
            ->groupBy('booking_id')
            ->map(fn (Collection $waivers) => $waivers->first());
    }

    protected function upcoming(Booking $booking): bool
    {
        if ($booking->status === 'cancelled' || !$booking->booking_date || !$booking->location) {
            return false;
        }

        return $booking->booking_date->toDateString() >= $this->escapeRooms->today($booking->location);
    }

    protected function presentWaiver(Booking $booking, ?Waiver $waiver): ?array
    {
        if (!$waiver) {
            return null;
        }

        $pending = $waiver->status === Waiver::STATUS_PENDING;

        return [
            'status' => $waiver->status,
            'signing_url' => $pending && $this->upcoming($booking) ? $waiver->signing_url : null,
            'signed_at' => $waiver->submitted_at?->toIso8601String(),
        ];
    }

    protected function presentGame(Booking $booking, ?Waiver $bookerWaiver): ?array
    {
        if ($booking->status === 'cancelled') {
            return null;
        }

        $summary = $this->escapeRooms->bookingGameSummary($booking);

        if (!$summary) {
            return null;
        }

        $session = $summary['session_id'] ? EscapeRoomSession::find($summary['session_id']) : null;
        $photoSession = $session?->photo_session_id ? PhotoSession::whereKey($session->photo_session_id)->first() : null;
        $bookerWasSent = $bookerWaiver && $photoSession
            && in_array((int) $bookerWaiver->id, $this->escapeRooms->sentWaiverIds($photoSession), true);
        $photoAvailable = $summary['completed']
            && $photoSession
            && $photoSession->purged_at === null
            && $photoSession->accessIsActive()
            && $photoSession->photos()->ready()->exists();
        $canCheckIn = $summary['has_waiver'] && !$summary['completed'] && $this->upcoming($booking);

        return [
            'room_name' => $summary['room_name'],
            'date' => $summary['date'],
            'time_label' => $summary['time_label'],
            'check_in_link' => $canCheckIn ? $summary['kiosk_url'] : null,
            'players_signed' => $summary['shared_time'] ? null : $summary['players_signed'],
            'people_covered' => $summary['shared_time'] ? null : $summary['people_covered'],
            'players_booked' => $summary['players_booked'],
            'completed' => $summary['completed'],
            'completed_without_photo' => $summary['completed_without_photo'],
            'completion_label' => $summary['completion_label'],
            'photo_sent' => $summary['sent'] > 0,
            'players_sent' => $summary['sent'],
            'photo_link' => $photoAvailable && $bookerWasSent ? $this->deliveries->photoLink($photoSession) : null,
        ];
    }
}
