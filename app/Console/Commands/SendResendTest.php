<?php

namespace App\Console\Commands;

use App\Services\ResendService;
use App\Services\VisitFollowUpService;
use Illuminate\Console\Command;

class SendResendTest extends Command
{
    protected $signature = 'resend:test {to : Address that should receive the test email} {--bulk : Send it the way campaigns and follow-ups go out}';

    protected $description = 'Send one test email straight through Resend, with no Gmail fallback, and report what Resend said';

    public const TEST_IMAGE = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function handle(ResendService $resend): int
    {
        $bulk = (bool) $this->option('bulk');
        $key = ResendService::apiKey(!$bulk);
        $to = trim((string) $this->argument('to'));

        $this->line('Switched on:  ' . (config('services.resend.enabled') ? 'yes' : 'no (RESEND_ENABLED is off, so live emails still go through Gmail)'));
        $this->line('API key:      ' . ($key === '' ? 'missing (set RESEND_KEY)' : strlen($key) . ' characters, starting ' . substr($key, 0, 3) . ', ending ' . substr($key, -4)) . ($bulk && filled(config('services.resend.marketing_key')) ? ' (RESEND_MARKETING_KEY)' : ''));
        $this->line('From address: ' . ResendService::fromAddress());
        $this->line('Bulk From:    ' . ResendService::fromAddress(false) . ' (campaigns and follow-ups)');
        $this->line('Unsubscribe:  ' . rtrim((string) config('app.url'), '/') . '/api/email-unsubscribe/... (one-click link in bulk emails; must be this server\'s public address)');
        $this->line('Verify in Resend the exact domain after the @ of both From addresses; a parent or sub-domain fails strict DMARC and the email bounces.');

        if ($key === '') {
            return self::FAILURE;
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("{$to} is not an email address.");

            return self::FAILURE;
        }

        $followUps = app(VisitFollowUpService::class);

        try {
            $id = $resend->send(
                $to,
                'Resend test from ZapZone',
                '<p>This test email was sent through Resend. If you can read it and see a small dot below, Resend is set up correctly.</p><p><img src="cid:resend-test-image" alt="test image" width="12" height="12"></p>',
                'This test email was sent through Resend. If you can read it, Resend is set up correctly.',
                config('gmail.sender_name', 'Zap Zone') . ', test',
                [['data' => base64_encode("ZapZone Resend test attachment\n"), 'filename' => 'resend-test.txt', 'mime_type' => 'text/plain']],
                [['data' => self::TEST_IMAGE, 'filename' => 'resend-test.png', 'mime_type' => 'image/png', 'content_id' => 'resend-test-image']],
                $bulk ? $followUps->oneClickUnsubscribeHeaders('resend-test') : [],
                !$bulk,
                false
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Resend accepted the email (id {$id}). Check the inbox for {$to}: it should show a small dot and a resend-test.txt attachment, and Gmail's Show original should say DKIM and DMARC: PASS.");

        return self::SUCCESS;
    }
}
