<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\Photo;
use App\Models\PhotoDelivery;
use App\Models\PhotoSession;
use App\Models\RegistrationEntry;
use App\Models\RegistrationEvent;
use App\Models\StaffTerminal;
use App\Models\User;
use App\Models\Waiver;
use App\Models\WaiverTemplate;
use App\Services\PhotoDeliveryService;
use App\Services\SmsService;
use App\Services\WaiverService;
use App\Support\OperatingDay;
use App\Support\SchemaSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\EventRegistrationFixtures;
use Tests\TestCase;

class PhotoEventRegistrationTest extends TestCase
{
    use RefreshDatabase;
    use EventRegistrationFixtures;

    private Company $company;

    private Location $brighton;

    private Location $farmington;

    private User $admin;

    private User $manager;

    private User $attendant;

    private RegistrationEvent $trunk;

    private RegistrationEvent $glow;

    private RegistrationEvent $harvest;

    private RegistrationEvent $later;

    private RegistrationEvent $farmingtonTrunk;

    private PhotoRegistrationFakeTexts $texts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');
        Storage::fake('photos');

        config([
            'gmail.enabled' => false,
            'twilio.sid' => 'AC' . str_repeat('2', 32),
            'twilio.auth_token' => 'test-token',
            'twilio.from_number' => '+18559245172',
            'app.frontend_url' => 'https://book.zapzone.test',
        ]);

        $this->texts = new PhotoRegistrationFakeTexts();
        $texts = $this->texts;
        $this->app->bind(SmsService::class, fn () => new PhotoRegistrationFakeSms($texts));

        $this->company = $this->makeCompany('ZapZone Photos');
        $this->brighton = $this->makeVenue($this->company, 'Brighton');
        $this->farmington = $this->makeVenue($this->company, 'Farmington');
        $this->admin = $this->makeStaff($this->company, null, 'admin', 'company_admin');
        $this->manager = $this->makeStaff($this->company, $this->brighton, 'manager', 'location_manager');
        $this->attendant = $this->makeStaff($this->company, $this->brighton, 'attendant', 'attendant');

        $this->at('2026-10-20 12:00');
        $this->trunk = $this->makeForm($this->brighton, 'Trunk or Treat 2026', '2026-10-31');
        $this->glow = $this->makeForm($this->brighton, 'Glow Night 2026', '2026-11-01');
        $this->harvest = $this->makeForm($this->brighton, 'Harvest Fest 2026', '2026-10-25');
        $this->later = $this->makeForm($this->brighton, 'Fall Flyer 2026', '2026-11-05');
        $this->farmingtonTrunk = $this->makeForm($this->farmington, 'Trunk or Treat 2026', '2026-10-31');

        $this->register($this->trunk, 'Sam Lee', 'sam.lee@example.com', '7345550142');
        $this->register($this->glow, 'Sam Ortiz', 'sam.ortiz@example.com', '7345550143');
        $this->register($this->harvest, 'Sam Park', 'sam.park@example.com', '7345550144');
        $this->register($this->later, 'Sam Fox', 'sam.fox@example.com', '7345550145');
        $this->register($this->farmingtonTrunk, 'Sam Hill', 'sam.hill@example.com', '7345550146');

        \App\Models\EmailTemplate::create([
            'company_id' => $this->company->id,
            'created_by' => $this->admin->id,
            'name' => 'Event registration photos',
            'subject' => 'Your photos from {{event_name}} at {{location_name}}',
            'body' => '<p>Hi {{first_name}},</p><p>Thanks for joining us for {{event_name}} at {{location_name}}. Your photos are ready to view and download.</p><p><a href="{{photo_link}}">View your photos</a></p><p>This link works until {{expires_on}}.</p><p>{{links}}</p><p>{{business_name}}<br>{{location_phone}}</p>',
            'sms_body' => 'Your photos from {{event_name}} at {{location_name}} are ready: {{photo_link}} (available until {{expires_on}})',
            'attach_photos' => true,
            'status' => 'active',
            'category' => \App\Models\EmailTemplate::CATEGORY_EVENT_PHOTOS,
        ]);

