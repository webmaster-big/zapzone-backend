<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Customer;
use App\Models\EmailNotification;
use App\Models\Location;
use App\Models\Package;
use App\Models\User;
use App\Models\WaiverBulkInvite;
use App\Models\WaiverInviteRecipient;
use App\Models\WaiverTemplate;
use App\Services\EmailNotificationService;
use App\Services\WaiverService;
use App\Support\PartyOrganizerLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PartyInvitationsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $location;

    private User $admin;

    private Package $party;

    private Package $plain;

    private Customer $organizer;

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gmail.enabled' => false,
            'services.resend.enabled' => false,
            'twilio.sid' => null,
            'app.frontend_url' => 'https://book.zapzone.test',
            'google_calendar.auto_sync' => false,
        ]);
        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = Company::create(['company_name' => 'Party Co', 'email' => 'owner@party.test', 'phone' => '5551230000', 'address' => '1 Main St']);
        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Brighton',
            'address' => '1 Test Way',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => 'brighton@party.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $this->admin = User::create([
            'first_name' => 'Party',
            'last_name' => 'Admin',
            'email' => 'admin@party.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
        $template = WaiverTemplate::create([
            'company_id' => $this->company->id,
            'title' => 'General Activity Waiver',
            'status' => WaiverTemplate::STATUS_ACTIVE,
            'is_default' => true,
            'body_text' => '<p>I release {{company_name}} for {{visit_date}}.</p>',
            'kind' => WaiverTemplate::KIND_STANDARD,
            'assigned_package_ids' => [],
            'electronic_consent_enabled' => true,
        ]);
        app(WaiverService::class)->syncVersion($template);

        $this->party = $this->package('Birthday Bash', true);
        $this->plain = $this->package('Open Play', false);
        $this->organizer = $this->customer('organizer@example.test');
    }

    private function package(string $name, bool $partyInvites): Package
    {
        return Package::create([
            'location_id' => $this->location->id,
            'name' => $name,
            'description' => $name,
            'category' => 'Birthday',
            'price' => 200,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 20,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
            'party_invites_enabled' => $partyInvites,
        ]);
    }

    private function customer(string $email, bool $verified = true): Customer
    {
        $customer = Customer::create([
            'first_name' => 'Pat',
            'last_name' => 'Parent',
            'email' => $email,
            'phone' => '7345550000',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);

        if ($verified) {
            $customer->forceFill(['email_verified_at' => now()])->save();
        }

        return $customer;
    }

    private function booking(Package $package, array $extra = []): Booking
    {
        return Booking::create(array_merge([
            'reference_number' => 'PTY-' . uniqid(),
            'booking_date' => now()->addDays(10)->toDateString(),
            'booking_time' => '14:00',
            'duration' => 120,
            'duration_unit' => 'minutes',
            'location_id' => $this->location->id,
            'package_id' => $package->id,
            'participants' => 10,
            'total_amount' => 200,
            'status' => 'confirmed',
            'guest_name' => 'Pat Parent',
            'guest_email' => 'organizer@example.test',
            'guest_of_honor_name' => 'Sam',
        ], $extra));
    }

    private function fresh(): void
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
    }

    private function asCustomer(Customer $customer): static
    {
        $this->fresh();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $customer->createToken('party')->plainTextToken, 'Accept' => 'application/json']);
    }

    private function captureEmails(): void
    {
        $this->sent = [];
        $this->partialMock(EmailNotificationService::class, function ($mock) {
            $mock->shouldReceive('sendEmail')->andReturnUsing(function ($to, $subject, $htmlBody) {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $htmlBody];
            });
        });
    }

    private function confirm(Booking $booking): array
    {
        $this->sent = [];
        app(EmailNotificationService::class)->triggerBookingNotification($booking->fresh(), EmailNotification::TRIGGER_BOOKING_CREATED);

        return $this->sent;
    }

    private function toCustomer(array $emails): array
    {
        return array_values(array_filter($emails, fn (array $email) => $email['to'] === 'organizer@example.test'));
    }

    public function test_the_package_setting_saves_and_reopens_in_each_state(): void
    {
        $this->assertFalse(Package::create([
            'location_id' => $this->location->id, 'name' => 'New Party', 'description' => 'x', 'category' => 'Birthday', 'price' => 1,
            'pricing_type' => 'base', 'min_participants' => 1, 'max_participants' => 5, 'duration' => 60, 'duration_unit' => 'minutes', 'is_active' => true,
        ])->fresh()->party_invites_enabled, 'off unless someone turns it on');

        foreach ([true, false] as $state) {
            $this->fresh();
            $this->actingAs($this->admin, 'sanctum')->putJson("/api/packages/{$this->plain->id}", ['party_invites_enabled' => $state])->assertOk();
            $this->fresh();
            $this->actingAs($this->admin, 'sanctum')->getJson("/api/packages/{$this->plain->id}")->assertOk()->assertJsonPath('data.party_invites_enabled', $state);
        }
    }

    public function test_an_enabled_confirmation_carries_the_organizer_link_in_the_configured_and_the_built_in_email(): void
    {
        $booking = $this->booking($this->party);
        $link = "https://book.zapzone.test/customer/party/{$booking->id}";
        $this->captureEmails();

        $configured = $this->toCustomer($this->confirm($booking));
        $this->assertNotEmpty($configured, 'the configured confirmation went to the organizer');
        $this->assertStringContainsString('Manage party invitations and waivers', $configured[0]['html']);
        $this->assertStringContainsString('Sign in to invite your party guests, send waiver links and see who has signed.', $configured[0]['html']);
        $this->assertStringContainsString($link, $configured[0]['html']);
        foreach (array_filter($this->sent, fn (array $email) => $email['to'] !== 'organizer@example.test') as $copy) {
            $this->assertStringNotContainsString('Manage party invitations and waivers', $copy['html'], 'staff copies never carry the organizer link');
        }

        EmailNotification::where('company_id', $this->company->id)
            ->whereIn('trigger_type', [EmailNotification::TRIGGER_BOOKING_CREATED, EmailNotification::TRIGGER_BOOKING_CONFIRMED])
            ->update(['is_active' => false]);

        $builtIn = $this->toCustomer($this->confirm($booking));
        $this->assertNotEmpty($builtIn, 'the built-in confirmation went to the organizer');
        $this->assertStringContainsString('Manage party invitations and waivers', $builtIn[0]['html']);
        $this->assertStringContainsString($link, $builtIn[0]['html']);
    }

    public function test_a_disabled_package_gets_the_standard_confirmation(): void
    {
        $booking = $this->booking($this->plain);
        $this->captureEmails();

        $emails = $this->toCustomer($this->confirm($booking));

        $this->assertNotEmpty($emails);
        $this->assertStringNotContainsString('Manage party invitations and waivers', $emails[0]['html']);
        $this->assertStringNotContainsString('/customer/party/', $emails[0]['html']);
    }

    public function test_the_organizer_invites_guests_and_sees_invitation_and_waiver_status(): void
    {
        $booking = $this->booking($this->party);

        $this->asCustomer($this->organizer)->getJson("/api/customer/party/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.booking.reference_number', $booking->reference_number)
            ->assertJsonPath('data.guests', []);

        $this->asCustomer($this->organizer)->postJson("/api/customer/party/{$booking->id}/guests", [
            'recipients' => [
                ['name' => 'Jamie Guest', 'email' => 'jamie@example.test'],
                ['name' => 'Riley Guest', 'email' => 'riley@example.test'],
            ],
        ])->assertOk()->assertJsonPath('data.added', 2);

        $guests = collect($this->asCustomer($this->organizer)->getJson("/api/customer/party/{$booking->id}")->assertOk()->json('data.guests'))->keyBy('name');
        $this->assertSame('Invite sent', $guests['Jamie Guest']['invitation_status']);
        $this->assertSame('Not signed yet', $guests['Jamie Guest']['waiver_status']);

        WaiverInviteRecipient::where('email', 'riley@example.test')->update(['status' => WaiverInviteRecipient::STATUS_COMPLETE]);
        $view = $this->asCustomer($this->organizer)->getJson("/api/customer/party/{$booking->id}")->assertOk()->json('data');
        $riley = collect($view['guests'])->firstWhere('name', 'Riley Guest');
        $this->assertSame('Signed', $riley['waiver_status']);
        $this->assertArrayNotHasKey('email', $riley, 'a signed guest keeps their contact details private');
        $this->assertSame(['added' => 2, 'signed' => 1, 'not_signed' => 1, 'not_delivered' => 0], $view['summary']);
    }

    public function test_a_different_account_staff_and_signed_out_visitors_are_refused(): void
    {
        $booking = $this->booking($this->party);

        $this->asCustomer($this->customer('stranger@example.test'))->getJson("/api/customer/party/{$booking->id}")->assertForbidden();
        $this->asCustomer($this->customer('stranger2@example.test'))->postJson("/api/customer/party/{$booking->id}/guests", ['recipients' => [['name' => 'X', 'email' => 'x@example.test']]])->assertForbidden();

        $this->fresh();
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/customer/party/{$booking->id}")->assertForbidden();

        $this->fresh();
        $this->withHeaders(['Accept' => 'application/json'])->getJson("/api/customer/party/{$booking->id}")->assertUnauthorized();
        $this->assertSame(0, WaiverBulkInvite::count());
    }

    public function test_the_organizer_page_is_refused_when_the_package_does_not_send_party_invitations(): void
    {
        $booking = $this->booking($this->plain);

        $this->asCustomer($this->organizer)->getJson("/api/customer/party/{$booking->id}")->assertNotFound();
        $this->assertSame(0, WaiverBulkInvite::count());
    }

    public function test_resends_and_repeat_visits_reuse_the_same_booking_group(): void
    {
        $booking = $this->booking($this->party);
        $staffGroup = WaiverBulkInvite::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'booking_id' => $booking->id,
            'waiver_template_id' => WaiverTemplate::first()->id,
            'selected_date' => $booking->booking_date,
            'chaperone_name' => 'Pat Parent',
            'status' => WaiverBulkInvite::STATUS_SENT,
        ]);

        $this->asCustomer($this->organizer)->getJson("/api/customer/party/{$booking->id}")->assertOk();
        $this->asCustomer($this->organizer)->postJson("/api/customer/party/{$booking->id}/guests", ['recipients' => [['name' => 'Jamie Guest', 'email' => 'jamie@example.test']]])->assertOk();
        $this->asCustomer($this->organizer)->getJson("/api/customer/party/{$booking->id}")->assertOk();

        $this->assertSame([$staffGroup->id], WaiverBulkInvite::where('booking_id', $booking->id)->pluck('id')->all());
        $this->assertSame(1, $staffGroup->recipients()->count());

        $this->captureEmails();
        $first = $this->toCustomer($this->confirm($booking))[0]['html'];
        $second = $this->toCustomer($this->confirm($booking))[0]['html'];
        preg_match('#https://book\.zapzone\.test/customer/party/\d+\?k=[0-9a-f]{40}#', $first, $a);
        preg_match('#https://book\.zapzone\.test/customer/party/\d+\?k=[0-9a-f]{40}#', $second, $b);
        $this->assertNotEmpty($a);
        $this->assertSame($a, $b);

        EmailNotification::where('company_id', $this->company->id)
            ->whereIn('trigger_type', [EmailNotification::TRIGGER_BOOKING_CREATED, EmailNotification::TRIGGER_BOOKING_CONFIRMED])
            ->update(['is_active' => false]);
        $builtIn = $this->toCustomer($this->confirm($booking))[0]['html'];
        preg_match('#https://book\.zapzone\.test/customer/party/\d+\?k=[0-9a-f]{40}#', $builtIn, $c);
        $this->assertSame($a, $c, 'the built-in confirmation points at the same party page');

        $this->asCustomer($this->organizer)->getJson("/api/customer/party/{$booking->id}")->assertOk()->assertJsonPath('data.summary.added', 1);
        $this->assertSame([$staffGroup->id], WaiverBulkInvite::where('booking_id', $booking->id)->pluck('id')->all());
    }

    public function test_a_guest_checkout_organizer_gets_in_through_the_email_link_with_the_same_email(): void
    {
        $booking = $this->booking($this->party, ['guest_email' => 'Guest.Parent@Example.test']);
        $other = $this->booking($this->party, ['guest_email' => 'someone.else@example.test']);
        $organizer = $this->customer('guest.parent@example.test', false);
        $token = PartyOrganizerLink::token($booking);

        $this->captureEmails();
        $html = collect($this->confirm($booking))->firstWhere('to', 'Guest.Parent@Example.test')['html'] ?? '';
        $this->assertStringContainsString("https://book.zapzone.test/customer/party/{$booking->id}?k={$token}", $html);

        $this->asCustomer($organizer)->getJson("/api/customer/party/{$booking->id}")->assertForbidden();
        $this->asCustomer($organizer)->getJson("/api/customer/party/{$booking->id}?k={$token}")
            ->assertOk()
            ->assertJsonPath('data.booking.reference_number', $booking->reference_number);
        $this->asCustomer($organizer)->postJson("/api/customer/party/{$booking->id}/guests?k={$token}", [
            'recipients' => [['name' => 'Jamie Guest', 'email' => 'jamie@example.test']],
        ])->assertOk()->assertJsonPath('data.added', 1);

        $this->asCustomer($organizer)->getJson("/api/customer/party/{$other->id}?k={$token}")->assertForbidden();
        $this->asCustomer($organizer)->getJson("/api/customer/party/{$booking->id}?k=" . substr($token, 0, 39) . 'x')->assertForbidden();
        $this->asCustomer($organizer)->getJson("/api/customer/party/{$booking->id}?k[]={$token}")->assertForbidden();
        $this->asCustomer($this->customer('stranger@example.test', false))->getJson("/api/customer/party/{$booking->id}?k={$token}")->assertForbidden();

        $booking->update(['guest_email' => 'new.address@example.test']);
        $this->asCustomer($organizer)->getJson("/api/customer/party/{$booking->id}?k={$token}")->assertForbidden();
    }

    public function test_the_organizers_waiver_button_never_opens_a_guests_waiver(): void
    {
        $booking = $this->booking($this->party, ['customer_id' => $this->organizer->id]);
        $own = app(WaiverService::class)->ensureForBooking($booking);
        $this->assertNotNull($own);

        $this->asCustomer($this->organizer)->postJson("/api/customer/party/{$booking->id}/guests", [
            'recipients' => [
                ['name' => 'Jamie Guest', 'email' => 'jamie@example.test'],
                ['name' => 'Riley Guest', 'email' => 'riley@example.test'],
            ],
        ])->assertOk()->assertJsonPath('data.added', 2);

        $guestLinks = \App\Models\Waiver::where('booking_id', $booking->id)->whereNotNull('bulk_invite_id')->get()->map->signing_url->all();
        $this->assertCount(2, $guestLinks);

        $this->captureEmails();
        $html = $this->toCustomer($this->confirm($booking))[0]['html'];
        $this->assertStringContainsString(e($own->fresh()->signing_url), $html, 'the organizer gets their own waiver');
        foreach ($guestLinks as $link) {
            $this->assertStringNotContainsString(e($link), $html, 'never a guest\'s personal waiver link');
        }

        $this->assertSame($own->id, app(WaiverService::class)->ensureForBooking($booking->fresh())->id);
    }

    public function test_guests_added_before_the_organizers_waiver_exists_never_stand_in_for_it(): void
    {
        $booking = $this->booking($this->party, ['customer_id' => $this->organizer->id]);

        $this->asCustomer($this->organizer)->postJson("/api/customer/party/{$booking->id}/guests", [
            'recipients' => [['name' => 'Jamie Guest', 'email' => 'jamie@example.test']],
        ])->assertOk();
        $guest = \App\Models\Waiver::where('booking_id', $booking->id)->whereNotNull('bulk_invite_id')->firstOrFail();

        $this->captureEmails();
        $html = $this->toCustomer($this->confirm($booking))[0]['html'];
        $this->assertStringNotContainsString(e($guest->signing_url), $html);

        $own = app(WaiverService::class)->ensureForBooking($booking->fresh());
        $this->assertNotNull($own);
        $this->assertNotSame($guest->id, $own->id, 'the organizer gets a waiver of their own');
        $this->assertNull($own->bulk_invite_id);
    }

    public function test_a_text_to_staff_or_a_custom_number_never_carries_the_organizers_link(): void
    {
        $texts = [];
        config(['twilio.sid' => 'AC' . str_repeat('3', 32), 'twilio.auth_token' => 'test-token', 'twilio.from_number' => '+18559245172']);
        $sms = \Mockery::mock(\App\Services\SmsService::class);
        $sms->shouldReceive('sendSms')->andReturnUsing(function ($to, $message) use (&$texts) {
            $texts[] = ['to' => $to, 'message' => $message];

            return 'SM' . count($texts);
        });
        $service = new \App\Services\SmsNotificationService();
        (new \ReflectionProperty(\App\Services\SmsNotificationService::class, 'smsService'))->setValue($service, $sms);
        $this->app->instance(\App\Services\SmsNotificationService::class, $service);

        \App\Models\SmsNotification::create([
            'company_id' => $this->company->id,
            'name' => 'Party text',
            'trigger_type' => \App\Models\SmsNotification::TRIGGER_BOOKING_CONFIRMED,
            'entity_type' => \App\Models\SmsNotification::ENTITY_ALL,
            'entity_ids' => [],
            'body' => 'PARTYTEXT {{party_line}}',
            'recipient_types' => [\App\Models\SmsNotification::RECIPIENT_CUSTOMER, \App\Models\SmsNotification::RECIPIENT_CUSTOM],
            'custom_phones' => ['+17345550199'],
            'is_active' => true,
        ]);
        $booking = $this->booking($this->party, ['guest_phone' => '7345550111']);

        app(\App\Services\SmsNotificationService::class)->triggerBookingNotification($booking->fresh(), \App\Models\SmsNotification::TRIGGER_BOOKING_CONFIRMED);

        $partyTexts = collect($texts)->filter(fn ($text) => str_contains($text['message'], 'PARTYTEXT'));
        $this->assertTrue($partyTexts->contains(fn ($text) => str_contains($text['message'], '/customer/party/' . $booking->id . '?k=')), 'the organizer gets the link');
        $staffCopy = $partyTexts->first(fn ($text) => str_contains($text['to'], '7345550199'));
        $this->assertNotNull($staffCopy);
        $this->assertStringNotContainsString('/customer/party/', $staffCopy['message']);
    }

    public function test_the_party_box_sits_inside_the_built_in_email_and_never_doubles_up(): void
    {
        $service = app(EmailNotificationService::class);
        $box = ['party_section' => 'BOX'];

        $this->assertSame('A {{ party_section }} B', $service->withPartySection('A {{ party_section }} B', $box));
        $this->assertSame('Go: {{party_link}}', $service->withPartySection('Go: {{party_link}}', $box));
        $this->assertSame("<div>{{ waiver_section }}\n{{party_section}}</div>", $service->withPartySection('<div>{{ waiver_section }}</div>', $box));
        $this->assertSame("<div><p>Hi</p>{{party_section}}\n</div>", $service->withPartySection('<div><p>Hi</p></div>', $box));

        $booking = $this->booking($this->party);
        EmailNotification::where('company_id', $this->company->id)
            ->whereIn('trigger_type', [EmailNotification::TRIGGER_BOOKING_CREATED, EmailNotification::TRIGGER_BOOKING_CONFIRMED])
            ->update(['is_active' => false]);
        $this->captureEmails();
        $html = $this->toCustomer($this->confirm($booking))[0]['html'];

        $this->assertSame(1, substr_count($html, 'Manage party invitations and waivers'));
        $this->assertLessThan(strpos($html, 'All rights reserved'), strpos($html, 'Manage party invitations and waivers'), 'the box comes before the footer');
    }

    public function test_no_party_box_is_sent_when_no_waiver_can_be_used_for_the_party(): void
    {
        WaiverTemplate::query()->delete();
        $booking = $this->booking($this->party);
        $this->captureEmails();

        $html = $this->toCustomer($this->confirm($booking))[0]['html'] ?? '';

        $this->assertNotSame('', $html);
        $this->assertStringNotContainsString('Manage party invitations and waivers', $html);
    }

    public function test_resending_one_guest_is_held_to_ten_a_minute(): void
    {
        $booking = $this->booking($this->party, ['customer_id' => $this->organizer->id]);
        $this->asCustomer($this->organizer)->postJson("/api/customer/party/{$booking->id}/guests", ['recipients' => [['name' => 'Jamie Guest', 'email' => 'jamie@example.test']]])->assertOk();
        $guestId = WaiverInviteRecipient::where('email', 'jamie@example.test')->value('id');
        $this->withMiddleware(ThrottleRequests::class);

        $statuses = [];
        foreach (range(1, 11) as $attempt) {
            $statuses[] = $this->asCustomer($this->organizer)->postJson("/api/customer/party/{$booking->id}/guests/{$guestId}/resend")->status();
        }

        $this->assertNotContains(429, array_slice($statuses, 0, 10));
        $this->assertSame(429, $statuses[10]);
    }

    public function test_a_party_guest_list_has_a_size_limit(): void
    {
        $booking = $this->booking($this->party, ['customer_id' => $this->organizer->id, 'participants' => 10]);
        $rows = fn (int $from, int $count) => array_map(fn ($n) => ['name' => "Guest {$n}", 'email' => "guest{$n}@example.test"], range($from, $from + $count - 1));
        $url = "/api/customer/party/{$booking->id}/guests";

        $this->asCustomer($this->organizer)->postJson($url, ['recipients' => $rows(1, 51)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You can add up to 50 guests at a time. Add the rest in another batch.');
        $this->assertSame(0, WaiverInviteRecipient::count());

        $this->asCustomer($this->organizer)->postJson($url, ['recipients' => $rows(1, 45)])->assertOk();
        $this->asCustomer($this->organizer)->postJson($url, ['recipients' => $rows(46, 6)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A party guest list can hold up to 50 people. This list has 45, so you can add 5 more.');
        $this->asCustomer($this->organizer)->postJson($url, ['recipients' => $rows(44, 7)])->assertOk();
        $this->assertSame(50, WaiverInviteRecipient::count(), 'two guests already on the list do not count against the space left');
        $this->asCustomer($this->organizer)->postJson($url, ['recipients' => $rows(51, 1)])
            ->assertStatus(422)
            ->assertJsonPath('message', "This party's guest list is full (50 people). Please call the venue if you need to invite more.");

        $this->assertSame(50, WaiverInviteRecipient::count());
        $this->assertSame(60, \App\Http\Controllers\Api\PartyOrganizerController::guestLimit($booking->fill(['participants' => 20])));

        $this->asCustomer($this->organizer)->postJson($url, ['recipients' => [['name' => 'Odd Row', 'email' => ['odd@example.test'], 'phone' => ['8105550199']]]])
            ->assertStatus(422);
    }

    public function test_adding_guests_and_sending_again_are_held_per_party_each_hour(): void
    {
        $booking = $this->booking($this->party, ['customer_id' => $this->organizer->id]);
        $this->asCustomer($this->organizer)->postJson("/api/customer/party/{$booking->id}/guests", ['recipients' => [['name' => 'Jamie Guest', 'email' => 'jamie@example.test']]])->assertOk();
        $guestId = WaiverInviteRecipient::where('email', 'jamie@example.test')->value('id');
        $this->withMiddleware(ThrottleRequests::class);

        $statuses = [];
        foreach (range(1, 6) as $minute) {
            foreach (range(1, 10) as $attempt) {
                $statuses[] = $this->asCustomer($this->organizer)->postJson("/api/customer/party/{$booking->id}/guests/{$guestId}/resend")->status();
            }
            $this->travel(61)->seconds();
        }
        $statuses[] = $this->asCustomer($this->organizer)->postJson("/api/customer/party/{$booking->id}/guests/{$guestId}/resend")->status();

        $this->assertNotContains(429, array_slice($statuses, 0, 60));
        $this->assertSame(429, $statuses[60], 'one party is held to 60 sends an hour');
        $this->asCustomer($this->organizer)->postJson('/api/customer/party/0' . $booking->id . "/guests/{$guestId}/resend")
            ->assertStatus(429)
            ->assertJsonPath('success', false);

        $adds = [];
        foreach (range(1, 11) as $attempt) {
            $adds[] = $this->asCustomer($this->organizer)->postJson("/api/customer/party/{$booking->id}/guests", ['recipients' => [['name' => "Add {$attempt}", 'email' => "add{$attempt}@example.test"]]])->status();
        }
        $this->assertNotContains(429, array_slice($adds, 0, 10));
        $this->assertSame(429, $adds[10], 'adding guests has its own limit of ten a minute');
    }

    public function test_a_stranger_cannot_use_up_an_organizers_hourly_sends(): void
    {
        $booking = $this->booking($this->party, ['customer_id' => $this->organizer->id]);
        $this->asCustomer($this->organizer)->postJson("/api/customer/party/{$booking->id}/guests", ['recipients' => [['name' => 'Jamie Guest', 'email' => 'jamie@example.test']]])->assertOk();
        $guestId = WaiverInviteRecipient::where('email', 'jamie@example.test')->value('id');
        $stranger = $this->customer('stranger.limits@example.test');
        $this->withMiddleware(ThrottleRequests::class);

        foreach (range(1, 7) as $minute) {
            foreach (range(1, 10) as $attempt) {
                $this->asCustomer($stranger)->postJson("/api/customer/party/{$booking->id}/guests/{$guestId}/resend")->assertStatus(403);
            }
            $this->travel(61)->seconds();
        }

        $this->asCustomer($this->organizer)->postJson("/api/customer/party/{$booking->id}/guests/{$guestId}/resend")->assertOk();
    }

    public function test_two_adds_at_the_same_moment_cannot_both_slip_past_the_guest_limit(): void
    {
        $booking = $this->booking($this->party, ['customer_id' => $this->organizer->id]);
        $this->asCustomer($this->organizer)->getJson("/api/customer/party/{$booking->id}")->assertOk();
        $held = \Illuminate\Support\Facades\Cache::lock('party-guests:' . $booking->id, 120);
        $this->assertTrue($held->get());

        $this->asCustomer($this->organizer)->postJson("/api/customer/party/{$booking->id}/guests", ['recipients' => [['name' => 'Jamie Guest', 'email' => 'jamie@example.test']]])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Your last guests are still being added. Please wait a moment and try again.');
        $this->assertSame(0, WaiverInviteRecipient::count());

        $held->release();
        $this->asCustomer($this->organizer)->postJson("/api/customer/party/{$booking->id}/guests", ['recipients' => [['name' => 'Jamie Guest', 'email' => 'jamie@example.test']]])->assertOk();
    }

    public function test_page_views_never_store_the_party_link_key(): void
    {
        $this->fresh();
        $this->withHeaders(['Accept' => 'application/json'])->postJson('/api/analytics/track', [
            'event_type' => 'page_view',
            'event_name' => 'page_view',
            'page_type' => 'party_organizer',
            'page_url' => 'https://book.zapzone.test/customer/party/41?k=secretkey123&utm_source=email',
            'page_path' => '/customer/party/41',
            'referrer' => 'https://book.zapzone.test/customer/login?next=%2Fcustomer%2Fparty%2F41%3Fk%3Dsecretkey123',
            'visitor_id' => 'visitor-party-1',
            'session_id' => 'session-party-1',
        ])->assertSuccessful();

        $stored = \App\Models\PageView::query()->latest('id')->first();

        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('secretkey123', (string) $stored->page_url);
        $this->assertStringContainsString('utm_source=email', (string) $stored->page_url);
        $this->assertStringNotContainsString('secretkey123', urldecode((string) $stored->referrer));
        $this->assertStringContainsString('customer%2Fparty%2F41', (string) $stored->referrer);

        $this->fresh();
        $this->withHeaders(['Accept' => 'application/json'])->postJson('/api/analytics/track/batch', [
            'events' => [[
                'event_type' => 'engagement',
                'event_name' => 'click',
                'page_url' => 'https://book.zapzone.test/customer/login?next=%2Fcustomer%2Fparty%2F41',
                'page_path' => '/customer/login',
                'visitor_id' => 'visitor-party-1',
                'session_id' => 'session-party-1',
                'metadata' => ['label' => 'Create an account', 'tag' => 'a', 'href' => '/customer/register?next=%2Fcustomer%2Fparty%2F41%3Fk%3Dsecretkey123'],
            ]],
        ])->assertSuccessful();

        $click = \App\Models\PageView::query()->latest('id')->first();
        $this->assertSame('click', $click->event_name);
        $this->assertStringNotContainsString('secretkey123', urldecode((string) json_encode($click->metadata)));
        $this->assertStringContainsString('/customer/register', (string) ($click->metadata['href'] ?? ''));
    }

    public function test_the_organizer_routes_keep_working_for_a_customer_with_rate_limits_on(): void
    {
        $booking = $this->booking($this->party);
        $this->withMiddleware(ThrottleRequests::class);

        $this->asCustomer($this->organizer)->getJson("/api/customer/party/{$booking->id}")->assertOk();
    }
}
