<?php

namespace App\Console\Commands;

use App\Models\EmailNotification;
use App\Models\Waiver;
use App\Models\WaiverSetting;
use App\Services\EmailNotificationService;
use App\Services\WaiverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendWaiverReminders extends Command
{
    protected $signature = 'waivers:send-reminders';

    protected $description = 'Send reminders for incomplete waivers whose visit date is within the reminder window';

    public function handle(WaiverService $waivers, EmailNotificationService $emails): int
    {
        // The reminder window is per-company; group due waivers by company and apply
        // each company's configured window (default 24h).
        $defaultWindow = 24;

        // Pull a generous candidate set (widest plausible window), then filter per company.
        $maxWindow = (int) (WaiverSetting::max('reminder_window_hours') ?: $defaultWindow);
        $candidates = $waivers->dueForReminder($maxWindow);

        $this->info("Found {$candidates->count()} candidate waiver(s) within {$maxWindow}h.");

        $sent = 0;
        $skipped = 0;

        foreach ($candidates as $waiver) {
            $companyId = $waiver->company_id ?? $waiver->location?->company_id;
            $window = $companyId
                ? (int) (WaiverSetting::forCompany($companyId)->reminder_window_hours ?: $defaultWindow)
                : $defaultWindow;

            // Respect each company's specific window
            $cutoff = now()->addHours($window)->endOfDay();
            if ($waiver->selected_date && $waiver->selected_date->greaterThan($cutoff)) {
                $skipped++;
                continue;
            }

            $recipient = $waiver->adult_email ?? $waiver->customer?->email;
            if (!$recipient) {
                $skipped++;
                continue;
            }

            try {
                $emails->triggerWaiverNotification($waiver, EmailNotification::TRIGGER_WAIVER_REMINDER);
                $waiver->update(['reminder_sent' => true, 'reminder_sent_at' => now()]);
                $sent++;
            } catch (\Throwable $e) {
                Log::error('Failed to send waiver reminder', [
                    'waiver_id' => $waiver->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Waiver reminders complete: {$sent} sent, {$skipped} skipped.");
        Log::info('Waiver reminder command completed', ['sent' => $sent, 'skipped' => $skipped]);

        return Command::SUCCESS;
    }
}
