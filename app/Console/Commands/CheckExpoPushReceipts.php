<?php

namespace App\Console\Commands;

use App\Models\MobilePushDevice;
use App\Models\MobilePushNotificationLog;
use App\Services\ExpoPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckExpoPushReceipts extends Command
{
    protected $signature = 'push:check-receipts';

    protected $description = 'Ask Expo what became of recently sent pushes and switch off the devices it reports as gone';

    // Expo clears receipts after 24 hours
    private const LOOKBACK_HOURS = 24;

    // Five Expo requests per run, far more than a day of venue traffic produces
    private const MAX_LOGS_PER_RUN = 5000;

    public function handle(ExpoPushService $expo): int
    {
        if (!ExpoPushService::isConfigured()) {
            $this->info('Expo push is switched off, so there are no receipts to check.');

            return self::SUCCESS;
        }

        $logs = MobilePushNotificationLog::query()
            ->awaitingReceipt()
            ->where('sent_at', '>=', now()->subHours(self::LOOKBACK_HOURS))
            ->orderBy('id')
            ->limit(self::MAX_LOGS_PER_RUN)
            ->get();

        if ($logs->isEmpty()) {
            $this->info('No push receipts are waiting to be checked.');

            return self::SUCCESS;
        }

        $receipts = $expo->receipts($logs->pluck('ticket_id')->all());

        $delivered = 0;
        $errors = 0;
        $stillWaiting = 0;
        $deadDeviceIds = [];

        foreach ($logs as $log) {
            $receipt = $receipts[$log->ticket_id] ?? null;

            // No answer yet, or the lookup itself failed. Left untouched so the next
            // run asks again: a missing receipt never means a dead device.
            if ($receipt === null) {
                $stillWaiting++;
                continue;
            }

            if (($receipt['status'] ?? null) === 'ok') {
                $log->markReceiptOk();
                $delivered++;
                continue;
            }

            $code = (string) ($receipt['error_code'] ?? 'ExpoError');

            $log->markReceiptFailed($code, $receipt['error_message'] ?? null);
            $errors++;

            if ($code === ExpoPushService::ERROR_DEVICE_NOT_REGISTERED && $log->mobile_push_device_id) {
                $deadDeviceIds[$log->mobile_push_device_id] = true;
            }
        }

        $deactivated = $this->deactivateDevices(array_keys($deadDeviceIds));

        $this->info("Checked {$logs->count()} push receipts.");
        $this->line("Successful: {$delivered}");
        $this->line("Errors: {$errors}");
        $this->line("Devices deactivated: {$deactivated}");

        if ($stillWaiting > 0) {
            $this->line("Still awaiting a receipt: {$stillWaiting}");
        }

        Log::info('Expo push receipts processed', [
            'checked' => $logs->count(),
            'delivered' => $delivered,
            'errors' => $errors,
            'devices_deactivated' => $deactivated,
            'still_awaiting' => $stillWaiting,
        ]);

        return self::SUCCESS;
    }

    private function deactivateDevices(array $deviceIds): int
    {
        if ($deviceIds === []) {
            return 0;
        }

        return MobilePushDevice::query()
            ->whereIn('id', $deviceIds)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }
}
