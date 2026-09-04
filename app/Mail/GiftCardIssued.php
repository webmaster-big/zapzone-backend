<?php

namespace App\Mail;

use App\Models\GiftCard;
use App\Models\Location;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GiftCardIssued extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public GiftCard $giftCard, public ?Location $location = null)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Zap Zone gift card');
    }

    public function content(): Content
    {
        $card = $this->giftCard;
        $venue = $this->location?->name ?: 'any Zap Zone location';
        $value = number_format((float) $card->initial_value, 2);
        $expiry = $card->expiry_date
            ? 'Valid until ' . $card->expiry_date->format('F j, Y') . '.'
            : 'This card does not expire.';

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
  <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">
    <div style="background:#1e3a8a;padding:24px;text-align:center;color:#ffffff;">
      <div style="font-size:14px;letter-spacing:1px;text-transform:uppercase;opacity:.8;">Zap Zone Gift Card</div>
      <div style="font-size:34px;font-weight:bold;margin-top:6px;">\${$value}</div>
    </div>
    <div style="padding:24px;">
      <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">Here is your gift card. Show this code at the counter or enter it at checkout.</p>
      <div style="border:2px dashed #1e3a8a;border-radius:10px;padding:16px;text-align:center;margin-bottom:16px;">
        <div style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:1px;">Gift card code</div>
        <div style="font-size:24px;font-weight:bold;letter-spacing:2px;margin-top:6px;">{$card->code}</div>
      </div>
      <p style="margin:0 0 8px;font-size:14px;line-height:1.6;">Balance: <strong>\${$value}</strong></p>
      <p style="margin:0 0 8px;font-size:14px;line-height:1.6;">Redeemable at: <strong>{$venue}</strong></p>
      <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.6;">{$expiry} Treat this code like cash — anyone who has it can spend the balance.</p>
    </div>
  </div>
</body>
</html>
HTML;

        return new Content(htmlString: $html);
    }
}
