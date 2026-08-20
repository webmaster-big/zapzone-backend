<?php

namespace App\Mail;

use App\Models\TicketOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TicketOrderReceipt extends Mailable
{
    use Queueable, SerializesModels;

    public TicketOrder $order;

    public array $lines;

    public ?string $qrCodeBase64;

    public function __construct(TicketOrder $order, ?string $qrCodeBase64 = null)
    {
        $order->loadMissing([
            'location.company',
            'customer',
            'attractionPurchases.attraction',
            'attractionPurchases.addOns',
            'eventPurchases.event',
            'eventPurchases.addOns',
        ]);

        $this->order = $order;
        $this->qrCodeBase64 = $qrCodeBase64;

        $this->lines = $order->lines()->map(function (array $line) {
            $model = $line['model'];

            return [
                'type' => $line['type'],
                'position' => (int) $model->line_position,
                'name' => $line['type'] === 'attraction'
                    ? ($model->attraction?->name ?? 'Attraction')
                    : ($model->event?->name ?? 'Event'),
                'quantity' => (int) $model->quantity,
                'total_amount' => (float) $model->total_amount,
                'discount_amount' => (float) ($model->discount_amount ?? 0),
                'applied_discounts' => $model->applied_discounts ?? [],
                'applied_fees' => $model->applied_fees ?? [],
                'scheduled_date' => $line['type'] === 'attraction'
                    ? $model->scheduled_date?->format('D j M Y')
                    : $model->purchase_date?->format('D j M Y'),
                'scheduled_time' => $line['type'] === 'attraction'
                    ? $model->scheduled_time?->format('g:i A')
                    : $model->purchase_time?->format('g:i A'),
                'reference_number' => $line['type'] === 'event' ? $model->reference_number : null,
                'add_ons' => $model->addOns->map(fn ($addOn) => [
                    'name' => $addOn->name,
                    'quantity' => (int) $addOn->pivot->quantity,
                    'price' => (float) $addOn->pivot->price_at_purchase,
                ])->all(),
            ];
        })->all();
    }

    public function build()
    {
        $this->subject('Your ZapZone order ' . $this->order->reference_number)
            ->view('emails.ticket-order-receipt')
            ->with([
                'order' => $this->order,
                'lines' => $this->lines,
                'qrCodeBase64' => $this->qrCodeBase64,
            ]);

        if ($this->qrCodeBase64) {
            $image = base64_decode($this->qrCodeBase64, true);

            if ($image !== false) {
                $this->attachData($image, 'order-qrcode.png', ['mime' => 'image/png']);
            }
        }

        return $this;
    }
}
