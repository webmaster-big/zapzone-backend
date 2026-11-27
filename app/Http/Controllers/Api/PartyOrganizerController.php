<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\WaiverBulkInvite;
use App\Models\WaiverInviteRecipient;
use App\Models\WaiverProfile;
use App\Support\PartyOrganizerLink;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class PartyOrganizerController extends Controller
{
    public const MAX_GUESTS_PER_ADD = 50;

    public const SMALLEST_GUEST_LIST = 50;

    public const GUESTS_PER_PARTICIPANT = 3;

    public const SENDS_PER_HOUR = 60;

    public function show(Request $request, Booking $booking): JsonResponse
    {
        if ($refusal = $this->refusal($request, $booking)) {
            return $refusal;
        }

        $invite = $this->partyGroup($booking);

        if (! $invite) {
            return $this->noWaiver();
        }

        $recipients = $invite->recipients()->orderBy('id')->get(['id', 'waiver_bulk_invite_id', 'name', 'email', 'phone', 'status', 'resent_count']);

        return response()->json([
            'success' => true,
            'data' => [
                'booking' => [
                    'id' => $booking->id,
                    'reference_number' => $booking->reference_number,
                    'package_name' => $booking->package?->name,
                    'location_name' => $booking->location?->name,
                    'booking_date' => Carbon::parse($booking->booking_date)->toDateString(),
                    'booking_time' => substr((string) ($booking->booking_time instanceof \DateTimeInterface ? $booking->booking_time->format('H:i') : $booking->booking_time), 0, 5),
                    'guest_of_honor_name' => $booking->guest_of_honor_name,
                    'cancelled' => $booking->status === 'cancelled',
                ],
                'guests' => $recipients->map(fn (WaiverInviteRecipient $recipient) => [
                    'id' => $recipient->id,
                    'name' => $recipient->name,
                    'invitation_status' => $this->invitationStatus($recipient->status),
                    'waiver_status' => $recipient->status === WaiverInviteRecipient::STATUS_COMPLETE ? 'Signed' : 'Not signed yet',
                    'signed' => $recipient->status === WaiverInviteRecipient::STATUS_COMPLETE,
                    'not_delivered' => $recipient->status === WaiverInviteRecipient::STATUS_FAILED,
                    'resent_count' => (int) $recipient->resent_count,
                ] + ($recipient->status === WaiverInviteRecipient::STATUS_COMPLETE ? [] : [
                    'email' => $recipient->email,
                    'phone' => $recipient->phone,
                ]))->values(),
                'summary' => [
                    'added' => $recipients->count(),
                    'signed' => $recipients->where('status', WaiverInviteRecipient::STATUS_COMPLETE)->count(),
                    'not_signed' => $recipients->where('status', '!=', WaiverInviteRecipient::STATUS_COMPLETE)->count(),
                    'not_delivered' => $recipients->where('status', WaiverInviteRecipient::STATUS_FAILED)->count(),
                ],
            ],
        ]);
    }

    public function addGuests(Request $request, Booking $booking): JsonResponse
    {
        if ($refusal = $this->refusal($request, $booking, true)) {
            return $refusal;
        }

        $rows = $request->input('recipients');

        if (is_array($rows) && count($rows) > self::MAX_GUESTS_PER_ADD) {
            return response()->json([
                'success' => false,
                'message' => 'You can add up to ' . self::MAX_GUESTS_PER_ADD . ' guests at a time. Add the rest in another batch.',
            ], 422);
        }

        $invite = $this->partyGroup($booking);

        if (! $invite) {
            return $this->noWaiver();
        }

        try {
            return Cache::lock('party-guests:' . $booking->getKey(), 120)->block(5, function () use ($request, $booking, $invite, $rows) {
                $limit = self::guestLimit($booking);
                $onList = $invite->recipients()->count();
                $incoming = $this->newGuestCount($invite, is_array($rows) ? $rows : []);

                if ($onList + $incoming > $limit) {
                    $room = max(0, $limit - $onList);

                    return response()->json([
                        'success' => false,
                        'message' => $room === 0
                            ? "This party's guest list is full ({$limit} people). Please call the venue if you need to invite more."
                            : "A party guest list can hold up to {$limit} people. This list has {$onList}, so you can add {$room} more.",
                    ], 422);
                }

                return app(WaiverPublicController::class)->bulkAddRecipients($request, $invite->manage_token);
            });
        } catch (LockTimeoutException) {
            return response()->json([
                'success' => false,
                'message' => 'Your last guests are still being added. Please wait a moment and try again.',
            ], 409);
        }
    }

    private function newGuestCount(WaiverBulkInvite $invite, array $rows): int
    {
        $existing = $invite->recipients()->get(['email', 'phone']);
        $knownEmails = $existing->pluck('email')->filter()->map(fn ($email) => strtolower(trim($email)))->all();
        $knownPhones = $existing->pluck('phone')->filter()->all();
        $count = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rawEmail = $row['email'] ?? null;
            $rawPhone = $row['phone'] ?? null;
            $email = is_scalar($rawEmail) && filled($rawEmail) ? strtolower(trim((string) $rawEmail)) : null;
            $phone = is_scalar($rawPhone) && filled($rawPhone) ? WaiverProfile::storedPhoneFor((string) $rawPhone) : null;
            $phone = $phone !== null && str_starts_with($phone, '+') ? null : $phone;

            if (! $email && ! $phone) {
                continue;
            }

            if ($email ? in_array($email, $knownEmails, true) : in_array($phone, $knownPhones, true)) {
                continue;
            }

            if ($email) {
                $knownEmails[] = $email;
            }

            if ($phone) {
                $knownPhones[] = $phone;
            }

            $count++;
        }

        return $count;
    }

    private function sendLimitReached(Booking $booking): ?JsonResponse
    {
        $key = 'party-send:booking:' . $booking->getKey();

        if (RateLimiter::tooManyAttempts($key, self::SENDS_PER_HOUR)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            return response()->json([
                'success' => false,
                'message' => "This party has sent a lot of invites in the last hour. Please try again in {$minutes} " . ($minutes === 1 ? 'minute' : 'minutes') . '.',
            ], 429);
        }

        RateLimiter::hit($key, 3600);

        return null;
    }

    public static function guestLimit(Booking $booking): int
    {
        return max(self::SMALLEST_GUEST_LIST, self::GUESTS_PER_PARTICIPANT * (int) $booking->participants);
    }

    public function resend(Request $request, Booking $booking, int $recipientId): JsonResponse
    {
        if ($refusal = $this->refusal($request, $booking, true)) {
            return $refusal;
        }

        if ($held = $this->sendLimitReached($booking)) {
            return $held;
        }

        $invite = $this->partyGroup($booking);

        return $invite
            ? app(WaiverPublicController::class)->bulkResendRecipient($invite->manage_token, $recipientId)
            : $this->noWaiver();
    }

    public function update(Request $request, Booking $booking, int $recipientId): JsonResponse
    {
        if ($refusal = $this->refusal($request, $booking, true)) {
            return $refusal;
        }

        if ($held = $this->sendLimitReached($booking)) {
            return $held;
        }

        $invite = $this->partyGroup($booking);

        return $invite
            ? app(WaiverPublicController::class)->bulkUpdateRecipient($request, $invite->manage_token, $recipientId)
            : $this->noWaiver();
    }

    public static function customerOwnsBooking(mixed $customer, Booking $booking): bool
    {
        if (! $customer instanceof Customer) {
            return false;
        }

        if ($booking->customer_id && (int) $booking->customer_id === (int) $customer->id) {
            return true;
        }

        return $customer->email_verified_at !== null
            && $booking->guest_email
            && strcasecmp(trim((string) $booking->guest_email), trim((string) $customer->email)) === 0;
    }

    private function refusal(Request $request, Booking $booking, bool $changing = false): ?JsonResponse
    {
        $customer = $request->user();

        if (! self::customerOwnsBooking($customer, $booking) && ! PartyOrganizerLink::grants($request->query('k'), $customer, $booking)) {
            return response()->json([
                'success' => false,
                'message' => 'This party belongs to a different account. Open the link in your booking confirmation email and sign in with the email address it was sent to.',
            ], 403);
        }

        $booking->loadMissing(['package', 'location']);

        if (! $booking->package?->party_invites_enabled) {
            return response()->json([
                'success' => false,
                'message' => 'Party invitations and waivers are not offered for this booking.',
            ], 404);
        }

        if ($changing && $booking->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'This booking was cancelled, so no more invitations can be sent.',
            ], 409);
        }

        return null;
    }

    private function partyGroup(Booking $booking): ?WaiverBulkInvite
    {
        $existing = WaiverBulkInvite::where('booking_id', $booking->id)->orderBy('id')->first();

        if ($existing) {
            return $existing;
        }

        return Cache::lock('party-group:' . $booking->id, 10)->block(5, function () use ($booking) {
            $existing = WaiverBulkInvite::where('booking_id', $booking->id)->orderBy('id')->first();

            if ($existing) {
                return $existing;
            }

            $template = PartyOrganizerLink::template($booking);

            if (! $template) {
                return null;
            }

            $customer = $booking->customer;

            return WaiverBulkInvite::create([
                'company_id' => $template->company_id,
                'location_id' => $booking->location_id,
                'booking_id' => $booking->id,
                'waiver_template_id' => $template->id,
                'selected_date' => Carbon::parse($booking->booking_date)->toDateString(),
                'chaperone_name' => trim((string) ($customer ? "{$customer->first_name} {$customer->last_name}" : $booking->guest_name)) ?: 'Party organizer',
                'chaperone_email' => $customer?->email ?? $booking->guest_email,
                'chaperone_phone' => $customer?->phone ?? $booking->guest_phone,
                'allow_shareable_link' => false,
                'status' => WaiverBulkInvite::STATUS_SENT,
                'created_by' => null,
            ]);
        });
    }

    private function invitationStatus(?string $status): string
    {
        return match ($status) {
            WaiverInviteRecipient::STATUS_NOT_SENT => 'Not sent yet',
            WaiverInviteRecipient::STATUS_FAILED => 'Not delivered',
            default => 'Invite sent',
        };
    }

    private function noWaiver(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'There is no waiver set up for this party yet. Please call the venue.',
        ], 409);
    }
}
