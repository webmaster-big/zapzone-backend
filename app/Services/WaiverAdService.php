<?php

namespace App\Services;

use App\Models\Waiver;
use App\Models\WaiverAdEvent;
use App\Models\WaiverAdSend;
use App\Models\WaiverTemplate;
use App\Models\WaiverTemplateAd;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class WaiverAdService
{
    public function selectForSubmission(WaiverTemplate $template, ?int $locationId, Waiver $waiver): ?array
    {
        if (!$template->ads_enabled) {
            return null;
        }

        try {
            $ad = $this->pickAd($template, $locationId);
            if (!$ad) {
                return null;
            }

            WaiverAdEvent::create([
                'waiver_template_ad_id' => $ad->id,
                'waiver_id' => $waiver->id,
                'company_id' => $template->company_id,
                'location_id' => $locationId,
                'event' => 'displayed',
            ]);

            return $this->presentForKiosk($ad, $template);
        } catch (\Throwable $e) {
            Log::warning('Waiver ad selection failed; skipping the ad screen', [
                'waiver_template_id' => $template->id,
                'waiver_id' => $waiver->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function pickAd(WaiverTemplate $template, ?int $locationId): ?WaiverTemplateAd
    {
        $candidates = WaiverTemplateAd::query()
            ->candidatesFor($template->id, $locationId)
            ->where('is_fallback', false)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($candidates->isNotEmpty()) {
            if ($template->ads_rotation_mode === 'ordered') {
                $template->increment('ads_rotation_counter');
                $counter = (int) $template->ads_rotation_counter;

                return $candidates[($counter - 1) % $candidates->count()];
            }

            return $candidates->random();
        }

        return WaiverTemplateAd::query()
            ->candidatesFor($template->id, $locationId)
            ->where('is_fallback', true)
            ->orderBy('position')
            ->orderBy('id')
            ->first();
    }

    protected function presentForKiosk(WaiverTemplateAd $ad, WaiverTemplate $template): array
    {
        return [
            'id' => $ad->id,
            'name' => $ad->name,
            'image_path' => $ad->image_path,
            'display_seconds' => max(1, min(10, (int) $template->ads_display_seconds)),
            'has_link' => !empty($ad->destination_url),
        ];
    }

    public function learnMore(Waiver $waiver, WaiverTemplateAd $ad, string $channel): array
    {
        $destination = $channel === WaiverAdSend::CHANNEL_EMAIL
            ? $waiver->adult_email
            : $waiver->adult_phone;

        if (!$destination) {
            return ['ok' => false, 'status' => 422, 'message' => $channel === WaiverAdSend::CHANNEL_EMAIL
                ? 'No email address is saved on this waiver.'
                : 'No phone number is saved on this waiver.'];
        }

        $send = $this->resolveSendRow($waiver, $ad, $channel, $destination);

        if ($send->status === WaiverAdSend::STATUS_SENT) {
            return ['ok' => true, 'status' => 200, 'message' => $this->confirmation($channel), 'already_sent' => true];
        }

        $claimed = WaiverAdSend::where('id', $send->id)
            ->where('status', '!=', WaiverAdSend::STATUS_SENT)
            ->where('attempts', $send->attempts)
            ->update([
                'status' => WaiverAdSend::STATUS_PENDING,
                'attempts' => $send->attempts + 1,
                'destination' => $destination,
                'error' => null,
            ]);

        if ($claimed === 0) {
            return ['ok' => true, 'status' => 200, 'message' => $this->confirmation($channel), 'already_sent' => true];
        }

        $send->refresh();

        $message = 'Here is the additional information you requested: ' . $ad->destination_url;

        try {
            if ($channel === WaiverAdSend::CHANNEL_EMAIL) {
                $this->sendEmail($waiver, $destination, $message);
            } else {
                app(SmsService::class)->sendSms($destination, $message);
            }

            $send->update(['status' => WaiverAdSend::STATUS_SENT, 'sent_at' => now()]);

            return ['ok' => true, 'status' => 200, 'message' => $this->confirmation($channel)];
        } catch (\Throwable $e) {
            $send->update([
                'status' => WaiverAdSend::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            Log::warning('Waiver ad Learn More send failed', [
                'waiver_id' => $waiver->id,
                'ad_id' => $ad->id,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'status' => 502, 'message' => $channel === WaiverAdSend::CHANNEL_EMAIL
                ? 'The email could not be sent. Please try again.'
                : 'The text could not be sent. Please try again or use email.'];
        }
    }

    protected function resolveSendRow(Waiver $waiver, WaiverTemplateAd $ad, string $channel, string $destination): WaiverAdSend
    {
        $attributes = [
            'waiver_id' => $waiver->id,
            'waiver_template_ad_id' => $ad->id,
            'channel' => $channel,
        ];

        try {
            return WaiverAdSend::firstOrCreate($attributes, [
                'company_id' => $waiver->company_id,
                'location_id' => $waiver->location_id,
                'destination' => $destination,
                'status' => WaiverAdSend::STATUS_PENDING,
                'attempts' => 0,
            ]);
        } catch (QueryException) {
            return WaiverAdSend::where($attributes)->firstOrFail();
        }
    }

    protected function confirmation(string $channel): string
    {
        return $channel === WaiverAdSend::CHANNEL_EMAIL
            ? 'Additional information sent by email.'
            : 'Additional information sent by text.';
    }

    protected function sendEmail(Waiver $waiver, string $to, string $message): void
    {
        $companyName = $waiver->company?->name ?: 'Zap Zone';
        $subject = 'The information you requested';
        $safeMessage = e($message);
        $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; font-size: 15px; line-height: 1.6;">
    <p>{$safeMessage}</p>
    <p style="color: #6b7280; font-size: 13px;">Sent by {$companyName} because you asked for more information at our waiver kiosk.</p>
</body>
</html>
HTML;

        $useGmailApi = config('gmail.enabled', false) &&
            (config('gmail.credentials.client_email') || file_exists(config('gmail.credentials_path', storage_path('app/gmail.json'))));

        if ($useGmailApi) {
            (new GmailApiService())->sendEmail($to, $subject, $htmlBody, $companyName);
        } else {
            Mail::html($htmlBody, function ($mail) use ($to, $subject, $companyName) {
                $mail->to($to)
                    ->subject($subject)
                    ->from(config('mail.from.address'), $companyName);
            });
        }
    }
}
