<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\SmsNotification;
use App\Models\SmsNotificationLog;
use App\Services\SmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnoseSms extends Command
{
    protected $signature = 'sms:diagnose
        {--phone= : Check one number without sending anything}
        {--delivery : Ask the provider whether recent messages actually reached the phone}
        {--limit=25 : How many recent messages to look up with --delivery}';

    protected $description = 'Report why text messages are or are not going out, without sending any';

    public function handle(): int
    {
        $this->newLine();
        $this->line('<options=bold>Text messaging check</> — nothing is sent by this command.');

        $ok = $this->reportCredentials();
        $this->reportTemplates();
        $this->reportRecentAttempts();
        $this->reportPhotoDeliveries();
        $this->reportNumbers();

        if ($phone = $this->option('phone')) {
            $this->reportOneNumber((string) $phone);
        }

        if ($this->option('delivery')) {
            $this->reportRealDeliveryStatus((int) $this->option('limit'));
        } else {
            $this->newLine();
            $this->line('   <fg=yellow>Note</> "sent" above means the provider accepted the message, not that the');
            $this->line('   phone received it. To find out which actually arrived, run:');
            $this->line('       php artisan sms:diagnose --delivery');
        }

        $this->newLine();
        $this->line($ok
            ? '<fg=green>Credentials are in place.</> Sections 3 and 4 are separate delivery paths: booking and waiver texts, then photo links. Check the one you are missing.'
            : '<fg=red>Text messaging cannot send at all until the missing settings above are filled in.</>');
        $this->newLine();

        return Command::SUCCESS;
    }

    protected function heading(string $text): void
    {
        $this->newLine();
        $this->line('<options=bold>' . $text . '</>');
    }

    protected function reportCredentials(): bool
    {
        $this->heading('1. Provider settings');

        $present = [
            'TWILIO_SID' => (string) config('twilio.sid'),
            'TWILIO_AUTH_TOKEN' => (string) config('twilio.auth_token'),
            'TWILIO_FROM_NUMBER' => (string) config('twilio.from_number'),
        ];

        $missing = [];

        foreach ($present as $key => $value) {
            if ($value === '') {
                $missing[] = $key;
                $this->line("   <fg=red>missing</>  {$key}");
                continue;
            }

            // Never print a secret. Length and last four characters are enough to tell
            // an empty value from a truncated paste.
            $shown = $key === 'TWILIO_FROM_NUMBER' ? $value : str_repeat('*', max(0, strlen($value) - 4)) . substr($value, -4);
            $this->line("   <fg=green>set</>      {$key} = {$shown}");
        }

        $sdk = class_exists(\Twilio\Rest\Client::class);
        $this->line($sdk
            ? '   <fg=green>ok</>       the Twilio library is installed'
            : '   <fg=red>missing</>  the Twilio library is not installed; run composer install');

        $from = $present['TWILIO_FROM_NUMBER'];
        if ($from !== '' && SmsService::toE164($from) === null) {
            $this->line("   <fg=red>problem</>  the sending number {$from} is not in a form the provider accepts");
        }

        // A toll-free or ordinary US sender has to be registered before carriers will
        // carry its traffic. Until it is, messages are accepted by the provider and then
        // dropped on the way to the phone, which looks exactly like success in our logs.
        $digits = preg_replace('/\D+/', '', $from) ?? '';
        $areaCode = strlen($digits) === 11 && str_starts_with($digits, '1') ? substr($digits, 1, 3) : substr($digits, 0, 3);

        if (in_array($areaCode, ['800', '833', '844', '855', '866', '877', '888'], true)) {
            $this->line("   <fg=yellow>note</>     {$from} is a toll-free number. Carriers only carry toll-free");
            $this->line('              traffic once Twilio has approved a Toll-Free Verification for it.');
            $this->line('              Until then messages are accepted and then dropped silently, and');
            $this->line('              messages containing a link are the first to go.');
            $this->line('              Check: Twilio Console > Messaging > Regulatory Compliance > Toll-Free Verification');
        } elseif ($digits !== '') {
            $this->line('   <fg=yellow>note</>     if this is a standard 10-digit US number it needs A2P 10DLC');
            $this->line('              registration before carriers will carry business traffic reliably.');
        }

        $configured = SmsService::isConfigured();
        $this->line($configured
            ? '   <fg=green>result</>   the system believes text messaging is switched on'
            : '   <fg=red>result</>   the system treats text messaging as switched off, so every message is skipped');

        if ($missing !== []) {
            $this->line('   Fill in ' . implode(', ', $missing) . ' on the server, then run: php artisan config:clear');
        }

        return $configured && $sdk;
    }

    protected function reportTemplates(): void
    {
        $this->heading('2. Which messages are set up');

        if (!$this->tableExists('sms_notifications')) {
            $this->line('   <fg=yellow>skipped</>  the sms_notifications table does not exist yet');

            return;
        }

        $companies = Company::query()->get(['id', 'company_name']);

        if ($companies->isEmpty()) {
            $this->line('   <fg=yellow>none</>     no companies found');

            return;
        }

        foreach ($companies as $company) {
            $total = SmsNotification::where('company_id', $company->id)->count();
            $active = SmsNotification::where('company_id', $company->id)->where('is_active', true)->count();

            if ($total === 0) {
                $this->line("   <fg=red>none</>     {$company->company_name}: no text messages are set up, so nothing can send");
                $this->line('              run: php artisan db:seed --class=DefaultSmsNotificationSeeder');
                continue;
            }

            if ($active === 0) {
                $this->line("   <fg=red>all off</>  {$company->company_name}: {$total} set up but every one is switched off");
                continue;
            }

            $this->line("   <fg=green>ok</>       {$company->company_name}: {$active} of {$total} switched on");
        }
    }

    protected function reportRecentAttempts(): void
    {
        $this->heading('3. Recent attempts');

        if (!$this->tableExists('sms_notification_logs')) {
            $this->line('   <fg=yellow>skipped</>  the sms_notification_logs table does not exist yet');

            return;
        }

        $window = now()->subDays(14);

        $counts = SmsNotificationLog::where('created_at', '>=', $window)
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        if ($counts->isEmpty()) {
            $this->line('   <fg=red>none</>     nothing has even been attempted in the last 14 days.');
            $this->line('              That points at the messages not being triggered rather than the provider');
            $this->line('              refusing them. Check that bookings and waivers are firing notifications.');

            return;
        }

        foreach ($counts as $status => $total) {
            $colour = $status === SmsNotificationLog::STATUS_SENT ? 'green' : ($status === SmsNotificationLog::STATUS_FAILED ? 'red' : 'yellow');
            $this->line("   <fg={$colour}>{$status}</>: {$total} in the last 14 days");
        }

        $failures = SmsNotificationLog::where('created_at', '>=', $window)
            ->where('status', SmsNotificationLog::STATUS_FAILED)
            ->whereNotNull('error_message')
            ->latest()
            ->limit(5)
            ->get(['recipient_phone', 'error_message', 'created_at']);

        if ($failures->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('   What the provider said, most recent first:');

        foreach ($failures as $failure) {
            $this->line('   · ' . $failure->created_at->format('M j H:i') . '  ' . $this->maskPhone((string) $failure->recipient_phone));
            $this->line('     ' . trim(mb_substr((string) $failure->error_message, 0, 200)));
        }

        $this->newLine();
        $this->line('   Provider error codes worth knowing:');
        $this->line('   · 21211  the destination number is not valid');
        $this->line('   · 21608  a trial account can only text numbers you have verified');
        $this->line('   · 21610  that person replied STOP and cannot be texted again');
        $this->line('   · 30034  the sending number is not registered for business texting, so');
        $this->line('            messages containing a link are dropped while plain ones get through');
    }

    /**
     * Photo links are delivered from their own table, not sms_notification_logs, so a
     * healthy notifications module tells you nothing about whether photos are arriving.
     */
    protected function reportPhotoDeliveries(): void
    {
        $this->heading('4. Photo links (a separate delivery path)');

        if (!$this->tableExists('photo_deliveries')) {
            $this->line('   <fg=yellow>skipped</>  the photo feature is not installed here');

            return;
        }

        $window = now()->subDays(14);

        $rows = DB::table('photo_deliveries')
            ->where('created_at', '>=', $window)
            ->whereNull('duplicate_of_id')
            ->select('channel', 'status', DB::raw('COUNT(*) as total'))
            ->groupBy('channel', 'status')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('   <fg=yellow>none</>     no photo links have been sent in the last 14 days at all,');
            $this->line('              by text or by email, so there is nothing here to diagnose yet.');

            return;
        }

        foreach (['sms' => 'by text', 'email' => 'by email'] as $channel => $label) {
            $forChannel = $rows->where('channel', $channel);

            if ($forChannel->isEmpty()) {
                $this->line("   <fg=yellow>none</>     nothing attempted {$label}");
                continue;
            }

            $parts = $forChannel->map(fn ($r) => "{$r->status} {$r->total}")->implode(', ');
            $sent = (int) ($forChannel->firstWhere('status', 'sent')->total ?? 0);
            $colour = $sent > 0 ? 'green' : 'red';
            $this->line("   <fg={$colour}>{$label}</>: {$parts}");
        }

        $failures = DB::table('photo_deliveries')
            ->where('created_at', '>=', $window)
            ->whereIn('status', ['failed', 'skipped'])
            ->whereNotNull('error')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['channel', 'destination', 'status', 'error', 'created_at']);

        if ($failures->isNotEmpty()) {
            $this->newLine();
            $this->line('   Why photo links did not go out, most recent first:');

            foreach ($failures as $failure) {
                $when = \Illuminate\Support\Carbon::parse($failure->created_at)->format('M j H:i');
                $this->line('   · ' . $when . '  ' . $failure->channel . ' ' . $failure->status . '  ' . $this->maskPhone((string) $failure->destination));
                $this->line('     ' . trim(mb_substr((string) $failure->error, 0, 200)));
            }
        }

        // A scheduled delivery whose time has passed means the scheduler is not running,
        // which strands next-day photo links and every automatic retry.
        $overdue = DB::table('photo_deliveries')
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now()->subMinutes(30))
            ->count();

        $this->newLine();
        $this->line($overdue === 0
            ? '   <fg=green>ok</>       nothing is overdue, so the scheduled job is running'
            : "   <fg=red>problem</>  {$overdue} photo link(s) are past their send time and still waiting.");

        if ($overdue > 0) {
            $this->line('              The scheduler is not running. Check the Forge cron for');
            $this->line('              "php artisan schedule:run" on this site.');
        }
    }

    protected function reportNumbers(): void
    {
        $this->heading('5. Are the stored numbers usable');

        if (!$this->tableExists('customers')) {
            $this->line('   <fg=yellow>skipped</>  the customers table does not exist yet');

            return;
        }

        $phones = DB::table('customers')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderByDesc('id')
            ->limit(500)
            ->pluck('phone');

        if ($phones->isEmpty()) {
            $this->line('   <fg=yellow>none</>     no customer phone numbers on file');

            return;
        }

        $refused = $phones->filter(fn ($phone) => SmsService::toE164((string) $phone) === null);

        $this->line('   checked the ' . $phones->count() . ' most recent customer numbers');
        $this->line($refused->isEmpty()
            ? '   <fg=green>ok</>       every one can be texted'
            : '   <fg=yellow>note</>     ' . $refused->count() . ' cannot be texted as written');

        foreach ($refused->take(10) as $phone) {
            $this->line('   · ' . $phone);
        }
    }

    protected function reportOneNumber(string $phone): void
    {
        $this->heading('6. The number you asked about');

        $dialled = SmsService::toE164($phone);

        $this->line('   as stored:  ' . $phone);
        $this->line($dialled === null
            ? '   <fg=red>result</>   cannot be texted as written'
            : '   <fg=green>result</>   would be texted as ' . $dialled);
    }

    /**
     * Ask the provider what actually happened to messages we recorded as sent.
     *
     * Our own "sent" only means the API accepted the message and handed back an id. The
     * final outcome -- delivered, or dropped somewhere between the provider and the phone --
     * arrives later and is not recorded anywhere, so a run of silently filtered messages is
     * indistinguishable from success. Looking each id up settles it. Read-only.
     */
    protected function reportRealDeliveryStatus(int $limit): void
    {
        $this->heading('7. What actually reached the phone');

        if (!SmsService::isConfigured()) {
            $this->line('   <fg=yellow>skipped</>  the provider is not configured, so there is nothing to ask');

            return;
        }

        $limit = max(1, min($limit, 100));

        $recent = SmsNotificationLog::whereNotNull('provider_sid')
            ->where('status', SmsNotificationLog::STATUS_SENT)
            ->latest()
            ->limit($limit)
            ->get(['recipient_phone', 'provider_sid', 'created_at']);

        if ($recent->isEmpty()) {
            $this->line('   <fg=yellow>none</>     no accepted messages with a provider id to look up');

            return;
        }

        try {
            $client = new \Twilio\Rest\Client(config('twilio.sid'), config('twilio.auth_token'));
        } catch (\Throwable $e) {
            $this->line('   <fg=red>problem</>  could not reach the provider: ' . $e->getMessage());

            return;
        }

        $this->line("   asking the provider about the last {$recent->count()} accepted messages...");
        $this->newLine();

        $tally = [];
        $problems = [];

        foreach ($recent as $log) {
            try {
                $message = $client->messages($log->provider_sid)->fetch();
                $status = (string) $message->status;
                $tally[$status] = ($tally[$status] ?? 0) + 1;

                if (in_array($status, ['undelivered', 'failed'], true)) {
                    $problems[] = [
                        'when' => $log->created_at->format('M j H:i'),
                        'to' => $this->maskPhone((string) $log->recipient_phone),
                        'code' => (string) ($message->errorCode ?? ''),
                        'why' => (string) ($message->errorMessage ?? 'no reason given'),
                    ];
                }
            } catch (\Throwable $e) {
                $tally['could not look up'] = ($tally['could not look up'] ?? 0) + 1;
            }
        }

        foreach ($tally as $status => $count) {
            $colour = $status === 'delivered' ? 'green' : (in_array($status, ['undelivered', 'failed'], true) ? 'red' : 'yellow');
            $this->line("   <fg={$colour}>{$status}</>: {$count}");
        }

        if ($problems !== []) {
            $this->newLine();
            $this->line('   Accepted by the provider but never reached the phone:');

            foreach (array_slice($problems, 0, 8) as $p) {
                $this->line('   · ' . $p['when'] . '  ' . $p['to'] . '  code ' . ($p['code'] ?: '-'));
                $this->line('     ' . trim(mb_substr($p['why'], 0, 180)));
            }

            $this->newLine();
            $this->line('   · 30032  the toll-free number is not verified yet');
            $this->line('   · 30034  a standard number is not registered for business texting');
            $this->line('   · 30007  the carrier filtered it as unwanted, often for containing a link');
            $this->line('   · 30003  the handset was unreachable or switched off');
            $this->line('   · 30005  that number does not exist');
        }

        $delivered = $tally['delivered'] ?? 0;

        $this->newLine();
        if ($delivered === 0) {
            $this->line('   <fg=red>Nothing in this sample reached a phone.</> The provider is accepting messages');
            $this->line('   and something downstream is dropping every one, which is what sender');
            $this->line('   registration fixes. The codes above say which.');
        } elseif ($problems !== []) {
            $this->line("   <fg=yellow>{$delivered} of {$recent->count()} arrived.</> The rest were dropped after acceptance.");
        } else {
            $this->line('   <fg=green>Everything in this sample arrived.</> Texting is genuinely working, so a');
            $this->line('   missing message is worth chasing per-recipient rather than system-wide.');
        }
    }

    protected function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) > 4 ? '(***) ***-' . substr($digits, -4) : $phone;
    }

    protected function tableExists(string $table): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
