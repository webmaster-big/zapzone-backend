<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StaffBookingNotification extends Mailable
{
    use Queueable, SerializesModels;

    public Booking $booking;
    public string $recipientName;
    public string $recipientRole;

    /**
     * Create a new message instance.
     */
    public function __construct(Booking $booking, string $recipientName = 'Team Member', string $recipientRole = 'staff')
    {
        // Ensure all relationships are loaded
        $booking->loadMissing(['location.company', 'customer', 'package', 'room', 'attractions', 'addOns']);
        $this->booking = $booking;
        $this->recipientName = $recipientName;
        $this->recipientRole = $recipientRole;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $customerName = $this->booking->customer
            ? $this->booking->customer->first_name . ' ' . $this->booking->customer->last_name
            : $this->booking->guest_name;

        $this->subject('🎉 New Booking Alert - ' . $this->booking->reference_number)
            ->view('emails.staff-booking-notification')
            ->with([
                'booking' => $this->booking,
                'customerName' => $customerName,
                'customerEmail' => $this->booking->customer?->email ?? $this->booking->guest_email,
                'customerPhone' => $this->booking->customer?->phone ?? $this->booking->guest_phone,
                'packageName' => $this->booking->package?->name ?? 'N/A',
                'locationName' => $this->booking->location?->name ?? 'N/A',
                'roomName' => $this->booking->room?->name ?? 'Not assigned',
                'recipientName' => $this->recipientName,
                'recipientRole' => $this->recipientRole,
            ]);

        return $this;
    }
}
