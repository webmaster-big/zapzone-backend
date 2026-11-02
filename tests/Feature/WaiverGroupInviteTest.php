<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Customer;
use App\Models\EmailNotification;
use App\Models\Location;
use App\Models\Package;
use App\Models\SmsNotification;
use App\Models\User;
use App\Models\Waiver;
use App\Models\WaiverBulkInvite;
use App\Models\WaiverInviteRecipient;
use App\Models\WaiverTemplate;
use App\Services\EmailNotificationService;
use App\Services\SmsNotificationService;
use App\Services\SmsService;
use App\Services\WaiverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WaiverGroupInviteTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Location $location;
    private User $manager;
    private WaiverTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gmail.enabled' => false,
            'services.resend.enabled' => false,
            'twilio.sid' => null,
            'app.frontend_url' => 'https://book.zapzone.test',
        ]);
        Mail::fake();
        Storage::fake(config('filesystems.default'));
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);
        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Waterford',
            'address' => '1 Test Way',
            'city' => 'Waterford',
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => 'waterford@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $this->manager = User::create([
            'first_name' => 'Morgan',
            'last_name' => 'Manager',
            'email' => 'manager@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'location_manager',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
        $this->template = WaiverTemplate::create([
            'company_id' => $this->company->id,
            'title' => 'General Activity Waiver',
            'status' => WaiverTemplate::STATUS_ACTIVE,
            'is_default' => true,
            'body_text' => '<p>I release {{company_name}} for {{visit_date}}.</p>',
            'kind' => WaiverTemplate::KIND_STANDARD,
            'assigned_package_ids' => [],
            'electronic_consent_enabled' => true,
        ]);
        app(WaiverService::class)->syncVersion($this->template);
    }

    private function createInvite(array $overrides = [])
    {
        return $this->actingAs($this->manager, 'sanctum')->postJson('/api/waiver-bulk-invites', array_merge([
            'waiver_template_id' => $this->template->id,
            'selected_date' => now()->addDays(3)->toDateString(),
            'chaperone_name' => 'Coach Taylor',
            'chaperone_email' => 'coach@example.test',
            'allow_shareable_link' => true,
        ], $overrides));
    }

    private function invite(array $overrides = []): WaiverBulkInvite
    {
        $id = $this->createInvite($overrides)->assertCreated()->json('data.id');

        return WaiverBulkInvite::findOrFail($id);
    }

    private function signature(array $overrides = []): array
    {
        return array_merge([
            'adult_first_name' => 'Jamie',
            'adult_last_name' => 'Parent',
            'adult_email' => 'jamie@example.test',
            'adult_phone' => '(248) 555-0142',
            'adult_dob' => '1985-04-12',
            'typed_legal_name' => 'Jamie Parent',
            'agreement_accepted' => true,
            'electronic_consent_accepted' => true,
            'minors' => [[
                'first_name' => 'Riley',
                'last_name' => 'Parent',
                'date_of_birth' => '2015-06-01',
                'relationship' => 'Parent',
            ]],
        ], $overrides);
    }

    private function emailsFail(): void
    {
        $this->partialMock(EmailNotificationService::class, function ($mock) {
            $mock->shouldReceive('sendEmail')->andThrow(new \RuntimeException('The address was refused'));
        });
    }

    private function textsGoThrough(\Closure $send): void
    {
        config([
            'twilio.sid' => 'AC' . str_repeat('2', 32),
            'twilio.auth_token' => 'test-token',
            'twilio.from_number' => '+18559245172',
        ]);

        $sms = \Mockery::mock(SmsService::class);
        $sms->shouldReceive('sendSms')->andReturnUsing($send);

        $service = new SmsNotificationService();
        (new \ReflectionProperty(SmsNotificationService::class, 'smsService'))->setValue($service, $sms);
        $this->app->instance(SmsNotificationService::class, $service);
    }

    public function test_a_parent_who_signs_through_the_group_link_shows_up_as_complete_for_the_chaperone(): void
    {
        $invite = $this->invite();

        $share = $this->getJson("/api/waivers/bulk/{$invite->manage_token}")
            ->assertOk()
            ->assertJsonPath('data.allow_shareable_link', true)
            ->json('data.shareable_token');
        $this->assertSame($invite->shareable_token, $share);

        $this->getJson("/api/waivers/group/{$share}")
            ->assertOk()
            ->assertJsonPath('data.group.chaperone_name', 'Coach Taylor')
            ->assertJsonPath('data.template.title', 'General Activity Waiver')
            ->assertJsonPath('data.prefill', []);
        $this->assertSame(0, Waiver::count());

        $id = $this->postJson("/api/waivers/group/{$share}/submit", $this->signature())
            ->assertCreated()
            ->json('data.id');

        $waiver = Waiver::findOrFail($id);
        $this->assertTrue($waiver->isCompleted());
        $this->assertSame($invite->id, (int) $waiver->bulk_invite_id);
        $this->assertSame(Waiver::SOURCE_BULK_INVITE, $waiver->source);
        $this->assertSame($invite->selected_date->toDateString(), $waiver->selected_date->toDateString());
        $this->assertSame(1, $waiver->minors()->count());

        $recipient = WaiverInviteRecipient::where('waiver_bulk_invite_id', $invite->id)->sole();
        $this->assertSame('Jamie Parent', $recipient->name);
        $this->assertSame(WaiverInviteRecipient::STATUS_COMPLETE, $recipient->status);
        $this->assertSame($waiver->id, (int) $recipient->waiver_id);
        $this->assertSame($recipient->id, (int) $waiver->fresh()->bulk_invite_recipient_id);

        $this->getJson("/api/waivers/bulk/{$invite->manage_token}")
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.complete', 1)
            ->assertJsonPath('data.recipients.0.name', 'Jamie Parent')
            ->assertJsonMissingPath('data.recipients.0.email');
    }

    public function test_the_group_link_only_works_when_staff_allowed_it(): void
    {
        $invite = $this->invite(['allow_shareable_link' => false]);
        $this->assertNull($invite->shareable_token);

        $this->getJson("/api/waivers/bulk/{$invite->manage_token}")
            ->assertOk()
            ->assertJsonPath('data.shareable_token', null);

        $invite->forceFill(['shareable_token' => 'planted-token-that-was-never-switched-on'])->save();

        $this->getJson('/api/waivers/group/planted-token-that-was-never-switched-on')->assertNotFound();
        $this->postJson('/api/waivers/group/planted-token-that-was-never-switched-on/submit', $this->signature())->assertNotFound();
        $this->getJson('/api/waivers/group/' . str_repeat('x', 48))->assertNotFound();
        $this->getJson("/api/waivers/group/{$invite->manage_token}")->assertNotFound();
        $this->assertSame(0, Waiver::count());
    }

    public function test_an_older_invite_without_a_link_gets_one_when_the_chaperone_opens_their_page(): void
    {
        $invite = $this->invite();
        $invite->forceFill(['shareable_token' => null])->save();

        $share = $this->getJson("/api/waivers/bulk/{$invite->manage_token}")
            ->assertOk()
            ->json('data.shareable_token');

        $this->assertSame(48, strlen($share));
        $this->getJson("/api/waivers/group/{$share}")->assertOk();
    }

    public function test_a_parent_the_chaperone_already_invited_completes_their_own_row(): void
    {
        $invite = $this->invite();
        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/recipients", [
            'recipients' => [['name' => 'Jamie P', 'email' => 'Jamie@Example.test']],
        ])->assertOk();
        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/send")
            ->assertOk()
            ->assertJsonPath('data.sent', 1);

        $pending = Waiver::where('bulk_invite_id', $invite->id)->sole();

        $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature())
            ->assertCreated()
            ->assertJsonPath('data.id', $pending->id);

        $this->assertSame(1, Waiver::count());
        $this->assertTrue($pending->fresh()->isCompleted());
        $recipient = WaiverInviteRecipient::where('waiver_bulk_invite_id', $invite->id)->sole();
        $this->assertSame(WaiverInviteRecipient::STATUS_COMPLETE, $recipient->status);
        $this->assertSame('Jamie P', $recipient->name);
    }

    public function test_the_same_parent_cannot_sign_twice_through_the_group_link(): void
    {
        $invite = $this->invite();

        $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature())->assertCreated();
        $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature(['adult_email' => 'other@example.test']))
            ->assertStatus(409);

        $this->assertSame(1, Waiver::count());
        $this->assertSame(1, WaiverInviteRecipient::count());
    }

    public function test_the_group_link_refuses_a_phone_that_cannot_be_reached(): void
    {
        $invite = $this->invite();

        $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature(['adult_phone' => '555']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['adult_phone']);

        $this->assertSame(0, Waiver::count());
        $this->assertSame(0, WaiverInviteRecipient::count());
    }

    public function test_an_invite_that_reaches_no_one_is_marked_failed_not_sent(): void
    {
        $invite = $this->invite();
        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/recipients", [
            'recipients' => [['name' => 'Bad Address', 'email' => 'bad@example.test']],
        ])->assertOk();

        $this->emailsFail();

        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/send")
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.sent', 0)
            ->assertJsonPath('data.failed', 1);

        $recipient = WaiverInviteRecipient::sole();
        $this->assertSame(WaiverInviteRecipient::STATUS_FAILED, $recipient->status);
        $this->assertNull($recipient->last_sent_at);

        $this->getJson("/api/waivers/bulk/{$invite->manage_token}")
            ->assertJsonPath('data.summary.failed', 1)
            ->assertJsonPath('data.recipients.0.status', 'failed');

        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/recipients/{$recipient->id}/resend")
            ->assertStatus(422);
        $this->assertSame(WaiverInviteRecipient::STATUS_FAILED, $recipient->fresh()->status);
        $this->assertSame(0, $recipient->fresh()->resent_count);

        $this->actingAs($this->manager, 'sanctum')->getJson('/api/waiver-bulk-invites')
            ->assertOk()
            ->assertJsonPath('data.bulk_invites.0.failed_count', 1)
            ->assertJsonPath('data.bulk_invites.0.complete_count', 0);
    }

    public function test_a_failed_invite_goes_through_when_sending_works_again(): void
    {
        $invite = $this->invite();
        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/recipients", [
            'recipients' => [['name' => 'Sam Guardian', 'email' => 'sam@example.test']],
        ])->assertOk();
        WaiverInviteRecipient::query()->update(['status' => WaiverInviteRecipient::STATUS_FAILED]);

        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/send")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sent', 1)
            ->assertJsonPath('data.failed', 0);

        $recipient = WaiverInviteRecipient::sole();
        $this->assertSame(WaiverInviteRecipient::STATUS_SENT, $recipient->status);
        $this->assertNotNull($recipient->last_sent_at);
    }

    public function test_a_resend_that_fails_does_not_undo_an_invite_that_already_went_out(): void
    {
        $invite = $this->invite();
        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/recipients", [
            'recipients' => [['name' => 'Sam Guardian', 'email' => 'sam@example.test']],
        ])->assertOk();
        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/send")->assertJsonPath('data.sent', 1);
        $recipient = WaiverInviteRecipient::sole();

        $this->emailsFail();

        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/recipients/{$recipient->id}/resend")
            ->assertStatus(422);
        $this->assertSame(WaiverInviteRecipient::STATUS_SENT, $recipient->fresh()->status);
    }

    public function test_a_parent_reached_only_by_text_counts_as_sent(): void
    {
        $texts = new \ArrayObject();
        $this->textsGoThrough(function ($to) use ($texts) {
            $texts[] = $to;

            return 'SM' . count($texts);
        });

        $invite = $this->invite(['chaperone_email' => null, 'chaperone_phone' => '2485550100']);
        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/recipients", [
            'recipients' => [['name' => 'Text Only', 'phone' => '(248) 555-0177']],
        ])->assertOk();

        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/send")
            ->assertOk()
            ->assertJsonPath('data.sent', 1);

        $this->assertSame(WaiverInviteRecipient::STATUS_SENT, WaiverInviteRecipient::sole()->status);
        $this->assertContains('2485550177', $texts->getArrayCopy());
    }

    public function test_a_text_the_provider_refuses_marks_the_parent_failed(): void
    {
        $this->textsGoThrough(fn () => throw new \RuntimeException('Twilio refused the number'));

        $invite = $this->invite();
        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/recipients", [
            'recipients' => [['name' => 'Text Only', 'phone' => '(248) 555-0177']],
        ])->assertOk();

        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/send")
            ->assertJsonPath('data.failed', 1);

        $this->assertSame(WaiverInviteRecipient::STATUS_FAILED, WaiverInviteRecipient::sole()->status);
    }

    public function test_staff_hear_whether_the_chaperone_was_reached(): void
    {
        $this->createInvite()
            ->assertCreated()
            ->assertJsonPath('data.chaperone_notified', true)
            ->assertJsonPath('message', 'Group invite created and the chaperone was notified.');

        $this->emailsFail();

        $response = $this->createInvite(['chaperone_email' => 'unreachable@example.test'])
            ->assertCreated()
            ->assertJsonPath('data.chaperone_notified', false);
        $this->assertStringContainsString('did not go out', $response->json('message'));

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/waiver-bulk-invites/' . $response->json('data.id') . '/resend')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_an_invite_with_no_chaperone_contact_tells_staff_to_share_the_link(): void
    {
        $id = $this->createInvite(['chaperone_email' => null])
            ->assertCreated()
            ->assertJsonPath('data.chaperone_notified', false)
            ->assertJsonPath('message', 'Group invite created. Copy the chaperone link and send it to them.')
            ->json('data.id');

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/waiver-bulk-invites/{$id}/resend")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This chaperone has no email or phone. Copy the chaperone link and send it to them.');
    }

    public function test_staff_see_how_many_parents_have_signed(): void
    {
        $invite = $this->invite();
        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/recipients", [
            'recipients' => [
                ['name' => 'Waiting One', 'email' => 'one@example.test'],
                ['name' => 'Waiting Two', 'email' => 'two@example.test'],
            ],
        ])->assertOk();
        $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature())->assertCreated();

        $this->actingAs($this->manager, 'sanctum')->getJson('/api/waiver-bulk-invites')
            ->assertOk()
            ->assertJsonPath('data.bulk_invites.0.recipients_count', 3)
            ->assertJsonPath('data.bulk_invites.0.complete_count', 1)
            ->assertJsonPath('data.bulk_invites.0.failed_count', 0);

        $this->actingAs($this->manager, 'sanctum')->getJson("/api/waiver-bulk-invites/{$invite->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data.recipients');
    }

    public function test_the_group_form_shows_the_waiver_text_filled_in_for_the_group(): void
    {
        $invite = $this->invite();

        $body = $this->getJson("/api/waivers/group/{$invite->shareable_token}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.body');

        $this->assertStringContainsString('ZapZone Test', $body);
        $this->assertStringContainsString($invite->selected_date->format('F j, Y'), $body);
    }

    public function test_an_escape_room_waiver_never_opens_through_a_group_link(): void
    {
        $invite = $this->invite();
        WaiverTemplate::whereKey($this->template->id)->update(['kind' => WaiverTemplate::KIND_ESCAPE_ROOM]);

        $this->getJson("/api/waivers/group/{$invite->shareable_token}")->assertNotFound();
        $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature())->assertNotFound();
        $this->assertSame(0, Waiver::count());
    }

    public function test_a_deleted_invite_or_waiver_closes_the_group_link(): void
    {
        $invite = $this->invite();
        $invite->delete();

        $this->getJson("/api/waivers/group/{$invite->shareable_token}")->assertNotFound();
        $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature())->assertNotFound();

        $other = $this->invite(['chaperone_name' => 'Coach Rivera']);
        $this->template->delete();

        $this->getJson("/api/waivers/group/{$other->shareable_token}")->assertNotFound();
        $this->postJson("/api/waivers/group/{$other->shareable_token}/submit", $this->signature())->assertNotFound();
        $this->assertSame(0, Waiver::count());
    }

    public function test_a_parent_on_the_list_is_matched_by_phone_when_they_sign_with_another_email(): void
    {
        $invite = $this->invite();
        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/recipients", [
            'recipients' => [['name' => 'Jamie By Text', 'phone' => '248-555-0142']],
        ])->assertOk();

        $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature(['adult_email' => 'work-address@example.test']))
            ->assertCreated();

        $recipient = WaiverInviteRecipient::sole();
        $this->assertSame(WaiverInviteRecipient::STATUS_COMPLETE, $recipient->status);
        $this->assertSame('Jamie By Text', $recipient->name);
        $this->assertSame(1, Waiver::count());
    }

    public function test_a_parent_on_another_groups_list_is_not_completed_by_this_groups_link(): void
    {
        $mine = $this->invite();
        $theirs = $this->invite(['chaperone_name' => 'Coach Rivera', 'selected_date' => now()->addDays(5)->toDateString()]);
        $this->postJson("/api/waivers/bulk/{$theirs->manage_token}/recipients", [
            'recipients' => [['name' => 'Jamie Elsewhere', 'email' => 'jamie@example.test']],
        ])->assertOk();

        $this->postJson("/api/waivers/group/{$mine->shareable_token}/submit", $this->signature())->assertCreated();

        $theirRow = WaiverInviteRecipient::where('waiver_bulk_invite_id', $theirs->id)->sole();
        $this->assertSame(WaiverInviteRecipient::STATUS_NOT_SENT, $theirRow->status);
        $this->assertNull($theirRow->waiver_id);
        $this->assertSame(1, WaiverInviteRecipient::where('waiver_bulk_invite_id', $mine->id)->count());
        $this->assertSame($mine->id, (int) Waiver::sole()->bulk_invite_id);
    }

    public function test_when_repeat_signatures_are_allowed_each_one_gets_its_own_row(): void
    {
        $this->template->update(['duplicate_rule' => WaiverTemplate::DUPLICATE_ALLOW]);
        $invite = $this->invite();

        $first = $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature())->assertCreated()->json('data.id');
        $second = $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature())->assertCreated()->json('data.id');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, WaiverInviteRecipient::where('status', WaiverInviteRecipient::STATUS_COMPLETE)->count());
        $this->assertSame(2, Waiver::completed()->count());
    }

    public function test_a_group_signature_is_attached_to_the_invites_booking(): void
    {
        $package = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Birthday Bash',
            'description' => 'Test package',
            'category' => 'Party',
            'price' => 30,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 20,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
        $date = now()->addDays(3)->toDateString();
        $booking = Booking::create([
            'reference_number' => 'GRP1001',
            'booking_date' => $date,
            'booking_time' => '14:00',
            'duration' => 60,
            'duration_unit' => 'minutes',
            'location_id' => $this->location->id,
            'package_id' => $package->id,
            'participants' => 12,
            'total_amount' => 360,
            'status' => 'confirmed',
            'guest_name' => 'Coach Taylor',
            'guest_email' => 'coach@example.test',
            'guest_phone' => '2485550100',
        ]);
        $invite = $this->invite(['booking_id' => $booking->id, 'selected_date' => $date]);

        $this->getJson("/api/waivers/group/{$invite->shareable_token}")
            ->assertOk()
            ->assertJsonPath('data.template.title', 'General Activity Waiver');
        $id = $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature())->assertCreated()->json('data.id');

        $waiver = Waiver::findOrFail($id);
        $this->assertSame($booking->id, (int) $waiver->booking_id);
        $this->assertSame($this->location->id, (int) $waiver->location_id);
    }

    public function test_an_address_on_the_dont_send_to_list_is_reported_as_not_delivered(): void
    {
        $invite = $this->invite();
        EmailNotification::where('company_id', $this->company->id)
            ->where('trigger_type', EmailNotification::TRIGGER_WAIVER_PARENT_INVITE)
            ->update(['excluded_emails' => json_encode(['blocked@example.test'])]);
        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/recipients", [
            'recipients' => [
                ['name' => 'Blocked Parent', 'email' => 'blocked@example.test'],
                ['name' => 'Open Parent', 'email' => 'open@example.test'],
            ],
        ])->assertOk();

        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/send")
            ->assertJsonPath('data.sent', 1)
            ->assertJsonPath('data.failed', 1);

        $this->assertSame(WaiverInviteRecipient::STATUS_FAILED, WaiverInviteRecipient::where('email', 'blocked@example.test')->value('status'));
        $this->assertSame(WaiverInviteRecipient::STATUS_SENT, WaiverInviteRecipient::where('email', 'open@example.test')->value('status'));
    }

    public function test_the_group_link_is_rate_limited_and_a_signed_in_customer_does_not_break_it(): void
    {
        $invite = $this->invite();
        $customer = Customer::create([
            'first_name' => 'Signed',
            'last_name' => 'In',
            'email' => 'signed-in@example.test',
            'phone' => '7345550000',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);
        $token = $customer->createToken('customer')->plainTextToken;
        $this->withMiddleware(ThrottleRequests::class);

        $this->withToken($token)->getJson("/api/waivers/group/{$invite->shareable_token}")->assertOk();

        for ($i = 0; $i < 30; $i++) {
            $this->withToken($token)->postJson("/api/waivers/group/{$invite->shareable_token}/submit", [])->assertStatus(422);
        }
        $this->withToken($token)->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature())->assertStatus(429);
        $this->assertSame(0, Waiver::count());
    }

    private function addParents(WaiverBulkInvite $invite, array $rows): void
    {
        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/recipients", ['recipients' => $rows])->assertOk();
    }

    public function test_a_parent_signs_and_is_recorded_against_the_waiver_text_they_actually_read(): void
    {
        $invite = $this->invite();
        $this->addParents($invite, [['name' => 'Jamie Parent', 'email' => 'jamie@example.test']]);
        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/send")->assertJsonPath('data.sent', 1);
        $pending = Waiver::where('bulk_invite_id', $invite->id)->sole();
        $oldVersionId = (int) $pending->waiver_template_version_id;

        $this->template->update(['body_text' => '<p>New wording for {{company_name}}.</p>']);
        app(WaiverService::class)->syncVersion($this->template->fresh());

        $shown = $this->getJson("/api/waivers/group/{$invite->shareable_token}")->assertOk()->json('data.template.version');
        $this->assertStringContainsString('New wording', $this->getJson("/api/waivers/group/{$invite->shareable_token}")->json('data.body'));

        $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature(['waiver_template_version' => $shown - 1]))
            ->assertStatus(409);
        $this->assertFalse($pending->fresh()->isCompleted());

        $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature(['waiver_template_version' => $shown]))
            ->assertCreated()
            ->assertJsonPath('data.id', $pending->id);

        $signed = $pending->fresh(['version']);
        $this->assertNotSame($oldVersionId, (int) $signed->waiver_template_version_id);
        $this->assertSame($shown, (int) $signed->version->version);
    }

    public function test_sending_skips_a_parent_who_signed_through_the_group_link_while_the_send_was_running(): void
    {
        $invite = $this->invite();
        $this->addParents($invite, [
            ['name' => 'First Parent', 'email' => 'first@example.test'],
            ['name' => 'Second Parent', 'email' => 'second@example.test'],
        ]);
        $second = WaiverInviteRecipient::where('email', 'second@example.test')->sole();
        $notified = new \ArrayObject();

        $this->partialMock(EmailNotificationService::class, function ($mock) use ($second, $notified) {
            $mock->shouldReceive('triggerWaiverNotification')->andReturnUsing(function (Waiver $waiver) use ($second, $notified) {
                $notified[] = $waiver->adult_email;
                WaiverInviteRecipient::whereKey($second->id)->update(['status' => WaiverInviteRecipient::STATUS_COMPLETE]);

                return 1;
            });
        });

        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/send")->assertJsonPath('data.sent', 1);

        $this->assertSame(['first@example.test'], $notified->getArrayCopy());
        $this->assertSame(WaiverInviteRecipient::STATUS_COMPLETE, $second->fresh()->status);
        $this->assertNull($second->fresh()->waiver_id);
        $this->assertSame(1, Waiver::count());
    }

    public function test_a_staff_copy_does_not_hide_that_the_parent_was_not_reached(): void
    {
        $invite = $this->invite();
        EmailNotification::where('company_id', $this->company->id)
            ->where('trigger_type', EmailNotification::TRIGGER_WAIVER_PARENT_INVITE)
            ->update([
                'recipient_types' => json_encode([EmailNotification::RECIPIENT_CUSTOMER, EmailNotification::RECIPIENT_CUSTOM]),
                'custom_emails' => json_encode(['front-desk-copy@example.test']),
            ]);
        $this->addParents($invite, [['name' => 'Bounce Parent', 'email' => 'bounce@example.test']]);
        $sent = new \ArrayObject();
        $this->partialMock(EmailNotificationService::class, function ($mock) use ($sent) {
            $mock->shouldReceive('sendEmail')->andReturnUsing(function ($to) use ($sent) {
                if ($to === 'bounce@example.test') {
                    throw new \RuntimeException('Mailbox does not exist');
                }
                $sent[] = $to;
            });
        });

        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/send")->assertJsonPath('data.failed', 1);

        $this->assertContains('front-desk-copy@example.test', $sent->getArrayCopy());
        $this->assertSame(WaiverInviteRecipient::STATUS_FAILED, WaiverInviteRecipient::sole()->status);
    }

    public function test_a_text_that_goes_out_still_counts_when_the_email_part_crashes(): void
    {
        $this->textsGoThrough(fn () => 'SM1');
        $invite = $this->invite();
        $this->addParents($invite, [['name' => 'Both Ways', 'email' => 'both@example.test', 'phone' => '2485550199']]);
        $this->partialMock(EmailNotificationService::class, function ($mock) {
            $mock->shouldReceive('buildVariables')->andThrow(new \TypeError('Unexpected null'));
        });

        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/send")->assertJsonPath('data.sent', 1);

        $this->assertSame(WaiverInviteRecipient::STATUS_SENT, WaiverInviteRecipient::sole()->status);
    }

    public function test_two_parents_sharing_a_phone_each_complete_their_own_row(): void
    {
        $invite = $this->invite();
        $this->addParents($invite, [
            ['name' => 'Mom Parent', 'email' => 'mom@example.test', 'phone' => '2485550142'],
            ['name' => 'Dad Parent', 'email' => 'dad@example.test', 'phone' => '2485550142'],
        ]);

        $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature([
            'adult_first_name' => 'Dad', 'adult_email' => 'dad@example.test', 'typed_legal_name' => 'Dad Parent',
        ]))->assertCreated();

        $this->assertSame(WaiverInviteRecipient::STATUS_COMPLETE, WaiverInviteRecipient::where('email', 'dad@example.test')->value('status'));
        $this->assertSame(WaiverInviteRecipient::STATUS_NOT_SENT, WaiverInviteRecipient::where('email', 'mom@example.test')->value('status'));

        $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature([
            'adult_first_name' => 'Grandma', 'adult_email' => 'grandma@example.test', 'typed_legal_name' => 'Grandma Parent', 'adult_phone' => '2485550142',
        ]))->assertStatus(409);
        $this->assertSame(WaiverInviteRecipient::STATUS_NOT_SENT, WaiverInviteRecipient::where('email', 'mom@example.test')->value('status'));
    }

    public function test_a_second_submit_while_the_first_is_still_saving_is_turned_away(): void
    {
        $invite = $this->invite();
        $lock = Cache::lock('waiver-group-submit:' . $invite->id . ':' . sha1('jamie@example.test|2485550142'), 30);
        $this->assertTrue($lock->get());

        $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature())->assertStatus(409);
        $this->assertSame(0, Waiver::count());

        $lock->release();
        $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature())->assertCreated();
    }

    public function test_the_chaperone_sees_the_contact_they_typed_only_until_that_parent_signs(): void
    {
        $invite = $this->invite();
        $this->addParents($invite, [['name' => 'Waiting Parent', 'email' => 'waiting@example.test', 'phone' => '2485550111']]);
        $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature())->assertCreated();

        $rows = collect($this->getJson("/api/waivers/bulk/{$invite->manage_token}")->assertOk()->json('data.recipients'))->keyBy('name');

        $this->assertSame('waiting@example.test', $rows['Waiting Parent']['email']);
        $this->assertSame('2485550111', $rows['Waiting Parent']['phone']);
        $this->assertArrayNotHasKey('email', $rows['Jamie Parent']);
        $this->assertArrayNotHasKey('phone', $rows['Jamie Parent']);
    }

    public function test_the_chaperone_fixes_a_failed_contact_and_the_invite_goes_out(): void
    {
        $invite = $this->invite();
        $this->addParents($invite, [['name' => 'Typo Parent', 'email' => 'typo@example.test']]);
        $this->emailsFail();
        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/send")->assertJsonPath('data.failed', 1);
        $recipient = WaiverInviteRecipient::sole();
        $pendingId = Waiver::sole()->id;

        $this->app->forgetInstance(EmailNotificationService::class);
        $this->app->offsetUnset(EmailNotificationService::class);

        $this->patchJson("/api/waivers/bulk/{$invite->manage_token}/recipients/{$recipient->id}", [
            'name' => 'Fixed Parent',
            'email' => 'fixed@example.test',
            'phone' => '(248) 555-0123',
        ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.status', 'sent');

        $recipient->refresh();
        $this->assertSame(WaiverInviteRecipient::STATUS_SENT, $recipient->status);
        $this->assertSame('fixed@example.test', $recipient->email);
        $this->assertSame('2485550123', $recipient->phone);
        $this->assertSame(1, WaiverInviteRecipient::count());
        $waiver = Waiver::sole();
        $this->assertSame($pendingId, $waiver->id);
        $this->assertSame('fixed@example.test', $waiver->adult_email);
        $this->assertSame('Fixed', $waiver->adult_first_name);
        $this->assertSame('Parent', $waiver->adult_last_name);
    }

    public function test_editing_a_contact_that_still_fails_says_so(): void
    {
        $invite = $this->invite();
        $this->addParents($invite, [['name' => 'Typo Parent', 'email' => 'typo@example.test']]);
        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/send")->assertJsonPath('data.sent', 1);
        $recipient = WaiverInviteRecipient::sole();
        $this->emailsFail();

        $this->patchJson("/api/waivers/bulk/{$invite->manage_token}/recipients/{$recipient->id}", ['email' => 'still-bad@example.test'])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.status', 'failed');
    }

    public function test_editing_refuses_bad_or_duplicate_contacts_signed_parents_and_other_groups(): void
    {
        $invite = $this->invite();
        $other = $this->invite(['chaperone_name' => 'Coach Rivera']);
        $this->addParents($invite, [
            ['name' => 'One', 'email' => 'one@example.test'],
            ['name' => 'Two', 'email' => 'two@example.test'],
        ]);
        $one = WaiverInviteRecipient::where('email', 'one@example.test')->sole();

        $this->patchJson("/api/waivers/bulk/{$invite->manage_token}/recipients/{$one->id}", ['phone' => '555'])
            ->assertStatus(422)->assertJsonValidationErrors(['phone']);
        $this->patchJson("/api/waivers/bulk/{$invite->manage_token}/recipients/{$one->id}", [])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->patchJson("/api/waivers/bulk/{$invite->manage_token}/recipients/{$one->id}", ['email' => 'TWO@example.test'])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->patchJson("/api/waivers/bulk/{$other->manage_token}/recipients/{$one->id}", ['email' => 'new@example.test'])
            ->assertNotFound();
        $this->assertSame('one@example.test', $one->fresh()->email);
        $this->assertSame(WaiverInviteRecipient::STATUS_NOT_SENT, $one->fresh()->status);

        $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature(['adult_email' => 'one@example.test']))->assertCreated();
        $this->patchJson("/api/waivers/bulk/{$invite->manage_token}/recipients/{$one->id}", ['email' => 'late@example.test'])
            ->assertStatus(409);
        $this->assertSame(WaiverInviteRecipient::STATUS_COMPLETE, $one->fresh()->status);
        $this->assertSame('one@example.test', $one->fresh()->email);
    }

    public function test_adding_someone_already_on_the_list_by_phone_is_skipped_but_a_family_member_is_not(): void
    {
        $invite = $this->invite();
        $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature())->assertCreated();

        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/recipients", [
            'recipients' => [
                ['name' => 'Jamie Again', 'phone' => '248-555-0142'],
                ['name' => 'Jamie Spouse', 'email' => 'spouse@example.test', 'phone' => '248-555-0142'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.added', 1)
            ->assertJsonPath('data.duplicates', 1);

        $this->assertSame(['Jamie Parent', 'Jamie Spouse'], WaiverInviteRecipient::orderBy('id')->pluck('name')->all());
    }

    public function test_a_resend_that_works_after_a_failure_marks_the_parent_sent(): void
    {
        $invite = $this->invite();
        $this->addParents($invite, [['name' => 'Retry Parent', 'email' => 'retry@example.test']]);
        WaiverInviteRecipient::query()->update(['status' => WaiverInviteRecipient::STATUS_FAILED]);
        $recipient = WaiverInviteRecipient::sole();

        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/recipients/{$recipient->id}/resend")->assertOk();

        $recipient->refresh();
        $this->assertSame(WaiverInviteRecipient::STATUS_SENT, $recipient->status);
        $this->assertSame(1, $recipient->resent_count);
        $this->assertNotNull($recipient->last_sent_at);
    }

    public function test_a_staff_text_copy_does_not_hide_that_the_parent_was_not_reached(): void
    {
        $this->textsGoThrough(function ($to) {
            if ($to === '2485550177') {
                throw new \RuntimeException('Twilio refused the number');
            }

            return 'SM1';
        });
        $invite = $this->invite();
        SmsNotification::where('company_id', $this->company->id)
            ->where('trigger_type', SmsNotification::TRIGGER_WAIVER_PARENT_INVITE)
            ->update([
                'recipient_types' => json_encode([SmsNotification::RECIPIENT_CUSTOMER, SmsNotification::RECIPIENT_CUSTOM]),
                'custom_phones' => json_encode(['2485550999']),
            ]);
        $this->addParents($invite, [['name' => 'Text Only', 'phone' => '(248) 555-0177']]);

        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/send")->assertJsonPath('data.failed', 1);

        $this->assertSame(WaiverInviteRecipient::STATUS_FAILED, WaiverInviteRecipient::sole()->status);
    }

    public function test_a_parent_who_signs_while_their_own_invite_is_going_out_stays_complete(): void
    {
        $invite = $this->invite();
        $this->addParents($invite, [['name' => 'Quick Parent', 'email' => 'quick@example.test']]);
        $recipient = WaiverInviteRecipient::sole();
        $this->partialMock(EmailNotificationService::class, function ($mock) use ($recipient) {
            $mock->shouldReceive('triggerWaiverNotification')->andReturnUsing(function () use ($recipient) {
                WaiverInviteRecipient::whereKey($recipient->id)->update(['status' => WaiverInviteRecipient::STATUS_COMPLETE]);

                return 0;
            });
        });

        $this->postJson("/api/waivers/bulk/{$invite->manage_token}/send");

        $this->assertSame(WaiverInviteRecipient::STATUS_COMPLETE, $recipient->fresh()->status);
    }

    public function test_a_relative_sharing_a_phone_with_a_listed_parent_gets_their_own_row(): void
    {
        $invite = $this->invite();
        $this->addParents($invite, [['name' => 'Mom Parent', 'email' => 'mom@example.test', 'phone' => '2485550142']]);

        $this->postJson("/api/waivers/group/{$invite->shareable_token}/submit", $this->signature([
            'adult_first_name' => 'Grandma', 'adult_email' => 'grandma@example.test', 'typed_legal_name' => 'Grandma Parent',
        ]))->assertCreated();

        $this->assertSame(WaiverInviteRecipient::STATUS_NOT_SENT, WaiverInviteRecipient::where('email', 'mom@example.test')->value('status'));
        $this->assertSame(WaiverInviteRecipient::STATUS_COMPLETE, WaiverInviteRecipient::where('email', 'grandma@example.test')->value('status'));
    }

    public function test_editing_a_contact_is_rate_limited(): void
    {
        $invite = $this->invite();
        $this->addParents($invite, [['name' => 'Edit Me', 'email' => 'edit-me@example.test']]);
        $recipient = WaiverInviteRecipient::sole();
        $this->withMiddleware(ThrottleRequests::class);

        for ($i = 0; $i < 10; $i++) {
            $this->patchJson("/api/waivers/bulk/{$invite->manage_token}/recipients/{$recipient->id}", ['phone' => '555'])->assertStatus(422);
        }
        $this->patchJson("/api/waivers/bulk/{$invite->manage_token}/recipients/{$recipient->id}", ['email' => 'fixed@example.test'])->assertStatus(429);
        $this->assertSame('edit-me@example.test', $recipient->fresh()->email);
    }
}
