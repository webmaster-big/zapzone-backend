<?php

namespace App\Console\Commands;

use App\Models\PhotoDelivery;
use App\Services\PhotoDeliveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendScheduledPhotoDeliveries extends Command
{
    protected $signature = 'photos:send-scheduled';

    protected $description = 'Send photo deliveries scheduled for 9:00 AM the next day in each location time zone';

    public function handle(PhotoDeliveryService $deliveries): int
    {
        $due = PhotoDelivery::due()->real()->with('session.location')->limit(500)->get();

        $this->info("Found {$due->count()} due photo delivery/deliveries.");

        $sent = 0;
        $failed = 0;

        foreach ($due as $delivery) {
            try {
                if ($deliveries->send($delivery)) {
                    $sent++;
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

        $this->info("Scheduled photo deliveries complete: {$sent} sent, {$failed} failed.");
        Log::info('Scheduled photo deliveries processed', ['sent' => $sent, 'failed' => $failed]);

        return Command::SUCCESS;
    }
}
