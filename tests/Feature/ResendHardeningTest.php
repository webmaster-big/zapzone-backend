<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignLog;
use App\Models\EmailNotification;
use App\Models\EmailNotificationLog;
use App\Models\Location;
use App\Models\User;
use App\Models\WaiverTemplate;
use App\Services\EmailCampaignSender;
use App\Services\EmailNotificationService;
use App\Services\GmailApiService;
use App\Services\InvitationService;
use App\Services\ResendException;
use App\Services\ResendService;
use App\Services\VisitFollowUpService;
use App\Services\WaiverService;
use Carbon\CarbonInterval;
use Database\Seeders\DefaultEmailNotificationSeeder;
use Google\Service\Gmail\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ResendHardeningTest extends TestCase
{
    use RefreshDatabase;

    private \ArrayObject $gmailSent;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gmail.sender_email' => 'bookings@zap-zone.com',
            'gmail.enabled' => true,
            'gmail.credentials.client_email' => 'sender@zapzone-project.iam.gserviceaccount.com',
            'services.resend.enabled' => true,
            'services.resend.key' => 're_secret_send_key',
            'services.resend.marketing_key' => null,
            'services.resend.from_address' => null,
            'services.resend.marketing_from_address' => null,
            'services.resend.bulk_per_second' => 0,
            'app.url' => 'https://api.zapzone.test',
            'app.frontend_url' => 'https://book.zapzone.test',
        ]);

        Http::preventStrayRequests();
        Sleep::fake();
        Mail::fake();
        $this->gmailSent = new \ArrayObject();
    }

    public function test_resend_sends_everything_with_the_gmail_switch_off_or_the_gmail_credentials_gone(): void
    {
        $this->acceptAll();
        $service = app(EmailNotificationService::class);

        config(['gmail.enabled' => false]);
        $this->assertTrue(GmailApiService::enabled());
        $this->assertSame('resend', GmailApiService::transport());
        $service->sendEmail('first@example.test', 'Your booking', '<p>See you</p>', ['company_name' => 'Zap Zone'], [], true);

        config(['gmail.enabled' => true, 'gmail.credentials.client_email' => null, 'gmail.credentials_path' => '/nonexistent/gmail.json']);
        $this->assertFalse(GmailApiService::gmailConfigured());
        $this->assertTrue(GmailApiService::enabled());
        (new GmailApiService())->sendTransactional('second@example.test', 'Your photos', '<p>Here</p>', 'Zap Zone');
        new InvitationService();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->data()['to'] === ['first@example.test']);
        Http::assertSent(fn (Request $request) => $request->data()['to'] === ['second@example.test']);
        Mail::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_a_campaign_goes_through_resend_with_the_gmail_switch_off_instead_of_being_logged_as_sent(): void
    {
        config(['gmail.enabled' => false]);
        $this->acceptAll();
        $campaign = $this->campaign($this->company(), ['pat@example.test']);

        app(EmailCampaignSender::class)->sendCampaign($campaign);

        Http::assertSentCount(1);
        Mail::assertNothingOutgoing();
        $this->assertSame(EmailCampaignLog::STATUS_SENT, EmailCampaignLog::where('email_campaign_id', $campaign->id)->value('status'));
    }

    public function test_without_gmail_a_refused_receipt_is_reported_and_nothing_falls_back(): void
    {
        config(['gmail.enabled' => false, 'gmail.credentials.client_email' => null, 'gmail.credentials_path' => '/nonexistent/gmail.json']);
        Http::fake(['api.resend.com/*' => Http::response(['statusCode' => 401, 'message' => 'API key is invalid', 'name' => 'validation_error'], 401)]);
        Log::spy();

        try {
            (new GmailApiService())->sendTransactional('guest@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone');
            $this->fail('A refused receipt must not look sent when Gmail is not set up.');
        } catch (ResendException $e) {
            $this->assertSame(401, $e->status);
            $this->assertSame(ResendException::ACCOUNT, $e->scope);
        }

        Http::assertSentCount(1);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'Gmail is not set up')
            && str_contains($context['error'], 'API key is invalid'))->once();
    }

    public function test_every_send_carries_its_own_idempotency_key_and_a_retry_reuses_it(): void
    {
        Http::fake(['api.resend.com/*' => Http::sequence()
            ->push(['statusCode' => 500, 'message' => 'Something went wrong', 'name' => 'application_error'], 500)
            ->push(['id' => 'email-1'], 200)
            ->push(['id' => 'email-2'], 200)]);

        $this->assertTrue($this->gmail()->sendTransactional('one@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone'));
        $this->assertTrue($this->gmail()->sendTransactional('two@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone'));

        $requests = collect(Http::recorded())->map(fn ($pair) => $pair[0]);
        $keys = $requests->map(fn (Request $request) => $request->header('Idempotency-Key')[0] ?? null)->all();

        $this->assertCount(3, $keys);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $keys[0]);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertNotSame($keys[1], $keys[2]);
        $this->assertSame(['zapzone-backend'], $requests->first()->header('User-Agent'));
        $this->assertCount(0, $this->gmailSent);
        $this->assertNull(ResendService::unavailableReason());
    }

    public function test_a_request_resend_is_still_working_on_counts_as_accepted_instead_of_going_out_twice(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['statusCode' => 409, 'message' => 'Same idempotency key used while original request is still in progress', 'name' => 'concurrent_idempotent_requests'], 409)]);

        $this->assertTrue($this->gmail()->sendTransactional('guest@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone'));

        Http::assertSentCount(2);
        $this->assertCount(0, $this->gmailSent);
    }

    public function test_a_send_that_timed_out_before_resend_took_it_makes_the_next_ones_skip_resend_for_a_minute(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw new ConnectionException('Operation timed out after 10001 milliseconds with 0 bytes received');
            }

            return Http::response(['statusCode' => 409, 'message' => 'Same idempotency key used while original request is still in progress', 'name' => 'concurrent_idempotent_requests'], 409);
        });

        $this->assertTrue($this->gmail()->sendTransactional('first@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone'));
        $this->assertSame(2, $calls);
        $this->assertCount(0, $this->gmailSent);
        $this->assertSame('it timed out or dropped the connection', ResendService::unavailableReason());

        $this->assertTrue($this->gmail()->sendTransactional('second@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone'));
        $this->assertSame(2, $calls);
        $this->assertCount(1, $this->gmailSent);
    }

    public function test_after_resend_cannot_be_reached_it_is_skipped_for_a_minute_so_receipts_do_not_wait_on_it(): void
    {
        $calls = 0;
        $up = false;
        Http::fake(function () use (&$calls, &$up) {
            $calls++;

            if (!$up) {
                throw new ConnectionException('Connection timed out after 10001 milliseconds');
            }

            return Http::response(['id' => 'email-9'], 200);
        });

        $this->assertTrue($this->gmail()->sendTransactional('first@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone'));
        $this->assertSame(2, $calls);
        $this->assertSame('it could not be reached', ResendService::unavailableReason());

        $this->assertTrue($this->gmail()->sendTransactional('second@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone'));
        $this->assertSame(2, $calls);
        $this->assertCount(2, $this->gmailSent);

        try {
            $this->gmail()->sendEmail('customer@example.test', 'Sale', '<p>20% off</p>', 'Zap Zone');
            $this->fail('Bulk email must wait while Resend is being skipped.');
        } catch (ResendException $e) {
            $this->assertTrue($e->isTransient());
        }

        $this->assertSame(2, $calls);

        $up = true;
        $this->travel(61)->seconds();
        $this->assertTrue($this->gmail()->sendTransactional('third@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone'));
        $this->assertSame(3, $calls);
        $this->assertCount(2, $this->gmailSent);
    }

    public function test_an_account_wide_refusal_stops_the_campaign_after_one_try_and_resend_to_failed_recovers_it(): void
    {
        $refuse = true;
        Http::fake(function () use (&$refuse) {
            return $refuse
                ? Http::response(['statusCode' => 403, 'message' => 'The zap-zone.com domain is not verified.', 'name' => 'validation_error'], 403)
                : Http::response(['id' => 'email-ok'], 200);
        });
        $company = $this->company();
        $first = $this->campaign($company, ['a@example.test', 'b@example.test', 'c@example.test'], EmailCampaign::STATUS_SENDING);
        $second = $this->campaign($company, ['d@example.test'], EmailCampaign::STATUS_SENDING);

        app(EmailCampaignSender::class)->sendWaiting(microtime(true) + 30);

        Http::assertSentCount(1);
        $first->refresh();
        $this->assertSame(EmailCampaign::STATUS_FAILED, $first->status);
        $this->assertStringContainsString('The zap-zone.com domain is not verified.', $first->error_message);
        $this->assertStringContainsString('Resend to Failed Recipients', $first->error_message);
        $this->assertSame(3, $first->failed_count);
        $this->assertSame(
            array_fill(0, 3, 'Not sent: Resend refused the campaign.'),
            EmailCampaignLog::where('email_campaign_id', $first->id)->orderBy('id')->pluck('error_message')->all()
        );
        $this->assertSame(EmailCampaign::STATUS_SENDING, $second->fresh()->status);
        $this->assertSame([EmailCampaignLog::STATUS_PENDING], EmailCampaignLog::where('email_campaign_id', $second->id)->pluck('status')->all());

        $refuse = false;
        $this->actingAs(User::where('email', 'admin@zapzone.test')->first(), 'sanctum')
            ->postJson("/api/email-campaigns/{$first->id}/resend", ['type' => 'failed'])
            ->assertOk();

        Http::assertSentCount(4);
        $this->assertSame(EmailCampaign::STATUS_COMPLETED, $first->fresh()->status);
        $this->assertSame(3, $first->fresh()->sent_count);
        $this->assertSame(0, $first->fresh()->failed_count);
    }

    public function test_a_small_campaign_sent_on_the_spot_reports_why_resend_stopped_it(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['statusCode' => 429, 'message' => 'You have reached your monthly email sending quota.', 'name' => 'monthly_quota_exceeded'], 429)]);
        $company = $this->company();

        $this->actingAs($this->admin($company), 'sanctum')->postJson('/api/email-campaigns', [
            'name' => 'VIP note',
            'subject' => 'A note',
            'body' => '<p>Hi</p>',
            'recipient_types' => ['custom'],
            'custom_emails' => ['vip1@example.test', 'vip2@example.test'],
        ])->assertCreated()
            ->assertJsonPath('data.status', EmailCampaign::STATUS_FAILED)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'monthly_quota_exceeded') && str_contains($message, 'Resend to Failed Recipients'));

        Http::assertSentCount(1);
    }

    public function test_a_resend_outage_pauses_the_campaign_and_the_next_run_finishes_it(): void
    {
        $up = false;
        Http::fake(function () use (&$up) {
            return $up
                ? Http::response(['id' => 'email-ok'], 200)
                : Http::response(['statusCode' => 500, 'message' => 'Internal server error', 'name' => 'internal_server_error'], 500);
        });
        $campaign = $this->campaign($this->company(), ['a@example.test', 'b@example.test'], EmailCampaign::STATUS_SENDING);

        app(EmailCampaignSender::class)->sendWaiting(microtime(true) + 30);

        Http::assertSentCount(3);
        $this->assertSame(EmailCampaign::STATUS_SENDING, $campaign->fresh()->status);
        $this->assertSame(0, $campaign->fresh()->failed_count);
        $this->assertSame([EmailCampaignLog::STATUS_PENDING, EmailCampaignLog::STATUS_PENDING], EmailCampaignLog::where('email_campaign_id', $campaign->id)->orderBy('id')->pluck('status')->all());

        app(EmailCampaignSender::class)->sendWaiting(microtime(true) + 30);
        Http::assertSentCount(3);

        $up = true;
        $this->travel(61)->seconds();
        app(EmailCampaignSender::class)->sendWaiting(microtime(true) + 30);

        Http::assertSentCount(5);
        $this->assertSame(EmailCampaign::STATUS_COMPLETED, $campaign->fresh()->status);
        $this->assertSame(2, $campaign->fresh()->sent_count);
    }

    public function test_twenty_identical_refusals_stop_a_campaign_only_until_one_of_its_emails_has_gone_out(): void
    {
        $calls = 0;
        $mode = 'broken';
        Http::fake(function () use (&$calls, &$mode) {
            $calls++;

            if (($mode === 'scattered' && $calls === 20) || ($mode === 'one-good-first' && $calls === 1)) {
                return Http::response(['id' => 'email-ok'], 200);
            }

            return $mode === 'broken'
                ? Http::response(['statusCode' => 422, 'message' => 'Invalid `headers` field.', 'name' => 'validation_error'], 422)
                : Http::response(['statusCode' => 422, 'message' => 'Invalid `to` field.', 'name' => 'validation_error'], 422);
        });
        $emails = array_map(fn ($n) => "guest{$n}@example.test", range(1, 25));
        $campaign = $this->campaign($this->company(), $emails);

        $this->assertSame(20, app(EmailCampaignSender::class)->sendCampaign($campaign));

        Http::assertSentCount(20);
        $campaign->refresh();
        $this->assertSame(EmailCampaign::STATUS_FAILED, $campaign->status);
        $this->assertStringContainsString('The last 20 emails in a row were refused the same way: Resend refused the email (HTTP 422, validation_error): Invalid `headers` field.', $campaign->error_message);
        $this->assertSame(25, $campaign->failed_count);
        $messages = EmailCampaignLog::where('email_campaign_id', $campaign->id)->orderBy('id')->pluck('error_message');
        $this->assertSame(20, $messages->filter(fn ($message) => str_contains($message, 'Invalid `headers` field.'))->count());
        $this->assertSame(5, $messages->filter(fn ($message) => $message === 'Not sent: Resend refused the campaign.')->count());

        foreach (['scattered' => [1, 24], 'one-good-first' => [1, 24]] as $name => $counts) {
            $calls = 0;
            $mode = $name;
            $other = $this->campaign($this->company(), $emails);

            $this->assertSame(25, app(EmailCampaignSender::class)->sendCampaign($other), $name);
            $this->assertSame(EmailCampaign::STATUS_COMPLETED, $other->fresh()->status, $name);
            $this->assertSame($counts, [$other->fresh()->sent_count, $other->fresh()->failed_count], $name);
        }
    }

    public function test_typed_in_campaign_addresses_get_an_unsubscribe_and_are_skipped_once_unsubscribed(): void
    {
        $this->acceptAll();
        $company = $this->company();
        $token = app(VisitFollowUpService::class)->optOutToken($company->id, 'gone@example.test');
        $this->post('/api/email-unsubscribe/' . $token, ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $campaign = $this->campaign($company, ['pat@example.test', 'gone@example.test'], EmailCampaign::STATUS_PENDING, 'custom');

        app(EmailCampaignSender::class)->sendCampaign($campaign);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->data()['to'] === ['pat@example.test']
            && $request->data()['headers']['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click'
            && str_starts_with($request->data()['headers']['List-Unsubscribe'], '<https://api.zapzone.test/api/email-unsubscribe/')
            && str_contains($request->data()['html'], 'Unsubscribe</a>'));
        $this->assertSame(
            ['pat@example.test' => null, 'gone@example.test' => 'Not sent: this person unsubscribed.'],
            EmailCampaignLog::where('email_campaign_id', $campaign->id)->orderBy('id')->pluck('error_message', 'recipient_email')->all()
        );
    }

    public function test_campaign_emails_carry_the_business_name_and_address_once(): void
    {
        $this->acceptAll();
        $company = $this->company();
        $campaign = $this->campaign($company, ['pat@example.test']);
        EmailCampaignLog::where('email_campaign_id', $campaign->id)->update(['variables_used' => json_encode([
            'company_name' => 'Zap Zone Brighton',
            'company_address' => '123 Main St',
            'location_address' => '8053 Challis Rd, Brighton, MI, 48116',
        ])]);
        $typed = $this->campaign($company, ['lee@example.test'], EmailCampaign::STATUS_PENDING, 'custom');
        $typed->update(['body' => '<p>Visit us at {{company_address}}</p>']);
        EmailCampaignLog::where('email_campaign_id', $typed->id)->update(['variables_used' => json_encode([
            'company_name' => 'Zap Zone Brighton',
            'company_address' => '123 Main St',
            'location_address' => '',
        ])]);

        app(EmailCampaignSender::class)->sendCampaign($campaign);
        app(EmailCampaignSender::class)->sendCampaign($typed->fresh());

        $html = fn (string $to) => collect(Http::recorded())->map(fn ($pair) => $pair[0]->data())->firstWhere('to', [$to])['html'];
        $this->assertStringContainsString('Zap Zone Brighton &middot; 8053 Challis Rd, Brighton, MI, 48116</p>', $html('pat@example.test'));
        $this->assertStringNotContainsString('123 Main St', $html('pat@example.test'));
        $this->assertSame(1, substr_count($html('lee@example.test'), '123 Main St'));
        $this->assertStringNotContainsString('&middot;', $html('lee@example.test'));
    }

    public function test_warm_up_limits_hold_campaign_email_for_the_next_hour_or_day_but_never_receipts_or_follow_ups(): void
    {
        config(['services.resend.campaign_per_hour' => 2]);
        $this->acceptAll();
        $this->travelTo(now()->startOfHour()->addMinutes(10));
        $campaign = $this->campaign($this->company(), ['a@example.test', 'b@example.test', 'c@example.test'], EmailCampaign::STATUS_SENDING);

        app(EmailCampaignSender::class)->sendWaiting(microtime(true) + 30);

        Http::assertSentCount(2);
        $this->assertSame(EmailCampaign::STATUS_SENDING, $campaign->fresh()->status);
        $this->assertSame(0, $campaign->fresh()->failed_count);
        $this->assertSame(1, EmailCampaignLog::where('email_campaign_id', $campaign->id)->where('status', EmailCampaignLog::STATUS_PENDING)->count());

        $this->assertTrue($this->gmail()->sendTransactional('guest@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone'));
        $this->assertTrue($this->gmail()->sendEmail('player@example.test', 'Thanks for playing', '<p>Thanks</p>', 'Zap Zone'));
        Http::assertSentCount(4);
        $this->assertSame(['sent' => 4, 'failed' => 0, 'gmail' => 0], ResendService::stats(1)[now()->format('Y-m-d')]);

        $this->artisan('resend:status')
            ->expectsOutputToContain('Campaigns this hour: 2 of 2 sent (RESEND_CAMPAIGN_PER_HOUR)')
            ->expectsOutputToContain('Campaigns today: no warm-up limit (RESEND_CAMPAIGN_PER_DAY not set)')
            ->assertExitCode(0);

        foreach (range(1, 4) as $step) {
            $this->travel(10)->minutes();
            app(EmailCampaignSender::class)->sendWaiting(microtime(true) + 30);
        }

        Http::assertSentCount(4);
        $this->assertSame(EmailCampaign::STATUS_SENDING, $campaign->fresh()->status);

        $this->travel(10)->minutes();
        app(EmailCampaignSender::class)->sendWaiting(microtime(true) + 30);

        Http::assertSentCount(5);
        $this->assertSame(EmailCampaign::STATUS_COMPLETED, $campaign->fresh()->status);
        $this->assertSame(3, $campaign->fresh()->sent_count);

        config(['services.resend.campaign_per_hour' => null, 'services.resend.campaign_per_day' => 1]);
        $this->travelTo(now()->startOfDay()->addHours(23)->addMinutes(45));
        $daily = $this->campaign($this->company(), ['d@example.test', 'e@example.test'], EmailCampaign::STATUS_SENDING);

        app(EmailCampaignSender::class)->sendWaiting(microtime(true) + 30);

        Http::assertSentCount(6);
        $this->assertSame(1, EmailCampaignLog::where('email_campaign_id', $daily->id)->where('status', EmailCampaignLog::STATUS_PENDING)->count());

        $this->travel(10)->minutes();
        app(EmailCampaignSender::class)->sendWaiting(microtime(true) + 30);
        Http::assertSentCount(6);

        $this->travel(10)->minutes();
        app(EmailCampaignSender::class)->sendWaiting(microtime(true) + 30);

        Http::assertSentCount(7);
        $this->assertSame(EmailCampaign::STATUS_COMPLETED, $daily->fresh()->status);
    }

    public function test_without_gmail_a_receipt_still_tries_resend_during_the_one_minute_skip(): void
    {
        config(['gmail.enabled' => false, 'gmail.credentials.client_email' => null, 'gmail.credentials_path' => '/nonexistent/gmail.json']);
        $this->acceptAll();
        \Illuminate\Support\Facades\Cache::put(ResendService::UNAVAILABLE_KEY, 'it answered HTTP 500', 60);

        $this->assertTrue((new GmailApiService())->sendTransactional('guest@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone'));
        Http::assertSentCount(1);

        try {
            (new GmailApiService())->sendEmail('customer@example.test', 'Sale', '<p>20% off</p>', 'Zap Zone');
            $this->fail('Bulk email must still wait during the skip.');
        } catch (ResendException $e) {
            $this->assertTrue($e->isTransient());
        }

        Http::assertSentCount(1);
        $this->assertSame(['sent' => 1, 'failed' => 0, 'gmail' => 0], ResendService::stats(1)[now()->format('Y-m-d')]);
    }

    public function test_a_from_address_typed_with_a_name_is_reduced_to_the_address(): void
    {
        config(['services.resend.from_address' => ' Zap Zone <bookings@zap-zone.com> ', 'services.resend.marketing_from_address' => 'News <news@zap-zone.com>']);
        $this->acceptAll();

        $this->assertSame('bookings@zap-zone.com', ResendService::fromAddress());
        $this->assertSame('news@zap-zone.com', ResendService::fromAddress(false));
        $this->gmail()->sendTransactional('guest@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone');

        Http::assertSent(fn (Request $request) => $request->data()['from'] === 'Zap Zone <bookings@zap-zone.com>');
    }

    public function test_refusals_are_sorted_into_recipient_outage_and_account_problems(): void
    {
        $this->assertSame(ResendException::ACCOUNT, ResendService::scopeOf(422, 'invalid_from_address'));
        $this->assertSame(ResendException::ACCOUNT, ResendService::scopeOf(451, 'security_error'));
        $this->assertSame(ResendException::ACCOUNT, ResendService::scopeOf(429, 'daily_quota_exceeded'));
        $this->assertSame(ResendException::TRANSIENT, ResendService::scopeOf(429, 'rate_limit_exceeded'));
        $this->assertSame(ResendException::TRANSIENT, ResendService::scopeOf(503, 'service_unavailable'));
        $this->assertSame(ResendException::RECIPIENT, ResendService::scopeOf(422, 'validation_error'));
        $this->assertSame(ResendException::RECIPIENT, ResendService::scopeOf(422, 'invalid_attachment'));

        Http::fake(['api.resend.com/*' => Http::response(['statusCode' => 429, 'message' => 'Too many requests', 'name' => 'rate_limit_exceeded'], 429)]);
        $campaign = $this->campaign($this->company(), ['a@example.test', 'b@example.test'], EmailCampaign::STATUS_SENDING);

        app(EmailCampaignSender::class)->sendWaiting(microtime(true) + 30);

        Http::assertSentCount(3);
        $this->assertSame(EmailCampaign::STATUS_SENDING, $campaign->fresh()->status);
        $this->assertSame(2, EmailCampaignLog::where('email_campaign_id', $campaign->id)->where('status', EmailCampaignLog::STATUS_PENDING)->count());
    }

    public function test_an_error_thrown_by_the_http_layer_is_still_reported_as_a_resend_problem(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\RequestException(new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(502, [], '{"name":"application_error","message":"Bad gateway"}'))));

        try {
            $this->gmail()->sendEmail('customer@example.test', 'Sale', '<p>20% off</p>', 'Zap Zone');
            $this->fail('A 502 must come back as a Resend problem.');
        } catch (ResendException $e) {
            $this->assertSame(502, $e->status);
            $this->assertTrue($e->isTransient());
        }

        $this->assertSame('it answered HTTP 502', ResendService::unavailableReason());
        $this->assertSame(1, ResendService::stats(1)[now()->format('Y-m-d')]['failed']);
    }

    public function test_a_recipient_caught_mid_send_by_a_cancel_ends_cancelled_not_waiting_forever(): void
    {
        $campaign = $this->campaign($this->company(), ['a@example.test', 'b@example.test'], EmailCampaign::STATUS_SENDING);
        Http::fake(function () use ($campaign) {
            EmailCampaign::whereKey($campaign->id)->update(['status' => EmailCampaign::STATUS_CANCELLED]);
            app(EmailCampaignSender::class)->cancelPending($campaign);

            return Http::response(['statusCode' => 500, 'message' => 'Internal server error', 'name' => 'internal_server_error'], 500);
        });

        app(EmailCampaignSender::class)->sendWaiting(microtime(true) + 30);

        $this->assertSame(
            [EmailCampaignLog::STATUS_CANCELLED, EmailCampaignLog::STATUS_CANCELLED],
            EmailCampaignLog::where('email_campaign_id', $campaign->id)->orderBy('id')->pluck('status')->all()
        );
    }

    public function test_bulk_email_is_paced_and_receipts_never_wait_for_it(): void
    {
        config(['services.resend.bulk_per_second' => 5]);
        (new \ReflectionProperty(ResendService::class, 'lastBulkSend'))->setValue(null, 0.0);
        $this->acceptAll();

        foreach (['a', 'b', 'c'] as $name) {
            $this->gmail()->sendEmail("{$name}@example.test", 'Sale', '<p>20% off</p>', 'Zap Zone');
        }
        $this->gmail()->sendTransactional('guest@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone');
        $this->gmail()->sendTransactional('guest2@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone');

        Http::assertSentCount(5);
        Sleep::assertSleptTimes(2);
        Sleep::assertSlept(fn (CarbonInterval $duration) => $duration->totalMilliseconds > 0 && $duration->totalMilliseconds <= 200, 2);
    }

    public function test_bulk_email_can_use_its_own_resend_key(): void
    {
        config(['services.resend.marketing_key' => 're_marketing_key', 'services.resend.marketing_from_address' => 'news@zap-zone.com']);
        $this->acceptAll();

        $this->gmail()->sendEmail('customer@example.test', 'Sale', '<p>20% off</p>', 'Zap Zone');
        $this->gmail()->sendTransactional('guest@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone');

        Http::assertSent(fn (Request $request) => $request->data()['to'] === ['customer@example.test']
            && $request->header('Authorization') === ['Bearer re_marketing_key']
            && $request->data()['from'] === 'Zap Zone <news@zap-zone.com>');
        Http::assertSent(fn (Request $request) => $request->data()['to'] === ['guest@example.test']
            && $request->header('Authorization') === ['Bearer re_secret_send_key']
            && $request->data()['from'] === 'Zap Zone <bookings@zap-zone.com>');
    }

    public function test_sender_names_are_quoted_only_when_they_need_it(): void
    {
        $this->assertSame('Zap Zone <bookings@zap-zone.com>', ResendService::fromHeader('Zap Zone', 'bookings@zap-zone.com'));
        $this->assertSame('Zap Zone Café <bookings@zap-zone.com>', ResendService::fromHeader('Zap Zone Café', 'bookings@zap-zone.com'));
        $this->assertSame('"Zap Zone, Brighton" <bookings@zap-zone.com>', ResendService::fromHeader('Zap Zone, Brighton', 'bookings@zap-zone.com'));
        $this->assertSame('"Zap Zone (Brighton)" <bookings@zap-zone.com>', ResendService::fromHeader('Zap Zone (Brighton)', 'bookings@zap-zone.com'));
        $this->assertSame('"Zap \"Zone\" \\\\ XL" <bookings@zap-zone.com>', ResendService::fromHeader('Zap "Zone" \\ XL', 'bookings@zap-zone.com'));
        $this->assertSame('"Dr. Fun" <bookings@zap-zone.com>', ResendService::fromHeader('Dr. Fun', 'bookings@zap-zone.com'));
    }

    public function test_the_status_command_shows_what_resend_sent_refused_and_left_to_gmail(): void
    {
        Http::fake(['api.resend.com/*' => Http::sequence()
            ->push(['id' => 'email-1'], 200)
            ->push(['statusCode' => 403, 'message' => 'The zap-zone.com domain is not verified.', 'name' => 'validation_error'], 403)]);

        $this->gmail()->sendTransactional('one@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone');
        $this->gmail()->sendTransactional('two@example.test', 'Receipt two', '<p>Thanks</p>', 'Zap Zone');

        $this->assertSame(['sent' => 1, 'failed' => 1, 'gmail' => 1], ResendService::stats(1)[now()->format('Y-m-d')]);

        $this->artisan('resend:status')
            ->expectsOutputToContain('ON, every email goes out through Resend')
            ->expectsOutputToContain('Gmail backup:  set up')
            ->expectsOutputToContain('The zap-zone.com domain is not verified.')
            ->expectsOutputToContain('Some emails went out through Gmail instead of Resend.')
            ->assertExitCode(0);
    }

    public function test_campaign_attachments_that_would_be_too_big_to_email_are_refused_up_front(): void
    {
        Storage::fake('public');
        $company = $this->company();
        $admin = $this->admin($company);
        $files = fn () => [
            UploadedFile::fake()->create('one.pdf', 7500, 'application/pdf'),
            UploadedFile::fake()->create('two.pdf', 7500, 'application/pdf'),
            UploadedFile::fake()->create('three.pdf', 7500, 'application/pdf'),
        ];

        $this->actingAs($admin, 'sanctum')->post('/api/email-campaigns', [
            'name' => 'Big files',
            'subject' => 'Hello',
            'body' => '<p>Hello</p>',
            'recipient_types' => ['custom'],
            'custom_emails' => ['pat@example.test'],
            'attachments' => $files(),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['attachments']);

        $this->actingAs($admin, 'sanctum')->post('/api/email-campaigns/send-test', [
            'subject' => 'Hello',
            'body' => '<p>Hello</p>',
            'test_email' => 'clark@example.test',
            'attachments' => $files(),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, EmailCampaign::count());
        Http::assertNothingSent();
    }

    public function test_a_notification_test_send_goes_out_the_way_that_notification_really_does(): void
    {
        config(['services.resend.marketing_from_address' => 'news@zap-zone.com']);
        $this->acceptAll();
        $company = $this->company();
        DefaultEmailNotificationSeeder::seedForCompany($company);
        $admin = $this->admin($company);
        $confirmation = EmailNotification::where('company_id', $company->id)->where('trigger_type', EmailNotification::TRIGGER_BOOKING_CONFIRMED)->firstOrFail();
        $thanks = EmailNotification::where('company_id', $company->id)->where('trigger_type', EmailNotification::TRIGGER_VISIT_COMPLETED)->firstOrFail();

        $this->actingAs($admin, 'sanctum')->postJson("/api/email-notifications/{$confirmation->id}/send-test", ['test_email' => 'clark@example.test'])->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson("/api/email-notifications/{$thanks->id}/send-test", ['test_email' => 'staff@example.test'])->assertOk();

        Http::assertSent(fn (Request $request) => $request->data()['to'] === ['clark@example.test'] && str_ends_with($request->data()['from'], '<bookings@zap-zone.com>'));
        Http::assertSent(fn (Request $request) => $request->data()['to'] === ['staff@example.test'] && str_ends_with($request->data()['from'], '<news@zap-zone.com>'));
    }

    public function test_the_chaperone_of_a_group_waiver_invite_gets_their_email_through_resend(): void
    {
        $this->acceptAll();
        $company = $this->company();
        $location = $this->location($company);
        $manager = User::create([
            'first_name' => 'Morgan',
            'last_name' => 'Manager',
            'email' => 'manager@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'location_manager',
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);
        $template = WaiverTemplate::create([
            'company_id' => $company->id,
            'title' => 'General Activity Waiver',
            'status' => WaiverTemplate::STATUS_ACTIVE,
            'is_default' => true,
            'body_text' => '<p>I release {{company_name}}.</p>',
            'kind' => WaiverTemplate::KIND_STANDARD,
            'assigned_package_ids' => [],
            'electronic_consent_enabled' => true,
        ]);
        app(WaiverService::class)->syncVersion($template);

        $this->actingAs($manager, 'sanctum')->postJson('/api/waiver-bulk-invites', [
            'waiver_template_id' => $template->id,
            'selected_date' => now()->addDays(3)->toDateString(),
            'chaperone_name' => 'Coach Taylor',
            'chaperone_email' => 'coach@example.test',
        ])->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->data()['to'] === ['coach@example.test']
            && str_contains($request->data()['html'], '/waiver/bulk/'));
        $this->assertSame(0, EmailNotificationLog::where('recipient_email', 'coach@example.test')->count());
    }

    public function test_a_big_customer_list_is_saved_in_batches_and_sends_with_the_right_details(): void
    {
        $this->acceptAll();
        $company = $this->company();
        $admin = $this->admin($company);
        $now = now();
        Contact::insert(array_map(fn ($n) => [
            'company_id' => $company->id,
            'email' => "customer{$n}@example.test",
            'first_name' => "Guest{$n}",
            'last_name' => 'Smith',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], range(1, 1100)));

        $this->actingAs($admin, 'sanctum')->postJson('/api/email-campaigns', [
            'name' => 'Everyone',
            'subject' => 'Hi {{recipient_first_name}}',
            'body' => '<p>See you soon</p>',
            'recipient_types' => ['customers'],
        ])->assertCreated()->assertJsonPath('message', EmailCampaignSender::queuedMessage(1100));

        $campaign = EmailCampaign::firstOrFail();
        $this->assertSame(1100, $campaign->total_recipients);
        $this->assertSame(1100, EmailCampaignLog::where('email_campaign_id', $campaign->id)->where('status', EmailCampaignLog::STATUS_PENDING)->count());
        $log = EmailCampaignLog::where('recipient_email', 'customer777@example.test')->firstOrFail();
        $this->assertSame('Guest777', $log->variables_used['recipient_first_name']);
        $this->assertNotNull($log->created_at);

        app(EmailCampaignSender::class)->sendWaiting(microtime(true) + 120);

        Http::assertSentCount(1100);
        Http::assertSent(fn (Request $request) => $request->data()['to'] === ['customer777@example.test'] && $request->data()['subject'] === 'Hi Guest777');
        $this->assertSame(EmailCampaign::STATUS_COMPLETED, $campaign->fresh()->status);
        $this->assertSame(1100, $campaign->fresh()->sent_count);
    }

    private function company(): Company
    {
        return Company::firstOrCreate(['email' => 'zapzonetest@zapzone.test'], [
            'company_name' => 'ZapZone Test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);
    }

    private function location(Company $company): Location
    {
        return Location::create([
            'company_id' => $company->id,
            'name' => 'Brighton',
            'address' => '8053 Challis Rd',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => 'brighton@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    private function admin(Company $company): User
    {
        return User::firstOrCreate(['email' => 'admin@zapzone.test'], [
            'first_name' => 'Admin',
            'last_name' => 'Staff',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $company->id,
        ]);
    }

    private function campaign(Company $company, array $emails, string $status = EmailCampaign::STATUS_PENDING, string $type = 'customer'): EmailCampaign
    {
        $campaign = EmailCampaign::create([
            'company_id' => $company->id,
            'sent_by' => $this->admin($company)->id,
            'name' => 'Fall sale',
            'subject' => 'Fall sale at {{company_name}}',
            'body' => '<p>20% off this weekend</p>',
            'recipient_types' => [$type === 'custom' ? 'custom' : 'customers'],
            'total_recipients' => count($emails),
            'status' => $status,
        ]);

        foreach ($emails as $email) {
            EmailCampaignLog::create([
                'email_campaign_id' => $campaign->id,
                'recipient_email' => $email,
                'recipient_type' => $type,
                'status' => EmailCampaignLog::STATUS_PENDING,
                'variables_used' => ['company_name' => 'Zap Zone Brighton'],
            ]);
        }

        return $campaign;
    }

    private function acceptAll(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'email-123'], 200)]);
    }

    private function gmail(): GmailApiService
    {
        $gmail = new \stdClass();
        $gmail->users_messages = new class($this->gmailSent) {
            public function __construct(private \ArrayObject $sent)
            {
            }

            public function send(string $user, Message $message): Message
            {
                $this->sent[] = $message;
                $result = new Message();
                $result->setId('gmail-1');

                return $result;
            }
        };

        $service = (new \ReflectionClass(GmailApiService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(GmailApiService::class, 'service'))->setValue($service, $gmail);

        return $service;
    }
}
