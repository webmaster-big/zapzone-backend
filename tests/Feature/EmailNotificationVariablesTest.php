<?php

namespace Tests\Feature;

use App\Models\Attraction;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\Company;
use App\Models\EmailNotification;
use App\Models\Event;
use App\Models\EventPurchase;
use App\Models\Location;
use App\Models\User;
use App\Models\WaiverTemplate;
use App\Services\EmailNotificationService;
use App\Services\WaiverService;
use Database\Seeders\DefaultEmailNotificationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailNotificationVariablesTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Location $location;
    protected User $admin;
    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false, 'app.frontend_url' => 'https://book.test']);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->company = Company::create([
            'company_name' => 'Zap Zone Test',
            'email' => 'owner@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);
        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Brighton',
            'address' => '8053 Challis Rd',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => 'brighton@zapzone.test',
        ]);
        $this->admin = $this->makeUser('company_admin', 'owner@zapzone.test');
        $this->manager = $this->makeUser('location_manager', 'manager@zapzone.test', $this->location);

        DefaultEmailNotificationSeeder::seedForCompany($this->company);
    }

    public function test_each_email_type_offers_the_placeholders_it_really_fills(): void
    {
        $waiver = $this->offered('waiver_signed');
        $this->assertContains('waiver_link', $waiver);
        $this->assertContains('activity_name', $waiver);
        $this->assertNotContains('booking_reference', $waiver);

        $event = $this->offered('event_confirmed');
        $this->assertContains('event_name', $event);
        $this->assertContains('waiver_section', $event);
        $this->assertNotContains('booking_reference', $event);

        $concern = $this->offered('schedule_help_requested');
        $this->assertContains('concern_message', $concern);
        $this->assertContains('preferred_time', $concern);
        $this->assertNotContains('booking_reference', $concern);

        $this->assertContains('waiver_section', $this->offered('booking_confirmed'));
        $this->assertContains('waiver_section', $this->offered('purchase_confirmed'));
        $this->assertContains('purchase_reference', $this->offered('purchase_rescheduled'));

        $report = $this->offered('end_of_day_sales_report');
        $this->assertContains('net_sales', $report);
        $this->assertNotContains('location_address', $report);

        $this->assertNotContains('booking_reference', $this->offered(EmailNotification::TRIGGER_INVITATION_SENT));

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/email-notifications/variables?trigger_type[]=waiver_signed')
            ->assertOk();
    }

    public function test_every_offered_placeholder_is_filled_when_a_waiver_email_sends(): void
    {
        $this->useEveryPlaceholder('waiver_signed', EmailNotification::RECIPIENT_CUSTOMER);
        $template = WaiverTemplate::create([
            'company_id' => $this->company->id,
            'title' => 'General waiver',
            'status' => WaiverTemplate::STATUS_ACTIVE,
            'is_default' => true,
            'body_text' => '<p>I release {{company_name}}.</p>',
            'kind' => WaiverTemplate::KIND_STANDARD,
            'electronic_consent_enabled' => true,
        ]);
        app(WaiverService::class)->syncVersion($template);

        $this->postJson("/api/waivers/kiosk/{$template->id}/submit", [
            'location_id' => $this->location->id,
            'adult_first_name' => 'Sam',
            'adult_last_name' => 'Lee',
            'adult_email' => 'sam@example.com',
            'adult_phone' => '(248) 555-0142',
            'adult_dob' => '1990-05-05',
            'typed_legal_name' => 'Sam Lee',
            'agreement_accepted' => true,
            'electronic_consent_accepted' => true,
        ])->assertSuccessful();

        $this->assertNoPlaceholderLeft($this->sentTo('sam@example.com'));
    }

    public function test_every_offered_placeholder_is_filled_when_a_checkout_help_alert_sends(): void
    {
        $this->useEveryPlaceholder('schedule_help_requested');

        $this->postJson('/api/checkout-concerns', [
            'location_id' => $this->location->id,
            'name' => 'Dana Whitfield',
            'phone' => '(810) 555-0177',
            'email' => 'dana@example.com',
            'message' => 'Nothing on Saturday works for 14 people.',
            'entity_type' => 'package',
            'entity_id' => 4,
            'entity_name' => 'Ultimate Party Package',
            'preferred_date' => '2026-11-05',
            'preferred_time' => '14:30',
        ])->assertStatus(201);

        $html = (string) $this->sentTo('manager@zapzone.test')->getHtmlBody();
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringContainsString('[location_address=8053 Challis Rd, Brighton, MI, 48116]', $html);
        $this->assertStringContainsString('[company_email=owner@zapzone.test]', $html);
    }

    public function test_every_offered_placeholder_is_filled_in_booking_ticket_and_event_confirmations(): void
    {
        $this->useEveryPlaceholder('booking_confirmed', EmailNotification::RECIPIENT_CUSTOMER);
        $this->useEveryPlaceholder('purchase_confirmed', EmailNotification::RECIPIENT_CUSTOMER);
        $this->useEveryPlaceholder('event_confirmed', EmailNotification::RECIPIENT_CUSTOMER);
        $service = app(EmailNotificationService::class);

        $booking = Booking::create([
            'reference_number' => 'BK-DRIFT',
            'booking_date' => '2026-11-05',
            'booking_time' => '14:00',
            'duration' => 60,
            'duration_unit' => 'minutes',
            'location_id' => $this->location->id,
            'participants' => 4,
            'total_amount' => 120,
            'status' => 'confirmed',
            'guest_name' => 'Bea Booker',
            'guest_email' => 'bea@example.com',
        ]);
        $service->processBookingCreated($booking);
        $this->assertNoPlaceholderLeft($this->sentTo('bea@example.com'));

        $service->processPurchaseCreated($this->ticketPurchase('tia@example.com'));
        $this->assertNoPlaceholderLeft($this->sentTo('tia@example.com'));

        $event = Event::create([
            'location_id' => $this->location->id,
            'name' => 'Glow Night',
            'date_type' => 'one_time',
            'start_date' => now()->addDays(3)->toDateString(),
            'time_start' => '18:00',
            'time_end' => '21:00',
            'interval_minutes' => 60,
            'price' => 15,
            'is_active' => true,
        ]);
        $party = EventPurchase::create([
            'reference_number' => 'EV-DRIFT',
            'event_id' => $event->id,
            'location_id' => $this->location->id,
            'guest_name' => 'Riley Rivera',
            'guest_email' => 'riley@example.com',
            'purchase_date' => now()->addDays(3)->toDateString(),
            'purchase_time' => '18:00',
            'quantity' => 2,
            'total_amount' => 30,
            'status' => 'confirmed',
        ]);
        $service->triggerEventNotification($party, EmailNotification::TRIGGER_EVENT_CONFIRMED);
        $this->assertNoPlaceholderLeft($this->sentTo('riley@example.com'));
    }

    public function test_the_backup_ticket_confirmation_shows_the_waiver_button_instead_of_its_placeholder(): void
    {
        $template = WaiverTemplate::create([
            'company_id' => $this->company->id,
            'title' => 'General waiver',
            'status' => WaiverTemplate::STATUS_ACTIVE,
            'is_default' => true,
            'body_text' => '<p>I release {{company_name}}.</p>',
            'kind' => WaiverTemplate::KIND_STANDARD,
            'electronic_consent_enabled' => true,
        ]);
        app(WaiverService::class)->syncVersion($template);

        $purchase = $this->ticketPurchase('ava@example.com');
        app(WaiverService::class)->ensureForAttractionPurchase($purchase);
        app(EmailNotificationService::class)->processPurchaseCreated($purchase);

        $html = (string) $this->sentTo('ava@example.com')->getHtmlBody();
        $this->assertStringContainsString('Complete Your Waiver', $html);
        $this->assertStringNotContainsString('{{waiver_section}}', $html);

        $other = $this->ticketPurchase('ben@example.com');
        $template->update(['status' => WaiverTemplate::STATUS_ARCHIVED]);
        app(EmailNotificationService::class)->processPurchaseCreated($other);
        $this->assertStringNotContainsString('{{waiver_section}}', (string) $this->sentTo('ben@example.com')->getHtmlBody());
    }

    public function test_previews_of_the_built_in_emails_leave_no_placeholder_behind(): void
    {
        foreach (['waiver_signed', 'event_confirmed', 'schedule_help_requested', 'payment_received', 'booking_confirmed', 'purchase_confirmed'] as $trigger) {
            $notifications = EmailNotification::where('company_id', $this->company->id)->where('trigger_type', $trigger)->get();
            $this->assertNotEmpty($notifications, $trigger);

            foreach ($notifications as $notification) {
                $data = $this->actingAs($this->admin, 'sanctum')
                    ->postJson("/api/email-notifications/{$notification->id}/preview")
                    ->assertOk()
                    ->json('data');

                $this->assertStringNotContainsString('{{', $data['subject'], "{$trigger} subject");
                $this->assertStringNotContainsString('{{', $data['body'], "{$trigger} body");
            }
        }
    }

    private function offered(string $trigger): array
    {
        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/email-notifications/variables?trigger_type=' . $trigger)
            ->assertOk()
            ->json('data');

        return collect([$data['specific'], $data['common']])
            ->flatMap(fn ($groups) => collect($groups)->flatMap(fn ($variables) => array_keys($variables)))
            ->unique()
            ->values()
            ->all();
    }

    private function useEveryPlaceholder(string $trigger, ?string $recipient = null): void
    {
        $body = collect($this->offered($trigger))
            ->map(fn (string $name) => '[' . $name . '={{' . $name . '}}]')
            ->implode(' ');

        $notifications = EmailNotification::where('company_id', $this->company->id)->where('trigger_type', $trigger)->get()
            ->filter(fn (EmailNotification $notification) => $recipient === null || in_array($recipient, (array) $notification->recipient_types, true));
        $this->assertNotEmpty($notifications, $trigger);

        foreach ($notifications as $notification) {
            $notification->update(['email_template_id' => null, 'subject' => 'Drift check', 'body' => $body]);
        }
    }

    private function ticketPurchase(string $email): AttractionPurchase
    {
        $attraction = Attraction::firstOrCreate(
            ['location_id' => $this->location->id, 'name' => 'Laser Tag'],
            ['description' => 'Arena laser tag', 'category' => 'Attractions', 'price' => 20, 'duration' => 30, 'max_capacity' => 20, 'status' => 'active']
        );

        return AttractionPurchase::create([
            'attraction_id' => $attraction->id,
            'guest_name' => 'Ticket Buyer',
            'guest_email' => $email,
            'quantity' => 2,
            'total_amount' => 40,
            'amount_paid' => 40,
            'purchase_date' => now()->toDateString(),
            'payment_status' => 'paid',
            'status' => 'confirmed',
        ]);
    }

    private function assertNoPlaceholderLeft(Email $email): void
    {
        $html = (string) $email->getHtmlBody();
        preg_match_all('/\{\{\s*[^{}\s]+\s*\}\}/', $html, $left);
        $this->assertSame([], $left[0], 'Placeholders left in the email');
    }

    private function sentTo(string $address): Email
    {
        $email = collect(Mail::mailer()->getSymfonyTransport()->messages())
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->filter(fn ($original) => collect($original->getTo())->contains(fn ($to) => strtolower($to->getAddress()) === strtolower($address)))
            ->last();

        $this->assertNotNull($email, "Nothing was emailed to {$address}");

        return $email;
    }

    private function makeUser(string $role, string $email, ?Location $location = null): User
    {
        return User::create([
            'company_id' => $this->company->id,
            'location_id' => $location?->id,
            'first_name' => 'Test',
            'last_name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
