<?php

namespace App\Console\Commands;

use App\Models\PhotoDelivery;
use App\Services\PhotoDeliveryService;
use App\Support\OperatingDay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendScheduledPhotoDeliveries extends Command
{
    protected $signature = 'photos:send-scheduled';

    protected $description = 'Send photo deliveries scheduled for 9:00 AM the next day in each location time zone, and give recent failures another attempt';

    public function handle(PhotoDeliveryService $deliveries): int
    {
        $due = PhotoDelivery::due()->real()->with('session.location')->limit(500)->get();
        $retrying = PhotoDelivery::retryable()->real()->with('session.location')->limit(200)->get();

        $this->info("Found {$due->count()} due photo delivery/deliveries and {$retrying->count()} awaiting another attempt.");

        $sent = 0;
        $failed = 0;
        $retried = 0;

        $heldOvernight = 0;

        foreach ($due->concat($retrying) as $delivery) {
            $isRetry = $delivery->status === PhotoDelivery::STATUS_FAILED;

            // A repeat attempt waits for daytime at that venue. A first attempt goes out when
            // it was asked to, but nobody should be woken at 1am by a second try at a photo
            // link. The row keeps its failed status and is picked up again in the morning.
            if ($isRetry && $this->isQuietHourAt($delivery)) {
                $heldOvernight++;
                continue;
            }

            try {
                if ($deliveries->send($delivery)) {
                    $sent++;
                    if ($isRetry) {
                        $retried++;
                    }
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Scheduled photo delivery threw', [
                    'delivery_id' => $delivery->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($due->pluck('session')->filter()->unique('id') as $session) {
            if ($session->delivered_at === null) {
                $session->forceFill(['delivered_at' => now()])->save();
            }
        }

        $this->info("Scheduled photo deliveries complete: {$sent} sent ({$retried} of them on a repeat attempt), {$failed} failed, {$heldOvernight} held until daytime.");
        Log::info('Scheduled photo deliveries processed', [
            'sent' => $sent,
            'retried' => $retried,
            'failed' => $failed,
            'held_overnight' => $heldOvernight,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Between 9pm and 8am at the venue, so a repeat attempt never texts a family overnight.
     */
    protected function isQuietHourAt(PhotoDelivery $delivery): bool
    {
        $hour = OperatingDay::localNow($delivery->session?->location)->hour;

        return $hour >= 21 || $hour < 8;
    }
}
