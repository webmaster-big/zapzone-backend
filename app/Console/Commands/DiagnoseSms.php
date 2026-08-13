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
    protected $signature = 'sms:diagnose {--phone= : Check one number without sending anything}';

    protected $description = 'Report why text messages are or are not going out, without sending any';

    public function handle(): int
    {
        $this->newLine();
        $this->line('<options=bold>Text messaging check</> — nothing is sent by this command.');

        $ok = $this->reportCredentials();
        $this->reportTemplates();
        $this->reportRecentAttempts();
        $this->reportNumbers();

        if ($phone = $this->option('phone')) {
            $this->reportOneNumber((string) $phone);
        }

        $this->newLine();
        $this->line($ok
            ? '<fg=green>Credentials are in place.</> If messages still are not arriving, the "recent attempts" section above names the provider error.'
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

    protected function reportNumbers(): void
    {
        $this->heading('4. Are the stored numbers usable');

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
        $this->heading('5. The number you asked about');

        $dialled = SmsService::toE164($phone);

        $this->line('   as stored:  ' . $phone);
        $this->line($dialled === null
            ? '   <fg=red>result</>   cannot be texted as written'
            : '   <fg=green>result</>   would be texted as ' . $dialled);
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
