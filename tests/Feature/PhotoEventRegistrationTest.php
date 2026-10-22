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
        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
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
