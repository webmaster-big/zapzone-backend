<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\Photo;
use App\Models\PhotoDelivery;
use App\Models\PhotoSession;
use App\Models\SmsSenderVerification;
use App\Models\User;
use App\Models\Waiver;
use App\Models\WaiverProfile;
use App\Models\WaiverTemplate;
use App\Services\SmsService;
use App\Services\WaiverService;
use App\Support\OperatingDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class PhotoTextDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const SENDER = '+18559245172';

    private Company $company;

    private Location $location;

    private User $admin;

    private User $manager;

    private FakeTwilioClient $twilio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        config([
            'twilio.sid' => 'AC' . str_repeat('1', 32),
            'twilio.auth_token' => 'test-token',
            'twilio.from_number' => self::SENDER,
            'app.frontend_url' => 'https://book.zapzone.test',
        ]);

        $this->twilio = new FakeTwilioClient();
        $twilio = $this->twilio;
        $this->app->bind(SmsService::class, fn () => new FakeTwilioSmsService($twilio));

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'ZapZone Waterford',
            'address' => '1 Test Way',
            'city' => 'Waterford',
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => 'waterford@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $this->admin = $this->staffUser('company_admin', 'admin@zapzone.test');
        $this->manager = $this->staffUser('location_manager', 'manager@zapzone.test');
    }

    private function staffUser(string $role, string $email): User
    {
        return User::create([
            'first_name' => ucfirst(str_replace('_', ' ', $role)),
            'last_name' => 'Tester',
            'email' => $email,
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    private function waiver(string $email = 'guest@example.test', ?string $phone = '8105889748', string $first = 'Jordan'): Waiver
    {
        static $template = null;

        if ($template === null || !WaiverTemplate::whereKey($template->id)->exists()) {
            $template = WaiverTemplate::create([
                'company_id' => $this->company->id,
                'title' => 'General waiver',
                'status' => WaiverTemplate::STATUS_ACTIVE,
                'is_default' => true,
                'body_text' => '<p>I release {{company_name}}.</p>',
                'electronic_consent_enabled' => true,
            ]);
            app(WaiverService::class)->syncVersion($template);
            $template = $template->fresh();
        }

        return Waiver::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'waiver_template_id' => $template->id,
            'waiver_template_version_id' => $template->versions()->first()->id,
            'status' => Waiver::STATUS_COMPLETED,
            'source' => Waiver::SOURCE_KIOSK,
            'adult_first_name' => $first,
            'adult_last_name' => 'Rivera',
            'adult_email' => $email,
            'adult_phone' => $phone,
            'selected_date' => now()->toDateString(),
            'submitted_at' => now(),
        ]);
    }

    private function staffSession(): PhotoSession
    {
        $session = PhotoSession::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'source' => PhotoSession::SOURCE_STAFF,
            'status' => PhotoSession::STATUS_IN_PROGRESS,
            'created_by' => $this->admin->id,
            'verbal_consent_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);

        Photo::create([
            'photo_session_id' => $session->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'source' => Photo::SOURCE_CAMERA,
            'processing_status' => Photo::PROCESSING_READY,
            'original_path' => 'x/original.jpg',
            'delivery_path' => 'x/delivery.jpg',
            'slideshow_path' => 'x/slideshow.jpg',
            'thumbnail_path' => 'x/thumb.jpg',
            'captured_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);

        return $session;
    }

    private function deliver(PhotoSession $session, array $waiverIds, ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->admin, 'sanctum')
            ->postJson("/api/photo-sessions/{$session->id}/deliver", [
                'method' => 'waiver_message',
                'schedule' => 'immediate',
                'waiver_ids' => $waiverIds,
            ]);
    }

    private function textRow(PhotoSession $session): PhotoDelivery
    {
        return PhotoDelivery::where('photo_session_id', $session->id)->where('channel', 'sms')->whereNull('duplicate_of_id')->firstOrFail();
    }

    private function emailRow(PhotoSession $session): PhotoDelivery
    {
        return PhotoDelivery::where('photo_session_id', $session->id)->where('channel', 'email')->whereNull('duplicate_of_id')->firstOrFail();
    }

    public function test_a_staff_send_reports_that_the_text_is_waiting_for_the_carrier_instead_of_claiming_success(): void
    {
        $session = $this->staffSession();
        $waiver = $this->waiver();

        $response = $this->deliver($session, [$waiver->id])->assertOk();

        $this->assertSame('sent', $response->json('outcome.result'));
        $this->assertSame('Sent, waiting for the carrier', $response->json('outcome.headline'));
        $this->assertSame(1, $response->json('outcome.carrier_pending'));
        $this->assertStringNotContainsString('every available contact method', (string) $response->json('message'));

        $text = $this->textRow($session);
        $this->assertSame('sent', $text->status);
        $this->assertSame($this->twilio->messages->sent[0]['sid'], $text->provider_sid);
        $this->assertSame(self::SENDER, $text->provider_from);
        $this->assertSame('queued', $text->provider_status);
        $this->assertSame(PhotoDelivery::OUTCOME_AWAITING, $text->outcome());
        $this->assertSame('sent', $this->emailRow($session)->status);

        $channels = collect($response->json('data.deliveries'))->keyBy('channel');
        $this->assertSame('awaiting_carrier', $channels['sms']['outcome']);
        $this->assertSame('Waiting for the carrier to confirm', $channels['sms']['outcome_label']);
        $this->assertTrue($channels['sms']['awaiting_carrier']);
        $this->assertFalse($channels['sms']['can_confirm_receipt']);
        $this->assertSame('sent', $channels['email']['outcome']);
        $this->assertFalse($response->json('outcome.ask_for_receipt'));
        $this->assertSame('unverified', $response->json('outcome.texting_state'));

        $this->twilio->statuses[$text->provider_sid] = ['status' => 'sent'];
        $refreshed = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-deliveries/status', ['delivery_ids' => $response->json('outcome.delivery_ids')])
            ->assertOk();
        $this->assertTrue(collect($refreshed->json('data.deliveries'))->firstWhere('channel', 'sms')['can_confirm_receipt']);
        $this->assertTrue($refreshed->json('data.outcome.ask_for_receipt'));
    }

    public function test_every_photo_text_carries_its_own_link_reference(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();

        $text = $this->textRow($session);
        $body = $this->twilio->messages->sent[0]['body'];

        $this->assertStringContainsString('https://book.zapzone.test/photos/' . $session->access_token . '?r=' . $text->linkRef(), $body);
        $this->assertSame($text->id, PhotoDelivery::fromLinkRef($session->id, $text->linkRef())?->id);
        $this->assertNull(PhotoDelivery::fromLinkRef($session->id, $text->linkRef() . 'x'));
        $this->assertNull(PhotoDelivery::fromLinkRef($session->id, substr($text->linkRef(), 0, -1) . (str_ends_with($text->linkRef(), '0') ? '1' : '0')));
        $this->assertNull(PhotoDelivery::fromLinkRef($session->id + 1, $text->linkRef()));
    }

    public function test_a_text_the_provider_refuses_is_a_partial_send_with_a_plain_reason(): void
    {
        $this->twilio->messages->failWith = new \Twilio\Exceptions\RestException("Unable to create record: The 'To' number +18105889748 is not a valid phone number.", 21211, 400);

        $session = $this->staffSession();
        $response = $this->deliver($session, [$this->waiver()->id])->assertOk();

        $this->assertSame('partial', $response->json('outcome.result'));
        $this->assertSame('Sent, but not by every method', $response->json('outcome.headline'));

        $text = $this->textRow($session);
        $this->assertSame('failed', $text->status);
        $this->assertSame('21211', $text->provider_error_code);
        $this->assertNull($text->provider_sid);
        $this->assertStringStartsWith('The phone number is not valid.', $text->error);

        $sms = collect($response->json('data.deliveries'))->firstWhere('channel', 'sms');
        $this->assertSame('failed', $sms['outcome']);
        $this->assertSame('danger', $sms['outcome_tone']);
        $this->assertStringStartsWith('The phone number is not valid.', $sms['outcome_detail']);
    }

    public function test_a_permanent_refusal_is_not_retried_automatically_but_a_passing_one_is(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);

        $text->forceFill([
            'status' => PhotoDelivery::STATUS_FAILED,
            'attempts' => 1,
            'provider_error_code' => '21610',
            'updated_at' => now()->subHour(),
        ])->saveQuietly();
        $this->assertFalse(PhotoDelivery::retryable()->whereKey($text->id)->exists());

        $text->forceFill(['provider_error_code' => '30001', 'updated_at' => now()->subHours(2)])->saveQuietly();
        $this->assertTrue(PhotoDelivery::retryable()->whereKey($text->id)->exists());

        $text->forceFill(['provider_error_code' => null, 'updated_at' => now()->subHours(3)])->saveQuietly();
        $this->assertTrue(PhotoDelivery::retryable()->whereKey($text->id)->exists());
    }

    public function test_nothing_sent_is_reported_as_a_failure(): void
    {
        config(['mail.default' => 'log']);
        $this->twilio->messages->failWith = new \Twilio\Exceptions\RestException('Unable to create record: Attempt to send to unsubscribed recipient', 21610, 400);

        $session = $this->staffSession();
        $response = $this->deliver($session, [$this->waiver()->id])->assertOk();

        $this->assertSame('failed', $response->json('outcome.result'));
        $this->assertSame('The photos could not be sent', $response->json('outcome.headline'));
        $this->assertSame('skipped', $this->emailRow($session)->status);
        $this->assertStringStartsWith('This guest replied STOP', $this->textRow($session)->error);
    }

    public function test_the_carrier_answer_updates_the_row_and_the_summary(): void
    {
        $session = $this->staffSession();
        $response = $this->deliver($session, [$this->waiver()->id])->assertOk();
        $ids = $response->json('outcome.delivery_ids');
        $text = $this->textRow($session);

        $this->twilio->statuses[$text->provider_sid] = ['status' => 'undelivered', 'errorCode' => 30032];

        $refresh = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-deliveries/status', ['delivery_ids' => $ids])
            ->assertOk();

        $sms = collect($refresh->json('data.deliveries'))->firstWhere('channel', 'sms');
        $this->assertSame('not_delivered', $sms['outcome']);
        $this->assertStringContainsStringIgnoringCase('toll-free verification', $sms['outcome_detail']);
        $this->assertFalse($sms['can_confirm_receipt']);
        $this->assertSame('partial', $refresh->json('data.outcome.result'));

        $text->refresh();
        $this->assertSame('undelivered', $text->provider_status);
        $this->assertSame('30032', $text->provider_error_code);
        $this->assertNull($text->delivered_at);
    }

    public function test_a_delivered_text_is_shown_as_delivered_and_stops_being_checked(): void
    {
        $session = $this->staffSession();
        $response = $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);
        $this->twilio->statuses[$text->provider_sid] = ['status' => 'delivered'];

        $refresh = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-deliveries/status', ['delivery_ids' => $response->json('outcome.delivery_ids')])
            ->assertOk();

        $this->assertSame('Photos sent', $refresh->json('data.outcome.headline'));
        $this->assertSame('delivered', collect($refresh->json('data.deliveries'))->firstWhere('channel', 'sms')['outcome']);
        $this->assertNotNull($text->fresh()->delivered_at);

        $this->assertFalse(PhotoDelivery::awaitingCarrier()->whereKey($text->id)->exists());
        $lookups = $this->twilio->lookups;
        $this->travel(20)->seconds();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-deliveries/status', ['delivery_ids' => $response->json('outcome.delivery_ids')])
            ->assertOk();
        $this->assertSame($lookups, $this->twilio->lookups);
    }

    public function test_the_status_check_only_sees_deliveries_in_the_callers_company(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();

        $otherCompany = Company::create([
            'company_name' => 'Other Co',
            'email' => 'other@other.test',
            'phone' => '5550000000',
            'address' => '9 Elsewhere',
        ]);
        $outsider = User::create([
            'first_name' => 'Out',
            'last_name' => 'Sider',
            'email' => 'outsider@other.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $otherCompany->id,
        ]);

        $response = $this->actingAs($outsider, 'sanctum')
            ->postJson('/api/photo-deliveries/status', ['delivery_ids' => [$this->textRow($session)->id]])
            ->assertOk();

        $this->assertSame([], $response->json('data.deliveries'));
        $this->actingAs($outsider, 'sanctum')
            ->postJson('/api/photo-deliveries/' . $this->textRow($session)->id . '/confirm-receipt')
            ->assertForbidden();
    }

    public function test_texting_is_only_confirmed_once_a_guest_has_the_text_on_their_phone(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);

        $context = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-sessions/context?location_id=' . $this->location->id)
            ->assertOk();
        $this->assertSame('unverified', $context->json('data.channels.sms_status.state'));
        $this->assertStringContainsString('not confirmed as working', (string) $context->json('data.channels.sms_note'));

        $this->twilio->statuses[$text->provider_sid] = ['status' => 'delivered'];
        $confirm = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/photo-deliveries/{$text->id}/confirm-receipt")
            ->assertOk();

        $this->assertSame('verified', $confirm->json('texting.state'));
        $this->assertStringContainsString('confirmed working', (string) $confirm->json('message'));
        $this->assertSame('received', $confirm->json('data.outcome'));
        $this->assertSame('Company admin Tester', $confirm->json('data.receipt_confirmed_by_name'));

        $verification = SmsSenderVerification::sole();
        $this->assertSame(self::SENDER, $verification->sender);
        $this->assertSame(SmsSenderVerification::METHOD_STAFF_CONFIRMED, $verification->method);
        $this->assertSame($text->id, $verification->photo_delivery_id);
        $this->assertSame($text->provider_sid, $verification->provider_sid);

        $context = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-sessions/context?location_id=' . $this->location->id)
            ->assertOk();
        $this->assertSame('verified', $context->json('data.channels.sms_status.state'));
        $this->assertNull($context->json('data.channels.sms_note'));

        config(['twilio.from_number' => '+18105550199']);
        $context = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-sessions/context?location_id=' . $this->location->id)
            ->assertOk();
        $this->assertSame('unverified', $context->json('data.channels.sms_status.state'));
    }

    public function test_a_text_the_carrier_did_not_deliver_cannot_be_marked_received(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);
        $text->forceFill(['provider_status' => 'undelivered', 'provider_error_code' => '30032'])->save();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/photo-deliveries/{$text->id}/confirm-receipt")
            ->assertStatus(422);

        $this->assertNull($text->fresh()->receipt_confirmed_at);
        $this->assertSame(0, SmsSenderVerification::count());
    }

    public function test_an_email_cannot_be_used_to_confirm_texting(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-deliveries/' . $this->emailRow($session)->id . '/confirm-receipt')
            ->assertStatus(422);

        $this->assertSame(0, SmsSenderVerification::count());
    }

    public function test_opening_the_link_from_the_text_proves_it_reached_the_phone(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);
        $email = $this->emailRow($session);

        $this->getJson("/api/photos/access/{$session->access_token}")->assertOk();
        $this->assertNull($text->fresh()->opened_at);
        $this->assertNull($email->fresh()->opened_at);

        $this->getJson("/api/photos/access/{$session->access_token}?r=" . $email->linkRef())->assertOk();
        $this->assertNotNull($email->fresh()->opened_at);
        $this->assertNull($text->fresh()->opened_at);
        $this->assertSame(0, SmsSenderVerification::count());

        $this->getJson("/api/photos/access/{$session->access_token}?r=" . $text->linkRef() . 'zz')->assertOk();
        $this->assertNull($text->fresh()->receipt_confirmed_at);

        $this->getJson("/api/photos/access/{$session->access_token}?r=" . $text->linkRef())->assertOk();
        $text->refresh();
        $this->assertNotNull($text->opened_at);
        $this->assertNotNull($text->receipt_confirmed_at);
        $this->assertSame(PhotoDelivery::RECEIPT_VIA_LINK, $text->receipt_confirmed_via);
        $this->assertNull($text->receipt_confirmed_by);
        $this->assertSame(SmsSenderVerification::METHOD_GUEST_OPENED_LINK, SmsSenderVerification::sole()->method);
    }

    public function test_recent_undelivered_texts_override_an_earlier_confirmation(): void
    {
        $first = $this->staffSession();
        $this->deliver($first, [$this->waiver()->id])->assertOk();
        $this->twilio->statuses[$this->textRow($first)->provider_sid] = ['status' => 'delivered'];
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-deliveries/' . $this->textRow($first)->id . '/confirm-receipt')
            ->assertOk();

        $second = $this->staffSession();
        $this->deliver($second, [$this->waiver('other@example.test', '2485550111', 'Sam')->id])->assertOk();
        $this->travel(1)->seconds();
        $this->textRow($second)->forceFill(['provider_status' => 'undelivered', 'provider_error_code' => '30032'])->save();

        $status = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-settings?location_id=' . $this->location->id)
            ->assertOk();

        $this->assertSame('failing', $status->json('data.channels.sms_status.state'));
        $this->assertSame('30032', $status->json('data.channels.sms_status.problem.code'));
        $this->assertNotNull($status->json('data.channels.sms_status.verified'));
        $this->assertStringContainsString('not reaching', (string) $status->json('data.channels.sms_note'));
    }

    public function test_with_texting_off_the_text_is_recorded_as_not_sent_and_the_email_still_goes(): void
    {
        config(['twilio.sid' => null]);

        $session = $this->staffSession();
        $response = $this->deliver($session, [$this->waiver()->id])->assertOk();

        $this->assertSame('sent', $response->json('outcome.result'));
        $this->assertSame('skipped', $this->textRow($session)->status);
        $this->assertSame('Text messaging is not switched on yet.', $this->textRow($session)->error);
        $this->assertSame([], $this->twilio->messages->sent);

        $context = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-sessions/context?location_id=' . $this->location->id)
            ->assertOk();
        $this->assertSame('not_configured', $context->json('data.channels.sms_status.state'));
    }

    public function test_a_family_on_one_number_is_texted_once_and_the_summary_says_so(): void
    {
        $session = $this->staffSession();
        $a = $this->waiver('parent@example.test', '8105889748', 'Alex');
        $b = $this->waiver('kid@example.test', '(810) 588-9748', 'Casey');

        $response = $this->deliver($session, [$a->id, $b->id])->assertOk();

        $this->assertCount(1, $this->twilio->messages->sent);
        $duplicate = collect($response->json('data.deliveries'))
            ->first(fn ($d) => $d['channel'] === 'sms' && $d['is_duplicate']);
        $this->assertSame('duplicate', $duplicate['outcome']);
        $this->assertStringContainsString('only one text went to it', $duplicate['outcome_detail']);
        $this->assertCount(4, $response->json('outcome.delivery_ids'));
    }

    public function test_the_library_resend_reports_the_real_outcome(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $photo = $session->photos()->first();

        $this->twilio->messages->failWith = new \Twilio\Exceptions\RestException('Unable to create record: Permission to send an SMS has not been enabled for the region', 21408, 400);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/photo-library/{$photo->id}/send", ['waiver_ids' => [$this->waiver('again@example.test', '2485550122', 'Riley')->id]])
            ->assertOk();

        $this->assertSame('partial', $response->json('outcome.result'));
        $this->assertStringNotContainsString('normal waiver message delivery flow', (string) $response->json('message'));
    }

    public function test_a_retry_says_what_actually_happened(): void
    {
        $this->twilio->messages->failWith = new \Twilio\Exceptions\RestException('Unable to create record: Invalid To Phone Number', 21211, 400);
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);

        $again = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/photo-deliveries/{$text->id}/retry")
            ->assertStatus(422);
        $this->assertStringContainsString('The phone number is not valid.', (string) $again->json('message'));

        $this->twilio->messages->failWith = null;
        $ok = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/photo-deliveries/{$text->id}/retry")
            ->assertOk();
        $this->assertStringContainsString('phone carrier confirms', (string) $ok->json('message'));
        $this->assertSame('awaiting_carrier', $ok->json('data.outcome'));
        $this->assertNull($text->fresh()->provider_error_code);
    }

    public function test_the_scheduled_check_records_the_carrier_answer(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);
        $this->twilio->statuses[$text->provider_sid] = ['status' => 'delivered'];

        $this->artisan('photos:check-text-delivery')->assertSuccessful();

        $this->assertSame('delivered', $text->fresh()->provider_status);
        $this->assertSame(PhotoDelivery::OUTCOME_DELIVERED, $text->fresh()->outcome());
    }

    public function test_an_old_text_with_no_answer_is_shown_as_unconfirmed_not_as_delivered(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);
        $text->forceFill(['sent_at' => now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS + 1), 'provider_status' => 'sent'])->save();

        $this->assertSame(PhotoDelivery::OUTCOME_UNCONFIRMED, $text->fresh()->outcome());
        $this->assertFalse(PhotoDelivery::awaitingCarrier()->whereKey($text->id)->exists());

        $legacy = $text->replicate();
        $legacy->forceFill(['provider_sid' => null, 'provider_status' => null])->save();
        $this->assertStringContainsString('before delivery tracking', (string) $legacy->fresh()->outcomeDetail());
    }

    public function test_the_settings_test_text_is_checked_with_the_carrier(): void
    {
        $send = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-settings/test-message', [
                'location_id' => $this->location->id,
                'channel' => 'sms',
                'destination' => '(810) 588-9748',
            ])
            ->assertOk();

        $this->assertStringNotContainsString('should arrive within a few seconds', (string) $send->json('message'));
        $sid = $send->json('data.provider_reference');
        $this->assertMatchesRegularExpression('/^SM[0-9a-f]{32}$/', $sid);

        $this->twilio->statuses[$sid] = ['status' => 'undelivered', 'errorCode' => 30032];
        $token = $send->json('data.check_token');
        $status = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-settings/test-message/status', ['location_id' => $this->location->id, 'provider_reference' => $sid, 'check_token' => $token])
            ->assertOk();

        $this->assertTrue($status->json('data.final'));
        $this->assertFalse($status->json('data.delivered'));
        $this->assertStringContainsStringIgnoringCase('toll-free verification', $status->json('data.message'));
        $this->assertSame(0, SmsSenderVerification::count());

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-settings/test-message/status', ['location_id' => $this->location->id, 'provider_reference' => 'not-a-sid', 'check_token' => $token])
            ->assertStatus(422);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/photo-settings/test-message/status', ['location_id' => $this->location->id, 'provider_reference' => $sid, 'check_token' => $token])
            ->assertNotFound();
    }

    public function test_only_an_admin_can_reset_the_texting_confirmation(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $this->twilio->statuses[$this->textRow($session)->provider_sid] = ['status' => 'delivered'];
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-deliveries/' . $this->textRow($session)->id . '/confirm-receipt')
            ->assertOk();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/photo-settings/texting/reset', ['location_id' => $this->location->id])
            ->assertForbidden();

        $reset = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-settings/texting/reset', ['location_id' => $this->location->id, 'reason' => 'Changed the sending number'])
            ->assertOk();

        $this->assertSame('unverified', $reset->json('data.state'));
        $earned = SmsSenderVerification::where('method', SmsSenderVerification::METHOD_STAFF_CONFIRMED)->sole();
        $this->assertNotNull($earned->revoked_at);
        $this->assertSame('Changed the sending number', $earned->revoke_reason);
        $this->assertSame(1, SmsSenderVerification::where('method', SmsSenderVerification::METHOD_RESET)->count());
    }

    public function test_the_kiosk_page_says_which_channels_carried_the_link_and_never_marks_the_text_opened(): void
    {
        $session = PhotoSession::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'source' => PhotoSession::SOURCE_KIOSK,
            'status' => PhotoSession::STATUS_READY,
            'delivery_method' => PhotoSession::DELIVERY_KIOSK_QR,
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);
        $session->startQrWindow();
        $session->save();
        Photo::create([
            'photo_session_id' => $session->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'source' => Photo::SOURCE_KIOSK,
            'processing_status' => Photo::PROCESSING_READY,
            'original_path' => 'x/original.jpg',
            'delivery_path' => 'x/delivery.jpg',
            'slideshow_path' => 'x/slideshow.jpg',
            'thumbnail_path' => 'x/thumb.jpg',
            'captured_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);

        $this->twilio->messages->failWith = new \Twilio\Exceptions\RestException('Unable to create record: not a mobile number', 21614, 400);

        $response = $this->postJson("/api/photos/access/{$session->fresh()->access_token}/contact", [
            'name' => 'Morgan Lee',
            'email' => 'morgan@example.test',
            'phone' => '(810) 588-9748',
        ])->assertOk();

        $this->assertSame(['email' => true, 'sms' => false, 'sms_reliable' => false], $response->json('data.link_sent_by'));
        $this->assertSame(0, PhotoDelivery::where('photo_session_id', $session->id)->whereNotNull('opened_at')->count());
    }

    public function test_the_delivery_report_says_how_each_session_reached_the_guest(): void
    {
        $emailOnly = $this->staffSession();
        $this->deliver($emailOnly, [$this->waiver()->id])->assertOk();
        $this->textRow($emailOnly)->forceFill(['provider_status' => 'undelivered', 'provider_error_code' => '30032'])->save();

        $both = $this->staffSession();
        $this->deliver($both, [$this->waiver('two@example.test', '2485550133', 'Pat')->id])->assertOk();
        $this->textRow($both)->forceFill(['provider_status' => 'delivered'])->save();

        $report = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-reports/delivery?location_id=' . $this->location->id)
            ->assertOk();

        $this->assertSame(1, $report->json('data.sessions_email_sent_and_text_arrived'));
        $this->assertSame(1, $report->json('data.sessions_email_sent_text_did_not_arrive'));
        $this->assertSame(0, $report->json('data.sessions_email_sent_text_unconfirmed'));
        $this->assertSame(0, $report->json('data.sessions_text_arrived_no_email'));
        $this->assertSame(2, $report->json('data.sms_accepted_by_provider'));
        $this->assertSame(1, $report->json('data.sms_delivered_to_phone'));
        $this->assertSame(1, $report->json('data.sms_not_delivered'));
        $this->assertNull($report->json('data.sms_sent'));
    }

    public function test_an_explicitly_foreign_number_is_kept_as_dialled(): void
    {
        $this->assertSame('+4791234567', WaiverProfile::storedPhoneFor('+47 912 34 567'));
        $this->assertSame('+6591234567', WaiverProfile::storedPhoneFor('+65 9123 4567'));
        $this->assertSame('+442079460958', WaiverProfile::storedPhoneFor('0044 20 7946 0958'));
        $this->assertSame('+442079460958', WaiverProfile::storedPhoneFor('+44 20 7946 0958'));
        $this->assertSame('8105889748', WaiverProfile::storedPhoneFor('(810) 588-9748'));
        $this->assertSame('8105889748', WaiverProfile::storedPhoneFor('+1 810 588 9748'));
        $this->assertSame('8105889748', WaiverProfile::storedPhoneFor('1-810-588-9748'));
        $this->assertNull(WaiverProfile::storedPhoneFor('555'));
        $this->assertNull(WaiverProfile::storedPhoneFor('   '));
        $this->assertSame('+4791234567', SmsService::toE164(WaiverProfile::storedPhoneFor('+47 912 34 567')));
    }

    public function test_the_staff_assign_path_stores_a_foreign_number_as_dialled(): void
    {
        $template = WaiverTemplate::create([
            'company_id' => $this->company->id,
            'title' => 'Assign test',
            'status' => WaiverTemplate::STATUS_ACTIVE,
            'is_default' => false,
            'body_text' => '<p>Terms.</p>',
            'electronic_consent_enabled' => true,
        ]);
        app(WaiverService::class)->syncVersion($template);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/waivers/assign', [
                'waiver_template_id' => $template->id,
                'location_id' => $this->location->id,
                'adult_email' => 'visitor@example.test',
                'adult_phone' => '+47 912 34 567',
                'selected_date' => now()->addDay()->toDateString(),
            ])
            ->assertSuccessful();

        $this->assertSame('+4791234567', Waiver::where('adult_email', 'visitor@example.test')->value('adult_phone'));
    }

    public function test_a_text_still_with_the_provider_cannot_be_marked_received(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);
        $this->twilio->statuses[$text->provider_sid] = ['status' => 'queued'];

        $refused = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/photo-deliveries/{$text->id}/confirm-receipt")
            ->assertStatus(422);

        $this->assertStringContainsString('has not received this text yet', (string) $refused->json('message'));
        $this->assertNull($text->fresh()->receipt_confirmed_at);
        $this->assertSame(0, SmsSenderVerification::count());
    }

    public function test_a_later_carrier_rejection_takes_back_a_staff_confirmation(): void
    {
        $session = $this->staffSession();
        $response = $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);

        $confirm = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/photo-deliveries/{$text->id}/confirm-receipt")
            ->assertOk();
        $this->assertSame('unverified', $confirm->json('texting.state'));
        $this->assertStringContainsString('once the phone carrier reports it delivered, or after 72 hours', (string) $confirm->json('message'));
        $this->assertSame(0, SmsSenderVerification::count());
        $this->assertTrue(PhotoDelivery::awaitingCarrier()->whereKey($text->id)->exists());

        $this->twilio->statuses[$text->provider_sid] = ['status' => 'undelivered', 'errorCode' => 30032];
        $this->travel(20)->seconds();

        $refresh = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-deliveries/status', ['delivery_ids' => $response->json('outcome.delivery_ids')])
            ->assertOk();

        $text->refresh();
        $this->assertNull($text->receipt_confirmed_at);
        $this->assertNull($text->receipt_confirmed_via);
        $this->assertSame(PhotoDelivery::OUTCOME_NOT_DELIVERED, $text->outcome());
        $this->assertSame(0, SmsSenderVerification::count());
        $this->assertSame('not_delivered', collect($refresh->json('data.deliveries'))->firstWhere('channel', 'sms')['outcome']);

        $state = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-sessions/context?location_id=' . $this->location->id)
            ->json('data.channels.sms_status.state');
        $this->assertSame('failing', $state);
    }

    public function test_resending_a_received_text_tracks_the_new_message_from_scratch(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);
        $this->getJson("/api/photos/access/{$session->access_token}?r=" . $text->linkRef())->assertOk();
        $this->assertNotNull($text->fresh()->receipt_confirmed_at);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-deliveries/{$text->id}/retry")->assertOk();

        $text->refresh();
        $this->assertNull($text->receipt_confirmed_at);
        $this->assertNull($text->opened_at);
        $this->assertSame(PhotoDelivery::OUTCOME_AWAITING, $text->outcome());
        $this->assertSame($this->twilio->messages->sent[1]['sid'], $text->provider_sid);
    }

    public function test_a_large_group_send_can_still_be_checked(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $ids = array_merge(PhotoDelivery::pluck('id')->all(), range(100000, 100060));

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-deliveries/status', ['delivery_ids' => $ids])
            ->assertOk();
    }

    public function test_a_check_that_started_before_a_resend_cannot_overwrite_the_new_message(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $stale = $this->textRow($session);
        $this->twilio->statuses[$stale->provider_sid] = ['status' => 'undelivered', 'errorCode' => 30032];

        PhotoDelivery::whereKey($stale->id)->update(['provider_sid' => 'SM' . str_repeat('b', 32), 'provider_status' => 'queued']);

        app(\App\Services\PhotoDeliveryService::class)->refreshCarrierStatus($stale, true);

        $row = $stale->fresh();
        $this->assertSame('SM' . str_repeat('b', 32), $row->provider_sid);
        $this->assertSame('queued', $row->provider_status);
        $this->assertNull($row->provider_error_code);
    }

    public function test_the_carrier_is_not_asked_again_within_ten_seconds(): void
    {
        $session = $this->staffSession();
        $response = $this->deliver($session, [$this->waiver()->id])->assertOk();
        $ids = $response->json('outcome.delivery_ids');

        $this->travel(20)->seconds();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/photo-deliveries/status', ['delivery_ids' => $ids])->assertOk();
        $this->assertSame(1, $this->twilio->lookups);

        $this->travel(9)->seconds();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/photo-deliveries/status', ['delivery_ids' => $ids])->assertOk();
        $this->assertSame(1, $this->twilio->lookups);

        $this->travel(2)->seconds();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/photo-deliveries/status', ['delivery_ids' => $ids])->assertOk();
        $this->assertSame(2, $this->twilio->lookups);
    }

    public function test_guests_with_bad_numbers_do_not_make_texting_look_broken(): void
    {
        $this->twilio->messages->failWith = new \Twilio\Exceptions\RestException('Unable to create record: Invalid To Phone Number', 21211, 400);

        foreach ([['a@example.test', '8105550101'], ['b@example.test', '8105550102'], ['c@example.test', '8105550103']] as [$email, $phone]) {
            $session = $this->staffSession();
            $this->deliver($session, [$this->waiver($email, $phone, 'Guest')->id])->assertOk();
        }

        $state = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-sessions/context?location_id=' . $this->location->id)
            ->json('data.channels.sms_status.state');
        $this->assertSame('unverified', $state);
    }

    public function test_three_missed_texts_in_a_row_mean_texting_is_failing_whatever_the_code(): void
    {
        foreach ([['a@example.test', '8105550101'], ['b@example.test', '8105550102'], ['c@example.test', '8105550103']] as $i => [$email, $phone]) {
            $session = $this->staffSession();
            $this->deliver($session, [$this->waiver($email, $phone, 'Guest')->id])->assertOk();
            $this->textRow($session)->forceFill(['provider_status' => 'undelivered', 'provider_error_code' => '30007', 'sent_at' => now()->addSeconds($i)])->save();
        }

        $status = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-sessions/context?location_id=' . $this->location->id)
            ->json('data.channels.sms_status');
        $this->assertSame('failing', $status['state']);
        $this->assertSame('30007', $status['problem']['code']);
    }

    public function test_failures_from_an_old_sending_number_stop_counting_after_it_is_replaced(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $this->textRow($session)->forceFill(['provider_status' => 'undelivered', 'provider_error_code' => '30032'])->save();

        $this->assertSame('failing', app(\App\Services\PhotoDeliveryService::class)->textingStatus($this->company->id)['state']);

        config(['twilio.from_number' => '+12485550199']);
        $this->assertSame('unverified', app(\App\Services\PhotoDeliveryService::class)->textingStatus($this->company->id)['state']);
    }

    public function test_a_link_open_does_not_hide_a_newer_sender_block(): void
    {
        $first = $this->staffSession();
        $this->deliver($first, [$this->waiver()->id])->assertOk();
        $old = $this->textRow($first);
        $old->forceFill(['provider_status' => 'delivered', 'sent_at' => now()->subHours(2)])->save();

        $second = $this->staffSession();
        $this->deliver($second, [$this->waiver('other@example.test', '2485550111', 'Sam')->id])->assertOk();
        $this->textRow($second)->forceFill(['provider_status' => 'undelivered', 'provider_error_code' => '30032', 'sent_at' => now()->subHour()])->save();

        $this->travel(5)->minutes();
        $this->getJson("/api/photos/access/{$first->access_token}?r=" . $old->linkRef())->assertOk();

        $this->assertSame('failing', app(\App\Services\PhotoDeliveryService::class)->textingStatus($this->company->id)['state']);
    }

    public function test_the_test_status_check_only_answers_for_the_callers_own_test(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-settings/test-message/status', [
                'location_id' => $this->location->id,
                'provider_reference' => 'SM' . str_repeat('c', 32),
                'check_token' => now()->addMinutes(10)->getTimestamp() . '.' . str_repeat('0', 64),
            ])
            ->assertNotFound();
    }

    public function test_an_array_link_reference_is_ignored_rather_than_crashing(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();

        $this->getJson("/api/photos/access/{$session->access_token}?r[]=x")->assertOk();
        $this->assertSame(0, PhotoDelivery::whereNotNull('opened_at')->count());
    }

    public function test_the_log_can_be_filtered_by_what_happened_to_the_text(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $this->textRow($session)->forceFill(['provider_status' => 'undelivered', 'provider_error_code' => '30032'])->save();

        $notDelivered = $this->actingAs($this->admin, 'sanctum')->getJson('/api/photo-deliveries?outcome=not_delivered')->assertOk();
        $reached = $this->actingAs($this->admin, 'sanctum')->getJson('/api/photo-deliveries?outcome=reached_phone')->assertOk();

        $this->assertCount(1, $notDelivered->json('data.data'));
        $this->assertSame('sms', $notDelivered->json('data.data.0.channel'));
        $this->assertCount(0, $reached->json('data.data'));
    }

    public function test_the_kiosk_page_does_not_promise_a_text_that_texting_cannot_yet_vouch_for(): void
    {
        $session = PhotoSession::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'source' => PhotoSession::SOURCE_KIOSK,
            'status' => PhotoSession::STATUS_READY,
            'delivery_method' => PhotoSession::DELIVERY_KIOSK_QR,
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);
        $session->startQrWindow();
        $session->save();
        Photo::create([
            'photo_session_id' => $session->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'source' => Photo::SOURCE_KIOSK,
            'processing_status' => Photo::PROCESSING_READY,
            'original_path' => 'x/original.jpg',
            'delivery_path' => 'x/delivery.jpg',
            'slideshow_path' => 'x/slideshow.jpg',
            'thumbnail_path' => 'x/thumb.jpg',
            'captured_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);

        $response = $this->postJson("/api/photos/access/{$session->fresh()->access_token}/contact", [
            'name' => 'Morgan Lee',
            'email' => 'morgan@example.test',
            'phone' => '(810) 588-9748',
        ])->assertOk();

        $this->assertSame(['email' => true, 'sms' => true, 'sms_reliable' => false], $response->json('data.link_sent_by'));

        PhotoDelivery::where('photo_session_id', $session->id)->where('channel', 'sms')
            ->update(['provider_status' => 'undelivered', 'provider_error_code' => '30032']);
        $again = $this->postJson("/api/photos/access/{$session->access_token}/contact", [])->assertOk();
        $this->assertFalse($again->json('data.link_sent_by.sms'));
    }

    public function test_a_plus_sign_in_front_of_a_north_american_number_asks_for_the_country_code(): void
    {
        $this->assertNull(WaiverProfile::storedPhoneFor('+248 555 0142'));
        $this->assertTrue(WaiverProfile::needsCountryCode('+248 555 0142'));
        $this->assertFalse(WaiverProfile::needsCountryCode('+47 912 34 567'));
        $this->assertFalse(WaiverProfile::needsCountryCode('(248) 555-0142'));
        $this->assertSame('2485550142', WaiverProfile::storedPhoneFor('+1 248 555 0142'));

        $template = WaiverTemplate::create([
            'company_id' => $this->company->id,
            'title' => 'Assign ambiguity',
            'status' => WaiverTemplate::STATUS_ACTIVE,
            'is_default' => false,
            'body_text' => '<p>Terms.</p>',
            'electronic_consent_enabled' => true,
        ]);
        app(WaiverService::class)->syncVersion($template);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/waivers/assign', [
                'waiver_template_id' => $template->id,
                'location_id' => $this->location->id,
                'adult_email' => 'ambiguous@example.test',
                'adult_phone' => '+248 555 0142',
                'selected_date' => now()->addDay()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.adult_phone.0', WaiverProfile::COUNTRY_CODE_NEEDED);
    }

    public function test_the_escape_room_board_gets_channel_notes_without_scanning_every_company(): void
    {
        $other = Company::create([
            'company_name' => 'Other Co',
            'email' => 'other@other.test',
            'phone' => '5550000000',
            'address' => '9 Elsewhere',
        ]);
        $otherLocation = Location::create([
            'company_id' => $other->id,
            'name' => 'Other Place',
            'address' => '9 Elsewhere',
            'city' => 'Flint',
            'state' => 'MI',
            'zip_code' => '48502',
            'phone' => '8105550000',
            'email' => 'other-place@other.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $this->textRowFor($other->id, $otherLocation->id, ['provider_status' => 'delivered', 'delivered_at' => now()]);

        $diagnostics = app(\App\Services\PhotoDeliveryService::class)->channelDiagnostics();

        $this->assertSame('unverified', $diagnostics['sms_status']['state']);
        $this->assertSame(0, $diagnostics['sms_status']['recent']['attempted']);
        $this->assertArrayHasKey('email_note', $diagnostics);
    }

    private function textRowFor(int $companyId, int $locationId, array $overrides = []): PhotoDelivery
    {
        $session = PhotoSession::create([
            'company_id' => $companyId,
            'location_id' => $locationId,
            'source' => PhotoSession::SOURCE_STAFF,
            'status' => PhotoSession::STATUS_READY,
            'delivery_method' => PhotoSession::DELIVERY_WAIVER_MESSAGE,
            'verbal_consent_at' => now(),
            'capture_date' => now()->toDateString(),
            'operating_day' => now()->toDateString(),
        ]);

        return PhotoDelivery::create(array_merge([
            'photo_session_id' => $session->id,
            'company_id' => $companyId,
            'location_id' => $locationId,
            'kind' => PhotoDelivery::KIND_IMMEDIATE,
            'channel' => PhotoDelivery::CHANNEL_SMS,
            'destination' => '+18105550100',
            'status' => PhotoDelivery::STATUS_SENT,
            'sent_at' => now(),
            'provider_sid' => 'SM' . md5(uniqid('', true)),
            'provider_from' => self::SENDER,
            'provider_status' => 'sent',
        ], $overrides));
    }

    public function test_a_staff_confirmation_becomes_texting_confirmed_once_the_carrier_says_delivered(): void
    {
        $session = $this->staffSession();
        $response = $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-deliveries/{$text->id}/confirm-receipt")->assertOk();
        $this->assertSame(0, SmsSenderVerification::count());

        $pending = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-deliveries/status', ['delivery_ids' => $response->json('outcome.delivery_ids')])
            ->assertOk();
        $this->assertSame(1, $pending->json('data.outcome.carrier_pending'));
        $this->assertSame('received', collect($pending->json('data.deliveries'))->firstWhere('channel', 'sms')['outcome']);
        $this->assertTrue(collect($pending->json('data.deliveries'))->firstWhere('channel', 'sms')['carrier_pending']);
        $this->assertFalse(collect($pending->json('data.deliveries'))->firstWhere('channel', 'sms')['awaiting_carrier']);

        $this->twilio->statuses[$text->provider_sid] = ['status' => 'delivered'];
        $this->travel(20)->seconds();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-deliveries/status', ['delivery_ids' => $response->json('outcome.delivery_ids')])
            ->assertOk()
            ->assertJsonPath('data.outcome.carrier_pending', 0);

        $this->assertSame($text->provider_sid, SmsSenderVerification::sole()->provider_sid);
        $this->assertSame('verified', app(\App\Services\PhotoDeliveryService::class)->textingStatus($this->company->id)['state']);
    }

    public function test_a_check_holding_an_old_copy_of_the_row_still_takes_back_a_receipt(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $stale = $this->textRow($session);
        $stale->forceFill(['provider_status' => 'sent'])->save();
        $stale = $stale->fresh();

        PhotoDelivery::whereKey($stale->id)->update([
            'receipt_confirmed_at' => now(),
            'receipt_confirmed_by' => $this->admin->id,
            'receipt_confirmed_via' => PhotoDelivery::RECEIPT_VIA_STAFF,
        ]);
        $this->twilio->statuses[$stale->provider_sid] = ['status' => 'undelivered', 'errorCode' => 30032];

        app(\App\Services\PhotoDeliveryService::class)->refreshCarrierStatus($stale, true);

        $row = $stale->fresh();
        $this->assertNull($row->receipt_confirmed_at);
        $this->assertSame(PhotoDelivery::OUTCOME_NOT_DELIVERED, $row->outcome());
        $this->assertFalse($row->reachedGuest());
    }

    public function test_an_old_text_link_stops_counting_once_the_text_is_sent_again(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);
        $oldRef = $text->linkRef();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-deliveries/{$text->id}/retry")->assertOk();
        $text->refresh();

        $this->assertNotSame($oldRef, $text->linkRef());
        $this->assertNull(PhotoDelivery::fromLinkRef($session->id, $oldRef));
        $this->assertStringContainsString('?r=' . $text->linkRef(), $this->twilio->messages->sent[1]['body']);

        $this->getJson("/api/photos/access/{$session->access_token}?r={$oldRef}")->assertOk();
        $this->assertNull($text->fresh()->receipt_confirmed_at);
        $this->assertNull($text->fresh()->opened_at);
        $this->assertSame(0, SmsSenderVerification::count());
    }

    public function test_a_carrier_rejection_takes_back_a_link_receipt_and_its_verification(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);

        $this->getJson("/api/photos/access/{$session->access_token}?r=" . $text->linkRef())->assertOk();
        $this->assertSame(1, SmsSenderVerification::whereNull('revoked_at')->count());

        $this->twilio->statuses[$text->provider_sid] = ['status' => 'undelivered', 'errorCode' => 30007];
        app(\App\Services\PhotoDeliveryService::class)->refreshCarrierStatus($text->fresh(), true);

        $this->assertNull($text->fresh()->receipt_confirmed_at);
        $this->assertNotNull($text->fresh()->opened_at);
        $this->assertSame(0, SmsSenderVerification::whereNull('revoked_at')->count());
    }

    public function test_one_revoked_confirmation_does_not_undo_another_real_one(): void
    {
        $service = app(\App\Services\PhotoDeliveryService::class);

        $a = $this->staffSession();
        $this->deliver($a, [$this->waiver()->id])->assertOk();
        $textA = $this->textRow($a);
        $this->getJson("/api/photos/access/{$a->access_token}?r=" . $textA->linkRef())->assertOk();

        $b = $this->staffSession();
        $this->deliver($b, [$this->waiver('b@example.test', '2485550122', 'Bea')->id])->assertOk();
        $textB = $this->textRow($b);
        $this->getJson("/api/photos/access/{$b->access_token}?r=" . $textB->linkRef())->assertOk();
        $this->assertSame(2, SmsSenderVerification::whereNull('revoked_at')->count());

        $this->twilio->statuses[$textA->provider_sid] = ['status' => 'undelivered', 'errorCode' => 30007];
        $this->twilio->statuses[$textB->provider_sid] = ['status' => 'delivered'];
        $service->refreshCarrierStatus($textA->fresh(), true);
        $service->refreshCarrierStatus($textB->fresh(), true);

        $this->assertSame(1, SmsSenderVerification::whereNull('revoked_at')->count());
        $this->assertSame('verified', $service->textingStatus($this->company->id)['state']);
    }

    public function test_after_a_reset_only_a_text_sent_later_can_confirm_texting(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $old = $this->textRow($session);

        $this->travel(1)->minutes();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-settings/texting/reset', ['location_id' => $this->location->id])
            ->assertOk();
        $this->travel(1)->minutes();

        $this->getJson("/api/photos/access/{$session->access_token}?r=" . $old->linkRef())->assertOk();
        $this->assertNotNull($old->fresh()->receipt_confirmed_at);
        $this->assertSame(0, SmsSenderVerification::whereNull('revoked_at')->count());

        $later = $this->staffSession();
        $this->deliver($later, [$this->waiver('late@example.test', '2485550133', 'Lee')->id])->assertOk();
        $this->getJson("/api/photos/access/{$later->access_token}?r=" . $this->textRow($later)->linkRef())->assertOk();
        $this->assertSame(1, SmsSenderVerification::whereNull('revoked_at')->count());
    }

    public function test_a_text_sent_before_tracking_cannot_be_confirmed(): void
    {
        $legacy = $this->textRowFor($this->company->id, $this->location->id, ['provider_sid' => null, 'provider_from' => null, 'provider_status' => null]);

        $refused = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/photo-deliveries/{$legacy->id}/confirm-receipt")
            ->assertStatus(422);

        $this->assertStringContainsString('before delivery tracking', (string) $refused->json('message'));
        $this->assertFalse($refused->json('data.can_confirm_receipt'));
        $this->assertNull($legacy->fresh()->receipt_confirmed_at);
    }

    public function test_a_blocked_sender_shows_as_failing_for_every_company_that_uses_it(): void
    {
        $service = app(\App\Services\PhotoDeliveryService::class);
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $mine = $this->textRow($session);
        $this->twilio->statuses[$mine->provider_sid] = ['status' => 'delivered'];
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-deliveries/{$mine->id}/confirm-receipt")->assertOk();
        $this->assertSame('verified', $service->textingStatus($this->company->id)['state']);

        $other = Company::create(['company_name' => 'Other Venue', 'email' => 'o@other.test', 'phone' => '5550000001', 'address' => '2 Elsewhere']);
        $otherLocation = Location::create([
            'company_id' => $other->id, 'name' => 'Other', 'address' => '2 Elsewhere', 'city' => 'Flint', 'state' => 'MI',
            'zip_code' => '48501', 'phone' => '8105550000', 'email' => 'other@other.test', 'timezone' => 'America/Detroit', 'is_active' => true,
        ]);
        $this->travel(1)->minutes();
        $this->textRowFor($other->id, $otherLocation->id, ['provider_status' => 'undelivered', 'provider_error_code' => '30032']);

        $this->assertSame('failing', $service->textingStatus($this->company->id)['state']);
        $this->assertSame('30032', $service->textingStatus($this->company->id)['problem']['code']);
    }

    public function test_a_send_refused_this_morning_is_not_hidden_behind_an_older_success(): void
    {
        $service = app(\App\Services\PhotoDeliveryService::class);
        $this->textRowFor($this->company->id, $this->location->id, ['provider_status' => 'delivered', 'sent_at' => now()->subHours(2)]);
        $refused = $this->textRowFor($this->company->id, $this->location->id, [
            'status' => PhotoDelivery::STATUS_FAILED, 'sent_at' => null, 'provider_sid' => null,
            'provider_status' => null, 'provider_error_code' => '21606',
        ]);
        PhotoDelivery::whereKey($refused->id)->update(['created_at' => now()->subHours(5), 'updated_at' => now()]);

        $this->assertSame('failing', $service->textingStatus($this->company->id)['state']);
    }

    public function test_every_waiting_text_gets_checked_even_past_the_first_ten(): void
    {
        $service = app(\App\Services\PhotoDeliveryService::class);
        $rows = collect(range(1, 12))->map(fn () => $this->textRowFor($this->company->id, $this->location->id, ['provider_status' => 'queued']));

        $service->refreshAwaiting(PhotoDelivery::whereIn('id', $rows->pluck('id'))->get());
        $this->assertSame(10, $this->twilio->lookups);

        $service->refreshAwaiting(PhotoDelivery::whereIn('id', $rows->pluck('id'))->get());
        $this->assertSame(12, $this->twilio->lookups);
        $this->assertSame(0, PhotoDelivery::whereIn('id', $rows->pluck('id'))->whereNull('provider_checked_at')->count());
    }

    public function test_carrier_checks_are_rate_limited_per_staff_member(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);

        foreach (range(1, 30) as $i) {
            $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-deliveries/{$text->id}/confirm-receipt");
        }

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/photo-deliveries/{$text->id}/confirm-receipt")
            ->assertStatus(429);
        $this->assertNotSame(429, $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/photo-deliveries/{$text->id}/confirm-receipt")
            ->status());
    }

    public function test_the_test_text_check_token_expires(): void
    {
        $send = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-settings/test-message', ['location_id' => $this->location->id, 'channel' => 'sms', 'destination' => '(810) 588-9748'])
            ->assertOk();

        $this->assertGreaterThanOrEqual(1, $send->json('data.segments'));
        $this->assertSame('GSM-7', $send->json('data.encoding'));

        $this->travel(31)->minutes();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-settings/test-message/status', [
                'location_id' => $this->location->id,
                'provider_reference' => $send->json('data.provider_reference'),
                'check_token' => $send->json('data.check_token'),
            ])
            ->assertNotFound();
    }

    public function test_a_staff_receipt_the_carrier_never_answers_is_accepted_after_three_days(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-deliveries/{$text->id}/confirm-receipt")->assertOk();
        $this->assertSame(0, SmsSenderVerification::count());

        $this->travel(PhotoDelivery::CARRIER_CHECK_HOURS + 1)->hours();
        $this->artisan('photos:check-text-delivery')->assertSuccessful();

        $this->assertSame(1, SmsSenderVerification::whereNull('revoked_at')->count());
    }

    public function test_retry_is_not_offered_as_the_fix_when_the_sender_is_blocked(): void
    {
        $session = $this->staffSession();
        $response = $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);
        $this->twilio->statuses[$text->provider_sid] = ['status' => 'undelivered', 'errorCode' => 30032];

        $refresh = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-deliveries/status', ['delivery_ids' => $response->json('outcome.delivery_ids')])
            ->assertOk();

        $sms = collect($refresh->json('data.deliveries'))->firstWhere('channel', 'sms');
        $this->assertFalse($sms['retry_useful']);
        $this->assertStringContainsString('will not help', (string) $refresh->json('data.outcome.message'));
        $this->assertStringNotContainsString('Retry', (string) $refresh->json('data.outcome.message'));
    }

    public function test_country_codes_written_apart_are_accepted_and_ambiguous_ones_explain_the_fix(): void
    {
        $this->assertSame('+47 92233445', WaiverProfile::storedPhoneFor('+47 922 33 445'));
        $this->assertSame('+45 22334455', WaiverProfile::storedPhoneFor('+45 22 33 44 55'));
        $this->assertSame('+65 82345678', WaiverProfile::storedPhoneFor('+65 8234 5678'));
        $this->assertSame('+47 92233445', WaiverProfile::storedPhoneFor('0047 922 33 445'));
        foreach (['+47 922 33 445', '+45 22 33 44 55', '+65 8234 5678', '+44 7911 123456', '+47 912 34 567'] as $typed) {
            $stored = WaiverProfile::storedPhoneFor($typed);
            $this->assertSame($stored, WaiverProfile::storedPhoneFor($stored), "{$typed} must stay valid once stored");
            $this->assertFalse(WaiverProfile::needsCountryCode($stored));
            $this->assertSame(\App\Services\SmsService::toE164($typed), \App\Services\SmsService::toE164($stored));
        }
        $this->assertNull(WaiverProfile::storedPhoneFor('+4792233445'));
        $this->assertTrue(WaiverProfile::needsCountryCode('+4792233445'));
        $this->assertStringContainsString('put a space after your country code', WaiverProfile::COUNTRY_CODE_NEEDED);
        $this->assertSame('2485550142', WaiverProfile::storedPhoneFor('001 248 555 0142'));
        $this->assertSame('2485550142', WaiverProfile::storedPhoneFor('+1 248 555 0142 ext 5'));
        $this->assertSame('8105550134', WaiverProfile::storedPhoneFor('(810) 555-0134 x12'));
    }

    public function test_the_report_separates_text_only_sessions_by_what_is_known(): void
    {
        $unknown = $this->staffSession();
        $this->deliver($unknown, [$this->waiver('unk@example.test', '2485550144', 'Uma')->id])->assertOk();
        PhotoDelivery::where('photo_session_id', $unknown->id)->where('channel', 'email')->update(['status' => PhotoDelivery::STATUS_FAILED]);

        $missed = $this->staffSession();
        $this->deliver($missed, [$this->waiver('mis@example.test', '2485550155', 'Mo')->id])->assertOk();
        PhotoDelivery::where('photo_session_id', $missed->id)->where('channel', 'email')->update(['status' => PhotoDelivery::STATUS_FAILED]);
        $this->textRow($missed)->forceFill(['provider_status' => 'undelivered', 'provider_error_code' => '30032'])->save();

        $report = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-reports/delivery?location_id=' . $this->location->id)
            ->assertOk();

        $this->assertSame(1, $report->json('data.sessions_text_unconfirmed_no_email'));
        $this->assertSame(1, $report->json('data.sessions_text_did_not_arrive_no_email'));
        $this->assertSame(0, $report->json('data.sessions_nothing_sent'));
    }

    public function test_the_waiting_filter_leaves_out_texts_already_received(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-deliveries/{$text->id}/confirm-receipt")->assertOk();

        $waiting = $this->staffSession();
        $this->deliver($waiting, [$this->waiver('wait@example.test', '2485550199', 'Wes')->id])->assertOk();
        $waitingText = $this->textRow($waiting);
        PhotoDelivery::whereKey($waitingText->id)->update(['provider_status' => 'sent']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-deliveries?outcome=awaiting_carrier')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $waitingText->id);
    }

    public function test_a_resend_from_an_old_copy_of_the_row_clears_the_newer_receipt(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);
        $stale = $text->fresh();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-deliveries/{$text->id}/confirm-receipt")->assertOk();
        $this->assertNotNull($text->fresh()->receipt_confirmed_at);

        app(\App\Services\PhotoDeliveryService::class)->send($stale);

        $row = $text->fresh();
        $this->assertNotSame($text->provider_sid, $row->provider_sid);
        $this->assertNull($row->receipt_confirmed_at);
        $this->assertNull($row->receipt_confirmed_via);
        $this->assertNotSame(PhotoDelivery::OUTCOME_RECEIVED, $row->outcome());
    }

    public function test_a_receipt_cannot_land_on_a_text_that_is_being_sent_again(): void
    {
        $service = app(\App\Services\PhotoDeliveryService::class);
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);
        PhotoDelivery::whereKey($text->id)->update(['provider_status' => 'sent']);

        $linkCopy = $text->fresh();
        PhotoDelivery::whereKey($text->id)->update(['link_nonce' => 'resentnonce1']);
        $this->assertFalse($service->confirmReceipt($linkCopy, null, PhotoDelivery::RECEIPT_VIA_LINK)['recorded']);

        $staffCopy = $text->fresh();
        PhotoDelivery::whereKey($text->id)->update(['status' => PhotoDelivery::STATUS_QUEUED]);
        $this->assertFalse($service->confirmReceipt($staffCopy, $this->admin)['recorded']);

        $this->assertNull($text->fresh()->receipt_confirmed_at);
        $this->assertSame(0, SmsSenderVerification::count());
    }

    public function test_staff_cannot_confirm_an_old_text_the_carrier_never_confirmed(): void
    {
        $old = $this->textRowFor($this->company->id, $this->location->id, [
            'sent_at' => now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS + 20),
        ]);

        $refused = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/photo-deliveries/{$old->id}/confirm-receipt")
            ->assertStatus(422);

        $this->assertStringContainsString('never confirmed it', (string) $refused->json('message'));
        $this->assertFalse($refused->json('data.can_confirm_receipt'));
        $this->assertNull($old->fresh()->receipt_confirmed_at);

        $this->artisan('photos:check-text-delivery')->assertSuccessful();
        $this->assertSame(0, SmsSenderVerification::count());

        $delivered = $this->textRowFor($this->company->id, $this->location->id, [
            'sent_at' => now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS + 20),
        ]);
        $this->twilio->statuses[$delivered->provider_sid] = ['status' => 'delivered'];

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/photo-deliveries/{$delivered->id}/confirm-receipt")
            ->assertOk();
        $this->assertSame(1, SmsSenderVerification::whereNull('revoked_at')->count());
    }

    public function test_the_three_day_acceptance_asks_the_carrier_once_more_first(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-deliveries/{$text->id}/confirm-receipt")->assertOk();

        $this->travel(PhotoDelivery::CARRIER_CHECK_HOURS + 1)->hours();
        $this->twilio->statuses[$text->provider_sid] = ['status' => 'undelivered', 'errorCode' => 30003];
        $this->artisan('photos:check-text-delivery')->assertSuccessful();

        $row = $text->fresh();
        $this->assertSame(0, SmsSenderVerification::whereNull('revoked_at')->count());
        $this->assertNull($row->receipt_confirmed_at);
        $this->assertSame(PhotoDelivery::OUTCOME_NOT_DELIVERED, $row->outcome());
    }

    public function test_already_settled_texts_do_not_stop_newer_receipts_from_settling(): void
    {
        foreach (range(1, 30) as $i) {
            $settled = $this->textRowFor($this->company->id, $this->location->id, [
                'sent_at' => now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS + 4),
                'receipt_confirmed_at' => now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS + 3),
                'receipt_confirmed_by' => $this->admin->id,
                'receipt_confirmed_via' => PhotoDelivery::RECEIPT_VIA_STAFF,
            ]);
            SmsSenderVerification::create([
                'company_id' => $this->company->id,
                'location_id' => $this->location->id,
                'sender' => self::SENDER,
                'photo_delivery_id' => $settled->id,
                'provider_sid' => $settled->provider_sid,
                'method' => SmsSenderVerification::METHOD_STAFF_CONFIRMED,
                'confirmed_by' => $this->admin->id,
                'confirmed_at' => now(),
            ]);
        }

        $waiting = $this->textRowFor($this->company->id, $this->location->id, [
            'sent_at' => now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS + 30),
            'receipt_confirmed_at' => now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS + 29),
            'receipt_confirmed_by' => $this->admin->id,
            'receipt_confirmed_via' => PhotoDelivery::RECEIPT_VIA_STAFF,
        ]);

        $this->assertSame(1, app(\App\Services\PhotoDeliveryService::class)->settleUnansweredReceipts());
        $this->assertTrue(SmsSenderVerification::where('provider_sid', $waiting->provider_sid)->whereNull('revoked_at')->exists());
        $this->assertSame(1, $this->twilio->lookups);
    }

    public function test_a_confirmation_recorded_from_an_old_copy_is_taken_back(): void
    {
        $text = $this->textRowFor($this->company->id, $this->location->id, [
            'provider_status' => 'delivered',
            'delivered_at' => now(),
            'receipt_confirmed_at' => now(),
            'receipt_confirmed_by' => $this->admin->id,
            'receipt_confirmed_via' => PhotoDelivery::RECEIPT_VIA_STAFF,
        ]);
        $stale = $text->fresh();
        PhotoDelivery::whereKey($text->id)->update([
            'provider_status' => 'undelivered',
            'receipt_confirmed_at' => null,
            'receipt_confirmed_by' => null,
            'receipt_confirmed_via' => null,
        ]);

        $record = new \ReflectionMethod(\App\Services\PhotoDeliveryService::class, 'recordVerification');
        $recorded = $record->invoke(app(\App\Services\PhotoDeliveryService::class), $stale, SmsSenderVerification::METHOD_STAFF_CONFIRMED, $this->admin->id);

        $this->assertFalse($recorded);
        $this->assertSame(1, SmsSenderVerification::count());
        $this->assertSame(0, SmsSenderVerification::whereNull('revoked_at')->count());
    }

    public function test_sending_again_retires_an_unconfirmed_link_verification_but_keeps_a_delivered_one(): void
    {
        $opened = $this->staffSession();
        $this->deliver($opened, [$this->waiver()->id])->assertOk();
        $openedText = $this->textRow($opened);
        $this->getJson("/api/photos/access/{$opened->access_token}?r=" . $openedText->linkRef())->assertOk();
        $this->assertSame(1, SmsSenderVerification::whereNull('revoked_at')->count());

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-deliveries/{$openedText->id}/retry")->assertOk();
        $this->assertSame(0, SmsSenderVerification::whereNull('revoked_at')->count());
        $this->assertStringContainsString('sent again', (string) SmsSenderVerification::sole()->revoke_reason);

        $delivered = $this->staffSession();
        $this->deliver($delivered, [$this->waiver('d@example.test', '2485550144', 'Dee')->id])->assertOk();
        $deliveredText = $this->textRow($delivered);
        $this->twilio->statuses[$deliveredText->provider_sid] = ['status' => 'delivered'];
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-deliveries/{$deliveredText->id}/confirm-receipt")->assertOk();
        $this->assertSame(1, SmsSenderVerification::whereNull('revoked_at')->count());

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-deliveries/{$deliveredText->id}/retry")->assertOk();
        $this->assertSame(1, SmsSenderVerification::whereNull('revoked_at')->count());
    }

    public function test_the_scheduled_sender_only_sends_a_row_nobody_changed_since_it_was_loaded(): void
    {
        $service = app(\App\Services\PhotoDeliveryService::class);
        $failed = $this->textRowFor($this->company->id, $this->location->id, [
            'status' => PhotoDelivery::STATUS_FAILED,
            'attempts' => 1,
            'provider_sid' => null,
            'provider_status' => null,
            'sent_at' => null,
        ]);

        $loaded = $failed->fresh();
        PhotoDelivery::whereKey($failed->id)->update(['status' => PhotoDelivery::STATUS_QUEUED]);
        $this->assertNull($service->claimForSending($loaded, PhotoDelivery::STATUS_FAILED));

        PhotoDelivery::whereKey($failed->id)->update(['status' => PhotoDelivery::STATUS_FAILED]);
        $copy = $failed->fresh();
        $this->assertNotNull($service->claimForSending($copy, PhotoDelivery::STATUS_FAILED));
        $this->assertNull($service->claimForSending($copy, PhotoDelivery::STATUS_FAILED));

        \Illuminate\Support\Facades\DB::table('photo_deliveries')->where('id', $failed->id)->update(['updated_at' => null]);
        $this->assertNotNull($service->claimForSending($failed->fresh(), PhotoDelivery::STATUS_FAILED));
    }

    public function test_a_returning_guest_keeps_a_foreign_number_but_a_mistyped_plus_is_not_trusted(): void
    {
        $controller = app(\App\Http\Controllers\Api\WaiverPublicController::class);
        $restore = new \ReflectionMethod($controller, 'phoneFromProfile');
        $profile = fn (array $phone) => WaiverProfile::create(array_merge([
            'company_id' => $this->company->id,
            'last_location_id' => $this->location->id,
            'first_name' => 'Ingrid',
            'last_name' => 'Berg',
        ], $phone));

        $spaced = $profile(['phone_raw' => '+47 922 33 445', 'phone_digits' => '4792233445', 'phone_e164' => '+4792233445']);
        $this->assertSame('+47 92233445', $restore->invoke($controller, $spaced));

        $typo = $profile(['phone_raw' => '+8105550134', 'phone_digits' => '8105550134', 'phone_e164' => '+8105550134']);
        $this->assertSame('8105550134', $restore->invoke($controller, $typo));

        $fromWaiver = $profile(['phone_raw' => '+4792233446', 'phone_digits' => '4792233446', 'phone_e164' => '+4792233446']);
        $this->assertSame('4792233446', $restore->invoke($controller, $fromWaiver));
        $this->waiver('ingrid@example.test', null, 'Ingrid')
            ->forceFill(['waiver_profile_id' => $fromWaiver->id, 'adult_phone' => '+4792233446'])
            ->save();
        $this->assertSame('+4792233446', $restore->invoke($controller, $fromWaiver));

        $local = $profile(['phone_raw' => '(810) 555-0134', 'phone_digits' => '8105550134', 'phone_e164' => '+18105550134']);
        $this->assertSame('8105550134', $restore->invoke($controller, $local));
    }

    public function test_staff_cannot_save_a_profile_number_that_needs_a_country_code(): void
    {
        $profile = WaiverProfile::create([
            'company_id' => $this->company->id,
            'last_location_id' => $this->location->id,
            'first_name' => 'Pat',
            'last_name' => 'Lee',
            'phone_digits' => '8105550199',
            'phone_raw' => '8105550199',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/waiver-profiles/{$profile->id}", ['phone' => '+8105550134'])
            ->assertStatus(422)
            ->assertJsonPath('message', WaiverProfile::COUNTRY_CODE_NEEDED);
        $this->assertSame('8105550199', $profile->fresh()->phone_digits);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/waiver-profiles/{$profile->id}", ['phone' => '(810) 555-0134'])
            ->assertOk();
        $this->assertSame('8105550134', $profile->fresh()->phone_digits);
    }

    public function test_the_kiosk_contact_form_asks_for_the_country_code_instead_of_texting_abroad(): void
    {
        $session = PhotoSession::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'source' => PhotoSession::SOURCE_KIOSK,
            'status' => PhotoSession::STATUS_READY,
            'delivery_method' => PhotoSession::DELIVERY_KIOSK_QR,
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);
        $session->startQrWindow();
        $session->save();

        $this->postJson("/api/photos/access/{$session->fresh()->access_token}/contact", [
            'name' => 'Morgan Lee',
            'email' => 'morgan@example.test',
            'phone' => '+248 555 0142',
        ])->assertStatus(422)->assertJsonPath('errors.phone.0', WaiverProfile::COUNTRY_CODE_NEEDED);

        $this->assertNull($session->fresh()->kiosk_contact_at);
        $this->assertSame(0, PhotoDelivery::where('photo_session_id', $session->id)->count());
        $this->assertSame([], $this->twilio->messages->sent);
    }

    public function test_the_advice_only_offers_a_retry_that_can_help(): void
    {
        $refused = ['status' => PhotoDelivery::STATUS_FAILED, 'attempts' => 1, 'provider_sid' => null, 'provider_status' => null, 'sent_at' => null, 'error' => 'Refused'];

        $stop = $this->textRowFor($this->company->id, $this->location->id, $refused + ['provider_error_code' => '21610']);
        $alone = app(\App\Services\PhotoDeliveryService::class)->summarizeOutcome(collect([$stop]), $this->company->id);
        $this->assertSame('failed', $alone['result']);
        $this->assertStringNotContainsString('Retry', $alone['message']);
        $this->assertStringContainsString('will not help', $alone['message']);

        $busy = $this->textRowFor($this->company->id, $this->location->id, $refused + ['provider_error_code' => '30001']);
        $this->assertStringContainsString('Retry sends it again', app(\App\Services\PhotoDeliveryService::class)->summarizeOutcome(collect([$busy]), $this->company->id)['message']);

        $blocked = $this->textRowFor($this->company->id, $this->location->id, ['provider_status' => 'undelivered', 'provider_error_code' => '30032']);
        $email = PhotoDelivery::create([
            'photo_session_id' => $blocked->photo_session_id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'kind' => PhotoDelivery::KIND_IMMEDIATE,
            'channel' => PhotoDelivery::CHANNEL_EMAIL,
            'destination' => 'guest@example.test',
            'status' => PhotoDelivery::STATUS_FAILED,
            'attempts' => 1,
            'error' => 'The mail server did not answer.',
        ]);
        $mixed = app(\App\Services\PhotoDeliveryService::class)->summarizeOutcome(collect([$email, $blocked]), $this->company->id);
        $this->assertStringContainsString('blocking texts', $mixed['message']);
        $this->assertStringContainsString('Retry can still send the email again', $mixed['message']);
        $this->assertStringNotContainsString('QR', $mixed['message']);

        $whileBlocked = app(\App\Services\PhotoDeliveryService::class)->summarizeOutcome(collect([$busy->fresh()]), $this->company->id);
        $this->assertStringContainsString('blocking texts', $whileBlocked['message']);
        $this->assertStringNotContainsString('Retry sends it again', $whileBlocked['message']);
    }

    public function test_a_session_where_nothing_could_be_attempted_counts_as_nothing_sent(): void
    {
        $this->textRowFor($this->company->id, $this->location->id, [
            'status' => PhotoDelivery::STATUS_SKIPPED,
            'provider_sid' => null,
            'provider_status' => null,
            'sent_at' => null,
        ]);

        $report = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-reports/delivery?location_id=' . $this->location->id)
            ->assertOk();

        $this->assertSame(1, $report->json('data.sessions_nothing_sent'));
        $this->assertSame(0, $report->json('data.sessions_text_unconfirmed_no_email'));
    }

    public function test_a_guest_opening_the_link_after_a_staff_receipt_confirms_texting_at_once(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-deliveries/{$text->id}/confirm-receipt")->assertOk();
        $this->assertSame(0, SmsSenderVerification::count());

        $this->getJson("/api/photos/access/{$session->access_token}?r=" . $text->fresh()->linkRef())->assertOk();

        $this->assertSame(PhotoDelivery::RECEIPT_VIA_LINK, $text->fresh()->receipt_confirmed_via);
        $this->assertSame(1, SmsSenderVerification::whereNull('revoked_at')->where('method', SmsSenderVerification::METHOD_GUEST_OPENED_LINK)->count());
        $this->assertSame('verified', app(\App\Services\PhotoDeliveryService::class)->textingStatus($this->company->id)['state']);
    }

    public function test_a_resend_keeps_a_link_confirmation_the_carrier_had_already_delivered(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);
        $this->getJson("/api/photos/access/{$session->access_token}?r=" . $text->linkRef())->assertOk();
        $this->assertSame(1, SmsSenderVerification::whereNull('revoked_at')->count());

        $this->twilio->statuses[$text->provider_sid] = ['status' => 'delivered'];
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-deliveries/{$text->id}/retry")->assertOk();

        $this->assertSame($text->provider_sid, SmsSenderVerification::whereNull('revoked_at')->sole()->provider_sid);
    }

    public function test_a_confirmation_recorded_while_the_text_is_being_resent_is_taken_back(): void
    {
        $text = $this->textRowFor($this->company->id, $this->location->id, [
            'receipt_confirmed_at' => now(),
            'receipt_confirmed_by' => $this->admin->id,
            'receipt_confirmed_via' => PhotoDelivery::RECEIPT_VIA_STAFF,
        ]);
        $stale = $text->fresh();
        PhotoDelivery::whereKey($text->id)->update(['status' => PhotoDelivery::STATUS_QUEUED]);

        $record = new \ReflectionMethod(\App\Services\PhotoDeliveryService::class, 'recordVerification');

        $this->assertFalse($record->invoke(app(\App\Services\PhotoDeliveryService::class), $stale, SmsSenderVerification::METHOD_STAFF_CONFIRMED, $this->admin->id));
        $this->assertSame(0, SmsSenderVerification::whereNull('revoked_at')->count());
    }

    public function test_one_text_the_provider_cannot_find_does_not_stop_other_receipts_settling(): void
    {
        $receipt = [
            'receipt_confirmed_by' => $this->admin->id,
            'receipt_confirmed_via' => PhotoDelivery::RECEIPT_VIA_STAFF,
        ];
        $lost = $this->textRowFor($this->company->id, $this->location->id, array_merge($receipt, [
            'sent_at' => now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS + 1),
            'receipt_confirmed_at' => now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS),
        ]));
        $older = $this->textRowFor($this->company->id, $this->location->id, array_merge($receipt, [
            'sent_at' => now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS + 5),
            'receipt_confirmed_at' => now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS + 4),
        ]));
        $this->twilio->statuses[$lost->provider_sid] = ['throw' => true];

        $this->assertSame(1, app(\App\Services\PhotoDeliveryService::class)->settleUnansweredReceipts());
        $this->assertTrue(SmsSenderVerification::where('provider_sid', $older->provider_sid)->whereNull('revoked_at')->exists());
        $this->assertNotNull($lost->fresh()->provider_checked_at);
        $this->assertSame(0, app(\App\Services\PhotoDeliveryService::class)->settleUnansweredReceipts());
    }

    public function test_the_scheduled_sender_skips_a_row_another_run_is_sending(): void
    {
        $row = $this->textRowFor($this->company->id, $this->location->id, [
            'kind' => PhotoDelivery::KIND_NEXT_DAY,
            'status' => PhotoDelivery::STATUS_SCHEDULED,
            'scheduled_for' => now()->subMinute(),
            'provider_sid' => null,
            'provider_status' => null,
            'sent_at' => null,
        ]);
        $lock = \Illuminate\Support\Facades\Cache::lock('photo-delivery-send:' . $row->id, 600);
        $this->assertTrue($lock->get());

        $this->artisan('photos:send-scheduled')->assertSuccessful();
        $this->assertSame(PhotoDelivery::STATUS_SCHEDULED, $row->fresh()->status);
        $this->assertSame(0, (int) $row->fresh()->attempts);

        $lock->release();
        $session = PhotoSession::find($row->photo_session_id);
        $session->startQrWindow();
        $session->save();
        $this->artisan('photos:send-scheduled')->assertSuccessful();
        $this->assertSame(1, (int) $row->fresh()->attempts);
        $this->assertSame(PhotoDelivery::STATUS_SENT, $row->fresh()->status);
        $this->assertCount(1, $this->twilio->messages->sent);
    }

    public function test_a_claimed_row_keeps_the_relations_it_was_loaded_with(): void
    {
        $row = $this->textRowFor($this->company->id, $this->location->id, [
            'status' => PhotoDelivery::STATUS_FAILED,
            'attempts' => 1,
            'provider_sid' => null,
            'provider_status' => null,
            'sent_at' => null,
        ]);
        $loaded = PhotoDelivery::with('session.location')->find($row->id);

        $claimed = app(\App\Services\PhotoDeliveryService::class)->claimForSending($loaded, PhotoDelivery::STATUS_FAILED);

        $this->assertTrue($claimed->relationLoaded('session'));
        $this->assertTrue($claimed->session->relationLoaded('location'));
    }

    public function test_recent_texts_are_found_through_an_index(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasIndex('photo_deliveries', 'photo_deliveries_text_recent_idx'));
    }

    public function test_a_refused_confirmation_only_suggests_a_retry_that_can_help(): void
    {
        $blocked = $this->staffSession();
        $this->deliver($blocked, [$this->waiver()->id])->assertOk();
        $blockedText = $this->textRow($blocked);
        $this->twilio->statuses[$blockedText->provider_sid] = ['status' => 'undelivered', 'errorCode' => 30032];

        $refused = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/photo-deliveries/{$blockedText->id}/confirm-receipt")
            ->assertStatus(422);
        $this->assertStringContainsString('will not help', (string) $refused->json('message'));
        $this->assertStringNotContainsString('Retry', (string) $refused->json('message'));

        $this->travel(1)->minutes();
        $this->textRowFor($this->company->id, $this->location->id, ['provider_status' => 'delivered', 'delivered_at' => now()]);

        $off = $this->staffSession();
        $this->deliver($off, [$this->waiver('off@example.test', '2485550166', 'Oz')->id])->assertOk();
        $offText = $this->textRow($off);
        $this->twilio->statuses[$offText->provider_sid] = ['status' => 'undelivered', 'errorCode' => 30003];

        $retryable = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/photo-deliveries/{$offText->id}/confirm-receipt")
            ->assertStatus(422);
        $this->assertStringContainsString('Send it again with Retry', (string) $retryable->json('message'));
    }

    public function test_a_staff_receipt_says_plainly_when_texting_is_already_confirmed(): void
    {
        $first = $this->staffSession();
        $this->deliver($first, [$this->waiver()->id])->assertOk();
        $this->getJson("/api/photos/access/{$first->access_token}?r=" . $this->textRow($first)->linkRef())->assertOk();

        $second = $this->staffSession();
        $this->deliver($second, [$this->waiver('two@example.test', '2485550177', 'Tia')->id])->assertOk();
        $confirm = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-deliveries/' . $this->textRow($second)->id . '/confirm-receipt')
            ->assertOk();

        $this->assertSame("Thanks. This text is recorded as received on the guest's phone.", $confirm->json('message'));
    }

    public function test_the_settings_note_explains_a_staff_receipt_that_is_still_settling(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-deliveries/{$text->id}/confirm-receipt")->assertOk();

        $status = app(\App\Services\PhotoDeliveryService::class)->textingStatus($this->company->id);

        $this->assertSame('unverified', $status['state']);
        $this->assertSame($text->id, $status['pending_receipt']['photo_delivery_id']);
        $this->assertStringContainsString('A staff member saw a photo text', (string) $status['note']);
        $this->assertStringNotContainsString('no guest has confirmed', (string) $status['note']);
    }

    public function test_the_report_keeps_sessions_still_waiting_to_send_out_of_nothing_sent(): void
    {
        $scheduled = $this->textRowFor($this->company->id, $this->location->id, [
            'kind' => PhotoDelivery::KIND_NEXT_DAY,
            'channel' => PhotoDelivery::CHANNEL_EMAIL,
            'destination' => 'later@example.test',
            'status' => PhotoDelivery::STATUS_SCHEDULED,
            'scheduled_for' => now()->addDay(),
            'provider_sid' => null,
            'provider_from' => null,
            'provider_status' => null,
            'sent_at' => null,
        ]);
        PhotoDelivery::create([
            'photo_session_id' => $scheduled->photo_session_id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'kind' => PhotoDelivery::KIND_NEXT_DAY,
            'channel' => PhotoDelivery::CHANNEL_SMS,
            'destination' => '+18105550100',
            'status' => PhotoDelivery::STATUS_SKIPPED,
        ]);

        $report = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-reports/delivery?location_id=' . $this->location->id)
            ->assertOk();

        $this->assertSame(1, $report->json('data.sessions_waiting_to_send'));
        $this->assertSame(0, $report->json('data.sessions_nothing_sent'));
    }

    public function test_the_summary_flags_blocked_texts_so_their_retry_can_be_hidden(): void
    {
        $blocked = $this->textRowFor($this->company->id, $this->location->id, ['provider_status' => 'undelivered', 'provider_error_code' => '30032']);
        $busy = $this->textRowFor($this->company->id, $this->location->id, [
            'status' => PhotoDelivery::STATUS_FAILED,
            'attempts' => 1,
            'provider_sid' => null,
            'provider_status' => null,
            'provider_error_code' => '30001',
            'sent_at' => null,
        ]);

        $during = app(\App\Services\PhotoDeliveryService::class);
        $this->assertTrue($during->summarizeOutcome(collect([$blocked, $busy]), $this->company->id)['texts_blocked']);
        $this->assertTrue($during->summarizeOutcome(collect([$busy]), $this->company->id)['texts_blocked']);
        $this->assertFalse($during->retryWouldHelp($busy));

        $this->travel(1)->minutes();
        $this->textRowFor($this->company->id, $this->location->id, ['provider_status' => 'delivered', 'delivered_at' => now()]);

        $after = app(\App\Services\PhotoDeliveryService::class);
        $this->assertFalse($after->summarizeOutcome(collect([$busy->fresh()]), $this->company->id)['texts_blocked']);
        $this->assertTrue($after->retryWouldHelp($busy->fresh()));
    }

    public function test_the_delivery_check_never_calls_unanswered_texts_arrived(): void
    {
        $command = app(\App\Console\Commands\DiagnoseSms::class);
        $verdict = fn (array $tally, int $looked, array $codes) => implode(' ', $command->deliveryVerdict($tally, $looked, $codes));

        $unanswered = $verdict(['delivered' => 2, 'sent' => 8], 10, []);
        $this->assertStringContainsString('2 of 10 are confirmed delivered', $unanswered);
        $this->assertStringContainsString('has not reported on 8', $unanswered);
        $this->assertStringNotContainsString('Everything in this sample arrived', $unanswered);

        $unreadable = $verdict(['delivered' => 3, 'could not look up' => 2], 5, []);
        $this->assertStringContainsString('2 could not be looked up', $unreadable);
        $this->assertStringNotContainsString('has not reported', $unreadable);

        $this->assertStringContainsString('could not be asked', $verdict(['could not look up' => 5], 5, []));
        $this->assertStringContainsString('1 of 4 arrived and 2 were dropped', $verdict(['delivered' => 1, 'undelivered' => 2, 'sent' => 1], 4, ['30003', '30003']));
        $this->assertStringContainsString('1 have no answer', $verdict(['delivered' => 1, 'undelivered' => 2, 'sent' => 1], 4, ['30003', '30003']));
        $this->assertStringContainsString('sender registration', $verdict(['undelivered' => 3], 3, ['30032', '30032', '30032']));
        $this->assertStringNotContainsString('sender registration', $verdict(['undelivered' => 1, 'sent' => 4], 5, ['30005']));
        $this->assertStringContainsString('Everything in this sample arrived', $verdict(['delivered' => 4], 4, []));
    }

    public function test_resending_an_email_forgets_that_the_old_one_was_opened(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $email = $this->emailRow($session);
        $this->getJson("/api/photos/access/{$session->access_token}?r=" . $email->linkRef())->assertOk();
        $this->assertNotNull($email->fresh()->opened_at);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-deliveries/{$email->id}/retry")->assertOk();

        $this->assertNull($email->fresh()->opened_at);
    }

    public function test_the_kiosk_contact_form_refuses_numbers_that_would_text_the_wrong_place(): void
    {
        $session = PhotoSession::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'source' => PhotoSession::SOURCE_KIOSK,
            'status' => PhotoSession::STATUS_READY,
            'delivery_method' => PhotoSession::DELIVERY_KIOSK_QR,
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);
        $session->startQrWindow();
        $session->save();

        foreach (['617-555-01422', '(617) 555-0142 extn 2', '0412 345 678', '+0 248 555 0142'] as $typo) {
            $this->postJson("/api/photos/access/{$session->fresh()->access_token}/contact", [
                'name' => 'Morgan Lee',
                'email' => 'morgan@example.test',
                'phone' => $typo,
            ])->assertStatus(422);
        }

        $this->assertNull($session->fresh()->kiosk_contact_at);
        $this->assertSame([], $this->twilio->messages->sent);
    }

    public function test_a_returning_guest_is_found_with_the_dialling_prefix_they_first_used(): void
    {
        WaiverProfile::create([
            'company_id' => $this->company->id,
            'last_location_id' => $this->location->id,
            'first_name' => 'Ingrid',
            'last_name' => 'Berg',
            'phone_digits' => '4792233445',
            'phone_e164' => '+4792233445',
            'phone_raw' => '+47 92233445',
        ]);
        $profiles = app(\App\Services\WaiverProfileService::class);

        foreach (['0047 922 33 445', '+47 922 33 445', '011 47 922 33 445', '+47 92233445'] as $typed) {
            $this->assertSame(\App\Services\WaiverProfileService::STATUS_FOUND, $profiles->lookup($this->company->id, $typed, 'Berg')['status'], $typed);
        }
    }

    public function test_staff_can_save_a_profile_whose_number_was_stored_before_the_spacing_rule(): void
    {
        $profile = WaiverProfile::create([
            'company_id' => $this->company->id,
            'last_location_id' => $this->location->id,
            'first_name' => 'Ingrid',
            'last_name' => 'Berg',
            'phone_digits' => '4792233445',
            'phone_raw' => '+4792233445',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/waiver-profiles/{$profile->id}", ['phone' => '+4792233445', 'email' => 'ingrid@example.test'])
            ->assertOk();
        $this->assertSame('ingrid@example.test', $profile->fresh()->email);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/waiver-profiles/{$profile->id}", ['phone' => '+4792233446'])
            ->assertStatus(422)
            ->assertJsonPath('message', WaiverProfile::COUNTRY_CODE_NEEDED);
    }

    public function test_numbers_that_cannot_be_dialled_as_typed_are_refused(): void
    {
        foreach (['0412 345 678', '1234567890', '001 248 555 014', '+1 248 555 01422', '61755501422'] as $typed) {
            $this->assertNull(WaiverProfile::storedPhoneFor($typed), $typed);
            $this->assertSame(WaiverProfile::PHONE_NOT_RECOGNISED, WaiverProfile::phoneProblem($typed), $typed);
        }

        $this->assertNull(WaiverProfile::storedPhoneFor('+0 248 555 0142'));
        $this->assertTrue(WaiverProfile::needsCountryCode('+0 248 555 0142'));
        $this->assertSame('+47 92233445', WaiverProfile::storedPhoneFor('+0047 922 33 445'));
        $this->assertSame('+47 92233445', WaiverProfile::storedPhoneFor('+(0)47 922 33 445'));
        $this->assertSame('2485550142', WaiverProfile::storedPhoneFor('(248) 555-0142'));
    }

    public function test_settle_never_queues_behind_texts_from_an_earlier_number(): void
    {
        $receipt = [
            'receipt_confirmed_by' => $this->admin->id,
            'receipt_confirmed_via' => PhotoDelivery::RECEIPT_VIA_STAFF,
        ];
        foreach (range(1, 30) as $i) {
            $this->textRowFor($this->company->id, $this->location->id, array_merge($receipt, [
                'provider_from' => '+18005550000',
                'sent_at' => now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS + 20),
                'receipt_confirmed_at' => now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS + 19),
                'provider_checked_at' => now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS + 19),
            ]));
        }
        $current = $this->textRowFor($this->company->id, $this->location->id, array_merge($receipt, [
            'sent_at' => now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS + 2),
            'receipt_confirmed_at' => now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS + 1),
            'provider_checked_at' => now()->subHour(),
        ]));

        $this->assertSame(1, app(\App\Services\PhotoDeliveryService::class)->settleUnansweredReceipts());
        $this->assertTrue(SmsSenderVerification::where('provider_sid', $current->provider_sid)->whereNull('revoked_at')->exists());
    }

    public function test_a_plus_after_brackets_or_invisible_marks_still_counts_as_a_country_code(): void
    {
        $this->assertSame('+65 98123456', WaiverProfile::storedPhoneFor('(+65) 9812 3456'));
        $this->assertSame('+45 22334455', WaiverProfile::storedPhoneFor('Tel: +45 22 33 44 55'));
        $this->assertSame('+47 92233445', WaiverProfile::storedPhoneFor("\u{202D}+47 922 33 445\u{202C}"));
        $this->assertSame('+65 98123456', WaiverProfile::storedPhoneFor("\u{FF0B}65 9812 3456"));
        $this->assertSame('+447911123456', WaiverProfile::storedPhoneFor('(+44) 7911 123456'));
        $this->assertSame('+447911123456', SmsService::toE164("\u{202A}+44 7911 123456\u{202C}"));
        $this->assertSame('2485550142', WaiverProfile::storedPhoneFor("\u{FF12}\u{FF14}\u{FF18} 555 0142"));
        $this->assertTrue(WaiverProfile::needsCountryCode('(+248) 555 0142'));
    }

    public function test_a_text_sent_before_a_reset_is_not_promised_to_confirm_texting(): void
    {
        $session = $this->staffSession();
        $response = $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);

        $this->travel(1)->minutes();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-settings/texting/reset', ['location_id' => $this->location->id])
            ->assertOk();
        $this->travel(1)->minutes();
        $this->twilio->statuses[$text->provider_sid] = ['status' => 'sent'];

        $status = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-deliveries/status', ['delivery_ids' => $response->json('outcome.delivery_ids')])
            ->assertOk();
        $this->assertFalse($status->json('data.outcome.ask_for_receipt'));

        $confirm = $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-deliveries/{$text->id}/confirm-receipt")->assertOk();
        $this->assertStringContainsString('does not confirm texting', (string) $confirm->json('message'));
    }

    public function test_a_block_from_a_replaced_number_no_longer_stops_a_resend(): void
    {
        $service = app(\App\Services\PhotoDeliveryService::class);
        $old = $this->textRowFor($this->company->id, $this->location->id, [
            'provider_from' => '+18005550000',
            'provider_status' => 'undelivered',
            'provider_error_code' => '30032',
        ]);

        $this->assertTrue($old->retryIsUseful());
        $this->assertFalse($service->blockedNow($old));
        $summary = $service->summarizeOutcome(collect([$old]), $this->company->id);
        $this->assertFalse($summary['texts_blocked']);
        $this->assertStringNotContainsString('blocking texts', $summary['message']);

        $current = $this->textRowFor($this->company->id, $this->location->id, ['provider_status' => 'undelivered', 'provider_error_code' => '30032']);
        $fresh = app(\App\Services\PhotoDeliveryService::class);
        $this->assertFalse($fresh->retryWouldHelp($current));
        $this->assertTrue($fresh->summarizeOutcome(collect([$current]), $this->company->id)['texts_blocked']);
    }

    public function test_a_live_block_hides_retry_on_texts_from_an_earlier_number_and_from_before_tracking(): void
    {
        $this->textRowFor($this->company->id, $this->location->id, ['provider_status' => 'undelivered', 'provider_error_code' => '30032']);
        $older = $this->textRowFor($this->company->id, $this->location->id, [
            'provider_from' => '+18005550000',
            'provider_status' => 'undelivered',
            'provider_error_code' => '30034',
            'sent_at' => now()->subMinutes(5),
        ]);
        $untracked = $this->textRowFor($this->company->id, $this->location->id, [
            'provider_sid' => null,
            'provider_from' => null,
            'provider_status' => null,
            'sent_at' => now()->subMinutes(5),
        ]);
        $service = app(\App\Services\PhotoDeliveryService::class);

        $this->assertTrue($older->retryIsUseful());
        $this->assertTrue($untracked->canRetry());
        $this->assertFalse($service->retryWouldHelp($older));
        $this->assertFalse($service->retryWouldHelp($untracked));
        $summary = $service->summarizeOutcome(collect([$older]), $this->company->id);
        $this->assertTrue($summary['texts_blocked']);
        $this->assertStringContainsString('blocking texts from this number', $summary['message']);

        $log = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-deliveries?channel=sms&per_page=50&location_id=' . $this->location->id)
            ->assertOk();
        $rows = collect($log->json('data.data'))->keyBy('id');
        $this->assertFalse($rows[$older->id]['retry_useful']);
        $this->assertFalse($rows[$untracked->id]['retry_useful']);
    }

    public function test_the_refusal_does_not_offer_retry_while_texts_from_this_number_are_blocked(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [
            $this->waiver()->id,
            $this->waiver('pair@example.test', '2485550188', 'Pat')->id,
        ])->assertOk();
        $texts = PhotoDelivery::where('photo_session_id', $session->id)->where('channel', 'sms')->orderBy('id')->get();
        $this->assertCount(2, $texts);
        PhotoDelivery::whereKey($texts[0]->id)->update(['provider_status' => 'undelivered', 'provider_error_code' => '30034']);
        $this->twilio->statuses[$texts[1]->provider_sid] = ['status' => 'undelivered', 'errorCode' => 30003];

        $refused = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/photo-deliveries/{$texts[1]->id}/confirm-receipt")
            ->assertStatus(422);

        $this->assertStringContainsString('while texts from this number are blocked', (string) $refused->json('message'));
    }

    public function test_account_problems_are_not_blamed_on_the_phone_carriers(): void
    {
        $refused = $this->textRowFor($this->company->id, $this->location->id, [
            'status' => PhotoDelivery::STATUS_FAILED,
            'attempts' => 1,
            'provider_sid' => null,
            'provider_status' => null,
            'provider_error_code' => '20003',
            'sent_at' => null,
        ]);

        $summary = app(\App\Services\PhotoDeliveryService::class)->summarizeOutcome(collect([$refused]), $this->company->id);

        $this->assertStringContainsString('texting account or number is not set up correctly', $summary['message']);
        $this->assertStringNotContainsString('carriers', $summary['message']);
        $this->assertTrue($summary['texts_blocked']);
    }

    public function test_a_row_stuck_in_queued_is_not_reported_as_waiting_to_send(): void
    {
        $stuck = $this->textRowFor($this->company->id, $this->location->id, [
            'channel' => PhotoDelivery::CHANNEL_EMAIL,
            'destination' => 'stuck@example.test',
            'status' => PhotoDelivery::STATUS_QUEUED,
            'provider_sid' => null,
            'provider_from' => null,
            'provider_status' => null,
            'sent_at' => null,
        ]);
        \Illuminate\Support\Facades\DB::table('photo_deliveries')->where('id', $stuck->id)->update(['updated_at' => now()->subHour()]);
        $this->textRowFor($this->company->id, $this->location->id, [
            'channel' => PhotoDelivery::CHANNEL_EMAIL,
            'destination' => 'fresh@example.test',
            'status' => PhotoDelivery::STATUS_QUEUED,
            'provider_sid' => null,
            'provider_from' => null,
            'provider_status' => null,
            'sent_at' => null,
        ]);

        $report = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-reports/delivery?location_id=' . $this->location->id)
            ->assertOk();

        $this->assertSame(1, $report->json('data.sessions_nothing_sent'));
        $this->assertSame(1, $report->json('data.sessions_waiting_to_send'));
    }

    public function test_staff_receipts_are_found_through_an_index(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasIndex('photo_deliveries', 'photo_deliveries_receipt_via_idx'));
    }

    public function test_a_short_account_outage_heals_once_the_same_number_works_again(): void
    {
        $outage = $this->textRowFor($this->company->id, $this->location->id, [
            'status' => PhotoDelivery::STATUS_FAILED,
            'attempts' => 1,
            'provider_sid' => null,
            'provider_status' => null,
            'provider_error_code' => '20003',
            'sent_at' => null,
        ]);
        \Illuminate\Support\Facades\DB::table('photo_deliveries')->where('id', $outage->id)->update(['updated_at' => now()->subMinutes(30)]);

        $this->assertTrue(PhotoDelivery::retryable()->whereKey($outage->id)->exists());

        $during = app(\App\Services\PhotoDeliveryService::class);
        $this->assertFalse($during->retryWouldHelp($outage->fresh()));
        $this->assertTrue($during->summarizeOutcome(collect([$outage->fresh()]), $this->company->id)['texts_blocked']);

        $this->textRowFor($this->company->id, $this->location->id, ['provider_status' => 'delivered', 'delivered_at' => now()]);

        $after = app(\App\Services\PhotoDeliveryService::class);
        $this->assertTrue($after->retryWouldHelp($outage->fresh()));
        $summary = $after->summarizeOutcome(collect([$outage->fresh()]), $this->company->id);
        $this->assertFalse($summary['texts_blocked']);
        $this->assertStringContainsString('Retry sends it again', $summary['message']);
    }

    public function test_guest_side_refusals_are_still_never_retried(): void
    {
        $stop = $this->textRowFor($this->company->id, $this->location->id, [
            'status' => PhotoDelivery::STATUS_FAILED,
            'attempts' => 1,
            'provider_sid' => null,
            'provider_status' => null,
            'provider_error_code' => '21610',
            'sent_at' => null,
        ]);
        \Illuminate\Support\Facades\DB::table('photo_deliveries')->where('id', $stop->id)->update(['updated_at' => now()->subMinutes(30)]);

        $this->assertFalse(PhotoDelivery::retryable()->whereKey($stop->id)->exists());
        $this->assertFalse($stop->fresh()->retryIsUseful());
        $this->assertFalse(app(\App\Services\PhotoDeliveryService::class)->retryWouldHelp($stop->fresh()));
    }

    public function test_a_transient_failure_does_not_hide_a_live_sender_block(): void
    {
        $blocked = $this->textRowFor($this->company->id, $this->location->id, ['provider_status' => 'undelivered', 'provider_error_code' => '30032']);
        $busy = $this->textRowFor($this->company->id, $this->location->id, [
            'status' => PhotoDelivery::STATUS_FAILED,
            'attempts' => 1,
            'provider_sid' => null,
            'provider_status' => null,
            'provider_error_code' => '30001',
            'sent_at' => null,
        ]);

        $service = app(\App\Services\PhotoDeliveryService::class);
        $this->assertTrue($service->senderBlockedNow());
        $this->assertTrue($service->summarizeOutcome(collect([$blocked, $busy]), $this->company->id)['texts_blocked']);
        $this->assertSame('failing', $service->textingStatus($this->company->id)['state']);
    }

    public function test_one_companys_confirmation_and_reset_leave_another_company_alone(): void
    {
        $other = Company::create([
            'company_name' => 'Other Co',
            'email' => 'other@other.test',
            'phone' => '5550000000',
            'address' => '9 Elsewhere',
        ]);
        $otherLocation = Location::create([
            'company_id' => $other->id,
            'name' => 'Other Place',
            'address' => '9 Elsewhere',
            'city' => 'Flint',
            'state' => 'MI',
            'zip_code' => '48502',
            'phone' => '8105550000',
            'email' => 'other-place@other.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $this->getJson("/api/photos/access/{$session->access_token}?r=" . $this->textRow($session)->linkRef())->assertOk();
        $this->assertSame('verified', app(\App\Services\PhotoDeliveryService::class)->textingStatus($this->company->id)['state']);
        $this->assertSame('unverified', app(\App\Services\PhotoDeliveryService::class)->textingStatus($other->id)['state']);

        $otherText = $this->textRowFor($other->id, $otherLocation->id, ['receipt_confirmed_at' => now(), 'receipt_confirmed_via' => PhotoDelivery::RECEIPT_VIA_LINK]);
        SmsSenderVerification::create([
            'company_id' => $other->id,
            'location_id' => $otherLocation->id,
            'sender' => self::SENDER,
            'photo_delivery_id' => $otherText->id,
            'provider_sid' => $otherText->provider_sid,
            'method' => SmsSenderVerification::METHOD_GUEST_OPENED_LINK,
            'confirmed_at' => now(),
        ]);

        $this->travel(1)->minutes();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-settings/texting/reset', ['location_id' => $this->location->id])
            ->assertOk();

        $this->assertSame('unverified', app(\App\Services\PhotoDeliveryService::class)->textingStatus($this->company->id)['state']);
        $this->assertSame('verified', app(\App\Services\PhotoDeliveryService::class)->textingStatus($other->id)['state']);
    }

    public function test_failed_and_read_carrier_statuses_are_treated_like_undelivered_and_delivered(): void
    {
        $service = app(\App\Services\PhotoDeliveryService::class);
        $failed = $this->textRowFor($this->company->id, $this->location->id);
        $read = $this->textRowFor($this->company->id, $this->location->id);
        $this->twilio->statuses[$failed->provider_sid] = ['status' => 'failed', 'errorCode' => 30008];
        $this->twilio->statuses[$read->provider_sid] = ['status' => 'read'];

        $service->refreshCarrierStatus($failed->fresh(), true);
        $service->refreshCarrierStatus($read->fresh(), true);

        $this->assertSame(PhotoDelivery::OUTCOME_NOT_DELIVERED, $failed->fresh()->outcome());
        $this->assertFalse($failed->fresh()->canConfirmReceipt());
        $this->assertSame(PhotoDelivery::OUTCOME_DELIVERED, $read->fresh()->outcome());
        $this->assertNotNull($read->fresh()->delivered_at);
        $this->assertTrue($read->fresh()->reachedGuest());
    }

    public function test_the_kiosk_page_vouches_for_the_text_once_it_reached_the_guest(): void
    {
        $session = PhotoSession::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'source' => PhotoSession::SOURCE_KIOSK,
            'status' => PhotoSession::STATUS_READY,
            'delivery_method' => PhotoSession::DELIVERY_KIOSK_QR,
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);
        $session->startQrWindow();
        $session->save();
        Photo::create([
            'photo_session_id' => $session->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'source' => Photo::SOURCE_KIOSK,
            'processing_status' => Photo::PROCESSING_READY,
            'original_path' => 'x/original.jpg',
            'delivery_path' => 'x/delivery.jpg',
            'slideshow_path' => 'x/slideshow.jpg',
            'thumbnail_path' => 'x/thumb.jpg',
            'captured_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);

        $contact = $this->postJson("/api/photos/access/{$session->fresh()->access_token}/contact", [
            'name' => 'Morgan Lee',
            'email' => 'morgan@example.test',
            'phone' => '(810) 588-9748',
        ])->assertOk();
        $this->assertTrue($contact->json('data.link_sent_by.sms'));
        $this->assertFalse($contact->json('data.link_sent_by.sms_reliable'));

        PhotoDelivery::where('photo_session_id', $session->id)->where('channel', 'sms')->update(['provider_status' => 'delivered', 'delivered_at' => now()]);

        $page = $this->getJson("/api/photos/access/{$session->fresh()->access_token}")->assertOk();
        $this->assertTrue($page->json('data.link_sent_by.sms_reliable'));
    }

    public function test_a_delivered_test_text_still_does_not_confirm_texting(): void
    {
        $send = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-settings/test-message', [
                'location_id' => $this->location->id,
                'channel' => 'sms',
                'destination' => '(810) 588-9748',
            ])
            ->assertOk();
        $sid = $send->json('data.provider_reference');
        $this->twilio->statuses[$sid] = ['status' => 'delivered'];

        $status = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/photo-settings/test-message/status', ['location_id' => $this->location->id, 'provider_reference' => $sid, 'check_token' => $send->json('data.check_token')])
            ->assertOk();

        $this->assertTrue($status->json('data.delivered'));
        $this->assertSame(0, SmsSenderVerification::count());
        $this->assertSame('unverified', app(\App\Services\PhotoDeliveryService::class)->textingStatus($this->company->id)['state']);
    }

    public function test_a_resend_retires_an_unconfirmed_link_verification_when_the_carrier_cannot_be_asked(): void
    {
        $session = $this->staffSession();
        $this->deliver($session, [$this->waiver()->id])->assertOk();
        $text = $this->textRow($session);
        $this->getJson("/api/photos/access/{$session->access_token}?r=" . $text->linkRef())->assertOk();
        $this->assertSame(1, SmsSenderVerification::whereNull('revoked_at')->count());

        $this->twilio->statuses[$text->provider_sid] = ['throw' => true];
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-deliveries/{$text->id}/retry")->assertOk();

        $this->assertSame(0, SmsSenderVerification::whereNull('revoked_at')->count());
    }

    public function test_the_cron_checks_older_texts_less_often(): void
    {
        $row = fn (int $sentMinutesAgo, int $checkedMinutesAgo) => $this->textRowFor($this->company->id, $this->location->id, [
            'sent_at' => now()->subMinutes($sentMinutesAgo),
            'provider_checked_at' => now()->subMinutes($checkedMinutesAgo),
        ]);
        $fresh = $row(5, 2);
        $hourly = $row(120, 20);
        $row(120, 5);
        $row(600, 30);

        $this->artisan('photos:check-text-delivery')->assertSuccessful();

        $this->assertSame(2, $this->twilio->lookups);
        $this->assertTrue($fresh->fresh()->provider_checked_at->greaterThan(now()->subMinute()));
        $this->assertTrue($hourly->fresh()->provider_checked_at->greaterThan(now()->subMinute()));
    }

    public function test_the_waiver_submit_gate_applies_the_phone_rule(): void
    {
        $controller = app(\App\Http\Controllers\Api\WaiverPublicController::class);
        $gate = new \ReflectionMethod($controller, 'requireTenDigitPhone');

        $ambiguous = ['adult_phone' => '+4792233445'];
        $refused = $gate->invokeArgs($controller, [&$ambiguous]);
        $this->assertSame(422, $refused->getStatusCode());
        $this->assertSame(WaiverProfile::COUNTRY_CODE_NEEDED, $refused->getData(true)['message']);

        $national = ['adult_phone' => '0412 345 678'];
        $this->assertSame(WaiverProfile::PHONE_NOT_RECOGNISED, $gate->invokeArgs($controller, [&$national])->getData(true)['message']);

        $spaced = ['adult_phone' => '+47 922 33 445'];
        $this->assertNull($gate->invokeArgs($controller, [&$spaced]));
        $this->assertSame('+47 92233445', $spaced['adult_phone']);

        $local = ['adult_phone' => '(248) 555-0142'];
        $this->assertNull($gate->invokeArgs($controller, [&$local]));
        $this->assertSame('2485550142', $local['adult_phone']);
    }

    private function kioskSessionWithPhoto(array $overrides = []): PhotoSession
    {
        $session = PhotoSession::create(array_merge([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'source' => PhotoSession::SOURCE_KIOSK,
            'status' => PhotoSession::STATUS_READY,
            'delivery_method' => PhotoSession::DELIVERY_KIOSK_QR,
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ], $overrides));
        $session->startQrWindow();
        $session->save();
        Photo::create([
            'photo_session_id' => $session->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'source' => Photo::SOURCE_KIOSK,
            'processing_status' => Photo::PROCESSING_READY,
            'original_path' => 'x/original.jpg',
            'delivery_path' => 'x/delivery.jpg',
            'slideshow_path' => 'x/slideshow.jpg',
            'thumbnail_path' => 'x/thumb.jpg',
            'captured_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);

        return $session->fresh();
    }

    private function textFor(PhotoSession $session, array $overrides = []): PhotoDelivery
    {
        return PhotoDelivery::create(array_merge([
            'photo_session_id' => $session->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'kind' => PhotoDelivery::KIND_IMMEDIATE,
            'channel' => PhotoDelivery::CHANNEL_SMS,
            'destination' => '+18105550100',
            'status' => PhotoDelivery::STATUS_SENT,
            'sent_at' => now(),
            'provider_sid' => 'SM' . md5(uniqid('', true)),
            'provider_from' => self::SENDER,
            'provider_status' => 'sent',
            'link_nonce' => 'kiosknonce01',
        ], $overrides));
    }

    public function test_a_kiosk_guest_opening_a_library_text_is_recorded_even_before_the_contact_form(): void
    {
        $session = $this->kioskSessionWithPhoto();
        $text = $this->textFor($session);

        $this->getJson("/api/photos/access/{$session->access_token}?r=" . $text->linkRef())
            ->assertOk()
            ->assertJsonPath('data.state', 'contact_required');

        $this->assertSame(PhotoDelivery::RECEIPT_VIA_LINK, $text->fresh()->receipt_confirmed_via);
        $this->assertSame(1, SmsSenderVerification::whereNull('revoked_at')->count());
    }

    public function test_the_kiosk_page_counts_a_library_text_when_saying_how_the_link_was_sent(): void
    {
        $session = $this->kioskSessionWithPhoto(['kiosk_contact_at' => now(), 'kiosk_contact_email' => 'morgan@example.test', 'kiosk_contact_phone' => '8105889748']);
        PhotoDelivery::create([
            'photo_session_id' => $session->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'kind' => PhotoDelivery::KIND_KIOSK,
            'channel' => PhotoDelivery::CHANNEL_EMAIL,
            'destination' => 'morgan@example.test',
            'status' => PhotoDelivery::STATUS_SENT,
            'sent_at' => now(),
        ]);
        $this->textFor($session, ['kind' => PhotoDelivery::KIND_KIOSK, 'provider_status' => 'undelivered', 'provider_error_code' => '30003']);

        $before = $this->getJson("/api/photos/access/{$session->access_token}")->assertOk();
        $this->assertTrue($before->json('data.link_sent_by.email'));
        $this->assertFalse($before->json('data.link_sent_by.sms'));

        $this->textFor($session, ['provider_status' => 'delivered', 'delivered_at' => now()]);
        PhotoDelivery::create([
            'photo_session_id' => $session->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'kind' => PhotoDelivery::KIND_IMMEDIATE,
            'channel' => PhotoDelivery::CHANNEL_EMAIL,
            'destination' => 'someone.else@example.test',
            'status' => PhotoDelivery::STATUS_SENT,
            'sent_at' => now(),
        ]);
        PhotoDelivery::query()->where('photo_session_id', $session->id)->where('kind', PhotoDelivery::KIND_KIOSK)->where('channel', PhotoDelivery::CHANNEL_EMAIL)->update(['status' => PhotoDelivery::STATUS_FAILED]);

        $others = $this->getJson("/api/photos/access/{$session->access_token}")->assertOk();
        $this->assertFalse($others->json('data.link_sent_by.email'));
        $this->assertFalse($others->json('data.link_sent_by.sms'));
        $this->assertFalse($others->json('data.link_sent_by.sms_reliable'));

        $this->textFor($session, ['destination' => '+18105889748', 'provider_status' => 'delivered', 'delivered_at' => now()]);

        $after = $this->getJson("/api/photos/access/{$session->access_token}")->assertOk();
        $this->assertTrue($after->json('data.link_sent_by.sms'));
        $this->assertTrue($after->json('data.link_sent_by.sms_reliable'));
    }

    public function test_the_report_never_counts_an_undelivered_text_as_reaching_the_phone(): void
    {
        $this->textRowFor($this->company->id, $this->location->id, [
            'provider_status' => 'undelivered',
            'provider_error_code' => '30003',
            'receipt_confirmed_at' => now(),
            'receipt_confirmed_via' => PhotoDelivery::RECEIPT_VIA_STAFF,
        ]);

        $report = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-reports/delivery?location_id=' . $this->location->id)
            ->assertOk();

        $this->assertSame(1, $report->json('data.sms_not_delivered'));
        $this->assertSame(0, $report->json('data.sms_delivered_to_phone'));
        $this->assertSame(0, $report->json('data.sms_received_confirmed'));
    }

    public function test_the_carrier_window_includes_its_last_instant(): void
    {
        $this->freezeSecond();
        $edge = $this->textRowFor($this->company->id, $this->location->id, ['sent_at' => now()->subHours(PhotoDelivery::CARRIER_CHECK_HOURS)]);

        $this->assertTrue($edge->fresh()->isAwaitingCarrier());
        $this->assertTrue(PhotoDelivery::awaitingCarrier()->whereKey($edge->id)->exists());

        $this->travelTo(now()->addMilliseconds(500));
        $this->assertTrue($edge->fresh()->isAwaitingCarrier());
        $this->assertTrue(PhotoDelivery::awaitingCarrier()->whereKey($edge->id)->exists());

        $this->travelTo(now()->startOfSecond()->addSecond());
        $this->assertFalse($edge->fresh()->isAwaitingCarrier());
        $this->assertFalse(PhotoDelivery::awaitingCarrier()->whereKey($edge->id)->exists());
    }
}

class FakeTwilioSmsService extends SmsService
{
    public function __construct(FakeTwilioClient $client)
    {
        $this->client = SmsService::isConfigured() ? $client : null;
        $this->fromNumber = config('twilio.from_number');
    }
}

class FakeTwilioClient
{
    public FakeTwilioMessageList $messages;

    public array $statuses = [];

    public int $lookups = 0;

    public function __construct()
    {
        $this->messages = new FakeTwilioMessageList();
    }

    public function messages(string $sid): object
    {
        $client = $this;

        return new class($client, $sid) {
            public function __construct(private FakeTwilioClient $client, private string $sid)
            {
            }

            public function fetch(): object
            {
                $this->client->lookups++;
                $known = $this->client->statuses[$this->sid] ?? ['status' => 'sent'];

                if (!empty($known['throw'])) {
                    throw new \Twilio\Exceptions\RestException('The requested resource was not found', 20404, 404);
                }

                $sent = collect($this->client->messages->sent)->firstWhere('sid', $this->sid);

                return (object) [
                    'sid' => $this->sid,
                    'status' => $known['status'],
                    'errorCode' => $known['errorCode'] ?? null,
                    'errorMessage' => null,
                    'from' => $sent['from'] ?? config('twilio.from_number'),
                ];
            }
        };
    }
}

class FakeTwilioMessageList
{
    public array $sent = [];

    public ?\Throwable $failWith = null;

    public function create(string $to, array $options): object
    {
        if ($this->failWith) {
            throw $this->failWith;
        }

        $sid = 'SM' . md5($to . count($this->sent) . microtime());
        $from = SmsService::toE164($options['from']) ?? $options['from'];
        $this->sent[] = ['sid' => $sid, 'to' => $to, 'from' => $from, 'body' => $options['body']];

        return (object) ['sid' => $sid, 'status' => 'queued', 'from' => $from];
    }
}
