<?php

namespace App\Console\Commands;

use App\Models\CheckoutConcern;
use App\Services\CheckoutConcernService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendCheckoutConcernAlerts extends Command
{
    protected $signature = 'concerns:send-alerts';

    protected $description = 'Alert venue staff about checkouts a guest left unfinished, once the grace period has passed and no payment arrived';

    private const LOCATION_ALERT_CAP_PER_HOUR = 12;

    public function handle(CheckoutConcernService $concerns): int
    {
        $due = CheckoutConcern::with('location.company')
            ->dueForAlert()
            ->orderBy('alert_after')
            ->limit(200)
            ->get();

        if ($due->isEmpty()) {
            $this->info('Nothing due.');

            return self::SUCCESS;
        }

        $this->info("{$due->count()} concern(s) due.");

        $sent = 0;
        $cancelled = 0;
        $suppressed = 0;

        foreach ($due as $concern) {
            try {
                Log::info('Processing a due checkout-concern alert', [
                    'checkout_concern_id' => $concern->id,
                    'kind' => $concern->kind,
                    'location_id' => $concern->location_id,
                    'venue' => $concern->location?->name,
                    'guest' => $concern->name,
                    'phone' => $concern->phone,
                    'email' => $concern->email,
                    'recorded_at' => $concern->created_at?->toIso8601String(),
                    'due_since' => $concern->alert_after?->toIso8601String(),
                    'minutes_waited' => $concern->created_at?->diffInMinutes(now()),
                ]);

                if ($concerns->hasPaidSince($concern->location_id, $concern->email, $concern->created_at)) {
                    $concern->update([
                        'alerted_at' => now(),
                        'alerted' => ['cancelled' => 'the guest completed a purchase during the grace period'],
                        'status' => CheckoutConcern::STATUS_RESOLVED,
                        'resolution_note' => 'They came back and paid — no call needed.',
                    ]);
                    $cancelled++;
                    Log::info('Checkout-concern alert CANCELLED - the guest paid during the grace period', [
                        'checkout_concern_id' => $concern->id,
                        'guest' => $concern->name,
                        'email' => $concern->email,
                        'venue' => $concern->location?->name,
                    ]);
                    continue;
                }

                $recentAtVenue = CheckoutConcern::where('location_id', $concern->location_id)
                    ->whereNotNull('alerted_at')
                    ->where('alerted_at', '>=', now()->subHour())
                    ->count();

                if ($recentAtVenue >= self::LOCATION_ALERT_CAP_PER_HOUR) {
                    $concern->update([
                        'alerted_at' => now(),
                        'alerted' => ['suppressed' => 'hourly cap reached for this venue'],
                    ]);
                    $suppressed++;
                    Log::warning('Checkout-concern alert SUPPRESSED - hourly cap reached for this venue', [
                        'checkout_concern_id' => $concern->id,
                        'location_id' => $concern->location_id,
                        'venue' => $concern->location?->name,
                        'alerts_in_last_hour' => $recentAtVenue,
                        'cap' => self::LOCATION_ALERT_CAP_PER_HOUR,
                    ]);
                    continue;
                }

                $outcome = $concerns->alertStaff($concern);
                $concern->update(['alerted_at' => now()]);
                $sent++;

                Log::info('Checkout-concern alert SENT', [
                    'checkout_concern_id' => $concern->id,
                    'venue' => $concern->location?->name,
                    'via' => $outcome['via'] ?? null,
                    'emails_sent' => $outcome['emails_sent'] ?? [],
                    'emails_failed' => $outcome['emails_failed'] ?? [],
                    'sms_sent' => $outcome['sms_sent'] ?? [],
                    'sms_failed' => $outcome['sms_failed'] ?? [],
                ]);
            } catch (\Throwable $e) {
                Log::error('Could not send a deferred checkout-concern alert', [
                    'checkout_concern_id' => $concern->id,
                    'venue' => $concern->location?->name,
                    'guest' => $concern->name,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $this->info("Sent {$sent}, cancelled {$cancelled}, suppressed {$suppressed}.");

        Log::info('Checkout-concern alert sweep finished', [
            'due' => $due->count(),
            'sent' => $sent,
            'cancelled' => $cancelled,
            'suppressed' => $suppressed,
            'failed' => $due->count() - $sent - $cancelled - $suppressed,
        ]);

        return self::SUCCESS;
    }
}
