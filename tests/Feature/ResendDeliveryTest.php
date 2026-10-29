<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignLog;
use App\Models\EmailNotification;
use App\Models\FollowUpOptOut;
use App\Models\Location;
use App\Models\Package;
use App\Models\User;
use App\Services\EmailCampaignSender;
use App\Services\EmailNotificationService;
use App\Services\GmailApiService;
use App\Services\VisitFollowUpService;
use Carbon\CarbonInterval;
use Google\Service\Gmail\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Mockery;
use Tests\TestCase;

class ResendDeliveryTest extends TestCase
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
            'services.resend.from_address' => null,
            'services.resend.bulk_per_second' => 0,
        ]);

        Http::preventStrayRequests();
        Sleep::fake();
        $this->gmailSent = new \ArrayObject();
    }

    public function test_a_transactional_email_goes_through_resend_with_its_images_and_attachments_and_skips_gmail(): void
    {
        $this->acceptAll();

        $this->assertTrue($this->gmail()->sendTransactional(
            'guest@example.test',
            'Your booking is confirmed',
            '<p>See you Saturday</p><p><img src="cid:qr@zapzone" alt="QR"></p>',
            'Zap Zone, Brighton',
            [
                ['data' => base64_encode('qr-bytes'), 'filename' => 'booking-qrcode.png', 'mime_type' => 'image/png', 'content_id' => 'qr@zapzone'],
                ['data' => base64_encode('pdf-bytes'), 'filename' => 'waiver.pdf', 'mime_type' => 'application/pdf'],
            ]
        ));

        $this->assertCount(0, $this->gmailSent);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === 'https://api.resend.com/emails'
                && $request->header('Authorization') === ['Bearer re_secret_send_key']
                && $body['from'] === '"Zap Zone, Brighton" <bookings@zap-zone.com>'
                && $body['to'] === ['guest@example.test']
                && $body['reply_to'] === 'bookings@zap-zone.com'
                && $body['subject'] === 'Your booking is confirmed'
                && str_contains($body['html'], 'src="cid:qr@zapzone"')
                && str_contains($body['text'], 'See you Saturday')
                && $body['attachments'] === [
                    ['filename' => 'waiver.pdf', 'content' => base64_encode('pdf-bytes'), 'content_type' => 'application/pdf'],
                    ['filename' => 'booking-qrcode.png', 'content' => base64_encode('qr-bytes'), 'content_type' => 'image/png', 'content_id' => 'qr@zapzone'],
                ];
        });
    }

    public function test_a_key_pasted_with_bearer_is_not_prefixed_twice_and_a_separate_from_address_keeps_replies_on_gmail(): void
    {
        config([
            'services.resend.key' => '  Bearer re_secret_send_key ',
            'services.resend.from_address' => 'noreply@zap-zone.com',
        ]);
        $this->acceptAll();

        $this->gmail()->sendTransactional('guest@example.test', "Receipt\r\nBcc: someone@else.test", '<p>Thanks</p>', "Zap \"Zone\"\nXL");

        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer re_secret_send_key']
            && $request->data()['from'] === '"Zap \"Zone\" XL" <noreply@zap-zone.com>'
            && $request->data()['reply_to'] === 'bookings@zap-zone.com'
            && $request->data()['subject'] === 'Receipt  Bcc: someone@else.test');
    }

    public function test_when_resend_refuses_the_email_gmail_sends_it_and_the_reason_is_logged(): void
    {
        Http::fake(['api.resend.com/*' => Http::response([
            'statusCode' => 401,
            'message' => 'API key is invalid',
            'name' => 'validation_error',
        ], 401)]);
        Log::spy();

        $this->assertTrue($this->gmail()->sendTransactional('guest@example.test', 'Your photos', '<p>Here they are</p>', 'Zap Zone'));

        $this->assertCount(1, $this->gmailSent);
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => str_contains($message, 'Resend send failed; sending through Gmail instead')
            && str_contains($context['error'], 'HTTP 401')
            && str_contains($context['error'], 'validation_error')
            && str_contains($context['error'], 'API key is invalid'))->once();
    }

    public function test_when_resend_cannot_be_reached_gmail_sends_the_email(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->assertTrue($this->gmail()->sendTransactional('guest@example.test', 'Your photos', '<p>Here they are</p>', 'Zap Zone'));

        $this->assertCount(1, $this->gmailSent);
    }

    public function test_a_short_rate_limit_waits_as_long_as_resend_asks_and_then_sends(): void
    {
        Http::fake(['api.resend.com/*' => Http::sequence()
            ->push(['statusCode' => 429, 'message' => 'Too many requests', 'name' => 'rate_limit_exceeded'], 429, ['retry-after' => '2'])
            ->push(['id' => 'email-123'], 200)]);

        $this->assertTrue($this->gmail()->sendTransactional('guest@example.test', 'Your photos', '<p>Here they are</p>', 'Zap Zone'));

        $this->assertCount(0, $this->gmailSent);
        Http::assertSentCount(2);
        Sleep::assertSlept(fn (CarbonInterval $duration) => (int) $duration->totalMilliseconds === 2000);
    }

    public function test_a_rate_limit_that_does_not_clear_falls_back_to_gmail_after_two_tries(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['statusCode' => 429, 'message' => 'Too many requests', 'name' => 'rate_limit_exceeded'], 429)]);

        $this->assertTrue($this->gmail()->sendTransactional('guest@example.test', 'Your photos', '<p>Here they are</p>', 'Zap Zone'));

        $this->assertCount(1, $this->gmailSent);
        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_a_used_up_quota_goes_straight_to_gmail_without_retrying(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['statusCode' => 429, 'message' => 'You have reached your monthly email sending quota.', 'name' => 'monthly_quota_exceeded'], 429, ['retry-after' => '60'])]);

        $this->assertTrue($this->gmail()->sendTransactional('guest@example.test', 'Your photos', '<p>Here they are</p>', 'Zap Zone'));

        $this->assertCount(1, $this->gmailSent);
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_bulk_email_goes_through_resend_from_the_bulk_address_with_its_headers(): void
    {
        config(['services.resend.marketing_from_address' => 'news@zap-zone.com']);
        $this->acceptAll();

        $this->assertTrue($this->gmail()->sendEmail('customer@example.test', 'Spring sale', '<p>20% off</p>', 'Zap Zone', [], [
            'List-Unsubscribe' => '<https://api.zapzone.test/api/email-unsubscribe/u.abc>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]));
        $this->gmail()->sendTransactional('guest@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone');

        $this->assertCount(0, $this->gmailSent);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->data()['to'] === ['customer@example.test']
            && $request->data()['from'] === 'Zap Zone <news@zap-zone.com>'
            && $request->data()['reply_to'] === 'bookings@zap-zone.com'
            && $request->data()['headers'] === [
                'List-Unsubscribe' => '<https://api.zapzone.test/api/email-unsubscribe/u.abc>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ]);
        Http::assertSent(fn (Request $request) => $request->data()['to'] === ['guest@example.test']
            && $request->data()['from'] === 'Zap Zone <bookings@zap-zone.com>'
            && !array_key_exists('headers', $request->data()));
    }

    public function test_when_resend_refuses_a_bulk_email_it_fails_instead_of_using_up_gmail(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['statusCode' => 429, 'message' => 'You have reached your monthly email sending quota.', 'name' => 'monthly_quota_exceeded'], 429)]);
        Log::spy();

        try {
            $this->gmail()->sendEmail('customer@example.test', 'Spring sale', '<p>20% off</p>', 'Zap Zone');
            $this->fail('A refused bulk email must be reported as failed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('monthly_quota_exceeded', $e->getMessage());
        }

        $this->assertCount(0, $this->gmailSent);
        Http::assertSentCount(1);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'bulk email not sent')
            && str_contains($context['error'], 'monthly_quota_exceeded'))->once();
        Log::shouldNotHaveReceived('error');
    }

    public function test_email_stays_on_gmail_while_resend_is_switched_off_or_has_no_key(): void
    {
        $this->acceptAll();

        config(['services.resend.enabled' => false]);
        $this->gmail()->sendTransactional('guest@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone');
        $this->gmail()->sendEmail('customer@example.test', 'Spring sale', '<p>20% off</p>', 'Zap Zone');

        config(['services.resend.enabled' => true, 'services.resend.key' => '']);
        $this->gmail()->sendTransactional('guest@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone');
        $this->gmail()->sendEmail('customer@example.test', 'Spring sale', '<p>20% off</p>', 'Zap Zone');

        $this->assertCount(4, $this->gmailSent);
        Http::assertNothingSent();
    }

    public function test_a_customer_campaign_goes_through_resend_and_its_one_click_unsubscribe_works(): void
    {
        $this->realGmailConfig();
        config(['app.url' => 'https://api.zapzone.test/', 'services.resend.marketing_from_address' => 'news@zap-zone.com']);
        $this->acceptAll();
        $company = $this->company();

        $first = $this->campaign($company, ['pat@example.test', 'lee@example.test']);
        $this->assertSame(2, app(EmailCampaignSender::class)->sendCampaign($first));

        Http::assertSentCount(2);
        $patRequest = collect(Http::recorded())->map(fn ($pair) => $pair[0])->first(fn (Request $request) => $request->data()['to'] === ['pat@example.test']);
        $data = $patRequest->data();
        $this->assertSame('Zap Zone Brighton <news@zap-zone.com>', $data['from']);
        $this->assertSame('List-Unsubscribe=One-Click', $data['headers']['List-Unsubscribe-Post']);
        $this->assertMatchesRegularExpression('#^<https://api\.zapzone\.test/api/email-unsubscribe/([^>/]+)>$#', $data['headers']['List-Unsubscribe']);
        $this->assertStringContainsString('Unsubscribe', $data['html']);
        preg_match('#/api/email-unsubscribe/([^>]+)>#', $data['headers']['List-Unsubscribe'], $match);
        $this->assertSame(
            ['company_id' => $company->id, 'email' => 'pat@example.test', 'notification_id' => null],
            app(VisitFollowUpService::class)->parseOptOutToken(rawurldecode($match[1]))
        );
        $this->assertSame(['sent', 'sent'], EmailCampaignLog::where('email_campaign_id', $first->id)->orderBy('id')->pluck('status')->all());

        $this->post('/api/email-unsubscribe/' . $match[1], ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->assertTrue(FollowUpOptOut::hasOptedOut($company->id, 'pat@example.test'));
        $this->assertFalse(FollowUpOptOut::hasOptedOut($company->id, 'lee@example.test'));

        $second = $this->campaign($company, ['pat@example.test', 'lee@example.test']);
        app(EmailCampaignSender::class)->sendCampaign($second);

        Http::assertSentCount(3);
        $this->assertSame(
            ['pat@example.test' => 'Not sent: this person unsubscribed.', 'lee@example.test' => null],
            EmailCampaignLog::where('email_campaign_id', $second->id)->orderBy('id')->pluck('error_message', 'recipient_email')->all()
        );
    }

    public function test_a_campaign_email_resend_refuses_is_marked_failed_with_the_reason_and_the_rest_still_go_out(): void
    {
        $this->realGmailConfig();
        Http::fake(['api.resend.com/*' => Http::sequence()
            ->push(['statusCode' => 422, 'message' => 'Invalid `to` field.', 'name' => 'validation_error'], 422)
            ->push(['id' => 'email-456'], 200)]);
        $company = $this->company();

        $campaign = $this->campaign($company, ['pat@example.test', 'lee@example.test']);
        app(EmailCampaignSender::class)->sendCampaign($campaign);

        $logs = EmailCampaignLog::where('email_campaign_id', $campaign->id)->orderBy('id')->get();
        $this->assertSame([EmailCampaignLog::STATUS_FAILED, EmailCampaignLog::STATUS_SENT], $logs->pluck('status')->all());
        $this->assertStringContainsString('Resend refused the email (HTTP 422, validation_error): Invalid `to` field.', $logs[0]->error_message);
        $this->assertSame([1, 1], [$campaign->fresh()->sent_count, $campaign->fresh()->failed_count]);
    }

    public function test_the_one_click_unsubscribe_address_takes_a_burst_from_one_mail_provider(): void
    {
        $company = $this->company();
        $followUps = app(VisitFollowUpService::class);

        foreach (range(1, 40) as $n) {
            $this->post('/api/email-unsubscribe/' . $followUps->optOutToken($company->id, "guest{$n}@example.test"), ['List-Unsubscribe' => 'One-Click'])->assertOk();
        }

        $this->assertTrue(FollowUpOptOut::hasOptedOut($company->id, 'guest40@example.test'));
        $this->post('/api/email-unsubscribe/u.forged.token', ['List-Unsubscribe' => 'One-Click'])->assertNotFound();
    }

    public function test_a_mail_app_that_opens_the_unsubscribe_link_in_a_browser_lands_on_the_unsubscribe_page(): void
    {
        config(['app.frontend_url' => 'https://book.zapzone.test/']);
        $company = $this->company();
        $token = app(VisitFollowUpService::class)->optOutToken($company->id, 'pat@example.test');

        $this->get('/api/email-unsubscribe/' . $token)->assertRedirect('https://book.zapzone.test/feedback/' . $token . '?unsubscribe=1');

        $this->assertFalse(FollowUpOptOut::hasOptedOut($company->id, 'pat@example.test'));
    }

    public function test_a_blank_sender_name_falls_back_to_the_business_name(): void
    {
        config(['gmail.sender_name' => 'Zap Zone']);
        $this->acceptAll();

        $this->gmail()->sendTransactional('guest@example.test', 'Receipt', '<p>Thanks</p>', '');
        $this->gmail()->sendEmail('guest@example.test', 'Offer', '<p>Sale</p>', " \n ");

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->data()['subject'] === 'Receipt' && $request->data()['from'] === 'Zap Zone <bookings@zap-zone.com>');
        Http::assertSent(fn (Request $request) => $request->data()['subject'] === 'Offer' && $request->data()['from'] === 'Zap Zone <bookings@zap-zone.com>');
    }

    public function test_booking_confirmations_are_sent_as_transactional_and_follow_ups_are_not(): void
    {
        $company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'zapzonetest@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);
        $location = Location::create([
            'company_id' => $company->id,
            'name' => 'Brighton',
            'address' => '8053 Challis Rd',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => 'brighton@zapzone.test',
        ]);
        $package = Package::create([
            'location_id' => $location->id,
            'name' => 'Ultimate Party',
            'description' => 'Two hours of fun',
            'category' => 'Parties',
            'price' => 200,
            'duration' => 2,
        ]);
        $booking = Booking::create([
            'reference_number' => 'RESEND-0001',
            'location_id' => $location->id,
            'package_id' => $package->id,
            'booking_date' => now()->addDays(3)->toDateString(),
            'booking_time' => '14:00:00',
            'participants' => 10,
            'duration' => 2,
            'total_amount' => 200,
            'guest_name' => 'Gina Guest',
            'guest_email' => 'guest@example.test',
            'status' => 'confirmed',
        ]);

        foreach ([EmailNotification::TRIGGER_BOOKING_CONFIRMED, EmailNotification::TRIGGER_BOOKING_FOLLOWUP] as $trigger) {
            EmailNotification::create([
                'company_id' => $company->id,
                'name' => 'Guest ' . $trigger,
                'trigger_type' => $trigger,
                'entity_type' => EmailNotification::ENTITY_ALL,
                'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
                'subject' => 'About your party',
                'body' => 'Hello',
                'is_active' => true,
            ]);
        }

        $sent = [];
        $this->partialMock(EmailNotificationService::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('sendEmail')->andReturnUsing(function ($to, $subject, $html, $variables, $attachments = [], $transactional = false) use (&$sent) {
                $sent[] = [$to, $transactional];
            });
        });

        app(EmailNotificationService::class)->triggerBookingNotification($booking, EmailNotification::TRIGGER_BOOKING_CONFIRMED);
        $confirmations = $sent;

        $sent = [];
        app(EmailNotificationService::class)->triggerBookingNotification($booking, EmailNotification::TRIGGER_BOOKING_FOLLOWUP);
        $followUps = $sent;

        $this->assertContains(['guest@example.test', true], $confirmations);
        $this->assertNotContains(false, array_column($confirmations, 1));
        $this->assertSame([['guest@example.test', false]], $followUps);
    }

    public function test_the_notification_service_asks_gmail_for_the_matching_kind_of_send(): void
    {
        config(['gmail.enabled' => true, 'gmail.credentials.client_email' => 'sender@zapzone-project.iam.gserviceaccount.com']);

        $gmail = Mockery::mock(GmailApiService::class);
        $gmail->shouldReceive('sendTransactional')->once()->with('guest@example.test', 'Receipt', '<p>Thanks</p>', 'Zap Zone Brighton', [], []);
        $gmail->shouldReceive('sendEmail')->once()->with('guest@example.test', 'Come back soon', '<p>Promo</p>', 'Zap Zone Brighton', [], ['List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);

        $service = new EmailNotificationService();
        (new \ReflectionProperty(EmailNotificationService::class, 'gmailService'))->setValue($service, $gmail);

        $service->sendEmail('guest@example.test', 'Receipt', '<p>Thanks</p>', ['company_name' => 'Zap Zone Brighton'], [], true);
        $service->sendEmail('guest@example.test', 'Come back soon', '<p>Promo</p>', ['company_name' => 'Zap Zone Brighton'], [], false, ['List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
    }

    public function test_the_test_command_reports_what_resend_said(): void
    {
        config(['services.resend.enabled' => false]);
        $this->acceptAll();

        $this->artisan('resend:test', ['to' => 'clark@example.test'])
            ->expectsOutputToContain('no (RESEND_ENABLED is off')
            ->expectsOutputToContain('18 characters, starting re_, ending _key')
            ->expectsOutputToContain('Resend accepted the email (id email-123)')
            ->assertExitCode(0);

        Http::assertSent(fn (Request $request) => $request->data()['to'] === ['clark@example.test']);
    }

    public function test_the_test_command_can_send_the_way_bulk_email_goes_out(): void
    {
        config(['services.resend.marketing_from_address' => 'news@zap-zone.com', 'app.url' => 'https://api.zapzone.test']);
        $this->acceptAll();

        $this->artisan('resend:test', ['to' => 'clark@example.test', '--bulk' => true])
            ->expectsOutputToContain('Bulk From:    news@zap-zone.com')
            ->expectsOutputToContain('Unsubscribe:  https://api.zapzone.test/api/email-unsubscribe/')
            ->assertExitCode(0);

        Http::assertSent(fn (Request $request) => $request->data()['from'] === '"Zap Zone, test" <news@zap-zone.com>'
            && $request->data()['headers']['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click'
            && $request->data()['headers']['List-Unsubscribe'] === '<https://api.zapzone.test/api/email-unsubscribe/resend-test>'
            && str_contains($request->data()['html'], 'cid:resend-test-image')
            && $request->data()['attachments'] === [
                ['filename' => 'resend-test.txt', 'content' => base64_encode("ZapZone Resend test attachment\n"), 'content_type' => 'text/plain'],
                ['filename' => 'resend-test.png', 'content' => \App\Console\Commands\SendResendTest::TEST_IMAGE, 'content_type' => 'image/png', 'content_id' => 'resend-test-image'],
            ]);
    }

    public function test_the_test_command_shows_the_refusal_and_fails(): void
    {
        Http::fake(['api.resend.com/*' => Http::response([
            'statusCode' => 403,
            'message' => 'The zap-zone.com domain is not verified.',
            'name' => 'validation_error',
        ], 403)]);

        $this->artisan('resend:test', ['to' => 'clark@example.test'])
            ->expectsOutputToContain('The zap-zone.com domain is not verified.')
            ->assertExitCode(1);

        $this->assertCount(0, $this->gmailSent);
    }

    private function realGmailConfig(): void
    {
        config([
            'gmail.enabled' => true,
            'gmail.credentials.client_email' => 'sender@zapzone-project.iam.gserviceaccount.com',
            'gmail.credentials.private_key' => 'not-a-real-key',
        ]);
    }

    private function company(): Company
    {
        return Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'zapzonetest@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);
    }

    private function campaign(Company $company, array $emails): EmailCampaign
    {
        $admin = User::firstOrCreate(['email' => 'admin@zapzone.test'], [
            'first_name' => 'Admin',
            'last_name' => 'Staff',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $company->id,
        ]);

        $campaign = EmailCampaign::create([
            'company_id' => $company->id,
            'sent_by' => $admin->id,
            'name' => 'Fall sale',
            'subject' => 'Fall sale at {{company_name}}',
            'body' => '<p>20% off this weekend</p>',
            'recipient_types' => ['customers'],
            'total_recipients' => count($emails),
            'status' => EmailCampaign::STATUS_PENDING,
        ]);

        foreach ($emails as $email) {
            EmailCampaignLog::create([
                'email_campaign_id' => $campaign->id,
                'recipient_email' => $email,
                'recipient_type' => 'customer',
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
