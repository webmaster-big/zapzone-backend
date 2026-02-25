<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\BookingInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PartyInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public Booking $booking;
    public BookingInvitation $invitation;
    public array $variables;

    /**
     * Create a new message instance.
     */
    public function __construct(Booking $booking, BookingInvitation $invitation, array $variables)
    {
        $booking->loadMissing(['location.company', 'customer', 'package']);
        $this->booking = $booking;
        $this->invitation = $invitation;
        $this->variables = $variables;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $companyName = $this->variables['company_name'] ?: 'Zap Zone';
        $hostFirst = $this->variables['host_first_name'] ?? 'Someone';
        $packageName = $this->formatPackageName($this->variables['package_name'] ?? 'Party');

        // Short, personal subject — avoids spam triggers from "Party Invitation"
        // and long package names that look promotional
        $this->subject("{$hostFirst} invited you to a party at {$companyName}")
            ->view('emails.party-invitation')
            ->with([
                'guestName' => $this->variables['guest_first_name'],
                'hostName' => $this->variables['host_name'],
                'packageName' => $packageName,
                'bookingDate' => $this->variables['booking_date'],
                'bookingTime' => $this->variables['booking_time'],
                'locationName' => $this->variables['location_name'],
                'locationAddress' => $this->variables['location_address'],
                'locationPhone' => $this->variables['location_phone'],
                'rsvpUrl' => $this->variables['rsvp_url'],
                'companyName' => $companyName,
                'currentYear' => $this->variables['current_year'],
                'guestOfHonor' => $this->variables['guest_of_honor_name'],
                'guestOfHonorAge' => $this->variables['guest_of_honor_age'],
            ]);

        return $this;
    }

    /**
     * Convert ALL CAPS package names to Title Case.
     */
    protected function formatPackageName(string $name): string
    {
        // If the name is mostly uppercase, convert to title case
        if (mb_strtoupper($name) === $name) {
            return mb_convert_case(mb_strtolower($name), MB_CASE_TITLE, 'UTF-8');
        }

        return $name;
    }
}
