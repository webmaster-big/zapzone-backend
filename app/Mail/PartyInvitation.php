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
     * Logo is embedded as base64 data URI so it displays in the email body
     * regardless of spam status (no external URL fetch, no CID attachment).
     */
    public function build()
    {
        $packageName = $this->formatPackageName($this->variables['package_name'] ?? 'Party');

        $this->subject('Event Invitation - Zap Zone')
            ->view('emails.party-invitation')
            ->with([
                'booking' => $this->booking,
                'guestName' => $this->variables['guest_first_name'],
                'hostName' => $this->variables['host_name'],
                'packageName' => $packageName,
                'bookingDate' => $this->variables['booking_date'],
                'bookingTime' => $this->variables['booking_time'],
                'locationName' => $this->variables['location_name'],
                'locationAddress' => $this->variables['location_address'],
                'locationPhone' => $this->variables['location_phone'],
                'rsvpUrl' => $this->variables['rsvp_url'],
                'guestOfHonor' => $this->variables['guest_of_honor_name'],
                'guestOfHonorAge' => $this->variables['guest_of_honor_age'],
            ]);

        return $this;
    }

    /**
     * Read the company logo file and return as base64 data URI.
     * This embeds the image directly in the HTML — no external URL, no CID.
     */
    protected function buildLogoDataUri(): ?string
    {
        try {
            $company = $this->booking->location?->company;
            if (!$company || !$company->logo_path) {
                return null;
            }

            $logoPath = $company->logo_path;

            // If already a data URI, use as-is
            if (str_starts_with($logoPath, 'data:')) {
                return $logoPath;
            }

            // If it's a full URL, download it
            if (str_starts_with($logoPath, 'http://') || str_starts_with($logoPath, 'https://')) {
                $imageData = @file_get_contents($logoPath);
                if ($imageData) {
                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->buffer($imageData);
                    return 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
                }
                return null;
            }

            // Relative path — read from local storage first
            $storagePath = storage_path('app/public/' . $logoPath);
            if (file_exists($storagePath)) {
                $imageData = file_get_contents($storagePath);
                $mimeType = mime_content_type($storagePath);
                return 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
            }

            // Try via Storage facade
            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($logoPath)) {
                $imageData = \Illuminate\Support\Facades\Storage::disk('public')->get($logoPath);
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($imageData);
                return 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
            }

            // Fallback: fetch from production storage URL
            $publicUrl = 'https://zapzone-backend-yt1lm2w5.on-forge.com/storage/' . $logoPath;
            $context = stream_context_create([
                'http' => ['timeout' => 10, 'user_agent' => 'Mozilla/5.0'],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $imageData = @file_get_contents($publicUrl, false, $context);
            if ($imageData) {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($imageData);
                return 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
            }

            return null;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to build logo data URI', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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