        $this->at('2026-10-31 14:00');
    }

    public function test_search_lists_waivers_and_the_photo_days_registrants_first(): void
    {
        $this->waiver('sam.rivera@example.com', '8105889748', 'Sam');
        $session = $this->staffSession($this->brighton);

        $this->actAs($this->admin);
        $response = $this->getJson('/api/photo-sessions/people-search?q=Sam&photo_session_id=' . $session->id)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.reference_day', '2026-10-31')
            ->assertJsonPath('data.waivers.0.kind', 'waiver')
            ->assertJsonPath('data.waivers.0.name', 'Sam Rivera');

        $registrations = $response->json('data.registrations');
        $this->assertSame(['Sam Lee', 'Sam Ortiz'], array_column($registrations, 'name'));
        $this->assertSame('registration', $registrations[0]['kind']);
        $this->assertTrue($registrations[0]['covers_photo_day']);
        $this->assertFalse($registrations[1]['covers_photo_day']);
        $this->assertSame('Trunk or Treat 2026', $registrations[0]['event_name']);
        $this->assertNull($registrations[0]['photo_video_consent']);
        $this->assertTrue($registrations[0]['can_email']);
        $this->assertTrue($registrations[0]['can_text']);
        $this->assertSame('sa*****@example.com', $registrations[0]['email_masked']);
        $this->assertSame('(***) ***-0142', $registrations[0]['phone_masked']);
        $this->assertArrayNotHasKey('email', $registrations[0]);
    }

    public function test_the_window_follows_the_photos_own_day(): void
    {
        $session = $this->staffSession($this->brighton, '2026-10-25');

        $this->actAs($this->admin);
        $names = array_column($this->getJson('/api/photo-sessions/people-search?q=Sam&photo_session_id=' . $session->id)
            ->assertOk()
            ->assertJsonPath('data.reference_day', '2026-10-25')
            ->json('data.registrations'), 'name');

        $this->assertSame(['Sam Park'], $names);
    }

    public function test_hidden_entries_removed_forms_and_other_venues_are_left_out(): void
    {
        $hidden = $this->register($this->trunk, 'Sam Hidden', 'hidden@example.com', '7345550150');
        $hidden->forceFill(['hidden_at' => now()])->save();

        $gone = $this->makeForm($this->brighton, 'Pop-up Night', '2026-10-31');
        $this->at('2026-10-20 12:00');
        $this->register($gone, 'Sam Gone', 'gone@example.com', '7345550151');
        $this->at('2026-10-31 14:00');
        $gone->delete();

        $other = $this->makeCompany('Elsewhere Fun');
        $otherVenue = $this->makeVenue($other, 'Lansing');
        $otherForm = $this->makeForm($otherVenue, 'Trunk or Treat 2026', '2026-10-31');
        $this->at('2026-10-20 12:00');
        $this->register($otherForm, 'Sam Stranger', 'stranger@example.com', '7345550152');
        $this->at('2026-10-31 14:00');

        $session = $this->staffSession($this->brighton);
        $this->actAs($this->admin);
        $names = array_column($this->getJson('/api/photo-sessions/people-search?q=Sam&photo_session_id=' . $session->id)->assertOk()->json('data.registrations'), 'name');

        $this->assertSame(['Sam Lee', 'Sam Ortiz'], $names);
    }

    public function test_a_multi_day_event_is_found_on_its_earlier_days(): void
    {
        $camp = \App\Models\Event::create([
            'location_id' => $this->brighton->id,
            'name' => 'Spooky Week',
            'date_type' => 'date_range',
            'start_date' => '2026-10-27',
            'end_date' => '2026-11-03',
            'time_start' => '09:00:00',
            'time_end' => '15:00:00',
            'interval_minutes' => 60,
            'price' => 10,
            'is_active' => true,
        ]);
        $week = $this->makeForm($this->brighton, 'Spooky Week', '2026-11-03', ['event_id' => $camp->id]);
        $this->at('2026-10-20 12:00');
        $this->register($week, 'Sam Week', 'week@example.com', '7345550153');
        $this->at('2026-10-31 14:00');

        $session = $this->staffSession($this->brighton);
        $this->actAs($this->admin);
        $rows = collect($this->getJson('/api/photo-sessions/people-search?q=Week&photo_session_id=' . $session->id)->assertOk()->json('data.registrations'));

        $this->assertSame(['Sam Week'], $rows->pluck('name')->all());
        $this->assertTrue($rows->first()['covers_photo_day']);
        $this->assertSame('2026-10-27', $rows->first()['first_day']);
    }

    public function test_one_row_per_person_and_wildcards_are_matched_literally(): void
    {
        $this->at('2026-10-20 12:00');
        $this->register($this->trunk, 'Sam Lee', 'sam.lee@example.com', '7345550142');
        $this->register($this->trunk, 'Kim_Lee', 'kim@example.com', '7345550160');
        $this->at('2026-10-31 14:00');

        $session = $this->staffSession($this->brighton);
        $this->actAs($this->admin);

        $this->assertSame(['Sam Lee'], array_column($this->getJson('/api/photo-sessions/people-search?q=Sam%20Lee&photo_session_id=' . $session->id)->json('data.registrations'), 'name'));
        $this->assertSame(['Sam Lee'], array_column($this->getJson('/api/photo-sessions/people-search?q=Lee%20Sam&photo_session_id=' . $session->id)->json('data.registrations'), 'name'));
        $this->assertSame([], $this->getJson('/api/photo-sessions/people-search?q=%25%25&photo_session_id=' . $session->id)->json('data.registrations'));
        $this->assertSame(['Sam Lee'], array_column($this->getJson('/api/photo-sessions/people-search?q=0142&photo_session_id=' . $session->id)->json('data.registrations'), 'name'));
    }

    public function test_attendants_see_their_own_venue_and_staff_without_a_location_see_none(): void
    {
        $session = $this->staffSession($this->brighton);

        $this->actAs($this->attendant);
        $this->assertSame(['Sam Lee', 'Sam Ortiz'], array_column($this->getJson('/api/photo-sessions/people-search?q=Sam&photo_session_id=' . $session->id)->assertOk()->json('data.registrations'), 'name'));

        $farmingtonSession = $this->staffSession($this->farmington);
        $this->actAs($this->attendant);
        $this->getJson('/api/photo-sessions/people-search?q=Sam&photo_session_id=' . $farmingtonSession->id)->assertForbidden();

        $floating = $this->makeStaff($this->company, null, 'floater', 'attendant');
        $this->actAs($floating);
        $response = $this->getJson('/api/photo-sessions/people-search?q=Sam&photo_session_id=' . $session->id);
        $this->assertTrue(in_array($response->status(), [200, 403], true));
        $this->assertSame([], $response->json('data.registrations') ?? []);
    }

    public function test_an_admin_on_another_venues_terminal_sees_no_registrants_here(): void
    {
        $terminal = StaffTerminal::forceCreate([
            'company_id' => $this->company->id,
            'location_id' => $this->farmington->id,
            'device_id' => 'front-desk-farmington',
            'label' => 'Front desk',
            'token_hash' => hash('sha256', 'terminal-secret'),
            'ceiling_role' => 'location_manager',
        ]);
        $session = $this->staffSession($this->brighton);

        $this->actAs($this->admin, ['pin', 'role:location_manager', 'terminal:' . $terminal->id]);
        $this->assertSame([], $this->getJson('/api/photo-sessions/people-search?q=Sam&photo_session_id=' . $session->id)->assertOk()->json('data.registrations'));

        $this->actAs($this->admin, ['pin', 'role:location_manager', 'terminal:' . $terminal->id]);
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();
        $this->deliver($session, [], [$sam->id])->assertStatus(422);
    }

    public function test_a_registrant_gets_email_and_the_text_is_recorded_as_not_sent(): void
    {
        $session = $this->staffSession($this->brighton);
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();

        $this->actAs($this->manager);
        $this->deliver($session, [], [$sam->id])
            ->assertOk()
            ->assertJsonPath('data.registrations.0.id', $sam->id)
            ->assertJsonPath('data.registrations.0.event_name', 'Trunk or Treat 2026');

        $rows = PhotoDelivery::where('photo_session_id', $session->id)->orderBy('channel')->get();
        $this->assertSame(['email', 'sms'], $rows->pluck('channel')->all());
        $this->assertSame([PhotoDelivery::STATUS_SENT, PhotoDelivery::STATUS_SKIPPED], $rows->pluck('status')->all());
        $this->assertSame([$sam->id, $sam->id], $rows->pluck('registration_entry_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([null, null], $rows->pluck('waiver_id')->all());
        $this->assertStringContainsString('asked for a text', (string) $rows[1]->error);
        $this->assertSame('Sam Lee', $rows[0]->recipient_name);
        $this->assertSame([], $this->texts->sent);
        $this->assertDatabaseHas('photo_session_registrations', ['photo_session_id' => $session->id, 'registration_entry_id' => $sam->id]);

        $variables = app(PhotoDeliveryService::class)->variables($session->fresh('location'), $rows[0]);
        $this->assertSame('there', $variables['first_name']);

        $this->actAs($this->manager);
        $presented = collect($this->getJson("/api/photo-sessions/{$session->id}")->assertOk()->json('data.deliveries'));
        $this->assertSame(['registration', 'registration'], $presented->pluck('recipient_source')->all());
    }

    public function test_staff_can_text_a_registrant_who_asked_for_it(): void
    {
        $session = $this->staffSession($this->brighton);
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();

        $this->actAs($this->manager);
        $this->deliver($session, [], [$sam->id], ['text_registrants' => true])->assertOk();

        $rows = PhotoDelivery::where('photo_session_id', $session->id)->orderBy('id')->get();
        $this->assertSame([PhotoDelivery::STATUS_SENT, PhotoDelivery::STATUS_SENT], $rows->pluck('status')->all());
        $this->assertCount(1, $this->texts->sent);
        $this->assertSame('+17345550142', $this->texts->sent[0]['to']);
    }

    public function test_a_waiver_and_a_registration_sharing_an_email_are_sent_once(): void
    {
        $waiver = $this->waiver('sam.lee@example.com', null, 'Sam');
        $session = $this->staffSession($this->brighton);
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();

        $this->actAs($this->admin);
        $this->deliver($session, [$waiver->id], [$sam->id])->assertOk();

        $email = PhotoDelivery::where('photo_session_id', $session->id)->where('channel', 'email')->orderBy('id')->get();
        $this->assertCount(2, $email);
        $this->assertSame((int) $waiver->id, (int) $email[0]->waiver_id);
        $this->assertSame(PhotoDelivery::STATUS_SENT, $email[0]->status);
        $this->assertSame((int) $email[0]->id, (int) $email[1]->duplicate_of_id);
        $this->assertSame((int) $sam->id, (int) $email[1]->registration_entry_id);
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('Your photos from Trunk or Treat 2026 at Brighton | Zap Zone', $messages->first()->getOriginalMessage()->getSubject());
        $this->assertStringContainsString('Hi Sam,', (string) $messages->first()->getOriginalMessage()->getHtmlBody());
    }

    public function test_unavailable_registrants_are_refused_before_anything_changes(): void
    {
        $session = $this->staffSession($this->brighton);
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();
        $park = RegistrationEntry::where('name', 'Sam Park')->firstOrFail();
        $hill = RegistrationEntry::where('name', 'Sam Hill')->firstOrFail();

        $this->actAs($this->admin);
        $this->deliver($session, [], [$park->id], ['slideshow_opt_in' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'One or more of the selected guests is no longer available. Please search again.');
        $this->assertFalse((bool) $session->photos()->first()->fresh()->slideshow_eligible);
        $this->assertNull($session->fresh()->qr_expires_at);

        $this->actAs($this->admin);
        $this->deliver($session, [], [$hill->id])->assertStatus(422);

        $sam->forceFill(['hidden_at' => now()])->save();
        $this->actAs($this->admin);
        $this->deliver($session, [], [$sam->id])->assertStatus(422);

        $this->actAs($this->admin);
        $this->deliver($session, [], range(1, 51))->assertStatus(422);

        $this->actAs($this->admin);
        $this->deliver($session, [], [])->assertStatus(422)
            ->assertJsonPath('message', 'Select at least one guest (a waiver or an event registration), or use the direct staff QR code instead.');

        $this->assertSame(0, PhotoDelivery::count());
        $this->assertNull($session->fresh()->delivery_method);
    }

    public function test_the_library_sends_to_registrants_of_the_photos_own_day(): void
    {
        $session = $this->staffSession($this->brighton, '2026-10-25');
        $session->forceFill(['delivery_method' => PhotoSession::DELIVERY_STAFF_QR, 'status' => PhotoSession::STATUS_READY, 'access_expires_at' => now()->addDays(20)])->save();
        $session->startQrWindow();
        $photo = $session->photos()->first();
        $park = RegistrationEntry::where('name', 'Sam Park')->firstOrFail();
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();

        $this->actAs($this->manager);
        $this->postJson("/api/photo-library/{$photo->id}/send", ['registration_entry_ids' => [$sam->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'One or more of the selected guests is no longer available. Please search again.');

        $this->actAs($this->manager);
        $this->postJson("/api/photo-library/{$photo->id}/send", ['registration_entry_ids' => [$park->id]])->assertOk();

        $this->assertSame(1, PhotoDelivery::where('registration_entry_id', $park->id)->where('status', PhotoDelivery::STATUS_SENT)->count());

        $this->actAs($this->manager);
        $this->postJson("/api/photo-library/{$photo->id}/send", [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Select at least one guest (a waiver or an event registration).');
    }

    public function test_a_library_send_keeps_a_kiosk_visitors_contact_gate(): void
    {
        $session = $this->staffSession($this->brighton);
        $session->forceFill([
            'source' => PhotoSession::SOURCE_KIOSK,
            'delivery_method' => PhotoSession::DELIVERY_KIOSK_QR,
            'status' => PhotoSession::STATUS_READY,
            'access_expires_at' => now()->addDays(20),
        ])->save();
        $session->startQrWindow();
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();

        $this->actAs($this->manager);
        $this->postJson('/api/photo-library/' . $session->photos()->first()->id . '/send', ['registration_entry_ids' => [$sam->id]])->assertOk();

        $this->assertSame(PhotoSession::DELIVERY_KIOSK_QR, $session->fresh()->delivery_method);
    }

    public function test_a_registration_hidden_before_the_scheduled_send_is_not_sent(): void
    {
        $session = $this->staffSession($this->brighton);
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();

        $this->actAs($this->manager);
        $this->deliver($session, [], [$sam->id], ['schedule' => PhotoSession::SCHEDULE_NEXT_DAY])->assertOk();

        $email = PhotoDelivery::where('photo_session_id', $session->id)->where('channel', 'email')->firstOrFail();
        $this->assertSame(PhotoDelivery::STATUS_SCHEDULED, $email->status);

        $sam->forceFill(['hidden_at' => now()])->save();
        $this->at('2026-11-01 09:05');
        $this->artisan('photos:send-scheduled')->assertSuccessful();

        $this->assertSame(PhotoDelivery::STATUS_CANCELED, $email->fresh()->status);
        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_photo_emails_escape_venue_text_but_not_the_subject(): void
    {
        $this->company->forceFill(['company_name' => 'Zap <b>&</b> Co'])->save();
        $session = $this->staffSession($this->brighton);
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();

        $this->actAs($this->manager);
        $this->deliver($session, [], [$sam->id])->assertOk();

        $message = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $html = (string) $message->getHtmlBody();
        $this->assertStringNotContainsString('Zap <b>&</b> Co', $html);
    }

    public function test_without_the_new_tables_search_and_send_fall_back_safely(): void
    {
        $session = $this->staffSession($this->brighton);
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();
        $tables = new \ReflectionProperty(SchemaSupport::class, 'tables');

        try {
            $tables->setValue(null, ['photo_session_registrations' => false]);

            $this->actAs($this->admin);
            $this->getJson('/api/photo-sessions/people-search?q=Sam&photo_session_id=' . $session->id)
                ->assertOk()
                ->assertJsonPath('data.registrations', []);

            $this->actAs($this->admin);
            $this->deliver($session, [], [$sam->id])
                ->assertStatus(422)
                ->assertJsonPath('message', 'Event registrations are not set up yet.');

            $this->actAs($this->admin);
            $this->getJson("/api/photo-sessions/{$session->id}")->assertOk()->assertJsonPath('data.registrations', []);
        } finally {
            SchemaSupport::flush();
        }
    }

    public function test_registrants_get_the_event_email_with_this_locations_links_and_the_photo_attached(): void
    {
        $instagram = $this->link('Instagram', 'https://instagram.com/zapzone');
        $this->locationUrl($instagram, $this->brighton, 'https://instagram.com/zapzonebrighton');
        $this->link('Facebook', 'https://facebook.com/zapzone');
        $this->link('TikTok', null);
        $template = $this->eventTemplate();
        $template->update(['body' => $template->body . '<p>Insta: {{link_instagram}} TikTok: [{{link_tiktok}}]</p>']);

        $session = $this->staffSession($this->brighton);
        Storage::disk('photos')->put('x/delivery.jpg', \Illuminate\Http\UploadedFile::fake()->image('p.jpg', 40, 30)->getContent());
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();

        $this->actAs($this->manager);
        $this->deliver($session, [], [$sam->id])->assertOk();

        $message = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $html = (string) $message->getHtmlBody();
        $this->assertSame('Your photos from Trunk or Treat 2026 at Brighton | Zap Zone', $message->getSubject());
        $this->assertStringContainsString('Thanks for joining us for Trunk or Treat 2026', $html);
        $this->assertStringContainsString('<a href="https://instagram.com/zapzonebrighton">Instagram</a> &middot; <a href="https://facebook.com/zapzone">Facebook</a>', $html);
        $this->assertStringNotContainsString('TikTok</a>', $html);
        $this->assertStringContainsString('Insta: https://instagram.com/zapzonebrighton TikTok: []', $html);
        $this->assertCount(1, $message->getAttachments());
        $this->assertStringStartsWith('trunk-or-treat-2026-photo-1', $message->getAttachments()[0]->getFilename());
    }

    public function test_the_event_email_can_be_switched_off_and_waivers_keep_the_usual_email(): void
    {
        $waiver = $this->waiver('sam.rivera@example.com', null, 'Sam');
        $session = $this->staffSession($this->brighton);
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();

        $this->actAs($this->manager);
        $this->deliver($session, [$waiver->id], [$sam->id])->assertOk();

        $subjects = Mail::mailer()->getSymfonyTransport()->messages()
            ->map(fn ($sent) => $sent->getOriginalMessage()->getSubject())->all();
        $this->assertSame(['Your photos from Brighton | Zap Zone are ready', 'Your photos from Trunk or Treat 2026 at Brighton | Zap Zone'], $subjects);

        $this->eventTemplate()->update(['status' => 'draft']);
        $second = $this->staffSession($this->brighton);
        $this->actAs($this->manager);
        $this->deliver($second, [], [$sam->id])->assertOk();

        $this->assertSame('Your photos from Brighton | Zap Zone are ready', Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getSubject());
    }

    public function test_a_registrant_who_asked_for_a_text_gets_the_event_wording(): void
    {
        $session = $this->staffSession($this->brighton);
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();

        $this->actAs($this->manager);
        $this->deliver($session, [], [$sam->id], ['text_registrants' => true])->assertOk();

        $this->assertStringStartsWith('Your photos from Trunk or Treat 2026 at Brighton | Zap Zone are ready: ', $this->texts->sent[0]['body']);
    }

    public function test_admins_manage_links_and_managers_set_their_own_locations_address(): void
    {
        $this->actAs($this->admin);
        $created = $this->postJson('/api/photo-message-links', ['label' => 'Google Review', 'default_url' => 'https://g.page/zapzone'])
            ->assertCreated()
            ->assertJsonPath('data.key', 'google_review')
            ->assertJsonPath('data.variable', 'link_google_review')
            ->json('data');

        $this->actAs($this->admin);
        $this->postJson('/api/photo-message-links', ['label' => 'Google review'])->assertStatus(422);
        $this->actAs($this->admin);
        $this->postJson('/api/photo-message-links', ['label' => 'Bad', 'default_url' => 'javascript:alert(1)'])->assertStatus(422);

        $this->actAs($this->manager);
        $this->postJson('/api/photo-message-links', ['label' => 'Yelp'])->assertForbidden();
        $this->actAs($this->manager);
        $this->putJson("/api/photo-message-links/{$created['id']}", ['label' => 'Renamed'])->assertForbidden();

        $this->actAs($this->manager);
        $this->putJson("/api/photo-message-links/{$created['id']}/url", ['location_id' => $this->brighton->id, 'url' => 'https://g.page/zapzone-brighton'])
            ->assertOk()
            ->assertJsonPath('data.urls.0.url', 'https://g.page/zapzone-brighton');
        $this->actAs($this->manager);
        $this->putJson("/api/photo-message-links/{$created['id']}/url", ['location_id' => $this->farmington->id, 'url' => 'https://g.page/x'])->assertForbidden();

        $this->actAs($this->manager);
        $this->getJson('/api/photo-message-links')
            ->assertOk()
            ->assertJsonPath('data.can_manage_links', false)
            ->assertJsonPath('data.locations', [['id' => $this->brighton->id, 'name' => 'Brighton | Zap Zone']]);

        $this->actAs($this->admin);
        $this->getJson('/api/photo-templates')->assertOk()->assertJsonPath('data.link_variables.0.variable', 'link_google_review');

        $this->actAs($this->manager);
        $this->putJson("/api/photo-message-links/{$created['id']}/url", ['location_id' => $this->brighton->id, 'url' => ''])->assertOk()->assertJsonPath('data.urls', []);
        $this->assertSame('https://g.page/zapzone', \App\Models\MessageLink::findOrFail($created['id'])->urlFor($this->brighton));

        $other = $this->makeStaff($this->makeCompany('Elsewhere'), null, 'stranger', 'company_admin');
        $this->actAs($other);
        $this->deleteJson("/api/photo-message-links/{$created['id']}")->assertNotFound();

        $this->actAs($this->admin);
        $this->deleteJson("/api/photo-message-links/{$created['id']}")->assertOk();
        $this->assertSame(0, \App\Models\MessageLink::count());
    }

    public function test_photo_settings_point_to_the_event_photo_email_template(): void
    {
        $old = \App\Models\PhotoMessageTemplate::forCompany($this->company->id, \App\Models\PhotoMessageTemplate::KIND_EVENT_REGISTRATION);

        $this->actAs($this->manager);
        $this->putJson("/api/photo-templates/{$old->id}", [
            'email_subject' => 'Photos from {{event_name}}',
            'email_body' => '<p>Hello</p>',
            'sms_body' => 'Photos: {{photo_link}}',
        ])->assertStatus(422);
        $this->actAs($this->manager);
        $this->postJson("/api/photo-templates/{$old->id}/reset")->assertStatus(422);

        $this->actAs($this->manager);
        $data = $this->getJson('/api/photo-templates?location_id=' . $this->brighton->id)->assertOk()->json('data');
        $this->assertNotContains('registration', $data['kinds']);
        $this->assertNotContains('registration', collect($data['templates'])->pluck('kind')->all());
        $this->assertSame($this->eventTemplate()->id, $data['event_photo_email']['id']);
        $this->assertTrue($data['event_photo_email']['all_locations']);
    }

    public function test_a_test_send_of_the_event_email_uses_sample_event_wording(): void
    {
        $this->link('Instagram', 'https://instagram.com/zapzone');

        $this->actAs($this->manager);
        $this->postJson('/api/photo-settings/test-message', [
            'location_id' => $this->brighton->id,
            'channel' => 'email',
            'destination' => 'tester@example.com',
            'kind' => 'registration',
        ])->assertOk();

        $message = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertStringContainsString('Trunk or Treat', $message->getSubject());
        $this->assertStringContainsString('<a href="https://instagram.com/zapzone">Instagram</a>', (string) $message->getHtmlBody());
    }

    public function test_a_shared_send_keeps_the_usual_email_when_the_registration_is_hidden_first(): void
    {
        $waiver = $this->waiver('sam.lee@example.com', null, 'Sam');
        $session = $this->staffSession($this->brighton);
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();

        $this->actAs($this->admin);
        $this->deliver($session, [$waiver->id], [$sam->id], ['schedule' => PhotoSession::SCHEDULE_NEXT_DAY])->assertOk();
        $sam->forceFill(['hidden_at' => now()])->save();

        $this->at('2026-11-01 09:05');
        $this->artisan('photos:send-scheduled')->assertSuccessful();

        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('Your photos from Brighton | Zap Zone', $messages->first()->getOriginalMessage()->getSubject());
    }

    public function test_scheduled_retried_and_library_sends_to_registrants_use_the_event_email(): void
    {
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();
        $subject = 'Your photos from Trunk or Treat 2026 at Brighton | Zap Zone';

        $scheduled = $this->staffSession($this->brighton);
        $this->actAs($this->manager);
        $this->deliver($scheduled, [], [$sam->id], ['schedule' => PhotoSession::SCHEDULE_NEXT_DAY])->assertOk();
        $this->at('2026-11-01 09:05');
        $this->artisan('photos:send-scheduled')->assertSuccessful();
        $this->assertSame($subject, Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getSubject());

        $this->at('2026-10-31 15:00');
        $failed = PhotoDelivery::where('photo_session_id', $scheduled->id)->where('channel', 'email')->firstOrFail();
        $failed->forceFill(['status' => PhotoDelivery::STATUS_FAILED, 'error' => 'Mailbox busy'])->save();
        $this->actAs($this->manager);
        $this->postJson("/api/photo-deliveries/{$failed->id}/retry")->assertOk();
        $this->assertSame($subject, Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getSubject());

        $library = $this->staffSession($this->brighton);
        $library->forceFill(['delivery_method' => PhotoSession::DELIVERY_STAFF_QR, 'status' => PhotoSession::STATUS_READY, 'access_expires_at' => now()->addDays(20)])->save();
        $this->actAs($this->manager);
        $this->postJson('/api/photo-library/' . $library->photos()->first()->id . '/send', ['registration_entry_ids' => [$sam->id]])->assertOk();
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(3, $messages);
        $this->assertSame($subject, $messages->last()->getOriginalMessage()->getSubject());
        $this->assertStringContainsString($library->access_token, (string) $messages->last()->getOriginalMessage()->getHtmlBody());
        $this->assertSame(PhotoDelivery::STATUS_SENT, PhotoDelivery::where('photo_session_id', $library->id)->where('channel', 'email')->value('status'));
    }

    public function test_attachments_can_be_turned_off_and_waiver_emails_can_use_links_too(): void
    {
        $this->link('Instagram', 'https://instagram.com/zapzone');
        $this->eventTemplate()->update(['attach_photos' => false]);
        $immediate = \App\Models\PhotoMessageTemplate::forCompany($this->company->id, \App\Models\PhotoMessageTemplate::KIND_IMMEDIATE);
        $immediate->update(['email_body' => $immediate->email_body . '<p>{{links}}</p>']);
        Storage::disk('photos')->put('x/delivery.jpg', \Illuminate\Http\UploadedFile::fake()->image('p.jpg', 40, 30)->getContent());
        $waiver = $this->waiver('sam.rivera@example.com', null, 'Sam');
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();

        $this->actAs($this->manager);
        $this->deliver($this->staffSession($this->brighton), [$waiver->id], [$sam->id])->assertOk();

        $messages = Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage())->values();
        $this->assertCount(2, $messages);
        $this->assertCount(0, $messages[1]->getAttachments());
        $this->assertStringContainsString('<a href="https://instagram.com/zapzone">Instagram</a>', (string) $messages[0]->getHtmlBody());
    }

    public function test_removed_or_mistyped_link_placeholders_show_nothing(): void
    {
        $template = $this->eventTemplate();
        $template->update([
            'body' => '<p>Follow us: [{{link_instagram}}] [{{ link_tiktok }}]</p>',
            'sms_body' => 'Photos {{photo_link}} [{{link_gone}}]',
        ]);
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();

        $this->actAs($this->manager);
        $this->deliver($this->staffSession($this->brighton), [], [$sam->id], ['text_registrants' => true])->assertOk();

        $this->assertStringContainsString('Follow us: [] []', (string) Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody());
        $this->assertStringEndsWith('[]', $this->texts->sent[0]['body']);
    }

    public function test_multi_day_dates_span_years_in_plain_text_and_long_link_lists_are_capped_in_texts(): void
    {
        $camp = \App\Models\Event::create([
            'location_id' => $this->brighton->id,
            'name' => 'Winter Camp',
            'date_type' => 'date_range',
            'start_date' => '2026-10-30',
            'end_date' => '2027-01-03',
            'time_start' => '09:00:00',
            'time_end' => '15:00:00',
            'interval_minutes' => 60,
            'price' => 10,
            'is_active' => true,
        ]);
        $form = $this->makeForm($this->brighton, 'Winter Camp', '2027-01-03', ['event_id' => $camp->id]);
        $this->at('2026-10-20 12:00');
        $kid = $this->register($form, 'Camp Kid', 'camp@example.com', '7345550170');
        $this->at('2026-10-31 14:00');

        foreach (range(1, 20) as $n) {
            $this->link("Link number {$n}", 'https://example.com/' . str_repeat('x', 40) . "/{$n}");
        }
        $this->eventTemplate()
            ->update(['subject' => '{{event_name}} {{event_date}}', 'sms_body' => '{{links}}']);

        $this->actAs($this->manager);
        $this->deliver($this->staffSession($this->brighton), [], [$kid->id], ['text_registrants' => true])->assertOk();

        $this->assertSame('Winter Camp Oct 30, 2026 to Jan 3, 2027', Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getSubject());
        $this->assertLessThanOrEqual(PhotoDeliveryService::LINKS_TEXT_LIMIT, mb_strlen($this->texts->sent[0]['body']));
        $this->assertStringStartsWith('Link number 1: https://example.com/', $this->texts->sent[0]['body']);
    }

    public function test_texted_links_skip_one_that_does_not_fit_and_keep_the_rest(): void
    {
        $this->link('Directions', 'https://maps.example.com/' . str_repeat('d', 460));
        $this->link('Instagram', 'https://instagram.com/zapzone');
        $this->link('Facebook', 'https://facebook.com/zapzone');
        $this->eventTemplate()
            ->update(['sms_body' => '{{links}}']);
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();

        $this->actAs($this->manager);
        $this->deliver($this->staffSession($this->brighton), [], [$sam->id], ['text_registrants' => true])->assertOk();

        $this->assertSame("Instagram: https://instagram.com/zapzone\nFacebook: https://facebook.com/zapzone", $this->texts->sent[0]['body']);
    }

    public function test_link_addresses_without_https_are_accepted_and_pin_lowered_admins_cannot_manage_links(): void
    {
        $this->actAs($this->admin);
        $this->postJson('/api/photo-message-links', ['label' => 'Instagram', 'default_url' => 'instagram.com/zapzone'])
            ->assertCreated()
            ->assertJsonPath('data.default_url', 'https://instagram.com/zapzone');
        $id = \App\Models\MessageLink::firstOrFail()->id;

        $this->actAs($this->manager);
        $this->putJson("/api/photo-message-links/{$id}/url", ['location_id' => $this->brighton->id, 'url' => 'www.instagram.com/zapzone.brighton'])
            ->assertOk()
            ->assertJsonPath('data.urls.0.url', 'https://www.instagram.com/zapzone.brighton');
        foreach (['javascript:alert(1)', 'zapzonebrighton', 'https//www.instagram.com/zapzone', 'https://zapzonebrighton', '@zapzonebrighton', 'user@instagram.com/x'] as $notAnAddress) {
            $this->actAs($this->manager);
            $this->putJson("/api/photo-message-links/{$id}/url", ['location_id' => $this->brighton->id, 'url' => $notAnAddress])
                ->assertStatus(422)
                ->assertJsonPath('errors.url.0', 'Enter a full web address, for example https://instagram.com/zapzone.');
        }
        $this->actAs($this->admin);
        $this->postJson('/api/photo-message-links', ['label' => 'Facebook', 'default_url' => 'facebook'])
            ->assertStatus(422)
            ->assertJsonPath('errors.default_url.0', 'Enter a full web address, for example https://instagram.com/zapzone.');
        $this->actAs($this->admin);
        $this->putJson("/api/photo-message-links/{$id}", ['default_url' => 'instagram.com:443/zapzone'])
            ->assertOk()
            ->assertJsonPath('data.default_url', 'https://instagram.com:443/zapzone');
        $this->assertSame('https://www.instagram.com/zapzone.brighton', \App\Models\MessageLinkUrl::where('location_id', $this->brighton->id)->value('url'));

        $terminal = StaffTerminal::forceCreate([
            'company_id' => $this->company->id,
            'location_id' => $this->brighton->id,
            'device_id' => 'front-desk-links',
            'label' => 'Front desk',
            'token_hash' => hash('sha256', 'terminal-secret'),
            'ceiling_role' => 'location_manager',
        ]);
        $this->actAs($this->admin, ['pin', 'role:location_manager', 'terminal:' . $terminal->id]);
        $this->postJson('/api/photo-message-links', ['label' => 'Yelp'])->assertForbidden();
        $this->actAs($this->admin, ['pin', 'role:location_manager', 'terminal:' . $terminal->id]);
        $this->deleteJson("/api/photo-message-links/{$id}")->assertForbidden();
        $this->actAs($this->admin, ['pin', 'role:location_manager', 'terminal:' . $terminal->id]);
        $this->putJson("/api/photo-message-links/{$id}/url", ['location_id' => $this->farmington->id, 'url' => 'https://x.test'])->assertForbidden();
    }

    public function test_without_the_link_tables_messages_still_send_and_the_links_page_says_so(): void
    {
        $tables = new \ReflectionProperty(SchemaSupport::class, 'tables');
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();
        $this->link('Instagram', 'https://instagram.com/zapzone');

        try {
            $tables->setValue(null, ['message_links' => false]);

            $this->actAs($this->admin);
            $this->getJson('/api/photo-message-links')->assertStatus(503);
            $this->actAs($this->admin);
            $this->getJson('/api/photo-templates')->assertOk()->assertJsonPath('data.link_variables', []);
            $this->actAs($this->manager);
            $this->deliver($this->staffSession($this->brighton), [], [$sam->id])->assertOk();
            $messages = Mail::mailer()->getSymfonyTransport()->messages();
            $this->assertCount(1, $messages);
            $html = (string) $messages->first()->getOriginalMessage()->getHtmlBody();
            $this->assertStringContainsString('Trunk or Treat 2026', $html);
            $this->assertStringNotContainsString('instagram.com', $html);
            $this->assertStringNotContainsString('{{', $html);
        } finally {
            SchemaSupport::flush();
        }
    }

    public function test_the_registration_form_page_knows_when_the_event_email_is_off(): void
    {
        $this->actAs($this->admin);
        $this->getJson("/api/registration-events/{$this->trunk->id}")->assertOk()->assertJsonPath('data.photo_email_active', true);

        $this->eventTemplate()->update(['status' => 'draft']);
        $this->actAs($this->admin);
        $this->getJson("/api/registration-events/{$this->trunk->id}")->assertOk()->assertJsonPath('data.photo_email_active', false)->assertJsonPath('data.photo_email', null);
    }

    public function test_the_template_naming_the_photos_location_wins_and_other_locations_fall_back(): void
    {
        $this->eventTemplate()->update(['subject' => 'Everywhere {{event_name}}']);
        \App\Models\EmailTemplate::create([
            'company_id' => $this->company->id,
            'location_id' => $this->brighton->id,
            'location_ids' => [$this->brighton->id],
            'created_by' => $this->admin->id,
            'name' => 'Brighton photos',
            'subject' => 'Brighton only {{event_name}}',
            'body' => '<p>{{ recipient_first_name }} {{ company_name }} {{customer_name}} [{{ not_a_tag }}] {{photo_link}}</p>',
            'status' => 'active',
            'category' => \App\Models\EmailTemplate::CATEGORY_EVENT_PHOTOS,
        ]);
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();
        $hill = RegistrationEntry::where('name', 'Sam Hill')->firstOrFail();

        $this->actAs($this->manager);
        $this->deliver($this->staffSession($this->brighton), [], [$sam->id])->assertOk();
        $this->actAs($this->admin);
        $this->deliver($this->staffSession($this->farmington), [], [$hill->id])->assertOk();

        $messages = Mail::mailer()->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage())->values();
        $this->assertSame('Brighton only Trunk or Treat 2026', $messages[0]->getSubject());
        $this->assertStringContainsString('there ' . $this->company->company_name . '  []', (string) $messages[0]->getHtmlBody());
        $this->assertStringNotContainsString('{{', (string) $messages[0]->getHtmlBody());
        $this->assertSame('Everywhere Trunk or Treat 2026', $messages[1]->getSubject());

        $this->eventTemplate()->update(['status' => 'draft']);
        $this->actAs($this->admin);
        $this->deliver($this->staffSession($this->farmington), [], [$hill->id])->assertOk();
        $this->assertStringNotContainsString('Trunk or Treat', Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getSubject());

        $this->actAs($this->admin);
        $this->postJson('/api/photo-settings/test-message', [
            'location_id' => $this->farmington->id,
            'channel' => 'email',
            'destination' => 'tester@example.com',
            'kind' => 'registration',
        ])->assertStatus(422);
    }

    public function test_an_events_campaign_sends_each_registrant_their_own_photos(): void
    {
        $this->link('Instagram', 'https://instagram.com/zapzone');
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();
        $session = $this->staffSession($this->brighton);
        $this->actAs($this->manager);
        $this->deliver($session, [], [$sam->id])->assertOk();
        $session->forceFill(['status' => PhotoSession::STATUS_READY, 'access_expires_at' => now()->addDays(20)])->save();
        $before = Mail::mailer()->getSymfonyTransport()->messages()->count();

        $this->actAs($this->admin);
        $preview = $this->postJson('/api/email-campaigns/preview-recipients', [
            'recipient_types' => ['event_registrants'],
            'recipient_filters' => ['registration_event_ids' => [$this->trunk->id, $this->farmingtonTrunk->id]],
        ])->assertOk()->json('data');
        $this->assertSame(2, $preview['total_recipients']);
        $this->assertSame(1, $preview['with_photos']);
        $this->assertArrayNotHasKey('variables', $preview['sample_recipients'][0]);

        $this->actAs($this->admin);
        $id = $this->postJson('/api/email-campaigns', [
            'name' => 'Your photos',
            'subject' => 'Photos from {{event_name}} {{links}}',
            'body' => '<p>Hi {{first_name}}, <a href="{{photo_link}}">your photos</a></p><p>{{links}}</p>',
            'recipient_types' => ['event_registrants'],
            'recipient_filters' => ['registration_event_ids' => [$this->trunk->id, $this->farmingtonTrunk->id]],
            'send_now' => true,
        ])->assertCreated()->json('data.id');

        $sent = Mail::mailer()->getSymfonyTransport()->messages()->slice($before)->map(fn ($message) => $message->getOriginalMessage())->keyBy(fn ($message) => $message->getTo()[0]->getAddress());
        $this->assertSame(['sam.hill@example.com', 'sam.lee@example.com'], $sent->keys()->sort()->values()->all());
        $lee = (string) $sent['sam.lee@example.com']->getHtmlBody();
        $this->assertStringContainsString('/photos/' . $session->access_token, $lee);
        $this->assertStringContainsString('Hi there,', $lee);
        $this->assertStringContainsString('<a href="https://instagram.com/zapzone">Instagram</a>', $lee);
        $this->assertSame('Photos from Trunk or Treat 2026 ', $sent['sam.lee@example.com']->getSubject());
        $this->assertStringNotContainsString('/photos/', (string) $sent['sam.hill@example.com']->getHtmlBody());

        $this->actAs($this->admin);
        $logs = $this->getJson("/api/email-campaigns/{$id}")->assertOk()->json('data.logs');
        $this->assertCount(2, $logs);
        $this->assertArrayNotHasKey('variables_used', $logs[0]);
    }

    public function test_events_campaigns_respect_locations_hidden_entries_and_opt_outs(): void
    {
        $payload = fn (array $ids, array $extra = []) => [
            'recipient_types' => ['event_registrants'],
            'recipient_filters' => ['registration_event_ids' => $ids] + $extra,
        ];

        $this->actAs($this->manager);
        $this->postJson('/api/email-campaigns/preview-recipients', $payload([$this->farmingtonTrunk->id]))->assertForbidden();
        $this->actAs($this->attendant);
        $this->postJson('/api/email-campaigns/preview-recipients', $payload([$this->trunk->id]))->assertForbidden();
        $this->actAs($this->admin);
        $this->postJson('/api/email-campaigns/preview-recipients', $payload([]))->assertStatus(422);
        $other = $this->makeForm($this->makeVenue($this->makeCompany('Other Fun'), 'Lansing'), 'Elsewhere', '2026-10-31');
        $this->actAs($this->admin);
        $this->postJson('/api/email-campaigns/preview-recipients', $payload([$other->id]))->assertStatus(422);

        $this->register($this->trunk, 'Riley Lee', 'riley@example.com', '7345550150');
        $this->register($this->trunk, 'Rowan Lee', 'ROWAN@example.com', '7345550151');
        $this->register($this->trunk, 'Rowan Again', 'rowan@example.com', '7345550152');
        RegistrationEntry::where('email', 'riley@example.com')->update(['hidden_at' => now()]);
        \App\Models\FollowUpOptOut::create(['company_id' => $this->company->id, 'email' => 'sam.lee@example.com']);

        $this->actAs($this->manager);
        $preview = $this->postJson('/api/email-campaigns/preview-recipients', $payload([$this->trunk->id]))->assertOk()->json('data');
        $this->assertSame(['rowan@example.com'], collect($preview['sample_recipients'])->pluck('email')->all());

        $this->actAs($this->manager);
        $this->postJson('/api/email-campaigns/preview-recipients', $payload([$this->trunk->id], ['only_with_photos' => true]))
            ->assertOk()
            ->assertJsonPath('data.total_recipients', 0);
    }

    public function test_a_large_events_campaign_finishes_after_the_reply_and_resends_skip_hidden_registrants(): void
    {
        foreach (range(1, 21) as $n) {
            $this->register($this->trunk, "Guest {$n}", "guest{$n}@example.com", '73455502' . str_pad((string) $n, 2, '0', STR_PAD_LEFT));
        }
        $before = Mail::mailer()->getSymfonyTransport()->messages()->count();

        $this->actAs($this->admin);
        $response = $this->postJson('/api/email-campaigns', [
            'name' => 'Thanks for coming',
            'subject' => 'Thanks from {{event_name}}',
            'body' => '<p>Hi {{first_name}}</p>',
            'recipient_types' => ['event_registrants'],
            'recipient_filters' => ['registration_event_ids' => [$this->trunk->id]],
            'send_now' => true,
        ])->assertCreated();
        $this->assertSame('Sending to 22 people now. Refresh the campaign to see them go out.', $response->json('message'));
        $this->assertSame(22, Mail::mailer()->getSymfonyTransport()->messages()->count() - $before);

        $campaign = \App\Models\EmailCampaign::findOrFail($response->json('data.id'));
        $this->assertSame(\App\Models\EmailCampaign::STATUS_COMPLETED, $campaign->status);
        $log = $campaign->logs()->where('recipient_email', 'guest1@example.com')->firstOrFail();
        $log->forceFill(['status' => 'failed'])->save();
        RegistrationEntry::where('email', 'guest1@example.com')->update(['hidden_at' => now()]);

        $this->actAs($this->admin);
        $this->postJson("/api/email-campaigns/{$campaign->id}/resend", ['type' => 'failed'])->assertOk();
        $this->assertSame('failed', $log->fresh()->status);
        $this->assertStringContainsString('hidden or removed', (string) $log->fresh()->error_message);
        $this->assertSame(22, Mail::mailer()->getSymfonyTransport()->messages()->count() - $before);
    }

    public function test_a_cancelled_photo_send_never_hands_out_that_photo_link(): void
    {
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();
        $session = $this->staffSession($this->brighton);
        $this->actAs($this->manager);
        $this->deliver($session, [], [$sam->id], ['schedule' => 'next_day_9am'])->assertOk();
        $session->forceFill(['status' => PhotoSession::STATUS_READY, 'access_expires_at' => now()->addDays(20)])->save();

        $this->assertSame(1, $this->registrantPreview([$this->trunk->id], true)['with_photos']);

        $delivery = PhotoDelivery::where('photo_session_id', $session->id)->where('registration_entry_id', $sam->id)->where('channel', PhotoDelivery::CHANNEL_EMAIL)->firstOrFail();
        $this->actAs($this->manager);
        $this->postJson("/api/photo-deliveries/{$delivery->id}/cancel")->assertOk();

        $this->assertSame(0, $this->registrantPreview([$this->trunk->id], true)['total_recipients']);
        $preview = $this->registrantPreview([$this->trunk->id], false);
        $this->assertSame(1, $preview['total_recipients']);
        $this->assertSame(0, $preview['with_photos']);
    }

    public function test_a_cancelled_shared_send_hides_the_registrants_link_too(): void
    {
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();
        $waiver = $this->waiver('sam.lee@example.com', null, 'Sam');
        $session = $this->staffSession($this->brighton);
        $this->actAs($this->manager);
        $this->deliver($session, [$waiver->id], [$sam->id], ['schedule' => 'next_day_9am'])->assertOk();
        $session->forceFill(['status' => PhotoSession::STATUS_READY, 'access_expires_at' => now()->addDays(20)])->save();

        $emails = PhotoDelivery::where('photo_session_id', $session->id)->where('channel', PhotoDelivery::CHANNEL_EMAIL)->get();
        $primary = $emails->firstWhere('duplicate_of_id', null);
        $this->assertNotNull($emails->firstWhere('registration_entry_id', $sam->id)?->duplicate_of_id);
        $this->assertSame(1, $this->registrantPreview([$this->trunk->id], true)['with_photos']);

        $this->actAs($this->manager);
        $this->postJson("/api/photo-deliveries/{$primary->id}/cancel")->assertOk();

        $this->assertSame(0, $this->registrantPreview([$this->trunk->id], true)['total_recipients']);
    }

    public function test_cancelling_a_campaign_that_is_sending_in_the_background_stops_it(): void
    {
        foreach (range(1, 24) as $n) {
            $this->register($this->trunk, "Guest {$n}", "guest{$n}@example.com", '73455503' . str_pad((string) $n, 2, '0', STR_PAD_LEFT));
        }
        $sentSoFar = 0;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSent::class, function () use (&$sentSoFar) {
            if (++$sentSoFar === 5) {
                \App\Models\EmailCampaign::query()->latest('id')->firstOrFail()->update(['status' => \App\Models\EmailCampaign::STATUS_CANCELLED]);
            }
        });

        $this->actAs($this->admin);
        $id = $this->postJson('/api/email-campaigns', [
            'name' => 'Thanks for coming',
            'subject' => 'Thanks from {{event_name}}',
            'body' => '<p>Hi {{first_name}}</p>',
            'recipient_types' => ['event_registrants'],
            'recipient_filters' => ['registration_event_ids' => [$this->trunk->id]],
            'send_now' => true,
        ])->assertCreated()->json('data.id');

        $campaign = \App\Models\EmailCampaign::findOrFail($id);
        $this->assertSame(5, $sentSoFar);
        $this->assertSame(\App\Models\EmailCampaign::STATUS_CANCELLED, $campaign->status);
        $this->assertSame(5, $campaign->sent_count);
        $this->assertSame(20, $campaign->logs()->where('status', 'pending')->count());
    }

    public function test_a_cancel_that_lands_during_the_last_email_is_kept(): void
    {
        foreach (range(1, 21) as $n) {
            $this->register($this->trunk, "Guest {$n}", "guest{$n}@example.com", '73455504' . str_pad((string) $n, 2, '0', STR_PAD_LEFT));
        }
        $sentSoFar = 0;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSent::class, function () use (&$sentSoFar) {
            if (++$sentSoFar === 22) {
                \App\Models\EmailCampaign::query()->latest('id')->firstOrFail()->update(['status' => \App\Models\EmailCampaign::STATUS_CANCELLED]);
            }
        });

        $this->actAs($this->admin);
        $id = $this->postJson('/api/email-campaigns', [
            'name' => 'Thanks for coming',
            'subject' => 'Thanks',
            'body' => '<p>Hi</p>',
            'recipient_types' => ['event_registrants'],
            'recipient_filters' => ['registration_event_ids' => [$this->trunk->id]],
            'send_now' => true,
        ])->assertCreated()->json('data.id');

        $this->assertSame(22, $sentSoFar);
        $this->assertSame(\App\Models\EmailCampaign::STATUS_CANCELLED, \App\Models\EmailCampaign::findOrFail($id)->status);
    }

    public function test_a_send_that_stopped_part_way_can_be_resent_to_the_rest(): void
    {
        $this->actAs($this->admin);
        $id = $this->postJson('/api/email-campaigns', [
            'name' => 'Later',
            'subject' => 'Hi from {{event_name}}',
            'body' => '<p>Hi {{first_name}}</p>',
            'recipient_types' => ['event_registrants'],
            'recipient_filters' => ['registration_event_ids' => [$this->trunk->id, $this->farmingtonTrunk->id]],
            'send_now' => false,
        ])->assertCreated()->json('data.id');
        DB::table('email_campaigns')->where('id', $id)->update(['status' => 'sending', 'updated_at' => now()->subMinutes(2)]);

        $this->actAs($this->admin);
        $this->postJson("/api/email-campaigns/{$id}/resend", ['type' => 'failed'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This campaign is still sending. Try again once it finishes.');

        DB::table('email_campaigns')->where('id', $id)->update(['updated_at' => now()->subMinutes(20)]);
        $this->actAs($this->admin);
        $shown = $this->getJson("/api/email-campaigns/{$id}")->assertOk()->json('data');
        $this->assertSame('failed', $shown['status']);
        $this->assertSame(2, $shown['failed_count']);
        $this->assertStringContainsString('Resend to Failed Recipients', $shown['error_message']);

        $before = Mail::mailer()->getSymfonyTransport()->messages()->count();
        $this->actAs($this->admin);
        $this->postJson("/api/email-campaigns/{$id}/resend", ['type' => 'failed'])->assertOk();
        $this->assertSame(2, Mail::mailer()->getSymfonyTransport()->messages()->count() - $before);
        $campaign = \App\Models\EmailCampaign::findOrFail($id);
        $this->assertSame('completed', $campaign->status);
        $this->assertSame(2, $campaign->sent_count);
        $this->assertSame(0, $campaign->failed_count);
    }

    public function test_resends_skip_people_who_unsubscribed(): void
    {
        $this->actAs($this->admin);
        $id = $this->postJson('/api/email-campaigns', [
            'name' => 'Hello',
            'subject' => 'Hello',
            'body' => '<p>Hi</p>',
            'recipient_types' => ['event_registrants'],
            'recipient_filters' => ['registration_event_ids' => [$this->trunk->id]],
            'send_now' => true,
        ])->assertCreated()->json('data.id');
        $log = \App\Models\EmailCampaignLog::where('email_campaign_id', $id)->firstOrFail();
        $log->forceFill(['status' => 'failed'])->save();
        \App\Models\FollowUpOptOut::create(['company_id' => $this->company->id, 'email' => 'sam.lee@example.com']);
        $before = Mail::mailer()->getSymfonyTransport()->messages()->count();

        $this->actAs($this->admin);
        $this->postJson("/api/email-campaigns/{$id}/resend", ['type' => 'failed'])->assertOk();
        $this->assertSame(0, Mail::mailer()->getSymfonyTransport()->messages()->count() - $before);
        $this->assertSame('failed', $log->fresh()->status);
        $this->assertStringContainsString('unsubscribed', (string) $log->fresh()->error_message);
    }

    public function test_events_campaign_emails_carry_a_working_unsubscribe_link(): void
    {
        $before = Mail::mailer()->getSymfonyTransport()->messages()->count();
        $send = function (array $types, string $body, array $extra = []) {
            $this->actAs($this->admin);

            return $this->postJson('/api/email-campaigns', [
                'name' => 'Notes',
                'subject' => 'Notes',
                'body' => $body,
                'recipient_types' => $types,
                'recipient_filters' => ['registration_event_ids' => [$this->trunk->id]],
                'send_now' => true,
            ] + $extra)->assertCreated();
        };

        $send(['event_registrants'], '<p>See you soon</p>');
        $mail = Mail::mailer()->getSymfonyTransport()->messages()->slice($before)->first()->getOriginalMessage();
        $html = (string) $mail->getHtmlBody();
        $this->assertSame(1, substr_count($html, 'Unsubscribe'));
        $this->assertMatchesRegularExpression('#href="https://book\.zapzone\.test/feedback/([^"?]+)\?unsubscribe=1"#', $html);
        preg_match('#/feedback/([^"?]+)\?unsubscribe=1#', $html, $match);
        $this->assertSame(['company_id' => $this->company->id, 'email' => 'sam.lee@example.com', 'notification_id' => null], app(\App\Services\VisitFollowUpService::class)->parseOptOutToken(html_entity_decode($match[1])));

        $this->actAs(null);
        $this->postJson('/api/visit-feedback/' . html_entity_decode($match[1]) . '/unsubscribe')->assertOk();
        $this->assertSame(0, $this->registrantPreview([$this->trunk->id], false)['total_recipients']);

        $this->register($this->trunk, 'Kai Lee', 'kai@example.com', '7345550188');
        $count = Mail::mailer()->getSymfonyTransport()->messages()->count();
        $send(['event_registrants'], '<p>Bye</p><p><a href="{{opt_out_link}}">Stop these</a></p>');
        $own = (string) Mail::mailer()->getSymfonyTransport()->messages()->slice($count)->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('Stop these', $own);
        $this->assertStringNotContainsString('Unsubscribe', $own);

        $count = Mail::mailer()->getSymfonyTransport()->messages()->count();
        $send(['custom'], '<p>Staff note {{opt_out_link}}</p>', ['custom_emails' => ['someone@example.com']]);
        $staff = (string) Mail::mailer()->getSymfonyTransport()->messages()->slice($count)->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringNotContainsString('Unsubscribe', $staff);
        $this->assertStringNotContainsString('{{', $staff);
    }

    public function test_test_campaign_emails_render_like_real_ones_without_gmail(): void
    {
        $instagram = $this->link('Instagram', 'https://instagram.com/zapzone');
        $before = Mail::mailer()->getSymfonyTransport()->messages()->count();

        $this->actAs($this->admin);
        $this->postJson('/api/email-campaigns/send-test', [
            'subject' => 'Hello {{links}}{{link_instagram}}',
            'body' => '<p>{{links}}</p><p>[{{link_gone}}]</p>',
            'test_email' => 'tester@example.com',
        ])->assertOk();

        $mail = Mail::mailer()->getSymfonyTransport()->messages()->slice($before)->first()->getOriginalMessage();
        $this->assertSame('Hello https://instagram.com/zapzone', $mail->getSubject());
        $html = (string) $mail->getHtmlBody();
        $this->assertStringContainsString('<a href="https://instagram.com/zapzone">Instagram</a>', $html);
        $this->assertStringContainsString('<p>[]</p>', $html);
        $this->assertNotNull($instagram);
    }

    public function test_attendants_do_not_see_events_campaigns_in_the_statistics(): void
    {
        $this->actAs($this->admin);
        $this->postJson('/api/email-campaigns', [
            'name' => 'Registrant thanks',
            'subject' => 'Thanks',
            'body' => '<p>Thanks</p>',
            'recipient_types' => ['event_registrants'],
            'recipient_filters' => ['registration_event_ids' => [$this->trunk->id]],
            'send_now' => false,
        ])->assertCreated();

        $this->actAs($this->manager);
        $this->assertSame(['Registrant thanks'], collect($this->getJson('/api/email-campaigns/statistics')->assertOk()->json('data.recent_campaigns'))->pluck('name')->all());

        $this->actAs($this->attendant);
        $this->assertSame([], $this->getJson('/api/email-campaigns/statistics')->assertOk()->json('data.recent_campaigns'));
    }

    public function test_malformed_campaign_filters_get_a_clear_error(): void
    {
        $this->actAs($this->admin);
        $this->postJson('/api/email-campaigns/preview-recipients', ['recipient_types' => ['event_registrants'], 'recipient_filters' => ''])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pick at least one event.');

        $this->actAs($this->admin);
        $this->postJson('/api/email-campaigns/preview-recipients', ['recipient_types' => ['event_registrants'], 'recipient_filters' => ['registration_event_ids' => '']])
            ->assertStatus(422);

        $this->actAs($this->admin);
        $this->postJson('/api/email-campaigns/preview-recipients', ['recipient_types' => ['custom'], 'custom_emails' => ['a@example.com'], 'recipient_filters' => ''])
            ->assertOk();
    }

    public function test_the_photo_email_pointers_say_who_can_change_the_template(): void
    {
        $this->actAs($this->admin);
        $this->assertTrue($this->getJson('/api/photo-templates?location_id=' . $this->brighton->id)->assertOk()->json('data.event_photo_email.can_manage'));
        $this->actAs($this->manager);
        $this->assertFalse($this->getJson('/api/photo-templates?location_id=' . $this->brighton->id)->assertOk()->json('data.event_photo_email.can_manage'));

        \App\Models\EmailTemplate::create([
            'company_id' => $this->company->id,
            'created_by' => $this->admin->id,
            'location_id' => $this->brighton->id,
            'location_ids' => [$this->brighton->id],
            'name' => 'Brighton photos',
            'subject' => 'Brighton {{event_name}}',
            'body' => '<p>{{photo_link}}</p>',
            'status' => 'active',
            'category' => \App\Models\EmailTemplate::CATEGORY_EVENT_PHOTOS,
        ]);

        $this->actAs($this->manager);
        $pointer = $this->getJson('/api/photo-templates?location_id=' . $this->brighton->id)->assertOk()->json('data.event_photo_email');
        $this->assertSame('Brighton photos', $pointer['name']);
        $this->assertTrue($pointer['can_manage']);

        $this->actAs($this->manager);
        $photoEmail = $this->getJson("/api/registration-events/{$this->trunk->id}")->assertOk()->json('data.photo_email');
        $this->assertSame(['name' => 'Brighton photos', 'can_manage' => true, 'all_locations' => false, 'location_count' => 1], array_intersect_key($photoEmail, array_flip(['name', 'can_manage', 'all_locations', 'location_count'])));
    }

    public function test_resending_after_a_cancel_only_sends_the_failed_people(): void
    {
        foreach (range(1, 24) as $n) {
            $this->register($this->trunk, "Guest {$n}", "guest{$n}@example.com", '73455505' . str_pad((string) $n, 2, '0', STR_PAD_LEFT));
        }
        $sentSoFar = 0;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSent::class, function () use (&$sentSoFar) {
            if (++$sentSoFar === 5) {
                \App\Models\EmailCampaign::query()->latest('id')->firstOrFail()->update(['status' => \App\Models\EmailCampaign::STATUS_CANCELLED]);
            }
        });

        $this->actAs($this->admin);
        $id = $this->postJson('/api/email-campaigns', [
            'name' => 'Thanks for coming',
            'subject' => 'Thanks',
            'body' => '<p>Hi</p>',
            'recipient_types' => ['event_registrants'],
            'recipient_filters' => ['registration_event_ids' => [$this->trunk->id]],
            'send_now' => true,
        ])->assertCreated()->json('data.id');
        $campaign = \App\Models\EmailCampaign::findOrFail($id);
        $campaign->logs()->where('status', 'sent')->orderBy('id')->firstOrFail()->forceFill(['status' => 'failed'])->save();
        $before = $sentSoFar;

        $this->actAs($this->admin);
        $this->postJson("/api/email-campaigns/{$id}/resend", ['type' => 'failed'])->assertOk();
        $this->assertSame(1, $sentSoFar - $before);
        $this->assertSame(20, $campaign->logs()->where('status', 'pending')->count());

        $this->actAs($this->admin);
        $this->postJson("/api/email-campaigns/{$id}/resend", ['type' => 'everything'])->assertStatus(422);
    }

    public function test_each_registrant_email_rechecks_their_photos_when_it_goes_out(): void
    {
        $sam = RegistrationEntry::where('name', 'Sam Lee')->firstOrFail();
        $session = $this->staffSession($this->brighton);
        $this->actAs($this->manager);
        $this->deliver($session, [], [$sam->id], ['schedule' => 'next_day_9am'])->assertOk();
        $session->forceFill(['status' => PhotoSession::STATUS_READY, 'access_expires_at' => now()->addDays(20)])->save();
        $send = function (array $filters) {
            $this->actAs($this->admin);

            return $this->postJson('/api/email-campaigns', [
                'name' => 'Your photos',
                'subject' => 'Your photos',
                'body' => '<p>Hi {{customer_name}}<a href="{{photo_link}}">photos</a></p>',
                'recipient_types' => ['event_registrants'],
                'recipient_filters' => ['registration_event_ids' => [$this->trunk->id]] + $filters,
                'send_now' => false,
            ])->assertCreated()->json('data.id');
        };
        $open = $send([]);
        $photosOnly = $send(['only_with_photos' => true]);
        $this->assertStringContainsString($session->access_token, (string) json_encode(\App\Models\EmailCampaignLog::where('email_campaign_id', $open)->firstOrFail()->variables_used));

        $delivery = PhotoDelivery::where('photo_session_id', $session->id)->where('registration_entry_id', $sam->id)->where('channel', PhotoDelivery::CHANNEL_EMAIL)->firstOrFail();
        $this->actAs($this->manager);
        $this->postJson("/api/photo-deliveries/{$delivery->id}/cancel")->assertOk();
        \App\Models\EmailCampaignLog::whereIn('email_campaign_id', [$open, $photosOnly])->update(['status' => 'failed']);
        $before = Mail::mailer()->getSymfonyTransport()->messages()->count();

        $this->actAs($this->admin);
        $this->postJson("/api/email-campaigns/{$open}/resend", ['type' => 'failed'])->assertOk();
        $sent = Mail::mailer()->getSymfonyTransport()->messages()->slice($before);
        $this->assertCount(1, $sent);
        $html = (string) $sent->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringNotContainsString($session->access_token, $html);
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringContainsString('<p>Hi <a href="">photos</a></p>', $html);

        $this->actAs($this->admin);
        $this->postJson("/api/email-campaigns/{$photosOnly}/resend", ['type' => 'failed'])->assertOk();
        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages()->slice($before));
        $log = \App\Models\EmailCampaignLog::where('email_campaign_id', $photosOnly)->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertSame('Not sent: their photos are no longer available.', $log->error_message);
    }

    public function test_managers_only_see_event_campaigns_for_their_own_location(): void
    {
        $make = function (array $formIds, string $name) {
            $this->actAs($this->admin);
            $this->postJson('/api/email-campaigns', [
                'name' => $name,
                'subject' => 'Hi',
                'body' => '<p>Hi</p>',
                'recipient_types' => ['event_registrants'],
                'recipient_filters' => ['registration_event_ids' => $formIds],
                'send_now' => false,
            ])->assertCreated();
        };
        $make([$this->trunk->id], 'Brighton only');
        $make([$this->farmingtonTrunk->id], 'Farmington only');
        $make([$this->trunk->id, $this->farmingtonTrunk->id], 'Both');

        $this->actAs($this->manager);
        $this->assertSame(['Brighton only'], collect($this->getJson('/api/email-campaigns')->assertOk()->json('data.data'))->pluck('name')->all());
        $this->actAs($this->manager);
        $this->assertSame(['Brighton only'], collect($this->getJson('/api/email-campaigns/statistics')->assertOk()->json('data.recent_campaigns'))->pluck('name')->all());

        $this->actAs($this->admin);
        $this->assertCount(3, $this->getJson('/api/email-campaigns')->assertOk()->json('data.data'));
    }

    public function test_an_event_campaign_that_would_reach_nobody_is_refused(): void
    {
        $this->actAs($this->admin);
        $this->postJson('/api/email-campaigns', [
            'name' => 'Photos',
            'subject' => 'Photos',
            'body' => '<p>{{photo_link}}</p>',
            'recipient_types' => ['event_registrants'],
            'recipient_filters' => ['registration_event_ids' => [$this->trunk->id], 'only_with_photos' => true],
            'send_now' => true,
        ])->assertStatus(422)->assertJsonPath('message', 'Nobody would get this email. No one from the picked events can get it right now: their photos are not ready, they gave no email address, or they unsubscribed.');
        $this->assertSame(0, \App\Models\EmailCampaign::count());
    }

    private function registrantPreview(array $formIds, bool $onlyWithPhotos): array
    {
        $this->actAs($this->admin);

        return $this->postJson('/api/email-campaigns/preview-recipients', [
            'recipient_types' => ['event_registrants'],
            'recipient_filters' => ['registration_event_ids' => $formIds, 'only_with_photos' => $onlyWithPhotos],
        ])->assertOk()->json('data');
    }

    private function eventTemplate(): \App\Models\EmailTemplate
    {
        return \App\Models\EmailTemplate::where('company_id', $this->company->id)->where('category', \App\Models\EmailTemplate::CATEGORY_EVENT_PHOTOS)->firstOrFail();
    }

    private function link(string $label, ?string $defaultUrl): \App\Models\MessageLink
    {
        return \App\Models\MessageLink::create([
            'company_id' => $this->company->id,
            'key' => \App\Models\MessageLink::keyFor($label),
            'label' => $label,
            'default_url' => $defaultUrl,
            'sort_order' => \App\Models\MessageLink::count(),
        ]);
    }

    private function locationUrl(\App\Models\MessageLink $link, Location $location, string $url): void
    {
        \App\Models\MessageLinkUrl::create(['message_link_id' => $link->id, 'location_id' => $location->id, 'url' => $url]);
    }

    private function register(RegistrationEvent $form, string $name, string $email, string $phone): RegistrationEntry
    {
        $this->actAs(null);
        $this->signUp($form, $name, $email, $phone)->assertCreated();

        return RegistrationEntry::query()->latest('id')->firstOrFail();
    }

    private function deliver(PhotoSession $session, array $waiverIds, array $entryIds, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/photo-sessions/{$session->id}/deliver", array_merge([
            'method' => 'waiver_message',
            'schedule' => 'immediate',
            'waiver_ids' => $waiverIds,
            'registration_entry_ids' => $entryIds,
        ], $extra));
    }

    private function staffSession(Location $location, ?string $day = null): PhotoSession
    {
        $operatingDay = $day ?? OperatingDay::forLocation($location, now());

        $session = PhotoSession::create([
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'source' => PhotoSession::SOURCE_STAFF,
            'status' => PhotoSession::STATUS_IN_PROGRESS,
            'created_by' => $this->admin->id,
            'verbal_consent_at' => now(),
            'capture_date' => $operatingDay,
            'operating_day' => $operatingDay,
        ]);

        Photo::create([
            'photo_session_id' => $session->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'source' => Photo::SOURCE_CAMERA,
            'processing_status' => Photo::PROCESSING_READY,
            'original_path' => 'x/original.jpg',
            'delivery_path' => 'x/delivery.jpg',
            'slideshow_path' => 'x/slideshow.jpg',
            'thumbnail_path' => 'x/thumb.jpg',
            'captured_at' => now(),
            'capture_date' => $operatingDay,
            'operating_day' => $operatingDay,
        ]);

        return $session;
    }

    private function waiver(string $email, ?string $phone, string $first): Waiver
    {
        $template = WaiverTemplate::create([
            'company_id' => $this->company->id,
            'title' => 'General waiver ' . uniqid(),
            'status' => WaiverTemplate::STATUS_ACTIVE,
            'is_default' => true,
            'body_text' => '<p>I release {{company_name}}.</p>',
            'electronic_consent_enabled' => true,
        ]);
        app(WaiverService::class)->syncVersion($template);
        $template = $template->fresh();

        return Waiver::create([
            'company_id' => $this->company->id,
            'location_id' => $this->brighton->id,
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
}

class PhotoRegistrationFakeSms extends SmsService
{
    public function __construct(PhotoRegistrationFakeTexts $client)
    {
        $this->client = SmsService::isConfigured() ? $client : null;
        $this->fromNumber = config('twilio.from_number');
    }
}

class PhotoRegistrationFakeTexts
{
    public array $sent = [];

    public object $messages;

    public function __construct()
    {
        $outbox = $this;

        $this->messages = new class($outbox) {
            public function __construct(private PhotoRegistrationFakeTexts $outbox)
            {
            }

            public function create(string $to, array $options): object
            {
                $sid = 'SM' . md5($to . count($this->outbox->sent));
                $this->outbox->sent[] = ['sid' => $sid, 'to' => $to, 'body' => $options['body'] ?? ''];

                return (object) ['sid' => $sid, 'status' => 'queued', 'from' => $options['from'] ?? null];
            }
        };
    }

    public function messages(string $sid): object
    {
        return new class {
            public function fetch(): object
            {
                return (object) ['status' => 'sent', 'errorCode' => null];
            }
        };
    }
}
