<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BookingCancellation extends Mailable
{
    use Queueable, SerializesModels;

    public Booking $booking;
    public Payment $payment;
    public float $refundAmount;
    public string $type; // 'refund' or 'void'

    /**
     * Create a new message instance.
     */
    public function __construct(Booking $booking, Payment $payment, float $refundAmount, string $type = 'refund')
    {
        $booking->loadMissing(['location.company', 'customer', 'package', 'room']);
        $this->booking = $booking;
        $this->payment = $payment;
        $this->refundAmount = $refundAmount;
        $this->type = $type;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $subject = $this->type === 'void'
            ? 'Booking Cancelled - Order #' . $this->booking->id
            : 'Booking Cancelled & Refund Processed - Order #' . $this->booking->id;

        return $this->subject($subject)
            ->view('emails.booking-cancellation')
            ->with([
                'booking' => $this->booking,
                'payment' => $this->payment,
                'refundAmount' => $this->refundAmount,
                'type' => $this->type,
                'customerName' => $this->booking->customer
                    ? $this->booking->customer->first_name . ' ' . $this->booking->customer->last_name
                    : $this->booking->guest_name,
                'packageName' => $this->booking->package?->name ?? 'N/A',
                'locationName' => $this->booking->location?->name ?? 'N/A',
            ]);
    }
}
