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
        $packageName = $this->formatPackageName($this->variables['package_name'] ?? 'Party');

        // Get company through booking -> location -> company (same chain as shareable-token)
        $company = $this->booking->location?->company;

        // Compute logo URL (same logic as shareable-token template)
        $logoUrl = null;
        if ($company && $company->logo_path) {
            $logoUrl = $company->logo_path;
            if (!str_starts_with($logoUrl, 'http://') && !str_starts_with($logoUrl, 'https://') && !str_starts_with($logoUrl, 'data:')) {
                $logoUrl = 'https://zapzone-backend-yt1lm2w5.on-forge.com/storage/' . $logoUrl;
            }
        }

        $this->subject('Event Invitation - Zap Zone')
            ->view('emails.party-invitation')
            ->with([
                'company' => $company,
                'logoUrl' => $logoUrl,
                'guestName' => $this->variables['guest_first_name'],
                'hostName' => $this->variables['host_name'],
                'packageName' => $packageName,
                'bookingDate' => $this->variables['booking_date'],
                'bookingTime' => $this->variables['booking_time'],
                'locationName' => $this->variables['location_name'],
                'locationAddress' => $this->variables['location_address'],
                'locationPhone' => $this->variables['location_phone'],
                'rsvpUrl' => $this->variables['rsvp_url'],
                'companyName' => $company?->company_name ?: 'Zap Zone',
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
