<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EmailNotification;
use App\Models\EmailNotificationLog;
use App\Models\Location;
use App\Models\User;
use App\Services\EmailNotificationService;
use Database\Seeders\DefaultEmailNotificationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailNotificationExcludedEmailsTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Location $location;
    protected User $admin;
    protected User $webmaster;
    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('ZapZone Test');
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

        $this->admin = $this->makeUser($this->company, 'company_admin', 'owner@zapzone.test');
        $this->webmaster = $this->makeUser($this->company, 'company_admin', 'Webmaster@BestInGames.com');
        $this->manager = $this->makeUser($this->company, 'location_manager', 'manager@zapzone.test', $this->location);

        Mail::fake();

        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    public function test_an_excluded_company_admin_is_skipped_while_everyone_else_is_still_emailed(): void
    {
        DefaultEmailNotificationSeeder::seedForCompany($this->company);

        $this->webmaster->update(['location_id' => $this->location->id]);

        $notifications = EmailNotification::where('company_id', $this->company->id)
            ->where('trigger_type', EmailNotification::TRIGGER_SCHEDULE_HELP_REQUESTED)
            ->get();

        $this->assertNotEmpty($notifications);

        foreach ($notifications as $notification) {
            $this->assertContains(EmailNotification::RECIPIENT_COMPANY_ADMIN, $notification->recipient_types);
            $notification->update(['excluded_emails' => ['webmaster@bestingames.com']]);
        }

        $this->postJson('/api/checkout-concerns', [
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
        ])->assertStatus(201);

        $sentTo = array_map('strtolower', EmailNotificationLog::pluck('recipient_email')->all());

        $this->assertContains('owner@zapzone.test', $sentTo);
        $this->assertContains('manager@zapzone.test', $sentTo);
        $this->assertNotContains('webmaster@bestingames.com', $sentTo);

        $alerted = \App\Models\CheckoutConcern::first()->alerted;
        $this->assertSame('notification_templates', $alerted['via']);
        $this->assertContains('manager@zapzone.test', $alerted['emails_sent']);
        $this->assertNotContains('webmaster@bestingames.com', array_map('strtolower', $alerted['emails_sent']));
    }

    public function test_without_an_exclusion_the_webmaster_admin_is_emailed_like_any_admin(): void
    {
        DefaultEmailNotificationSeeder::seedForCompany($this->company);

        $this->postJson('/api/checkout-concerns', [
            'location_id' => $this->location->id,
            'name' => 'Dana Whitfield',
            'phone' => '(810) 555-0177',
            'email' => 'dana@example.com',
            'message' => 'Call me.',
            'entity_type' => 'package',
            'entity_id' => 4,
            'entity_name' => 'Ultimate Party Package',
            'preferred_date' => '2026-09-05',
            'preferred_time' => '14:30',
        ])->assertStatus(201);

        $sentTo = array_map('strtolower', EmailNotificationLog::pluck('recipient_email')->all());

        $this->assertContains('webmaster@bestingames.com', $sentTo);
    }

    public function test_the_built_in_concern_alert_honours_exclusions_on_a_switched_off_template(): void
    {
        DefaultEmailNotificationSeeder::seedForCompany($this->company);

        $this->webmaster->update(['location_id' => $this->location->id]);

        EmailNotification::where('company_id', $this->company->id)
            ->where('trigger_type', EmailNotification::TRIGGER_SCHEDULE_HELP_REQUESTED)
            ->get()
            ->each(fn (EmailNotification $notification) => $notification->update([
                'is_active' => false,
                'excluded_emails' => ['webmaster@bestingames.com'],
            ]));

        $this->postJson('/api/checkout-concerns', [
            'location_id' => $this->location->id,
            'name' => 'Dana Whitfield',
            'phone' => '(810) 555-0177',
            'email' => 'dana@example.com',
            'message' => 'Call me.',
            'entity_type' => 'package',
            'entity_id' => 4,
            'entity_name' => 'Ultimate Party Package',
            'preferred_date' => '2026-09-05',
            'preferred_time' => '14:30',
        ])->assertStatus(201);

        $alerted = \App\Models\CheckoutConcern::first()->alerted;
        $sentTo = array_map('strtolower', $alerted['emails_sent']);

        $this->assertSame('built_in_defaults', $alerted['via']);
        $this->assertContains('manager@zapzone.test', $sentTo);
        $this->assertNotContains('webmaster@bestingames.com', $sentTo);
    }

    public function test_visit_emails_never_keep_an_exclusion_list(): void
    {
        $created = $this->actingAs($this->admin, 'sanctum')->postJson('/api/email-notifications', [
            'name' => 'Thanks for playing',
            'trigger_type' => EmailNotification::TRIGGER_VISIT_COMPLETED,
            'entity_type' => EmailNotification::ENTITY_ALL,
            'subject' => 'Thanks {{customer_name}}',
            'body' => 'Come back soon.',
            'recipient_types' => [EmailNotification::RECIPIENT_CUSTOMER],
            'excluded_emails' => ['guest@example.com'],
        ])->assertStatus(201);

        $this->assertSame([], EmailNotification::findOrFail($created->json('data.id'))->excluded_emails);
    }

    public function test_a_switched_off_customer_email_keeps_its_list_in_the_built_in_fallback(): void
    {
        DefaultEmailNotificationSeeder::seedForCompany($this->company);

        $package = \App\Models\Package::create([
            'location_id' => $this->location->id,
            'name' => 'Ultimate Party',
            'description' => 'Two hours of fun',
            'category' => 'Parties',
            'price' => 200,
            'duration' => 2,
        ]);

        $booking = \App\Models\Booking::create([
            'reference_number' => 'EXCL-0001',
            'location_id' => $this->location->id,
            'package_id' => $package->id,
            'booking_date' => now()->addDays(3)->toDateString(),
            'booking_time' => '14:00:00',
            'participants' => 10,
            'duration' => 2,
            'total_amount' => 200,
            'guest_name' => 'Gina Guest',
            'guest_email' => 'guest@example.com',
            'status' => 'confirmed',
        ]);

        EmailNotification::where('company_id', $this->company->id)
            ->where('trigger_type', EmailNotification::TRIGGER_BOOKING_CONFIRMED)
            ->update(['is_active' => false]);

        $sent = [];
        $this->partialMock(EmailNotificationService::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('sendEmail')->andReturnUsing(function ($to) use (&$sent) {
                $sent[] = $to;
            });
        });

        app(EmailNotificationService::class)->triggerBookingNotification($booking, EmailNotification::TRIGGER_BOOKING_CONFIRMED);
        $this->assertSame(['guest@example.com'], $sent);

        $sent = [];
        EmailNotification::findDefault($this->company->id, EmailNotification::DEFAULT_BOOKING_CONFIRMATION_CUSTOMER)
            ->update(['excluded_emails' => ['Guest@Example.com']]);

        app(EmailNotificationService::class)->triggerBookingNotification($booking->fresh(), EmailNotification::TRIGGER_BOOKING_CONFIRMED);
        $this->assertSame([], $sent);
    }

    public function test_the_daily_sales_report_skips_excluded_addresses(): void
    {
        $notification = EmailNotification::create([
            'company_id' => $this->company->id,
            'name' => 'End of Day',
            'trigger_type' => EmailNotification::TRIGGER_END_OF_DAY_SALES_REPORT,
            'entity_type' => EmailNotification::ENTITY_ALL,
            'recipient_types' => [EmailNotification::RECIPIENT_COMPANY_ADMIN, EmailNotification::RECIPIENT_CUSTOM],
            'custom_emails' => ['reports@zapzone.test', 'skip-me@zapzone.test'],
            'excluded_emails' => ['webmaster@bestingames.com', 'SKIP-ME@zapzone.test'],
            'subject' => 'Sales for {{report_date}}',
            'body' => 'Totals.',
            'is_active' => true,
        ]);

        app(EmailNotificationService::class)->sendDailySalesReport($notification, ['report_date' => 'Oct 15']);

        $sentTo = array_map('strtolower', EmailNotificationLog::pluck('recipient_email')->all());

        sort($sentTo);
        $this->assertSame(['owner@zapzone.test', 'reports@zapzone.test'], $sentTo);
    }

    public function test_garbage_in_the_exclusion_column_never_blocks_a_send(): void
    {
        $notification = EmailNotification::create([
            'company_id' => $this->company->id,
            'name' => 'End of Day',
            'trigger_type' => EmailNotification::TRIGGER_END_OF_DAY_SALES_REPORT,
            'entity_type' => EmailNotification::ENTITY_ALL,
            'recipient_types' => [EmailNotification::RECIPIENT_COMPANY_ADMIN],
            'subject' => 'Sales',
            'body' => 'Totals.',
            'is_active' => true,
        ]);

        DB::table('email_notifications')->where('id', $notification->id)->update(['excluded_emails' => '"just a string"']);
        app(EmailNotificationService::class)->sendDailySalesReport($notification->fresh(), []);

        $sentTo = array_map('strtolower', EmailNotificationLog::pluck('recipient_email')->all());
        sort($sentTo);
        $this->assertSame(['owner@zapzone.test', 'webmaster@bestingames.com'], $sentTo);

        EmailNotificationLog::query()->delete();

        DB::table('email_notifications')->where('id', $notification->id)->update(['excluded_emails' => '[["nested"], 42, " WEBMASTER@bestingames.com "]']);
        app(EmailNotificationService::class)->sendDailySalesReport($notification->fresh(), []);

        $this->assertSame(['owner@zapzone.test'], array_map('strtolower', EmailNotificationLog::pluck('recipient_email')->all()));
    }

    public function test_creating_and_editing_a_notification_saves_the_excluded_list(): void
    {
        $created = $this->actingAs($this->admin, 'sanctum')->postJson('/api/email-notifications', [
            'name' => 'Staff alert',
            'trigger_type' => EmailNotification::TRIGGER_BOOKING_CONFIRMED,
            'entity_type' => EmailNotification::ENTITY_ALL,
            'subject' => 'New booking',
            'body' => 'A booking came in.',
            'recipient_types' => [EmailNotification::RECIPIENT_COMPANY_ADMIN],
            'excluded_emails' => ['Webmaster@BestInGames.com', 'webmaster@bestingames.com'],
        ])->assertStatus(201);

        $notification = EmailNotification::findOrFail($created->json('data.id'));
        $this->assertSame(['webmaster@bestingames.com'], $notification->excluded_emails);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/email-notifications/{$notification->id}")
            ->assertOk()
            ->assertJsonPath('data.excluded_emails', ['webmaster@bestingames.com']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/email-notifications/{$notification->id}", ['excluded_emails' => ['not-an-email']])
            ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/email-notifications/{$notification->id}", ['excluded_emails' => []])
            ->assertOk();

        $this->assertSame([], $notification->fresh()->excluded_emails);
    }

    public function test_pasted_invisible_characters_and_stray_punctuation_are_cleaned_before_validation(): void
    {
        $notification = $this->makeNotification($this->company, [EmailNotification::RECIPIENT_COMPANY_ADMIN]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/email-notifications/{$notification->id}", ['excluded_emails' => [
                "\u{200B}Webmaster@BestInGames.com",
                " ops@zapzone.test;",
                "\u{00A0}front\u{00AD}desk@zapzone.test\u{2060}",
                "\u{FEFF}reports@zapzone.test.",
            ]])
            ->assertOk();

        $this->assertSame(
            ['webmaster@bestingames.com', 'ops@zapzone.test', 'frontdesk@zapzone.test', 'reports@zapzone.test'],
            $notification->fresh()->excluded_emails
        );

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/email-notifications/{$notification->id}", ['excluded_emails' => ["\u{1F600}@zapzone.test"]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('excluded_emails.0');
    }

    public function test_built_in_notifications_accept_an_excluded_list(): void
    {
        DefaultEmailNotificationSeeder::seedForCompany($this->company);

        $default = EmailNotification::findDefault($this->company->id, EmailNotification::DEFAULT_BOOKING_CONFIRMATION_STAFF);
        $this->assertNotNull($default);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/email-notifications/{$default->id}", ['excluded_emails' => ['webmaster@bestingames.com']])
            ->assertOk();

        $this->assertSame(['webmaster@bestingames.com'], $default->fresh()->excluded_emails);
    }

    public function test_the_migration_excludes_the_webmaster_only_where_it_could_be_emailed(): void
    {
        $otherCompany = $this->makeCompany('Other Co');

        $adminAlert = $this->makeNotification($this->company, [EmailNotification::RECIPIENT_STAFF, EmailNotification::RECIPIENT_COMPANY_ADMIN]);
        $customerOnly = $this->makeNotification($this->company, [EmailNotification::RECIPIENT_CUSTOMER]);
        $alreadyExcluding = $this->makeNotification($this->company, [EmailNotification::RECIPIENT_COMPANY_ADMIN], ['someone@else.test']);
        $otherCompanyAlert = $this->makeNotification($otherCompany, [EmailNotification::RECIPIENT_COMPANY_ADMIN]);
        $customList = $this->makeNotification($this->company, [EmailNotification::RECIPIENT_CUSTOM]);
        $customList->update(['custom_emails' => ['reports@zapzone.test', 'Webmaster@BestInGames.com']]);
        $concernAlert = $this->makeNotification($this->company, [EmailNotification::RECIPIENT_STAFF]);
        DB::table('email_notifications')->where('id', $concernAlert->id)->update(['trigger_type' => EmailNotification::TRIGGER_SCHEDULE_HELP_REQUESTED]);
        $malformed = $this->makeNotification($this->company, [EmailNotification::RECIPIENT_CUSTOM]);
        DB::table('email_notifications')->where('id', $malformed->id)->update([
            'custom_emails' => '[["nested"], 42, " Webmaster@BestInGames.com "]',
            'recipient_types' => '"not a list"',
        ]);

        $migration = require base_path('database/migrations/2026_10_15_000001_add_excluded_emails_to_email_notifications.php');
        $migration->up();
        $migration->up();

        $this->assertSame(['webmaster@bestingames.com'], $adminAlert->fresh()->excluded_emails);
        $this->assertEmpty($customerOnly->fresh()->excluded_emails);
        $this->assertSame(['someone@else.test', 'webmaster@bestingames.com'], $alreadyExcluding->fresh()->excluded_emails);
        $this->assertEmpty($otherCompanyAlert->fresh()->excluded_emails);
        $this->assertSame(['webmaster@bestingames.com'], $customList->fresh()->excluded_emails);
        $this->assertSame(['webmaster@bestingames.com'], $concernAlert->fresh()->excluded_emails);
        $this->assertSame(['webmaster@bestingames.com'], json_decode(DB::table('email_notifications')->where('id', $malformed->id)->value('excluded_emails'), true));
    }

    private function makeCompany(string $name): Company
    {
        return Company::create([
            'company_name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)) . '@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);
    }

    private function makeUser(Company $company, string $role, string $email, ?Location $location = null): User
    {
        return User::create([
            'company_id' => $company->id,
            'location_id' => $location?->id,
            'first_name' => 'Test',
            'last_name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function makeNotification(Company $company, array $recipientTypes, ?array $excluded = null): EmailNotification
    {
        $notification = EmailNotification::create([
            'company_id' => $company->id,
            'name' => 'Alert ' . implode('+', $recipientTypes),
            'trigger_type' => EmailNotification::TRIGGER_BOOKING_CONFIRMED,
            'entity_type' => EmailNotification::ENTITY_ALL,
            'recipient_types' => $recipientTypes,
            'subject' => 'Subject',
            'body' => 'Body',
            'is_active' => true,
        ]);

        DB::table('email_notifications')->where('id', $notification->id)->update([
            'excluded_emails' => $excluded === null ? null : json_encode($excluded),
        ]);

        return $notification;
    }
}
