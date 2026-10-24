<?php

namespace Tests\Feature;

use App\Models\Attraction;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\EmailCampaign;
use App\Models\EmailNotification;
use App\Models\EmailTemplate;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DefaultEmailNotificationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailAdminScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Company $other;
    protected Location $brighton;
    protected Location $waterford;
    protected Location $lansing;
    protected User $admin;
    protected User $manager;
    protected User $attendant;
    protected User $otherAdmin;
    protected User $waterfordManager;
    protected User $waterfordAttendant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->company = $this->makeCompany('Zap Zone Test');
        $this->other = $this->makeCompany('Other Fun Co');
        $this->brighton = $this->makeLocation($this->company, 'Brighton');
        $this->waterford = $this->makeLocation($this->company, 'Waterford');
        $this->lansing = $this->makeLocation($this->other, 'Lansing');

        $this->admin = $this->makeUser($this->company, 'company_admin', 'owner@zapzone.test', $this->brighton);
        $this->manager = $this->makeUser($this->company, 'location_manager', 'manager@zapzone.test', $this->brighton);
        $this->attendant = $this->makeUser($this->company, 'attendant', 'desk@zapzone.test', $this->brighton);
        $this->otherAdmin = $this->makeUser($this->other, 'company_admin', 'owner@other.test', $this->lansing);
        $this->waterfordManager = $this->makeUser($this->company, 'location_manager', 'wmanager@zapzone.test', $this->waterford);
        $this->waterfordAttendant = $this->makeUser($this->company, 'attendant', 'wdesk@zapzone.test', $this->waterford);

        DefaultEmailNotificationSeeder::seedForCompany($this->company);
    }

    public function test_the_preview_only_fills_in_records_the_staff_member_can_reach(): void
    {
        $stranger = $this->customer('Stranger', 'stranger@other.test');
        $lansing = $this->booking($this->lansing, 'BK-LANSING', $stranger, 'lansing secret');
        $wanda = $this->customer('Wanda', 'wanda@zapzone.test');
        $waterford = $this->booking($this->waterford, 'BK-WATERFORD', $wanda);
        $bea = $this->customer('Bea', 'bea@zapzone.test');
        $brighton = $this->booking($this->brighton, 'BK-BRIGHTON', $bea);
        $notification = $this->notificationFor('booking_confirmed');

        $data = $this->preview($this->admin, $notification, ['booking_id' => $lansing->id, 'customer_id' => $stranger->id]);
        $this->assertSame('john.doe@example.com', $data['variables_used']['customer_email']);
        $this->assertSame('BK-2026-00001', $data['variables_used']['booking_reference']);
        $this->assertStringNotContainsString('lansing', strtolower(json_encode($data)));

        $data = $this->preview($this->admin, $notification, ['booking_id' => $waterford->id]);
        $this->assertSame('BK-WATERFORD', $data['variables_used']['booking_reference']);
        $this->assertSame('wanda@zapzone.test', $data['variables_used']['customer_email']);
        $this->assertSame('Waterford', $data['variables_used']['location_name']);

        foreach ([$this->manager, $this->attendant] as $staff) {
            $data = $this->preview($staff, $notification, ['booking_id' => $waterford->id]);
            $this->assertSame('BK-2026-00001', $data['variables_used']['booking_reference']);
            $this->assertSame('john.doe@example.com', $data['variables_used']['customer_email']);

            $data = $this->preview($staff, $notification, ['customer_id' => $wanda->id]);
            $this->assertSame('john.doe@example.com', $data['variables_used']['customer_email']);

            $data = $this->preview($staff, $notification, ['booking_id' => $brighton->id]);
            $this->assertSame('BK-BRIGHTON', $data['variables_used']['booking_reference']);
            $this->assertSame('bea@zapzone.test', $data['variables_used']['customer_email']);
        }

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/email-notifications/{$notification->id}/preview", ['booking_id' => [$lansing->id, $brighton->id]])
            ->assertOk()
            ->assertJsonPath('data.variables_used.booking_reference', 'BK-2026-00001');
    }

    public function test_a_test_send_never_emails_another_companys_booking(): void
    {
        $stranger = $this->customer('Stranger', 'stranger@other.test');
        $lansing = $this->booking($this->lansing, 'BK-LANSING', $stranger, 'lansing secret');
        $notification = $this->notificationFor('booking_confirmed');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/email-notifications/{$notification->id}/send-test", ['test_email' => 'me@zapzone.test', 'booking_id' => $lansing->id, 'customer_id' => $stranger->id])
            ->assertOk();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/email-notifications/{$notification->id}/send-test", ['test_email' => 'me@zapzone.test', 'booking_id' => 999999])
            ->assertOk();

        $html = collect(Mail::mailer()->getSymfonyTransport()->messages())
            ->map(fn ($sent) => (string) $sent->getOriginalMessage()->getHtmlBody())
            ->implode("\n");
        $this->assertStringContainsString('John', $html);
        $this->assertStringNotContainsString('Stranger', $html);
        $this->assertStringNotContainsString('BK-LANSING', $html);
    }

    public function test_a_ticket_purchase_previews_with_its_own_location_and_stays_in_scope(): void
    {
        $notification = $this->notificationFor('purchase_confirmed');
        $here = $this->purchase($this->waterford, 'waterford tickets');
        $there = $this->purchase($this->lansing, 'lansing tickets');

        $data = $this->preview($this->admin, $notification, ['purchase_id' => $here->id]);
        $this->assertSame('waterford tickets', $data['variables_used']['purchase_notes']);
        $this->assertSame('Waterford', $data['variables_used']['location_name']);
        $this->assertSame('1 Main St, Waterford, MI, 48116', $data['variables_used']['location_address']);

        $data = $this->preview($this->admin, $notification, ['purchase_id' => $there->id]);
        $this->assertSame('', $data['variables_used']['purchase_notes']);
        $this->assertStringNotContainsString('lansing', strtolower(json_encode($data)));
    }

    public function test_managers_only_open_their_own_locations_notifications(): void
    {
        $waterfordOnly = $this->makeNotification($this->waterford);
        $companyWide = $this->makeNotification(null);

        $this->actingAs($this->manager, 'sanctum')->getJson("/api/email-notifications/{$waterfordOnly->id}")->assertForbidden();
        $this->actingAs($this->manager, 'sanctum')->getJson("/api/email-notifications/{$waterfordOnly->id}/logs")->assertForbidden();
        $this->actingAs($this->attendant, 'sanctum')->deleteJson("/api/email-notifications/{$waterfordOnly->id}")->assertForbidden();
        $this->assertDatabaseHas('email_notifications', ['id' => $waterfordOnly->id]);

        $this->actingAs($this->manager, 'sanctum')->getJson("/api/email-notifications/{$companyWide->id}")->assertOk();
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/email-notifications/{$waterfordOnly->id}")->assertOk();
        $this->actingAs($this->otherAdmin, 'sanctum')->getJson("/api/email-notifications/{$waterfordOnly->id}")->assertNotFound();
    }

    public function test_another_companys_template_cannot_be_attached(): void
    {
        $theirs = EmailTemplate::create([
            'company_id' => $this->other->id,
            'created_by' => $this->otherAdmin->id,
            'name' => 'Their template',
            'subject' => 'Secret subject',
            'body' => '<p>Secret body</p>',
            'status' => 'active',
        ]);
        $notification = $this->notificationFor('booking_confirmed');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/email-notifications/{$notification->id}", ['email_template_id' => $theirs->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email_template_id');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/email-campaigns', [
                'name' => 'Fall news',
                'subject' => 'Hi',
                'body' => '<p>Hello</p>',
                'email_template_id' => $theirs->id,
                'recipient_types' => ['custom'],
                'custom_emails' => ['someone@example.com'],
                'send_now' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email_template_id');
    }

    public function test_customer_campaigns_are_paused_with_a_clear_message(): void
    {
        $this->contact($this->brighton, 'ada@example.com');

        foreach (['/api/email-campaigns/preview-recipients', '/api/email-campaigns'] as $url) {
            $this->actingAs($this->admin, 'sanctum')->postJson($url, [
                'name' => 'Fall news',
                'subject' => 'Hi {{ recipient_first_name }}',
                'body' => '<p>Hello</p>',
                'recipient_types' => ['customers', 'attendants'],
                'send_now' => true,
            ])
                ->assertStatus(422)
                ->assertJsonPath('message', 'Campaigns to customers are paused until campaign emails carry an unsubscribe link. You can still send to staff or a custom list.');
        }

        $old = EmailCampaign::create([
            'company_id' => $this->company->id,
            'location_id' => $this->brighton->id,
            'sent_by' => $this->admin->id,
            'name' => 'Old customer campaign',
            'subject' => 'Hi',
            'body' => '<p>Hello</p>',
            'recipient_types' => ['customers'],
            'status' => EmailCampaign::STATUS_COMPLETED,
        ]);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/email-campaigns/{$old->id}/resend", ['type' => 'all'])->assertStatus(422);

        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
        $this->assertSame(1, EmailCampaign::count());
    }

    public function test_staff_campaigns_still_send_and_stay_inside_the_company(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/email-campaigns/preview-recipients', [
            'recipient_types' => ['attendants'],
            'recipient_filters' => ['location_id' => $this->lansing->id],
        ])->assertForbidden();

        $this->actingAs($this->manager, 'sanctum')->postJson('/api/email-campaigns/preview-recipients', [
            'recipient_types' => ['attendants'],
            'location_id' => $this->waterford->id,
        ])->assertForbidden();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/email-campaigns', [
            'name' => 'Staff news',
            'subject' => 'Hi {{ recipient_first_name }}',
            'body' => '<p>Hello {{ recipient_first_name }} at {{ company_name }}</p>',
            'recipient_types' => ['attendants'],
            'location_id' => $this->brighton->id,
            'send_now' => true,
        ])->assertCreated();

        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $html = (string) $messages->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('Hello Test at Zap Zone Test', $html);
        $this->assertStringNotContainsString('{{', $html);

        $waterfordCampaign = EmailCampaign::create([
            'company_id' => $this->company->id,
            'location_id' => $this->waterford->id,
            'sent_by' => $this->admin->id,
            'name' => 'Waterford only',
            'subject' => 'Hi',
            'body' => '<p>Hello</p>',
            'recipient_types' => ['attendants'],
            'status' => EmailCampaign::STATUS_COMPLETED,
        ]);
        $this->actingAs($this->manager, 'sanctum')->getJson("/api/email-campaigns/{$waterfordCampaign->id}")->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/email-campaigns/{$waterfordCampaign->id}")->assertOk();
        $this->actingAs($this->otherAdmin, 'sanctum')->getJson("/api/email-campaigns/{$waterfordCampaign->id}")->assertNotFound();
    }

    public function test_contacts_stay_inside_the_staff_members_company_and_location(): void
    {
        $mine = $this->contact($this->brighton, 'bea@example.com');
        $waterford = $this->contact($this->waterford, 'wanda@example.com');
        $theirs = $this->contact($this->lansing, 'stranger@other.test');

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/contacts/{$theirs->id}")->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/contacts/{$theirs->id}", ['first_name' => 'Hacked'])->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/contacts/{$theirs->id}")->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/contacts/{$theirs->id}/add-tag", ['tag' => 'vip'])->assertForbidden();
        $this->assertDatabaseHas('contacts', ['id' => $theirs->id, 'first_name' => 'Contact']);

        $this->actingAs($this->manager, 'sanctum')->getJson("/api/contacts/{$waterford->id}")->assertForbidden();
        $this->actingAs($this->manager, 'sanctum')->getJson("/api/contacts/{$mine->id}")->assertOk();
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/contacts/{$waterford->id}")->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/contacts/bulk-delete', ['ids' => [$mine->id, $theirs->id]])
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 1);
        $this->assertDatabaseHas('contacts', ['id' => $theirs->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/contacts/export-for-campaign', ['company_id' => $this->other->id])
            ->assertOk()
            ->assertJsonPath('data.count', 0);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/contacts', ['company_id' => $this->other->id, 'email' => 'planted@example.com'])
            ->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/contacts', ['company_id' => $this->company->id, 'location_id' => $this->lansing->id, 'email' => 'planted@example.com'])
            ->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/contacts/{$waterford->id}", ['location_id' => $this->lansing->id])
            ->assertForbidden();
        $this->assertDatabaseMissing('contacts', ['email' => 'planted@example.com']);
    }

    public function test_location_staff_cannot_touch_another_locations_records(): void
    {
        $notification = $this->makeNotification($this->waterford);
        $booking = $this->booking($this->waterford, 'BK-W1', $this->customer('Wes', 'wes@example.com'));
        $log = \App\Models\EmailNotificationLog::create([
            'email_notification_id' => $notification->id,
            'recipient_email' => 'wes@example.com',
            'recipient_type' => 'customer',
            'notifiable_type' => Booking::class,
            'notifiable_id' => $booking->id,
            'status' => 'failed',
        ]);
        $campaign = EmailCampaign::create([
            'company_id' => $this->company->id,
            'location_id' => $this->waterford->id,
            'sent_by' => $this->admin->id,
            'name' => 'Waterford staff news',
            'subject' => 'Hi',
            'body' => '<p>Hello</p>',
            'recipient_types' => ['attendants'],
            'status' => EmailCampaign::STATUS_PENDING,
        ]);
        $contact = $this->contact($this->waterford, 'wanda@example.com');
        $contact->update(['tags' => ['vip']]);

        foreach ([$this->manager, $this->attendant] as $staff) {
            $calls = [
                ['putJson', "/api/email-notifications/{$notification->id}", ['subject' => 'Changed']],
                ['patchJson', "/api/email-notifications/{$notification->id}/toggle-status", []],
                ['postJson', "/api/email-notifications/{$notification->id}/duplicate", []],
                ['postJson', "/api/email-notifications/{$notification->id}/preview", []],
                ['postJson', "/api/email-notifications/{$notification->id}/send-test", ['test_email' => 'me@zapzone.test']],
                ['postJson', "/api/email-notifications/{$notification->id}/reset-default", []],
                ['postJson', "/api/email-notifications/{$notification->id}/logs/{$log->id}/resend", []],
                ['deleteJson', "/api/email-notifications/{$notification->id}", []],
                ['postJson', "/api/email-campaigns/{$campaign->id}/cancel", []],
                ['postJson', "/api/email-campaigns/{$campaign->id}/resend", ['type' => 'all']],
                ['deleteJson', "/api/email-campaigns/{$campaign->id}", []],
                ['putJson', "/api/contacts/{$contact->id}", ['first_name' => 'Changed']],
                ['postJson', "/api/contacts/{$contact->id}/add-tag", ['tag' => 'new']],
                ['postJson', "/api/contacts/{$contact->id}/remove-tag", ['tag' => 'vip']],
                ['deleteJson', "/api/contacts/{$contact->id}", []],
            ];

            foreach ($calls as [$method, $url, $body]) {
                $this->actingAs($staff, 'sanctum')->{$method}($url, $body)->assertForbidden();
            }
        }

        $this->assertSame('Subject', $notification->fresh()->subject);
        $this->assertSame(1, EmailNotification::where('name', 'like', 'Alert Waterford%')->count());
        $this->assertSame('failed', $log->fresh()->status);
        $this->assertSame(EmailCampaign::STATUS_PENDING, $campaign->fresh()->status);
        $this->assertSame(['vip'], $contact->fresh()->tags);
        $this->assertSame('Contact', $contact->fresh()->first_name);
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_location_staff_leave_company_wide_and_report_emails_to_admins(): void
    {
        $confirmation = $this->notificationFor('booking_confirmed');
        $report = $this->notificationFor('end_of_day_sales_report');

        foreach ([$this->manager, $this->attendant] as $staff) {
            foreach ([$confirmation, $report] as $notification) {
                $this->actingAs($staff, 'sanctum')->putJson("/api/email-notifications/{$notification->id}", ['custom_emails' => ['me@gmail.com']])->assertForbidden();
                $this->actingAs($staff, 'sanctum')->patchJson("/api/email-notifications/{$notification->id}/toggle-status")->assertForbidden();
                $this->actingAs($staff, 'sanctum')->postJson("/api/email-notifications/{$notification->id}/reset-default")->assertForbidden();
            }
        }
        $this->assertTrue((bool) $confirmation->fresh()->is_active);
        $this->assertSame([], (array) $confirmation->fresh()->custom_emails);

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/email-notifications/{$confirmation->id}/toggle-status")->assertOk();
        $this->assertFalse((bool) $confirmation->fresh()->is_active);
    }

    public function test_location_staff_emails_stay_at_their_own_location(): void
    {
        $base = [
            'name' => 'Desk alert',
            'trigger_type' => 'booking_created',
            'entity_type' => EmailNotification::ENTITY_ALL,
            'recipient_types' => ['custom'],
            'custom_emails' => ['me@gmail.com'],
            'subject' => 'New booking',
            'body' => '<p>{{customer_name}}</p>',
        ];

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/email-notifications', $base + ['location_id' => $this->waterford->id])
            ->assertForbidden();

        $id = $this->actingAs($this->attendant, 'sanctum')
            ->postJson('/api/email-notifications', $base)
            ->assertCreated()
            ->json('data.id');
        $this->assertSame($this->brighton->id, (int) EmailNotification::find($id)->location_id);

        $this->actingAs($this->attendant, 'sanctum')
            ->putJson("/api/email-notifications/{$id}", ['location_id' => null])
            ->assertOk();
        $this->assertSame($this->brighton->id, (int) EmailNotification::find($id)->location_id);

        $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/email-notifications/{$id}", ['location_id' => $this->waterford->id])
            ->assertForbidden();
    }

    public function test_resend_only_reaches_bookings_at_the_staff_members_locations(): void
    {
        $confirmation = $this->notificationFor('booking_confirmed');
        $booking = $this->booking($this->waterford, 'BK-W2', $this->customer('Wes', 'wes@example.com'));
        $log = \App\Models\EmailNotificationLog::create([
            'email_notification_id' => $confirmation->id,
            'recipient_email' => 'wes@example.com',
            'recipient_type' => 'customer',
            'notifiable_type' => Booking::class,
            'notifiable_id' => $booking->id,
            'status' => 'failed',
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/email-notifications/{$confirmation->id}/logs/{$log->id}/resend")
            ->assertForbidden();
        $this->assertSame('failed', $log->fresh()->status);
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());

        $this->actingAs($this->waterfordManager, 'sanctum')
            ->postJson("/api/email-notifications/{$confirmation->id}/logs/{$log->id}/resend")
            ->assertOk();
    }

    public function test_company_wide_campaigns_and_campaign_stats_respect_locations(): void
    {
        $everywhere = EmailCampaign::create([
            'company_id' => $this->company->id,
            'location_id' => null,
            'sent_by' => $this->admin->id,
            'name' => 'Company news',
            'subject' => 'Hi',
            'body' => '<p>Hello</p>',
            'recipient_types' => ['attendants'],
            'status' => EmailCampaign::STATUS_PENDING,
        ]);
        $waterfordOnly = EmailCampaign::create([
            'company_id' => $this->company->id,
            'location_id' => $this->waterford->id,
            'sent_by' => $this->admin->id,
            'name' => 'Waterford secret',
            'subject' => 'Hi',
            'body' => '<p>Hello</p>',
            'recipient_types' => ['attendants'],
            'status' => EmailCampaign::STATUS_COMPLETED,
        ]);

        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/email-campaigns/{$everywhere->id}/cancel")->assertForbidden();
        $this->actingAs($this->attendant, 'sanctum')->deleteJson("/api/email-campaigns/{$everywhere->id}")->assertForbidden();
        $this->assertSame(EmailCampaign::STATUS_PENDING, $everywhere->fresh()->status);

        $recent = collect($this->actingAs($this->manager, 'sanctum')->getJson('/api/email-campaigns/statistics')->assertOk()->json('data.recent_campaigns'))->pluck('name');
        $this->assertContains('Company news', $recent);
        $this->assertNotContains('Waterford secret', $recent);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/email-campaigns/{$everywhere->id}/cancel")->assertOk();
    }

    public function test_an_admins_all_locations_staff_campaign_reaches_every_location(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/email-campaigns', [
            'name' => 'Everyone',
            'subject' => 'Hi',
            'body' => '<p>Hello {{ location_name }}</p>',
            'recipient_types' => ['attendants'],
            'send_now' => true,
        ])->assertCreated();

        $to = collect(Mail::mailer()->getSymfonyTransport()->messages())
            ->flatMap(fn ($sent) => collect($sent->getOriginalMessage()->getTo())->map(fn ($address) => $address->getAddress()))
            ->sort()->values()->all();
        $this->assertSame(['desk@zapzone.test', 'wdesk@zapzone.test'], $to);
        $this->assertNull(EmailCampaign::first()->location_id);
    }

    public function test_shared_contacts_bulk_actions_and_counts_respect_locations(): void
    {
        $shared = Contact::create(['company_id' => $this->company->id, 'location_id' => null, 'email' => 'shared@example.com', 'first_name' => 'Shared', 'status' => 'active']);
        $mine = $this->contact($this->brighton, 'bea@example.com');
        $theirs = $this->contact($this->waterford, 'wanda@example.com');

        $this->actingAs($this->manager, 'sanctum')->getJson("/api/contacts/{$shared->id}")->assertForbidden();
        $this->actingAs($this->manager, 'sanctum')->deleteJson("/api/contacts/{$shared->id}")->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/contacts/{$shared->id}")->assertOk();

        $created = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/contacts', ['company_id' => $this->company->id, 'email' => 'walkin@example.com'])
            ->assertCreated()
            ->json('data.id');
        $this->assertSame($this->brighton->id, (int) Contact::find($created)->location_id);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/contacts/bulk-update', ['ids' => [$mine->id, $theirs->id], 'action' => 'add_tags', 'tags' => ['party']])
            ->assertOk();
        $this->assertSame(['party'], $mine->fresh()->tags);
        $this->assertNull($theirs->fresh()->tags);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/contacts/bulk-update', ['ids' => [$mine->id], 'action' => 'set_location', 'location_id' => $this->waterford->id])
            ->assertForbidden();
        $this->assertSame($this->brighton->id, (int) $mine->fresh()->location_id);

        $theirs->update(['tags' => ['waterford-only']]);
        $this->assertNotContains('waterford-only', $this->actingAs($this->manager, 'sanctum')->getJson('/api/contacts/tags')->assertOk()->json('data'));

        $foreign = $this->contact($this->lansing, 'stranger@other.test');
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/contacts/bulk-delete', ['ids' => [$mine->id, $foreign->id]])
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 1);
        $log = \App\Models\ActivityLog::where('action', 'Contacts Bulk Delete')->latest('id')->first();
        $this->assertSame([$mine->id], $log->metadata['contact_ids']);
    }

    public function test_contact_creation_never_reveals_another_companys_list(): void
    {
        $this->contact($this->lansing, 'known@other.test');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/contacts', ['company_id' => $this->other->id, 'email' => 'known@other.test'])
            ->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/contacts', ['company_id' => $this->other->id, 'email' => 'unknown@other.test'])
            ->assertForbidden();
    }

    public function test_previews_match_what_each_email_really_fills_and_use_the_staff_members_location(): void
    {
        $payment = $this->notificationFor('payment_received');
        $data = $this->preview($this->admin, $payment, []);
        $this->assertArrayNotHasKey('customer_first_name', $data['variables_used']);
        $this->assertArrayHasKey('payment_amount', $data['variables_used']);

        $report = $this->notificationFor('end_of_day_sales_report');
        $data = $this->preview($this->admin, $report, []);
        $this->assertArrayNotHasKey('location_name', $data['variables_used']);

        $confirmation = $this->notificationFor('booking_confirmed');
        $data = $this->preview($this->waterfordManager, $confirmation, []);
        $this->assertSame('Waterford', $data['variables_used']['location_name']);
    }

    private function preview(User $as, EmailNotification $notification, array $params): array
    {
        return $this->actingAs($as, 'sanctum')
            ->postJson("/api/email-notifications/{$notification->id}/preview", $params)
            ->assertOk()
            ->json('data');
    }

    private function notificationFor(string $trigger): EmailNotification
    {
        return EmailNotification::where('company_id', $this->company->id)
            ->where('trigger_type', $trigger)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function makeNotification(?Location $location): EmailNotification
    {
        return EmailNotification::create([
            'company_id' => $this->company->id,
            'location_id' => $location?->id,
            'name' => 'Alert ' . ($location?->name ?? 'everywhere'),
            'trigger_type' => EmailNotification::TRIGGER_BOOKING_CONFIRMED,
            'entity_type' => EmailNotification::ENTITY_ALL,
            'recipient_types' => [EmailNotification::RECIPIENT_COMPANY_ADMIN],
            'subject' => 'Subject',
            'body' => 'Body',
        ]);
    }

    private function customer(string $first, string $email): Customer
    {
        return Customer::create([
            'first_name' => $first,
            'last_name' => 'Rivera',
            'email' => $email,
            'phone' => '2485550100',
            'password' => Hash::make('password'),
        ]);
    }

    private function booking(Location $location, string $reference, Customer $customer, string $notes = ''): Booking
    {
        return Booking::create([
            'reference_number' => $reference,
            'booking_date' => '2026-11-05',
            'booking_time' => '14:00',
            'duration' => 60,
            'duration_unit' => 'minutes',
            'location_id' => $location->id,
            'customer_id' => $customer->id,
            'participants' => 4,
            'total_amount' => 120,
            'status' => 'confirmed',
            'notes' => $notes,
        ]);
    }

    private function purchase(Location $location, string $notes): AttractionPurchase
    {
        $attraction = Attraction::create([
            'location_id' => $location->id,
            'name' => 'Laser Tag ' . $location->name,
            'description' => 'Arena laser tag',
            'category' => 'Attractions',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);

        return AttractionPurchase::create([
            'attraction_id' => $attraction->id,
            'guest_name' => 'Ticket Buyer',
            'guest_email' => 'tickets@example.com',
            'quantity' => 2,
            'total_amount' => 40,
            'amount_paid' => 40,
            'purchase_date' => now()->toDateString(),
            'payment_status' => 'paid',
            'notes' => $notes,
        ]);
    }

    private function contact(Location $location, string $email): Contact
    {
        return Contact::create([
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'email' => $email,
            'first_name' => 'Contact',
            'last_name' => 'Person',
            'status' => 'active',
        ]);
    }

    private function makeCompany(string $name): Company
    {
        return Company::create([
            'company_name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)) . '@example.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);
    }

    private function makeLocation(Company $company, string $name): Location
    {
        return Location::create([
            'company_id' => $company->id,
            'name' => $name,
            'address' => '1 Main St',
            'city' => $name,
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => strtolower($name) . '@example.test',
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
}
