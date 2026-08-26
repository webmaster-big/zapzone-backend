<?php

namespace Tests\Feature;

use App\Models\Attraction;
use App\Models\AttractionPurchase;
use App\Models\CheckoutConcern;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Location;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CheckoutConcernTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Location $location;
    protected Location $otherLocation;
    protected User $manager;
    protected User $attendant;
    protected User $otherVenueStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $this->location = $this->makeLocation('Brighton', 'brighton@zapzone.test');
        $this->otherLocation = $this->makeLocation('Canton', 'canton@zapzone.test');

        $this->manager = $this->makeUser('location_manager', 'manager@zapzone.test', $this->location, '810-555-0134');
        $this->attendant = $this->makeUser('attendant', 'attendant@zapzone.test', $this->location, '(810) 555 0199');
        $this->otherVenueStaff = $this->makeUser('attendant', 'canton@zapzone.test', $this->otherLocation, '7345550101');

        Mail::fake();

        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    public function test_the_public_endpoint_is_rate_limited(): void
    {
        $this->withMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $accepted = 0;
        $blocked = 0;

        for ($i = 0; $i < 12; $i++) {
            $status = $this->postJson('/api/checkout-concerns', $this->schedulePayload([
                'email' => "burst{$i}@example.com",
                'phone' => '810555' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            ]))->getStatusCode();

            if ($status === 429) {
                $blocked++;
            } else {
                $accepted++;
            }
        }

        $this->assertGreaterThan(0, $blocked, 'the limiter never fired');
        $this->assertLessThan(12, $accepted, 'every request was accepted, so nothing was limited');
    }

    private function makeLocation(string $name, string $email): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'address' => '8053 Challis Rd',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => $email,
        ]);
    }

    private function makeUser(string $role, string $email, ?Location $location, ?string $phone = null, string $status = 'active'): User
    {
        return User::create([
            'company_id' => $this->company->id,
            'location_id' => $location?->id,
            'first_name' => 'Test',
            'last_name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $email,
            'phone' => $phone,
            'password' => Hash::make('password'),
            'role' => $role,
            'status' => $status,
        ]);
    }

    private function makeAttraction(): Attraction
    {
        return Attraction::create([
            'location_id' => $this->location->id,
            'name' => 'Laser Tag',
            'description' => 'Arena laser tag',
            'category' => 'Attractions',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);
    }

    private function schedulePayload(array $overrides = []): array
    {
        return $overrides + [
            'location_id' => $this->location->id,
            'name' => 'Dana Whitfield',
            'phone' => '(810) 555-0177',
            'email' => 'dana@example.com',
            'message' => 'Nothing on Saturday works for 14 people.',
            'entity_type' => 'package',
            'entity_id' => 4,
            'entity_name' => 'Ultimate Party Package',
            'preferred_date' => '2026-09-05',
            'preferred_time' => '14:30',
        ];
    }

    private function abandonPayload(array $overrides = []): array
    {
        return $overrides + [
            'location_id' => $this->location->id,
            'name' => 'Sam Ortiz',
            'phone' => '810-555-0188',
            'email' => 'sam@example.com',
            'entity_type' => 'attraction',
            'entity_id' => 9,
            'entity_name' => 'Laser Tag',
            'context' => ['step_label' => 'Payment', 'estimated_total' => 84.5],
        ];
    }

    public function test_a_guest_can_raise_a_schedule_concern_without_signing_in(): void
    {
        $response = $this->postJson('/api/checkout-concerns', $this->schedulePayload());

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertStringContainsString('will contact you', $response->json('message'));

        $concern = CheckoutConcern::first();
        $this->assertSame(CheckoutConcern::KIND_SCHEDULE_HELP, $concern->kind);
        $this->assertSame('Dana Whitfield', $concern->name);
        $this->assertSame($this->location->id, $concern->location_id);
        $this->assertSame($this->company->id, $concern->company_id);
        $this->assertSame('Ultimate Party Package', $concern->entity_name);
        $this->assertSame('2026-09-05', $concern->preferred_date->toDateString());
    }

    public function test_every_active_staff_email_at_that_venue_is_alerted_and_nobody_elses(): void
    {
        $this->makeUser('attendant', 'left@zapzone.test', $this->location, null, 'inactive');

        $this->postJson('/api/checkout-concerns', $this->schedulePayload())->assertStatus(201);

        $alerted = CheckoutConcern::first()->alerted;

        $this->assertEqualsCanonicalizing(
            ['manager@zapzone.test', 'attendant@zapzone.test'],
            $alerted['emails_sent']
        );
        $this->assertNotContains('canton@zapzone.test', $alerted['emails_sent']);
        $this->assertNotContains('left@zapzone.test', $alerted['emails_sent']);
    }

    public function test_the_concern_reaches_staff_as_an_in_app_notification_for_that_location(): void
    {
        $this->postJson('/api/checkout-concerns', $this->schedulePayload())->assertStatus(201);

        $notification = Notification::first();

        $this->assertNotNull($notification);
        $this->assertSame($this->location->id, $notification->location_id);
        $this->assertSame('Customer needs help with the schedule', $notification->title);
        $this->assertStringContainsString('810', $notification->message);
        $this->assertSame(CheckoutConcern::first()->id, $notification->metadata['checkout_concern_id']);
    }

    public function test_a_schedule_concern_becomes_a_contact_the_venue_can_reach(): void
    {
        $this->postJson('/api/checkout-concerns', $this->schedulePayload())->assertStatus(201);

        $contact = Contact::where('email', 'dana@example.com')->first();

        $this->assertNotNull($contact);
        $this->assertSame('Dana', $contact->first_name);
        $this->assertSame('Whitfield', $contact->last_name);
        $this->assertSame($this->location->id, $contact->location_id);
        $this->assertSame('checkout_schedule_help', $contact->source);
        $this->assertContains('schedule-help', $contact->tags);
        $this->assertSame($contact->id, CheckoutConcern::first()->contact_id);
    }

    public function test_a_name_and_a_number_alone_are_enough_to_raise_a_concern(): void
    {
        $response = $this->postJson('/api/checkout-concerns', [
            'location_id' => $this->location->id,
            'name' => 'Pat Reyes',
            'phone' => '8105550122',
        ]);

        $response->assertStatus(201);

        $concern = CheckoutConcern::first();
        $this->assertNull($concern->email);
        $this->assertNotEmpty($concern->alerted['emails_sent']);
    }

    public function test_a_guest_who_gave_only_a_name_and_number_still_lands_in_contacts(): void
    {
        $this->postJson('/api/checkout-concerns', [
            'location_id' => $this->location->id,
            'name' => 'Pat Reyes',
            'phone' => '8105550122',
        ])->assertStatus(201);

        $contact = Contact::whereNull('email')->where('phone', '8105550122')->first();

        $this->assertNotNull($contact, 'a phone-only guest never reached the contacts table');
        $this->assertSame('Pat', $contact->first_name);
        $this->assertSame('Reyes', $contact->last_name);
        $this->assertSame($this->company->id, $contact->company_id);
        $this->assertSame($this->location->id, $contact->location_id);
        $this->assertSame($contact->id, CheckoutConcern::first()->contact_id);
    }

    public function test_the_same_phone_only_guest_does_not_create_a_second_contact(): void
    {
        $this->postJson('/api/checkout-concerns', [
            'location_id' => $this->location->id,
            'name' => 'Pat Reyes',
            'phone' => '8105550122',
            'message' => 'First problem',
        ])->assertStatus(201);

        $this->postJson('/api/checkout-concerns', [
            'location_id' => $this->location->id,
            'name' => 'Pat Reyes',
            'phone' => '8105550122',
            'message' => 'A different, later problem',
        ])->assertStatus(201);

        $this->assertSame(2, CheckoutConcern::count());
        $this->assertSame(1, Contact::whereNull('email')->where('phone', '8105550122')->count());
    }

    public function test_a_phone_only_abandonment_also_lands_in_contacts(): void
    {
        $payload = $this->abandonPayload();
        unset($payload['email']);

        $this->postJson('/api/checkout-concerns/abandoned', $payload)
            ->assertStatus(200)
            ->assertJsonPath('data.recorded', true);

        $contact = Contact::whereNull('email')->where('phone', '810-555-0188')->first();

        $this->assertNotNull($contact);
        $this->assertSame('Sam', $contact->first_name);
        $this->assertSame('abandoned_checkout', $contact->source);
    }

    public function test_a_phone_only_contact_can_still_be_edited_by_staff(): void
    {
        $this->postJson('/api/checkout-concerns', [
            'location_id' => $this->location->id,
            'name' => 'Pat Reyes',
            'phone' => '8105550122',
        ])->assertStatus(201);

        $contact = Contact::whereNull('email')->first();

        $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/contacts/{$contact->id}", ['first_name' => 'Patricia'])
            ->assertStatus(200);

        $this->assertSame('Patricia', $contact->fresh()->first_name);
    }

    public function test_a_concern_without_a_name_or_number_is_refused(): void
    {
        $this->postJson('/api/checkout-concerns', ['location_id' => $this->location->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'phone']);
    }

    public function test_the_same_concern_sent_twice_alerts_staff_once(): void
    {
        $this->postJson('/api/checkout-concerns', $this->schedulePayload())->assertStatus(201);
        $this->postJson('/api/checkout-concerns', $this->schedulePayload())->assertStatus(200);

        $this->assertSame(1, CheckoutConcern::count());
        $this->assertSame(1, Notification::count());
    }

    public function test_a_different_second_message_from_the_same_guest_still_reaches_staff(): void
    {
        $this->postJson('/api/checkout-concerns', $this->schedulePayload([
            'message' => 'Nothing on Saturday works for 14 people.',
        ]))->assertStatus(201);

        $this->postJson('/api/checkout-concerns', $this->schedulePayload([
            'message' => 'We could do Sunday 5pm instead - please call my cell.',
        ]))->assertStatus(201);

        $this->assertSame(2, CheckoutConcern::count());
        $this->assertSame(2, Notification::count());
    }

    public function test_a_name_carrying_a_header_injection_is_refused(): void
    {
        $this->postJson('/api/checkout-concerns', $this->schedulePayload([
            'name' => "Bob\r\nBcc: victim@example.com",
        ]))->assertStatus(422)->assertJsonValidationErrors(['name']);

        $this->assertSame(0, CheckoutConcern::count());
    }

    public function test_the_mail_subject_can_never_carry_a_newline(): void
    {
        $service = new \App\Services\CheckoutConcernService();
        $concern = new CheckoutConcern([
            'kind' => CheckoutConcern::KIND_SCHEDULE_HELP,
            'name' => "Bob\r\nBcc: victim@example.com",
        ]);
        $concern->setRelation('location', $this->location);

        $subject = (new \ReflectionMethod($service, 'subjectFor'))->invoke($service, $concern);

        $this->assertStringNotContainsString("\r", $subject);
        $this->assertStringNotContainsString("\n", $subject);
    }

    public function test_an_unpaid_pre_charge_row_does_not_suppress_the_abandonment_alert(): void
    {
        AttractionPurchase::create([
            'attraction_id' => $this->makeAttraction()->id,
            'guest_name' => 'Sam Ortiz',
            'guest_email' => 'sam@example.com',
            'quantity' => 2,
            'total_amount' => 40,
            'amount_paid' => 0,
            'purchase_date' => now()->toDateString(),
            'payment_status' => 'pending',
        ]);

        $this->postJson('/api/checkout-concerns/abandoned', $this->abandonPayload())
            ->assertStatus(200)
            ->assertJsonPath('data.recorded', true);

        $this->assertSame(1, CheckoutConcern::count());
        $this->assertNotNull(CheckoutConcern::first()->alert_after);
    }

    public function test_a_half_typed_email_still_records_the_guest_and_alerts_staff(): void
    {
        $this->postJson('/api/checkout-concerns/abandoned', $this->abandonPayload(['email' => 'jamie']))
            ->assertStatus(200)
            ->assertJsonPath('data.recorded', true);

        $concern = CheckoutConcern::first();
        $this->assertNull($concern->email);
        $this->assertSame('Sam Ortiz', $concern->name);

        $this->travel(6)->minutes();
        $this->artisan('concerns:send-alerts')->assertSuccessful();

        $this->assertNotEmpty($concern->fresh()->alerted['emails_sent']);
    }

    public function test_a_beacon_sent_as_text_plain_is_still_understood(): void
    {
        $this->call(
            'POST',
            '/api/checkout-concerns/abandoned',
            [], [], [],
            ['CONTENT_TYPE' => 'text/plain;charset=UTF-8'],
            json_encode($this->abandonPayload())
        )->assertStatus(200);

        $this->assertSame(1, CheckoutConcern::count());
        $this->assertSame('Sam Ortiz', CheckoutConcern::first()->name);
    }

    public function test_closing_the_checkout_records_the_guest_immediately(): void
    {
        $response = $this->postJson('/api/checkout-concerns/abandoned', $this->abandonPayload());

        $response->assertStatus(200)->assertJsonPath('data.recorded', true);

        $concern = CheckoutConcern::first();
        $this->assertSame(CheckoutConcern::KIND_ABANDONED_CHECKOUT, $concern->kind);
        $this->assertSame('Payment', $concern->context['step_label']);

        $contact = Contact::where('email', 'sam@example.com')->first();
        $this->assertNotNull($contact);
        $this->assertSame('abandoned_checkout', $contact->source);
        $this->assertSame('810-555-0188', $contact->phone);

        $this->assertNotNull($concern->alert_after);
        $this->assertNull($concern->alerted_at);
        $this->assertSame(0, Notification::count());
    }

    public function test_the_venue_is_alerted_once_the_grace_period_passes(): void
    {
        $this->postJson('/api/checkout-concerns/abandoned', $this->abandonPayload())->assertStatus(200);

        $this->travel(6)->minutes();
        $this->artisan('concerns:send-alerts')->assertSuccessful();

        $concern = CheckoutConcern::first()->fresh();
        $this->assertNotNull($concern->alerted_at);
        $this->assertNotEmpty($concern->alerted['emails_sent']);
        $this->assertSame('Checkout left unfinished', Notification::first()->title);
    }

    public function test_a_guest_who_comes_back_and_pays_inside_the_grace_period_is_never_reported(): void
    {
        $this->postJson('/api/checkout-concerns/abandoned', $this->abandonPayload())->assertStatus(200);

        AttractionPurchase::create([
            'attraction_id' => $this->makeAttraction()->id,
            'guest_name' => 'Sam Ortiz',
            'guest_email' => 'sam@example.com',
            'quantity' => 2,
            'total_amount' => 40,
            'amount_paid' => 40,
            'purchase_date' => now()->toDateString(),
            'payment_status' => 'paid',
        ]);

        $this->travel(6)->minutes();
        $this->artisan('concerns:send-alerts')->assertSuccessful();

        $concern = CheckoutConcern::first()->fresh();
        $this->assertNotNull($concern->alerted_at);
        $this->assertSame(CheckoutConcern::STATUS_RESOLVED, $concern->status);
        $this->assertArrayHasKey('cancelled', $concern->alerted);
        $this->assertSame(0, Notification::count());
    }

    public function test_an_alert_still_goes_out_after_the_scheduler_was_down_for_hours(): void
    {
        $this->postJson('/api/checkout-concerns/abandoned', $this->abandonPayload())->assertStatus(200);

        $this->travel(5)->hours();
        $this->artisan('concerns:send-alerts')->assertSuccessful();

        $this->assertNotNull(CheckoutConcern::first()->fresh()->alerted_at);
        $this->assertSame(1, Notification::count());
    }

    public function test_a_pending_alert_is_not_sent_twice(): void
    {
        $this->postJson('/api/checkout-concerns/abandoned', $this->abandonPayload())->assertStatus(200);

        $this->travel(6)->minutes();
        $this->artisan('concerns:send-alerts')->assertSuccessful();
        $this->artisan('concerns:send-alerts')->assertSuccessful();

        $this->assertSame(1, Notification::count());
    }

    public function test_the_abandonment_reply_never_tells_the_guest_anything_was_saved(): void
    {
        $response = $this->postJson('/api/checkout-concerns/abandoned', $this->abandonPayload());

        $this->assertNull($response->json('message'));
        $this->assertSame(['success', 'data'], array_keys($response->json()));
    }

    public function test_a_guest_who_actually_paid_is_not_reported_as_abandoning(): void
    {
        $attraction = Attraction::create([
            'location_id' => $this->location->id,
            'name' => 'Laser Tag',
            'description' => 'Arena laser tag',
            'category' => 'Attractions',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);

        AttractionPurchase::create([
            'attraction_id' => $attraction->id,
            'guest_name' => 'Sam Ortiz',
            'guest_email' => 'sam@example.com',
            'quantity' => 2,
            'total_amount' => 40,
            'amount_paid' => 40,
            'purchase_date' => now()->toDateString(),
            'payment_status' => 'paid',
        ]);

        $this->postJson('/api/checkout-concerns/abandoned', $this->abandonPayload())
            ->assertStatus(200)
            ->assertJsonPath('data.recorded', false);

        $this->assertSame(0, CheckoutConcern::count());
        $this->assertSame(0, Notification::count());
    }

    public function test_a_time_the_checkout_sent_in_an_odd_shape_still_reads_as_something(): void
    {
        $this->postJson('/api/checkout-concerns', $this->schedulePayload(['preferred_time' => '2:30 PM']))
            ->assertStatus(201);

        $this->assertSame('2:30 PM', CheckoutConcern::first()->preferred_time_label);

        CheckoutConcern::query()->delete();

        $this->postJson('/api/checkout-concerns', $this->schedulePayload(['preferred_time' => 'mornings']))
            ->assertStatus(201);

        $concern = CheckoutConcern::first();
        $this->assertSame('mornings', $concern->preferred_time_label);
        $this->assertStringContainsString('mornings', $concern->what_they_wanted);
    }

    public function test_the_bell_leads_with_the_person_to_call(): void
    {
        $this->postJson('/api/checkout-concerns', $this->schedulePayload())->assertStatus(201);

        $this->assertSame('Dana Whitfield', Notification::first()->metadata['customerName']);
    }

    public function test_an_admin_edited_template_is_used_when_one_exists(): void
    {
        \App\Models\EmailNotification::create([
            'company_id' => $this->company->id,
            'name' => 'Schedule Help (Staff)',
            'trigger_type' => \App\Models\EmailNotification::TRIGGER_SCHEDULE_HELP_REQUESTED,
            'entity_type' => \App\Models\EmailNotification::ENTITY_ALL,
            'recipient_types' => [\App\Models\EmailNotification::RECIPIENT_STAFF],
            'subject' => 'Reworded - {{customer_name}}',
            'body' => 'Call {{customer_name}} on {{customer_phone}}.',
            'is_active' => true,
        ]);

        $this->postJson('/api/checkout-concerns', $this->schedulePayload())->assertStatus(201);

        $concern = CheckoutConcern::first();
        $this->assertSame('notification_templates', $concern->alerted['via']);

        $log = \App\Models\EmailNotificationLog::first();
        $this->assertNotNull($log, 'the templated path did not log a send');
        $this->assertContains($log->recipient_email, ['manager@zapzone.test', 'attendant@zapzone.test']);
    }

    public function test_without_a_template_the_built_in_default_still_sends(): void
    {
        \App\Models\EmailNotification::query()->delete();

        $this->postJson('/api/checkout-concerns', $this->schedulePayload())->assertStatus(201);

        $concern = CheckoutConcern::first();
        $this->assertSame('built_in_defaults', $concern->alerted['via']);
        $this->assertNotEmpty($concern->alerted['emails_sent']);
    }

    public function test_every_company_gets_the_two_templates_so_staff_can_edit_them(): void
    {
        $this->postJson('/api/checkout-concerns', $this->schedulePayload())->assertStatus(201);

        $keys = \App\Models\EmailNotification::where('company_id', $this->company->id)
            ->whereIn('trigger_type', [
                \App\Models\EmailNotification::TRIGGER_SCHEDULE_HELP_REQUESTED,
                \App\Models\EmailNotification::TRIGGER_CHECKOUT_ABANDONED,
            ])->count();

        $smsKeys = \App\Models\SmsNotification::where('company_id', $this->company->id)
            ->whereIn('trigger_type', [
                \App\Models\SmsNotification::TRIGGER_SCHEDULE_HELP_REQUESTED,
                \App\Models\SmsNotification::TRIGGER_CHECKOUT_ABANDONED,
            ])->count();

        $this->assertSame(2, $keys, 'the email templates are not visible to staff');
        $this->assertSame(2, $smsKeys, 'the SMS templates are not visible to staff');
    }

    public function test_an_abandoned_checkout_never_emails_the_guest_even_if_a_template_says_to(): void
    {
        \App\Models\EmailNotification::create([
            'company_id' => $this->company->id,
            'name' => 'Abandoned (misconfigured)',
            'trigger_type' => \App\Models\EmailNotification::TRIGGER_CHECKOUT_ABANDONED,
            'entity_type' => \App\Models\EmailNotification::ENTITY_ALL,
            'recipient_types' => [
                \App\Models\EmailNotification::RECIPIENT_CUSTOMER,
                \App\Models\EmailNotification::RECIPIENT_STAFF,
            ],
            'subject' => 'Unfinished - {{customer_name}}',
            'body' => 'Call {{customer_name}}.',
            'is_active' => true,
        ]);

        $this->postJson('/api/checkout-concerns/abandoned', $this->abandonPayload())->assertStatus(200);

        $this->travel(6)->minutes();
        $this->artisan('concerns:send-alerts')->assertSuccessful();

        $sentTo = \App\Models\EmailNotificationLog::pluck('recipient_email')->all();

        $this->assertNotContains('sam@example.com', $sentTo, 'the guest was emailed about their own abandoned checkout');
        $this->assertNotEmpty($sentTo);
    }

    public function test_staff_can_list_only_their_own_venues_concerns(): void
    {
        $this->postJson('/api/checkout-concerns', $this->schedulePayload())->assertStatus(201);
        $this->postJson('/api/checkout-concerns', $this->schedulePayload([
            'location_id' => $this->otherLocation->id,
            'email' => 'other@example.com',
        ]))->assertStatus(201);

        $this->assertSame(2, CheckoutConcern::count());

        $rows = $this->actingAs($this->attendant, 'sanctum')
            ->getJson('/api/checkout-concerns')
            ->assertStatus(200)
            ->json('data.concerns');

        $this->assertCount(1, $rows);
        $this->assertSame($this->location->id, $rows[0]['location_id']);
    }

    public function test_staff_can_mark_a_concern_handled(): void
    {
        $this->postJson('/api/checkout-concerns', $this->schedulePayload())->assertStatus(201);
        $concern = CheckoutConcern::first();

        $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/checkout-concerns/{$concern->id}", [
                'status' => CheckoutConcern::STATUS_CONTACTED,
                'resolution_note' => 'Called, moved them to 4pm.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'contacted');

        $concern->refresh();
        $this->assertSame($this->manager->id, $concern->handled_by);
        $this->assertNotNull($concern->handled_at);
    }

    public function test_staff_cannot_touch_another_venues_concern(): void
    {
        $this->postJson('/api/checkout-concerns', $this->schedulePayload([
            'location_id' => $this->otherLocation->id,
        ]))->assertStatus(201);

        $concern = CheckoutConcern::first();

        $this->actingAs($this->attendant, 'sanctum')
            ->putJson("/api/checkout-concerns/{$concern->id}", ['status' => CheckoutConcern::STATUS_RESOLVED])
            ->assertStatus(403);
    }
}
