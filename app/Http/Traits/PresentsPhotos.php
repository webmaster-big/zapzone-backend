<?php

namespace App\Http\Traits;

use App\Http\Controllers\Api\PhotoMediaController;
use App\Models\Photo;
use App\Models\PhotoDelivery;
use App\Models\PhotoSession;
use App\Support\OperatingDay;

trait PresentsPhotos
{
    /**
     * Media is on a private disk, so callers get a short-lived signed URL rather than a
     * permanent public path. Never hand out Storage::url() for photo media.
     */
    protected function photoUrl(Photo $photo, string $variant): ?string
    {
        return PhotoMediaController::signedUrl($photo, $variant);
    }

    protected function presentPhoto(Photo $photo): array
    {
        return [
            'id' => $photo->id,
            'photo_session_id' => $photo->photo_session_id,
            'position' => $photo->position,
            'source' => $photo->source,
            'processing_status' => $photo->processing_status,
            'processing_error' => $photo->processing_error,
            'delivery_url' => $this->photoUrl($photo, 'delivery'),
            'slideshow_url' => $this->photoUrl($photo, 'slideshow'),
            'thumbnail_url' => $this->photoUrl($photo, 'thumb'),
            'width' => $photo->width,
            'height' => $photo->height,
            'bytes' => $photo->bytes,
            'captured_at' => $photo->captured_at?->toIso8601String(),
            'capture_date' => $photo->capture_date?->toDateString(),
            'operating_day' => $photo->operating_day?->toDateString(),
            'slideshow_eligible' => $photo->slideshow_eligible,
            'slideshow_state' => $photo->slideshow_state,
            'slideshow_priority' => $photo->slideshow_priority,
            'download_count' => $photo->download_count,
            'purged' => $photo->purged_at !== null,
            'overlay' => $photo->relationLoaded('overlay') && $photo->overlay
                ? ['id' => $photo->overlay->id, 'name' => $photo->overlay->name]
                : null,
        ];
    }

    protected function presentDelivery(PhotoDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'photo_session_id' => $delivery->photo_session_id,
            'waiver_id' => $delivery->waiver_id,
            'kind' => $delivery->kind,
            'channel' => $delivery->channel,
            'destination_masked' => $delivery->maskedDestination(),
            'recipient_name' => $delivery->recipient_name,
            'status' => $delivery->status,
            'is_duplicate' => $delivery->isDuplicate(),
            'duplicate_of_id' => $delivery->duplicate_of_id,
            'scheduled_for' => $delivery->scheduled_for?->toIso8601String(),
            'sent_at' => $delivery->sent_at?->toIso8601String(),
            'opened_at' => $delivery->opened_at?->toIso8601String(),
            'attempts' => $delivery->attempts,
            'error' => $delivery->error,
            'can_retry' => $delivery->canRetry(),
            'can_cancel' => $delivery->canCancel(),
            'created_at' => $delivery->created_at?->toIso8601String(),
        ];
    }

    protected function presentSession(PhotoSession $session, bool $includeContact = false): array
    {
        $session->loadMissing('location', 'photos.overlay', 'deliveries', 'waivers', 'creator');
        $tz = OperatingDay::timezoneFor($session->location);

        $payload = [
            'id' => $session->id,
            'source' => $session->source,
            'status' => $session->status,
            'location_id' => $session->location_id,
            'location_name' => $session->location?->name,
            'timezone' => $tz,
            'delivery_method' => $session->delivery_method,
            'delivery_schedule' => $session->delivery_schedule,
            'slideshow_opt_in' => $session->slideshow_opt_in,
            'verbal_consent_at' => $session->verbal_consent_at?->toIso8601String(),
            'photo_count' => $session->photos->count(),
            'max_photos' => $session->maxPhotos(),
            'photos' => $session->photos->map(fn ($photo) => $this->presentPhoto($photo))->values(),
            'qr_status' => $session->qrStatus(),
            'qr_expires_at' => $session->qr_expires_at?->toIso8601String(),
            'qr_target_url' => $this->qrTargetUrl($session),
            'qr_scan_count' => $session->qr_scan_count,
            'access_status' => $session->accessStatus(),
            'access_expires_at' => $session->access_expires_at?->toIso8601String(),
            'photo_link' => $this->sessionPhotoLink($session),
            'delivery_status' => $session->deliveryStatus(),
            'deliveries' => $session->deliveries->map(fn ($d) => $this->presentDelivery($d))->values(),
            'waivers' => $session->waivers->map(fn ($waiver) => [
                'id' => $waiver->id,
                'name' => trim(($waiver->adult_first_name ?? '') . ' ' . ($waiver->adult_last_name ?? '')),
            ])->values(),
            'captured_at' => $session->captured_at?->toIso8601String(),
            'capture_date' => $session->capture_date?->toDateString(),
            'operating_day' => $session->operating_day?->toDateString(),
            'created_by_name' => $session->creator
                ? trim(($session->creator->first_name ?? '') . ' ' . ($session->creator->last_name ?? ''))
                : null,
            'created_at' => $session->created_at?->toIso8601String(),
        ];

        if ($includeContact) {
            $payload['kiosk_contact'] = [
                'name' => $session->kiosk_contact_name,
                'email' => $session->kiosk_contact_email,
                'phone' => $session->kiosk_contact_phone,
                'marketing_consent' => $session->kiosk_marketing_consent,
                'submitted_at' => $session->kiosk_contact_at?->toIso8601String(),
            ];
        }

        return $payload;
    }

    protected function qrTargetUrl(PhotoSession $session): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/photos/qr/' . $session->qr_token;
    }

    protected function sessionPhotoLink(PhotoSession $session): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/photos/' . $session->access_token;
    }
}
