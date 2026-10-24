<?php

namespace Tests\Feature;

use App\Models\AddOn;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Customer;
use App\Models\EmailNotification;
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

class NotificationEmailEscapingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Location $location;
    protected User $admin;
    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'company_name' => "Tom & Jerry's Fun",
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

        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    public function test_a_name_typed_on_the_kiosk_waiver_reaches_the_email_as_text(): void
    {
        $this->signKioskWaiver('Sam<img src="https://evil.example/x.png">', "O'Brien", 'sam@example.com')->assertSuccessful();

        $email = $this->sentTo('sam@example.com');
        $html = (string) $email->getHtmlBody();

        $this->assertStringContainsString('Hi Sam&lt;img src=&quot;https://evil.example/x.png&quot;&gt; O&#039;Brien,', $html);
        $this->assertStringNotContainsString('<img src="https://evil.example', $html);
        $this->assertStringContainsString('<strong>Tom &amp; Jerry&#039;s Fun</strong>', $html);
        $this->assertSame("Waiver Completed - Tom & Jerry's Fun", $email->getSubject());
    }

    public function test_a_guest_cannot_pull_other_values_into_an_email(): void
    {
        $this->signKioskWaiver('{{company_name}}', 'Lee', 'lee@example.com')->assertSuccessful();

        $html = (string) $this->sentTo('lee@example.com')->getHtmlBody();

        $this->assertStringContainsString('Hi {{company_name}} Lee,', $html);
    }

    public function test_a_concern_alert_shows_guest_markup_as_text_and_survives_an_odd_step(): void
    {
        $this->postJson('/api/checkout-concerns', [
            'location_id' => $this->location->id,
            'name' => '<b>Dana</b> Whitfield',
            'phone' => '(810) 555-0177',
            'email' => 'dana@example.com',
            'message' => 'Call me <a href="https://phish.example">here</a>',
            'entity_type' => 'package',
            'entity_id' => 4,
            'entity_name' => 'Ultimate Party Package',
            'preferred_date' => '2026-11-05',
            'preferred_time' => '14:30',
            'context' => ['step_label' => ['Pick a time', 'Pay']],
        ])->assertStatus(201);

        $email = $this->sentTo('manager@zapzone.test');
        $html = (string) $email->getHtmlBody();

        $this->assertStringContainsString('&lt;b&gt;Dana&lt;/b&gt; Whitfield', $html);
        $this->assertStringNotContainsString('<a href="https://phish.example">', $html);
        $this->assertStringNotContainsString('<b>Dana</b>', $html);
    }

    public function test_the_preview_shows_guest_values_as_text(): void
    {
        $customer = Customer::create([
            'first_name' => '<img src=x onerror=alert(1)>',
            'last_name' => 'Lee',
            'email' => 'preview@example.com',
            'phone' => '2485550100',
            'password' => Hash::make('password'),
        ]);
        Booking::create([
            'reference_number' => 'PREVIEW1',
            'booking_date' => '2026-11-05',
            'booking_time' => '14:00',
            'duration' => 60,
            'duration_unit' => 'minutes',
            'location_id' => $this->location->id,
            'customer_id' => $customer->id,
            'participants' => 4,
            'total_amount' => 120,
            'status' => 'confirmed',
        ]);
        $notification = EmailNotification::where('company_id', $this->company->id)->firstOrFail();

        $response = $this->actingAs($this->admin, 'sanctum')->postJson("/api/email-notifications/{$notification->id}/preview", [
            'subject' => 'Hi {{customer_name}}',
            'body' => '<p>Hi {{customer_name}}</p>',
            'customer_id' => $customer->id,
        ])->assertOk();

        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $response->json('data.body'));
        $this->assertStringNotContainsString('<img src=x', $response->json('data.body'));
        $this->assertStringContainsString('<img src=x onerror=alert(1)>', $response->json('data.subject'));
    }

    public function test_the_html_parts_keep_their_markup_and_escape_what_they_hold(): void
    {
        $service = app(EmailNotificationService::class);

        $body = $service->renderBody('<p>{{customer_name}}</p>{{waiver_section}}|{{addons_list}}|{{qr_code}}|{{location_breakdown_rows}}|{{unknown}}|{{ notes }}|[{{list}}]', [
            'customer_name' => '<b>Sam</b>',
            'waiver_section' => '<div class="w">sign</div>',
            'addons_list' => 'Pizza x1 - $10<br>Soda x2 - $4',
            'qr_code' => '<img src="/storage/qr.png">',
            'location_breakdown_rows' => '<tr><td>Brighton</td></tr>',
            'notes' => '$1 \\1 {{qr_code}}',
            'list' => ['a', 'b'],
        ]);
        $this->assertSame('<p>&lt;b&gt;Sam&lt;/b&gt;</p><div class="w">sign</div>|Pizza x1 - $10<br>Soda x2 - $4|<img src="/storage/qr.png">|<tr><td>Brighton</td></tr>|{{unknown}}|$1 \\1 {{qr_code}}|[]', $body);
        $this->assertSame("Hi <b>Sam</b> & co", $service->replaceVariables('Hi {{customer_name}} & co', ['customer_name' => '<b>Sam</b>']));

        $section = (new \ReflectionMethod(EmailNotificationService::class, 'waiverSectionHtml'))->invoke($service, 'https://book.test/waiver/a"b<c');
        $this->assertStringContainsString('href="https://book.test/waiver/a&quot;b&lt;c"', $section);

        $cake = AddOn::create(['location_id' => $this->location->id, 'name' => '<b>Cake</b>', 'price' => 5]);
        $booking = Booking::create([
            'reference_number' => 'ESC1',
            'booking_date' => '2026-11-05',
            'booking_time' => '14:00',
            'duration' => 60,
            'duration_unit' => 'minutes',
            'location_id' => $this->location->id,
            'participants' => 4,
            'total_amount' => 120,
            'status' => 'confirmed',
            'guest_name' => '<i>Jo</i>',
            'guest_email' => 'jo@example.com',
            'guest_phone' => '2485550100',
            'qr_code_path' => 'qrcodes/a"b.png',
        ]);
        $booking->addOns()->attach($cake->id, ['quantity' => 2, 'price_at_booking' => 5]);

        $variables = $service->buildVariables($booking->fresh(), 'booking', true);
        $this->assertStringContainsString('&lt;b&gt;Cake&lt;/b&gt; x2', $variables['addons_list']);
        $this->assertStringContainsString('src="/storage/qrcodes/a&quot;b.png"', $variables['qr_code']);
        $this->assertSame('<i>Jo</i>', $variables['customer_name']);
    }

    public function test_campaign_emails_sent_without_gmail_fill_in_values_as_text(): void
    {
        $mail = new \App\Mail\DynamicCampaignMail(
            'Hi {{ recipient_first_name }} from {{ company_name }}',
            '<p>Hello {{ recipient_first_name }}</p><p>{{ company_name }}</p><p>{{ recipient_last_name }}</p>',
            [
                'recipient_first_name' => '<b>Sam</b>',
                'recipient_last_name' => '{{ company_email }}',
                'company_name' => "Tom & Jerry's Fun",
                'company_email' => 'owner@zapzone.test',
            ]
        );

        $html = $mail->render();

        $this->assertStringContainsString('<p>Hello &lt;b&gt;Sam&lt;/b&gt;</p>', $html);
        $this->assertStringContainsString('<p>Tom &amp; Jerry&#039;s Fun</p>', $html);
        $this->assertStringContainsString('<p>{{ company_email }}</p>', $html);
        $this->assertStringNotContainsString('owner@zapzone.test', $html);
        $this->assertStringNotContainsString('{{ recipient_first_name }}', $html);
        $mail->assertHasSubject("Hi <b>Sam</b> from Tom & Jerry's Fun");
    }

    private function signKioskWaiver(string $first, string $last, string $email)
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

        return $this->postJson("/api/waivers/kiosk/{$template->id}/submit", [
            'location_id' => $this->location->id,
            'adult_first_name' => $first,
            'adult_last_name' => $last,
            'adult_email' => $email,
            'adult_phone' => '(248) 555-0142',
            'adult_dob' => '1990-05-05',
            'typed_legal_name' => $first . ' ' . $last,
            'agreement_accepted' => true,
            'electronic_consent_accepted' => true,
        ]);
    }

    private function sentTo(string $address): Email
    {
        $email = collect(Mail::mailer()->getSymfonyTransport()->messages())
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->first(fn ($original) => collect($original->getTo())->contains(fn ($to) => strtolower($to->getAddress()) === strtolower($address)));

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
