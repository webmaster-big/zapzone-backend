<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\WaiverTemplate;
use App\Services\WaiverService;

class PartyOrganizerLink
{
    public static function organizerEmail(Booking $booking): string
    {
        $booking->loadMissing('customer');

        return strtolower(trim((string) ($booking->customer?->email ?? $booking->guest_email ?? '')));
    }

    public static function token(Booking $booking): string
    {
        $email = self::organizerEmail($booking);

        if ($email === '' || empty($booking->id)) {
            return '';
        }

        return substr(hash_hmac('sha256', 'party-organizer|' . $booking->id . '|' . $email, (string) config('app.key')), 0, 40);
    }

    public static function url(Booking $booking): string
    {
        $link = rtrim((string) config('app.frontend_url', config('app.url', '')), '/') . '/customer/party/' . $booking->id;
        $token = self::token($booking);

        return $token !== '' ? $link . '?k=' . $token : $link;
    }

    public static function template(Booking $booking): ?WaiverTemplate
    {
        $template = app(WaiverService::class)->templateForBooking($booking);

        if ($template && ! $template->isEscapeRoom()) {
            return $template;
        }

        $booking->loadMissing('location');
        $companyId = $booking->location?->company_id;

        if (! $companyId) {
            return null;
        }

        $fallback = WaiverTemplate::resolveForActivity($companyId, $booking->location_id, $booking->package_id, []);

        return $fallback && ! $fallback->isEscapeRoom() ? $fallback : null;
    }

    public static function grants(mixed $given, mixed $customer, Booking $booking): bool
    {
        $expected = self::token($booking);

        return $customer instanceof Customer
            && is_string($given)
            && $given !== ''
            && $expected !== ''
            && hash_equals($expected, $given)
            && strcasecmp(trim((string) $customer->email), self::organizerEmail($booking)) === 0;
    }
}
