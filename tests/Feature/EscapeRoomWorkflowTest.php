<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DayOff;
use App\Models\EscapeRoomSession;
use App\Models\Location;
use App\Models\LocationPhotoSetting;
use App\Models\Package;
use App\Models\PackageAvailabilitySchedule;
use App\Models\Photo;
use App\Models\PhotoDelivery;
use App\Models\PhotoMessageTemplate;
use App\Models\PhotoOverlay;
use App\Models\PhotoSession;
use App\Models\SmsNotification;
use App\Models\User;
use App\Models\Waiver;
use App\Models\WaiverBulkInvite;
use App\Models\WaiverTemplate;
use App\Services\EmailNotificationService;
use App\Services\EscapeRoomSessionService;
use App\Services\PhotoDeliveryService;
use App\Services\PhotoProcessingService;
use App\Services\WaiverService;
use App\Support\EscapeRoomException;
use App\Support\OperatingDay;
use App\Support\SchemaSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EscapeRoomWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-10-03';

    private Company $company;

    private Location $location;

    private Location $otherLocation;

    private User $admin;

    private User $attendant;

    private User $otherAttendant;

    private Package $morgue;

    private Package $airlock;

    private Package $party;

    private Package $otherRoom;

    private WaiverTemplate $general;

    private WaiverTemplate $escapeWaiver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false, 'app.frontend_url' => 'https://zapzone.test']);
        Storage::fake(config('filesystems.default'));
        Storage::fake('photos');
        Storage::fake('public');
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(Carbon::parse(self::TODAY . ' 13:30:00', 'America/Detroit'));

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $this->location = $this->makeLocation('Waterford');
        $this->otherLocation = $this->makeLocation('Brighton');

        $this->admin = $this->makeUser('company_admin', $this->location, 'admin');
        $this->attendant = $this->makeUser('attendant', $this->location, 'attendant');
        $this->otherAttendant = $this->makeUser('attendant', $this->otherLocation, 'brighton');

        $this->morgue = $this->makePackage('The Morgue', $this->location, true);
        $this->airlock = $this->makePackage('Airlock Escape', $this->location, true);
        $this->party = $this->makePackage('Birthday Party', $this->location, false);
        $this->otherRoom = $this->makePackage('The Vault', $this->otherLocation, true);

        $this->general = $this->makeTemplate('General Activity Waiver', WaiverTemplate::KIND_STANDARD, true, [$this->party->id, $this->morgue->id]);
        $this->escapeWaiver = $this->makeTemplate('Escape Room Waiver', WaiverTemplate::KIND_ESCAPE_ROOM, true);
    }

    private function makeLocation(string $name): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'address' => '1 Test Way',
            'city' => $name,
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => strtolower($name) . '@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    private function makeUser(string $role, Location $location, string $key): User
    {
        return User::create([
            'first_name' => ucfirst($key),
            'last_name' => 'Staff',
            'email' => $key . '@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
        ]);
    }

    private function makePackage(string $name, Location $location, bool $escapeRoom): Package
    {
        $package = Package::create([
            'location_id' => $location->id,
            'name' => $name,
            'description' => 'Test package',
            'category' => 'Adventure',
            'price' => 30,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 10,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'is_active' => true,
            'is_escape_room' => $escapeRoom,
        ]);

        PackageAvailabilitySchedule::create([
            'package_id' => $package->id,
            'availability_type' => 'daily',
            'day_configuration' => [],
            'time_slot_start' => '11:00',
            'time_slot_end' => '20:00',
            'time_slot_interval' => 60,
            'priority' => 0,
            'is_active' => true,
        ]);

        return $package;
    }

    private function makeTemplate(string $title, string $kind, bool $default, array $packageIds = []): WaiverTemplate
    {
        $template = WaiverTemplate::create([
            'company_id' => $this->company->id,
            'title' => $title,
            'status' => WaiverTemplate::STATUS_ACTIVE,
            'is_default' => $default,
            'body_text' => '<p>I release {{company_name}} for {{activity_name}}.</p>',
            'kind' => $kind,
            'assigned_package_ids' => $packageIds,
            'electronic_consent_enabled' => true,
        ]);

        app(WaiverService::class)->syncVersion($template);

        return $template->fresh();
    }

    private function makeBooking(Package $package, string $time, string $name = 'Jordan Rivera', string $status = 'confirmed'): Booking
    {
        static $counter = 0;
        $counter++;

        return Booking::create([
            'reference_number' => 'ER' . $counter . str_replace(':', '', $time) . $package->id,
            'booking_date' => self::TODAY,
            'booking_time' => $time,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'location_id' => $package->location_id,
            'package_id' => $package->id,
            'participants' => 4,
            'total_amount' => 120,
            'status' => $status,
            'guest_name' => $name,
            'guest_email' => 'booker' . $counter . '@example.test',
            'guest_phone' => '2485550100',
        ]);
    }

    private function signPayload(array $overrides = []): array
    {
        return array_merge([
            'adult_first_name' => 'Casey',
            'adult_last_name' => 'Player',
            'adult_email' => 'casey@example.test',
            'adult_phone' => '(248) 555-0142',
            'adult_dob' => '1990-05-05',
            'typed_legal_name' => 'Casey Player',
            'agreement_accepted' => true,
            'electronic_consent_accepted' => true,
        ], $overrides);
    }

    private function sign(Package $room, string $time, array $overrides = [], ?Location $location = null)
    {
        $location ??= $this->location;

        return $this->postJson(
            "/api/waivers/escape-room/{$location->id}/submit",
            array_merge($this->signPayload($overrides), ['package_id' => $room->id, 'session_time' => $time])
        );
    }

    private function signed(Package $room, string $time, string $first, string $email): Waiver
    {
        $response = $this->sign($room, $time, [
            'adult_first_name' => $first,
            'adult_email' => $email,
            'typed_legal_name' => $first . ' Player',
        ]);

        $response->assertCreated();

        return Waiver::findOrFail($response->json('data.id'));
    }

    private function openGame(Package $room, string $time, ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->attendant, 'sanctum')
            ->postJson('/api/escape-rooms/sessions', [
                'location_id' => $room->location_id,
                'package_id' => $room->id,
                'date' => self::TODAY,
                'time' => $time,
            ])
            ->assertCreated()
            ->json('data');
    }

    private function withGroupPhoto(int $sessionId, ?User $as = null): PhotoSession
    {
        $this->actingAs($as ?? $this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$sessionId}/photo-session", ['verbal_consent' => true])
            ->assertOk();

        $photoSession = PhotoSession::findOrFail(EscapeRoomSession::findOrFail($sessionId)->photo_session_id);
        Storage::disk('photos')->put('x/' . $photoSession->id . '/delivery.jpg', 'fake-jpeg-bytes');

        Photo::create([
            'photo_session_id' => $photoSession->id,
            'company_id' => $photoSession->company_id,
            'location_id' => $photoSession->location_id,
            'position' => 1,
            'source' => Photo::SOURCE_CAMERA,
            'processing_status' => Photo::PROCESSING_READY,
            'original_path' => 'x/' . $photoSession->id . '/original.jpg',
            'delivery_path' => 'x/' . $photoSession->id . '/delivery.jpg',
            'slideshow_path' => 'x/' . $photoSession->id . '/slideshow.jpg',
            'thumbnail_path' => 'x/' . $photoSession->id . '/thumb.jpg',
            'captured_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);

        return $photoSession;
    }

    private function complete(int $sessionId, bool $escaped = true, ?string $time = '47:12', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$sessionId}/complete", [
                'escaped' => $escaped,
                'completion_time' => $time,
            ]);
    }

    private function sentPhotoEmails(): array
    {
        return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->filter(fn ($email) => str_contains((string) $email->getSubject(), 'Thanks for playing'))
            ->values()
            ->all();
    }

    private function thanksForPlaying(): \App\Models\EmailNotification
    {
        \Database\Seeders\DefaultEmailNotificationSeeder::seedForCompany($this->company);

        return \App\Models\EmailNotification::where('company_id', $this->company->id)
            ->where('default_key', \App\Models\EmailNotification::DEFAULT_THANKS_FOR_PLAYING)
            ->firstOrFail();
    }

    private function recipients(): array
    {
        return collect($this->sentPhotoEmails())
            ->map(fn ($email) => $email->getTo()[0]->getAddress())
            ->sort()
            ->values()
            ->all();
    }

    public function test_standard_resolution_never_returns_an_escape_room_waiver(): void
    {
        $this->escapeWaiver->update(['location_id' => $this->location->id]);

        $resolvedParty = WaiverTemplate::resolveForActivity($this->company->id, $this->location->id, $this->party->id);
        $resolvedUnassigned = WaiverTemplate::resolveForActivity($this->company->id, $this->location->id, 999999);

        $this->assertSame($this->general->id, $resolvedParty?->id);
        $this->assertSame($this->general->id, $resolvedUnassigned?->id);
        $this->assertSame(
            $this->escapeWaiver->id,
            WaiverTemplate::resolveForEscapeRoom($this->company->id, $this->location->id, $this->airlock->id)?->id
        );
    }

    public function test_an_escape_room_booking_gets_the_escape_room_waiver_and_a_party_keeps_the_general_one(): void
    {
        $escapeBooking = $this->makeBooking($this->morgue, '15:00');
        $partyBooking = $this->makeBooking($this->party, '15:00');

        $escapeWaiver = app(WaiverService::class)->ensureForBooking($escapeBooking);
        $partyWaiver = app(WaiverService::class)->ensureForBooking($partyBooking);

        $this->assertSame($this->escapeWaiver->id, $escapeWaiver->waiver_template_id);
        $this->assertSame($this->morgue->id, $escapeWaiver->package_id);
        $this->assertSame($this->general->id, $partyWaiver->waiver_template_id);
        $this->assertNull($partyWaiver->package_id);
    }

    public function test_without_an_escape_room_waiver_an_escape_room_booking_keeps_todays_behaviour(): void
    {
        $this->escapeWaiver->update(['status' => WaiverTemplate::STATUS_DRAFT]);

        $waiver = app(WaiverService::class)->ensureForBooking($this->makeBooking($this->morgue, '15:00'));

        $this->assertSame($this->general->id, $waiver->waiver_template_id);
    }

    public function test_the_check_in_page_lists_this_locations_escape_rooms_and_only_times_not_finished(): void
    {
        DayOff::create([
            'location_id' => $this->location->id,
            'date' => self::TODAY,
            'time_start' => '17:00',
            'time_end' => '18:00',
            'reason' => 'Maintenance',
            'package_ids' => [$this->airlock->id],
        ]);

        $response = $this->getJson("/api/waivers/escape-room/{$this->location->id}")->assertOk();

        $rooms = collect($response->json('data.rooms'));
        $this->assertEqualsCanonicalizing(['The Morgue', 'Airlock Escape'], $rooms->pluck('name')->all());

        $airlockTimes = collect($rooms->firstWhere('name', 'Airlock Escape')['times'])->pluck('time')->all();
        $this->assertSame(['13:00', '14:00', '15:00', '16:00', '18:00', '19:00'], $airlockTimes);
        $this->assertTrue(collect($rooms->firstWhere('name', 'Airlock Escape')['times'])->firstWhere('time', '13:00')['in_progress']);
        $this->assertStringNotContainsString('Jordan', $response->getContent());
    }

    public function test_a_signed_waiver_is_tied_to_its_room_game_and_the_only_booking_at_that_time(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:00');

        $response = $this->sign($this->morgue, '14:00')->assertCreated();
        $waiver = Waiver::findOrFail($response->json('data.id'));
        $session = EscapeRoomSession::findOrFail($waiver->escape_room_session_id);

        $this->assertSame(Waiver::STATUS_COMPLETED, $waiver->status);
        $this->assertSame(Waiver::SOURCE_KIOSK, $waiver->source);
        $this->assertSame($this->escapeWaiver->id, $waiver->waiver_template_id);
        $this->assertSame($this->morgue->id, $waiver->package_id);
        $this->assertSame($booking->id, $waiver->booking_id);
        $this->assertSame(self::TODAY, $waiver->selected_date->toDateString());
        $this->assertSame('14:00', $session->timeKey());
        $this->assertSame($this->morgue->id, $session->package_id);
        $this->assertSame('2485550142', $waiver->adult_phone);
    }

    public function test_signing_never_sends_a_photo(): void
    {
        $this->sign($this->morgue, '14:00')->assertCreated();

        $this->assertSame(0, PhotoDelivery::count());
        $this->assertSame([], $this->sentPhotoEmails());
    }

    public function test_a_guest_cannot_sign_for_a_time_or_room_that_is_not_offered(): void
    {
        $this->sign($this->morgue, '14:30')->assertStatus(422);
        $this->sign($this->morgue, '11:00')->assertStatus(422);
        $this->sign($this->party, '14:00')->assertStatus(422);
        $this->sign($this->otherRoom, '14:00')->assertStatus(422);
        $this->sign($this->otherRoom, '14:00', [], $this->otherLocation)->assertCreated();

        $this->assertSame(1, Waiver::count());
    }

    public function test_the_standard_kiosk_and_staff_tools_refuse_the_escape_room_waiver(): void
    {
        $this->getJson("/api/waivers/kiosk/{$this->escapeWaiver->id}")->assertNotFound();
        $this->postJson("/api/waivers/kiosk/{$this->escapeWaiver->id}/submit", $this->signPayload())->assertNotFound();
        $this->getJson("/api/waivers/kiosk/{$this->general->id}")->assertOk();

        $manager = $this->makeUser('location_manager', $this->location, 'manager');

        $this->actingAs($manager, 'sanctum')->postJson('/api/waivers/assign', [
            'waiver_template_id' => $this->escapeWaiver->id,
            'selected_date' => self::TODAY,
            'adult_email' => 'someone@example.test',
        ])->assertStatus(422);
    }

    public function test_two_bookings_sharing_a_time_are_one_group_and_no_booking_is_guessed(): void
    {
        $this->makeBooking($this->airlock, '15:00', 'Family A');
        $this->makeBooking($this->airlock, '15:00', 'Family B');

        $first = $this->signed($this->airlock, '15:00', 'Avery', 'avery@example.test');
        $second = $this->signed($this->airlock, '15:00', 'Blake', 'blake@example.test');

        $this->assertNull($first->booking_id);
        $this->assertNull($second->booking_id);
        $this->assertSame($first->escape_room_session_id, $second->escape_room_session_id);
    }

    public function test_complete_emails_only_this_games_players_with_the_finish_time_and_the_photo(): void
    {
        $avery = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $this->signed($this->morgue, '14:00', 'Blake', 'blake@example.test');
        $this->signed($this->morgue, '15:00', 'Later', 'later@example.test');
        $this->signed($this->airlock, '14:00', 'Other', 'other-room@example.test');
        $this->sign($this->otherRoom, '14:00', ['adult_email' => 'brighton@example.test'], $this->otherLocation)->assertCreated();

        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);

        $this->complete($game['id'])->assertOk()
            ->assertJsonPath('data.completed', true)
            ->assertJsonPath('data.completion_label', '47:12')
            ->assertJsonPath('data.counts.sent', 2);

        $this->assertSame(['avery@example.test', 'blake@example.test'], $this->recipients());

        $email = collect($this->sentPhotoEmails())->first(fn ($message) => $message->getTo()[0]->getAddress() === 'avery@example.test');
        $this->assertStringContainsString('47:12', $email->getHtmlBody());
        $this->assertStringContainsString('The Morgue', $email->getHtmlBody());
        $this->assertStringContainsString('2:00 PM', $email->getHtmlBody());
        $this->assertCount(1, $email->getAttachments());

        $this->assertSame(
            [$avery->id],
            PhotoDelivery::where('waiver_id', $avery->id)->pluck('waiver_id')->all()
        );
        $this->assertSame(2, PhotoDelivery::where('kind', PhotoDelivery::KIND_ESCAPE_ROOM)->count());
    }

    public function test_pressing_complete_twice_sends_once(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);

        $this->complete($game['id'])->assertOk();
        $this->complete($game['id'])->assertStatus(409);

        $this->assertCount(1, $this->sentPhotoEmails());
    }

    public function test_didnt_escape_is_allowed_and_a_time_is_required_when_they_escaped(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);

        $this->complete($game['id'], true, null)->assertStatus(422);
        $this->complete($game['id'], true, '99:99')->assertStatus(422);
        $this->complete($game['id'], false, null)->assertOk()->assertJsonPath('data.completion_label', 'Did not escape');

        $email = $this->sentPhotoEmails()[0];
        $this->assertStringContainsString('escape this time. Come back and try again!', $email->getHtmlBody());
        $this->assertStringNotContainsString('Did not escape', $email->getHtmlBody());
    }

    public function test_complete_needs_a_photo_and_a_signed_player(): void
    {
        $game = $this->openGame($this->morgue, '14:00');
        $this->complete($game['id'])->assertStatus(422);

        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertStatus(422);

        $this->assertNull(EscapeRoomSession::find($game['id'])->completed_at);
    }

    public function test_a_late_signer_is_reached_by_send_to_new_players_only(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();

        $this->signed($this->morgue, '14:00', 'Late', 'late@example.test');

        $this->actingAs($this->attendant, 'sanctum')
            ->getJson("/api/escape-rooms/sessions/{$game['id']}")
            ->assertJsonPath('data.counts.new_players', 1);

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/send-new")
            ->assertOk();

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/send-new")
            ->assertStatus(409);

        $this->assertSame(['avery@example.test', 'late@example.test'], $this->recipients());
    }

    public function test_the_same_email_on_two_waivers_is_sent_once(): void
    {
        $this->signed($this->morgue, '14:00', 'Parent', 'family@example.test');
        $this->signed($this->morgue, '14:00', 'Other', 'family@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);

        $this->complete($game['id'])->assertOk();

        $this->assertSame(['family@example.test'], $this->recipients());
    }

    public function test_the_normal_deliver_and_library_send_refuse_an_escape_room_photo(): void
    {
        $waiver = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $photoSession = $this->withGroupPhoto($game['id']);
        $photo = $photoSession->photos()->first();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-sessions/{$photoSession->id}/deliver", [
            'method' => 'waiver_message',
            'waiver_ids' => [$waiver->id],
        ])->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/photo-library/{$photo->id}/send", [
            'waiver_ids' => [$waiver->id],
        ])->assertStatus(422);

        $this->assertSame(0, PhotoDelivery::count());
    }

    public function test_players_from_a_cancelled_booking_are_left_out(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:00');
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $walkIn = $this->signed($this->morgue, '14:00', 'Walkin', 'walkin@example.test');
        $walkIn->forceFill(['booking_id' => null])->save();

        $booking->update(['status' => 'cancelled']);

        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk()
            ->assertJsonPath('data.counts.excluded', 1)
            ->assertJsonPath('data.excluded_players.0.excluded_reason', EscapeRoomSessionService::EXCLUDED_BOOKING_CANCELLED);

        $this->assertSame(['walkin@example.test'], $this->recipients());
    }

    public function test_players_follow_their_booking_to_a_new_time(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:00');
        $waiver = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');

        $booking->update(['booking_time' => '16:00']);
        app(EscapeRoomSessionService::class)->followBooking($booking->fresh());

        $session = EscapeRoomSession::findOrFail($waiver->fresh()->escape_room_session_id);
        $this->assertSame('16:00', $session->timeKey());
    }

    public function test_a_player_who_picked_the_wrong_time_can_be_moved_before_the_send(): void
    {
        $waiver = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/waivers/{$waiver->id}/move", [
                'package_id' => $this->airlock->id,
                'time' => '15:00',
            ])->assertOk()->assertJsonPath('data.counts.players', 0);

        $moved = EscapeRoomSession::findOrFail($waiver->fresh()->escape_room_session_id);
        $this->assertSame($this->airlock->id, $moved->package_id);
        $this->assertSame('15:00', $moved->timeKey());

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$moved->id}/waivers/{$waiver->id}/move", [
                'package_id' => $this->otherRoom->id,
                'time' => '15:00',
            ])->assertStatus(422);
    }

    public function test_staff_from_another_location_cannot_see_or_send_a_game(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');

        $this->actingAs($this->otherAttendant, 'sanctum')->getJson("/api/escape-rooms/sessions/{$game['id']}")->assertForbidden();
        $this->actingAs($this->otherAttendant, 'sanctum')->postJson("/api/escape-rooms/sessions/{$game['id']}/complete", [
            'escaped' => true,
            'completion_time' => '40:00',
        ])->assertForbidden();
        $this->actingAs($this->otherAttendant, 'sanctum')
            ->getJson('/api/escape-rooms/day?location_id=' . $this->location->id)
            ->assertForbidden();
    }

    public function test_a_room_overlay_is_used_for_that_rooms_photos_only_and_never_conflicts_with_the_general_one(): void
    {
        $general = PhotoOverlay::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'name' => 'Waterford frame',
            'image_path' => 'photo-overlays/general.png',
            'priority' => 10,
        ]);
        $morgueFrame = PhotoOverlay::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'package_id' => $this->morgue->id,
            'name' => 'Morgue frame',
            'image_path' => 'photo-overlays/morgue.png',
            'priority' => 90,
        ]);

        $processor = app(PhotoProcessingService::class);

        $this->assertSame($general->id, $processor->resolveOverlay($this->location)?->id);
        $this->assertSame($morgueFrame->id, $processor->resolveOverlay($this->location, now(), $this->morgue->id)?->id);
        $this->assertSame($general->id, $processor->resolveOverlay($this->location, now(), $this->airlock->id)?->id);
        $this->assertSame([], $processor->overlayConflicts($this->location->id));

        $game = $this->openGame($this->morgue, '14:00');
        $photoSession = $this->withGroupPhoto($game['id']);
        $this->assertSame($this->morgue->id, $processor->roomFor($photoSession->photos()->first()));
    }

    public function test_the_booking_email_waiver_joins_its_game_when_signed(): void
    {
        $booking = $this->makeBooking($this->morgue, '16:00');
        $pending = app(WaiverService::class)->ensureForBooking($booking);

        $this->getJson("/api/waivers/access/{$pending->access_token}")
            ->assertOk()
            ->assertJsonPath('data.escape_room.room_name', 'The Morgue')
            ->assertJsonPath('data.escape_room.time', '16:00');

        $this->postJson("/api/waivers/access/{$pending->access_token}/submit", $this->signPayload())->assertOk();

        $session = EscapeRoomSession::findOrFail($pending->fresh()->escape_room_session_id);
        $this->assertSame('16:00', $session->timeKey());
        $this->assertSame($booking->id, $pending->fresh()->booking_id);
    }

    public function test_an_escape_room_waiver_only_covers_escape_room_packages_and_its_type_is_locked_once_signed(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/waiver-templates', [
            'title' => 'Bad escape waiver',
            'body_text' => '<p>Text</p>',
            'kind' => WaiverTemplate::KIND_ESCAPE_ROOM,
            'assigned_package_ids' => [$this->party->id],
        ])->assertStatus(422);

        $this->sign($this->airlock, '14:00')->assertCreated();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/waiver-templates/{$this->escapeWaiver->id}", [
            'kind' => WaiverTemplate::KIND_STANDARD,
        ])->assertStatus(422);
    }

    public function test_the_day_view_lists_each_rooms_times_with_bookings_and_signed_players(): void
    {
        $this->makeBooking($this->morgue, '14:00', 'Jordan Rivera');
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');

        $data = $this->actingAs($this->attendant, 'sanctum')
            ->getJson('/api/escape-rooms/day?location_id=' . $this->location->id)
            ->assertOk()
            ->json('data');

        $morgue = collect($data['rooms'])->firstWhere('name', 'The Morgue');
        $slot = collect($morgue['slots'])->firstWhere('time', '14:00');

        $this->assertSame(self::TODAY, $data['date']);
        $this->assertSame('Jordan Rivera', $slot['bookings'][0]['name']);
        $this->assertSame(1, $slot['signed']);
        $this->assertSame('signing', $slot['status']);
        $this->assertCount(9, $morgue['slots']);
        $this->assertNull(collect($data['rooms'])->firstWhere('name', 'Birthday Party'));
    }

    public function test_room_names_are_escaped_in_the_email(): void
    {
        $this->morgue->update(['name' => 'The <b>Morgue</b>']);
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);

        $this->complete($game['id'])->assertOk();

        $html = $this->sentPhotoEmails()[0]->getHtmlBody();
        $this->assertStringContainsString('The &lt;b&gt;Morgue&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Morgue</b>', $html);
    }

    public function test_staff_can_remove_a_stranger_from_a_game_before_the_send(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $stranger = $this->signed($this->morgue, '14:00', 'Stranger', 'stranger@example.test');
        $game = $this->openGame($this->morgue, '14:00');

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/waivers/{$stranger->id}/remove")
            ->assertOk()
            ->assertJsonPath('data.counts.players', 1);

        $this->assertNull($stranger->fresh()->escape_room_session_id);

        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();

        $this->assertSame(['avery@example.test'], $this->recipients());

        $this->actingAs($this->otherAttendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/waivers/{$stranger->id}/remove")
            ->assertForbidden();
    }

    public function test_emails_cut_off_mid_send_are_resent_by_send_to_new_players(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $photoSession = $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();

        $stuckWaiver = $this->signed($this->morgue, '14:00', 'Stuck', 'stuck@example.test');
        $delivery = PhotoDelivery::create([
            'photo_session_id' => $photoSession->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'waiver_id' => $stuckWaiver->id,
            'kind' => PhotoDelivery::KIND_ESCAPE_ROOM,
            'channel' => PhotoDelivery::CHANNEL_EMAIL,
            'destination' => 'stuck@example.test',
            'recipient_name' => 'Stuck Player',
            'status' => PhotoDelivery::STATUS_QUEUED,
        ]);
        PhotoDelivery::whereKey($delivery->id)->update(['updated_at' => now()->subMinutes(20)]);

        $this->actingAs($this->attendant, 'sanctum')
            ->getJson("/api/escape-rooms/sessions/{$game['id']}")
            ->assertJsonPath('data.counts.stuck', 1)
            ->assertJsonPath('data.can_send_new', true);

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/send-new")
            ->assertOk();

        $this->assertSame(PhotoDelivery::STATUS_SENT, $delivery->fresh()->status);
        $this->assertSame(['avery@example.test', 'stuck@example.test'], $this->recipients());
    }

    public function test_an_unsigned_general_waiver_on_an_escape_room_booking_becomes_the_escape_room_waiver(): void
    {
        $this->escapeWaiver->update(['status' => WaiverTemplate::STATUS_DRAFT]);
        $booking = $this->makeBooking($this->morgue, '16:00');
        $pending = app(WaiverService::class)->ensureForBooking($booking);
        $this->assertSame($this->general->id, $pending->waiver_template_id);

        $this->escapeWaiver->update(['status' => WaiverTemplate::STATUS_ACTIVE]);

        $this->getJson("/api/waivers/access/{$pending->access_token}")
            ->assertOk()
            ->assertJsonPath('data.template.id', $this->escapeWaiver->id)
            ->assertJsonPath('data.escape_room.room_name', 'The Morgue');

        $this->postJson("/api/waivers/access/{$pending->access_token}/submit", $this->signPayload())->assertOk();

        $fresh = $pending->fresh();
        $this->assertSame($this->escapeWaiver->id, $fresh->waiver_template_id);
        $this->assertSame('16:00', EscapeRoomSession::findOrFail($fresh->escape_room_session_id)->timeKey());

        $partyPending = app(WaiverService::class)->ensureForBooking($this->makeBooking($this->party, '16:00'));
        $this->getJson("/api/waivers/access/{$partyPending->access_token}")->assertOk()->assertJsonPath('data.template.id', $this->general->id);
    }

    public function test_the_staff_kiosk_does_not_hand_out_the_general_waiver_for_an_escape_room(): void
    {
        $this->actingAs($this->attendant, 'sanctum')->postJson('/api/waivers/kiosk-session', [
            'source_type' => 'package',
            'source_id' => $this->morgue->id,
        ])->assertStatus(422);

        $this->actingAs($this->attendant, 'sanctum')->postJson('/api/waivers/kiosk-session', [
            'source_type' => 'package',
            'source_id' => $this->party->id,
        ])->assertOk();
    }

    public function test_an_escape_room_waiver_still_saves_after_one_of_its_rooms_is_switched_off(): void
    {
        $this->escapeWaiver->update(['assigned_package_ids' => [$this->airlock->id]]);
        $this->airlock->update(['is_escape_room' => false]);

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/waiver-templates/{$this->escapeWaiver->id}", [
            'title' => 'Escape Room Waiver (edited)',
            'assigned_package_ids' => [$this->airlock->id],
        ])->assertOk();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/waiver-templates/{$this->escapeWaiver->id}", [
            'assigned_package_ids' => [$this->airlock->id, $this->party->id],
        ])->assertStatus(422);
    }

    public function test_booking_lookups_ignore_players_check_in_waivers(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:00');
        $player = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $this->assertSame($booking->id, $player->booking_id);

        $response = $this->actingAs($this->attendant, 'sanctum')->postJson('/api/waivers/kiosk-session', [
            'source_type' => 'booking',
            'source_id' => $booking->id,
        ])->assertOk();

        $this->assertFalse($response->json('data.already_completed'));
        $this->assertNotSame($player->access_token, $response->json('data.access_token'));
    }

    public function test_the_bookers_own_waiver_stays_with_its_booking(): void
    {
        $booking = $this->makeBooking($this->morgue, '16:00');
        $pending = app(WaiverService::class)->ensureForBooking($booking);
        $this->postJson("/api/waivers/access/{$pending->access_token}/submit", $this->signPayload())->assertOk();
        $booker = $pending->fresh();
        $game = EscapeRoomSession::findOrFail($booker->escape_room_session_id);

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game->id}/waivers/{$booker->id}/booking", ['booking_id' => null])
            ->assertStatus(422);
        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game->id}/waivers/{$booker->id}/move", ['package_id' => $this->morgue->id, 'time' => '17:00'])
            ->assertStatus(422);
        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game->id}/waivers/{$booker->id}/remove")
            ->assertStatus(422);

        $this->assertSame($booking->id, $booker->fresh()->booking_id);
        $this->assertSame($game->id, $booker->fresh()->escape_room_session_id);
        $this->actingAs($this->attendant, 'sanctum')
            ->getJson("/api/escape-rooms/sessions/{$game->id}")
            ->assertJsonPath('data.players.0.is_sign_in', false);
    }

    public function test_a_waiver_that_changed_after_it_was_opened_asks_the_guest_to_reload(): void
    {
        $this->escapeWaiver->update(['status' => WaiverTemplate::STATUS_DRAFT]);
        $pending = app(WaiverService::class)->ensureForBooking($this->makeBooking($this->morgue, '16:00'));
        $this->escapeWaiver->update(['status' => WaiverTemplate::STATUS_ACTIVE]);

        $this->postJson("/api/waivers/access/{$pending->access_token}/submit", $this->signPayload())->assertStatus(409);
        $this->assertSame(Waiver::STATUS_PENDING, $pending->fresh()->status);
        $this->assertSame($this->general->id, $pending->fresh()->waiver_template_id);

        $this->getJson("/api/waivers/access/{$pending->access_token}")->assertOk()->assertJsonPath('data.template.id', $this->escapeWaiver->id);
        $this->postJson("/api/waivers/access/{$pending->access_token}/submit", $this->signPayload())->assertOk();
    }

    public function test_a_standard_waiver_cannot_become_an_escape_room_waiver_that_covers_a_party(): void
    {
        $spare = $this->makeTemplate('Spare waiver', WaiverTemplate::KIND_STANDARD, false);
        $partyTwo = $this->makePackage('Arcade Party', $this->location, false);
        $spare->update(['assigned_package_ids' => [$partyTwo->id]]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/waiver-templates/{$spare->id}", ['kind' => WaiverTemplate::KIND_ESCAPE_ROOM])
            ->assertStatus(422);
    }

    public function test_the_staff_kiosk_still_works_for_a_room_without_an_escape_room_waiver(): void
    {
        $this->escapeWaiver->update(['status' => WaiverTemplate::STATUS_DRAFT]);

        $this->actingAs($this->attendant, 'sanctum')->postJson('/api/waivers/kiosk-session', [
            'source_type' => 'package',
            'source_id' => $this->morgue->id,
        ])->assertOk();
    }

    public function test_a_refused_room_is_reported_as_a_room_problem(): void
    {
        $this->sign($this->party, '14:00')->assertStatus(422)->assertJsonValidationErrors(['package_id']);
        $this->sign($this->morgue, '14:30')->assertStatus(422)->assertJsonValidationErrors(['session_time']);
    }

    public function test_a_delivery_already_taken_by_another_send_is_not_sent_twice(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $photoSession = $this->withGroupPhoto($game['id']);
        $photoSession->startQrWindow();
        $photoSession->save();

        $delivery = PhotoDelivery::create([
            'photo_session_id' => $photoSession->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'kind' => PhotoDelivery::KIND_ESCAPE_ROOM,
            'channel' => PhotoDelivery::CHANNEL_EMAIL,
            'destination' => 'race@example.test',
            'recipient_name' => 'Race Player',
            'status' => PhotoDelivery::STATUS_QUEUED,
        ]);
        $stale = $delivery->fresh();
        $this->travel(2)->seconds();
        PhotoDelivery::whereKey($delivery->id)->update(['updated_at' => now()]);

        $sent = app(\App\Services\PhotoDeliveryService::class)->sendEscapeRoomDeliveries($photoSession->fresh(), [$stale]);

        $this->assertSame(0, $sent);
        $this->assertSame([], $this->recipients());
    }

    private function dayData(?string $date = null, ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->attendant, 'sanctum')
            ->getJson('/api/escape-rooms/day?location_id=' . $this->location->id . ($date ? '&date=' . $date : ''))
            ->assertOk()
            ->json('data');
    }

    private function daySlots(string $roomName, ?string $date = null): \Illuminate\Support\Collection
    {
        $room = collect($this->dayData($date)['rooms'])->firstWhere('name', $roomName);

        return collect($room['slots'] ?? [])->keyBy('time');
    }

    private function gameDetail(int $sessionId, ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->attendant, 'sanctum')
            ->getJson("/api/escape-rooms/sessions/{$sessionId}")
            ->assertOk()
            ->json('data');
    }

    private function guestTimesFor(string $roomName): array
    {
        $rooms = collect($this->getJson("/api/waivers/escape-room/{$this->location->id}")->assertOk()->json('data.rooms'));

        return collect($rooms->firstWhere('name', $roomName)['times'] ?? [])->pluck('time')->all();
    }

    private function startPhoto(int $sessionId): PhotoSession
    {
        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$sessionId}/photo-session", ['verbal_consent' => true])
            ->assertOk();

        return PhotoSession::findOrFail(EscapeRoomSession::findOrFail($sessionId)->photo_session_id);
    }

    private function uploadGroupPhoto(int $photoSessionId)
    {
        return $this->actingAs($this->attendant, 'sanctum')->post(
            "/api/photo-sessions/{$photoSessionId}/photos",
            ['file' => UploadedFile::fake()->image('group.jpg', 1200, 800)],
            ['Accept' => 'application/json']
        );
    }

    private function overlayImage(string $path, array $rgb): void
    {
        $image = imagecreatetruecolor(300, 200);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 0, 0, 299, 14, imagecolorallocate($image, ...$rgb));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        Storage::disk('public')->put($path, $png);
    }

    private function makeOverlay(string $name, ?Package $room = null, int $priority = 10): PhotoOverlay
    {
        $path = 'photo-overlays/' . \Illuminate\Support\Str::slug($name) . '.png';
        $this->overlayImage($path, $room ? [200, 0, 0] : [0, 0, 200]);

        return PhotoOverlay::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'package_id' => $room?->id,
            'name' => $name,
            'image_path' => $path,
            'priority' => $priority,
        ]);
    }

    private function recordingProcessor(): PhotoProcessingService
    {
        $processor = new class extends PhotoProcessingService {
            public array $calls = [];

            protected function applyOverlay(\GdImage $canvas, PhotoOverlay $overlay): void
            {
                $this->calls[] = 'overlay:' . $overlay->id;
                parent::applyOverlay($canvas, $overlay);
            }

            protected function applyDateLayer(\GdImage $canvas, LocationPhotoSetting $setting, Photo $photo, ?Location $location): void
            {
                $this->calls[] = 'date:' . $this->captureDateText($setting, $photo, $location);
                parent::applyDateLayer($canvas, $setting, $photo, $location);
            }
        };

        $this->app->instance(PhotoProcessingService::class, $processor);

        return $processor;
    }

    private function signViaLink(Waiver $pending, array $overrides = [])
    {
        return $this->postJson("/api/waivers/access/{$pending->access_token}/submit", $this->signPayload($overrides));
    }

    private function allEmails(): array
    {
        return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->values()
            ->all();
    }

    public function test_the_staff_kiosk_gives_an_escape_room_booking_its_escape_room_waiver_even_when_the_general_one_is_picked(): void
    {
        $booking = $this->makeBooking($this->morgue, '16:00');

        $response = $this->actingAs($this->attendant, 'sanctum')->postJson('/api/waivers/kiosk-session', [
            'source_type' => 'booking',
            'source_id' => $booking->id,
            'template_id' => $this->general->id,
        ])->assertOk();

        $pending = Waiver::where('access_token', $response->json('data.access_token'))->firstOrFail();
        $this->assertSame($this->escapeWaiver->id, $pending->waiver_template_id);
        $this->assertSame($this->morgue->id, $pending->package_id);

        $this->signViaLink($pending)->assertOk();

        $game = EscapeRoomSession::findOrFail($pending->fresh()->escape_room_session_id);
        $this->assertSame('16:00', $game->timeKey());
        $this->assertSame($booking->id, $pending->fresh()->booking_id);

        $partyBooking = $this->makeBooking($this->party, '16:00');
        $party = $this->actingAs($this->attendant, 'sanctum')->postJson('/api/waivers/kiosk-session', [
            'source_type' => 'booking',
            'source_id' => $partyBooking->id,
            'template_id' => $this->general->id,
        ])->assertOk();

        $this->assertSame($this->general->id, Waiver::where('access_token', $party->json('data.access_token'))->value('waiver_template_id'));
    }

    public function test_a_booking_signed_on_the_general_waiver_before_rollout_is_still_in_its_game(): void
    {
        $this->escapeWaiver->update(['status' => WaiverTemplate::STATUS_DRAFT]);
        $booking = $this->makeBooking($this->morgue, '14:00');
        $own = app(WaiverService::class)->ensureForBooking($booking);
        $this->assertSame($this->general->id, $own->waiver_template_id);
        $this->signViaLink($own, ['adult_email' => 'booker@example.test'])->assertOk();
        $this->assertNull($own->fresh()->escape_room_session_id);

        $this->escapeWaiver->update(['status' => WaiverTemplate::STATUS_ACTIVE]);
        $this->signed($this->morgue, '14:00', 'Walkin', 'walkin@example.test');

        $this->assertSame(2, $this->daySlots('The Morgue')['14:00']['signed']);

        $game = $this->openGame($this->morgue, '14:00');
        $this->assertSame(2, $game['counts']['players']);
        $this->assertFalse(collect($game['players'])->firstWhere('waiver_id', $own->id)['is_sign_in']);

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/waivers/{$own->id}/remove")
            ->assertStatus(422);

        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk()->assertJsonPath('data.counts.sent', 2);

        $this->assertSame(['booker@example.test', 'walkin@example.test'], $this->recipients());
        $this->assertSame($game['id'], $own->fresh()->escape_room_session_id);
        $this->assertSame($this->general->id, $own->fresh()->waiver_template_id);
    }

    public function test_a_guest_who_submits_just_after_midnight_stays_in_the_game_they_picked(): void
    {
        $this->travelTo(Carbon::parse(self::TODAY . ' 23:50:00', 'America/Detroit'));
        $booking = $this->makeBooking($this->morgue, '23:00');

        $rooms = collect($this->getJson("/api/waivers/escape-room/{$this->location->id}")->assertOk()->json('data.rooms'));
        $late = collect($rooms->firstWhere('name', 'The Morgue')['times'])->firstWhere('time', '23:00');
        $this->assertSame(self::TODAY, $late['date']);

        $this->travelTo(Carbon::parse(self::TODAY . ' 23:50:00', 'America/Detroit')->addMinutes(13));

        $response = $this->sign($this->morgue, '23:00', ['session_date' => self::TODAY])->assertCreated();
        $waiver = Waiver::findOrFail($response->json('data.id'));
        $game = EscapeRoomSession::findOrFail($waiver->escape_room_session_id);

        $this->assertSame(self::TODAY, $game->dateKey());
        $this->assertSame('23:00', $game->timeKey());
        $this->assertSame($booking->id, $waiver->booking_id);
        $this->assertSame(self::TODAY, $waiver->selected_date->toDateString());

        $this->sign($this->morgue, '23:00')->assertStatus(422)->assertJsonValidationErrors(['session_time']);
        $this->sign($this->morgue, '23:00', ['session_date' => '2026-10-01'])->assertStatus(422);
        $this->sign($this->morgue, '23:00', ['session_date' => '2026-10-05'])->assertStatus(422);
        $this->assertSame(1, Waiver::count());
    }

    public function test_yesterdays_date_is_only_accepted_for_a_game_that_is_still_going(): void
    {
        $this->sign($this->morgue, '14:00', ['session_date' => '2026-10-02'])->assertStatus(422)->assertJsonValidationErrors(['session_time']);
        $this->sign($this->morgue, '14:00', ['session_date' => '2026-10-04'])->assertStatus(422)->assertJsonValidationErrors(['session_time']);
        $this->assertSame(0, Waiver::count());
        $this->sign($this->morgue, '14:00', ['session_date' => self::TODAY])->assertCreated();
    }

    public function test_the_day_view_counts_only_players_who_will_get_the_photo(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:00');
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $this->signed($this->morgue, '14:00', 'Blake', 'blake@example.test');
        $booking->update(['status' => 'cancelled']);
        $walkIn = $this->signed($this->morgue, '14:00', 'Walkin', 'walkin@example.test');
        $this->assertNull($walkIn->booking_id);

        $slot = $this->daySlots('The Morgue')['14:00'];

        $this->assertSame(1, $slot['signed']);
        $this->assertSame([], $slot['bookings']);
    }

    public function test_photos_cannot_be_removed_reordered_or_discarded_once_the_game_is_sent(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $photoSession = $this->withGroupPhoto($game['id']);
        $photo = $photoSession->photos()->first();
        $this->complete($game['id'])->assertOk();

        PhotoSession::whereKey($photoSession->id)->update(['delivered_at' => null, 'delivery_method' => null]);

        $this->actingAs($this->attendant, 'sanctum')
            ->deleteJson("/api/photo-sessions/{$photoSession->id}/photos/{$photo->id}")
            ->assertStatus(422);
        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/photo-sessions/{$photoSession->id}/photos/reorder", ['order' => [$photo->id]])
            ->assertStatus(422);
        $this->actingAs($this->attendant, 'sanctum')
            ->deleteJson("/api/photo-sessions/{$photoSession->id}")
            ->assertStatus(422);

        $this->assertNotNull(Photo::find($photo->id));
        $this->assertNotNull(PhotoSession::find($photoSession->id));
    }

    public function test_the_escape_room_email_always_shows_the_groups_time(): void
    {
        $notification = $this->thanksForPlaying();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/email-notifications/{$notification->id}", [
            'subject' => 'Thanks for playing {{activity_name}}',
            'body' => '<p>Hi {{customer_first_name}}</p>',
        ])->assertOk();

        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();

        $this->assertStringContainsString('Your group escaped in 47:12!', $this->sentPhotoEmails()[0]->getHtmlBody());
    }

    public function test_an_escape_room_waiver_for_one_location_only_covers_that_locations_rooms(): void
    {
        $payload = [
            'title' => 'Brighton escape waiver',
            'body_text' => '<p>Text</p>',
            'kind' => WaiverTemplate::KIND_ESCAPE_ROOM,
            'location_id' => $this->otherLocation->id,
        ];

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/waiver-templates', $payload + ['assigned_package_ids' => [$this->airlock->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.assigned_package_ids.0', $this->airlock->id);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/waiver-templates', $payload + ['assigned_package_ids' => [$this->otherRoom->id]])
            ->assertCreated();
    }

    public function test_a_waiver_used_by_a_group_invite_cannot_change_its_type(): void
    {
        $spare = $this->makeTemplate('Spare waiver', WaiverTemplate::KIND_STANDARD, false);
        $manager = $this->makeUser('location_manager', $this->location, 'manager');

        $this->actingAs($manager, 'sanctum')->postJson('/api/waiver-bulk-invites', [
            'waiver_template_id' => $spare->id,
            'selected_date' => self::TODAY,
            'chaperone_name' => 'Coach Taylor',
        ])->assertSuccessful();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/waiver-templates/{$spare->id}", ['kind' => WaiverTemplate::KIND_ESCAPE_ROOM])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kind']);

        $this->assertSame(WaiverTemplate::KIND_STANDARD, $spare->fresh()->kind);
    }

    public function test_a_guest_is_asked_to_read_the_waiver_again_if_it_changed_while_they_signed(): void
    {
        $shown = $this->getJson("/api/waivers/escape-room/{$this->location->id}/rooms/{$this->morgue->id}")
            ->assertOk()
            ->json('data.template.version');

        $this->escapeWaiver->update(['body_text' => '<p>New wording for {{activity_name}}.</p>']);
        app(WaiverService::class)->syncVersion($this->escapeWaiver->fresh());

        $current = $this->getJson("/api/waivers/escape-room/{$this->location->id}/rooms/{$this->morgue->id}")
            ->assertOk()
            ->json('data.template.version');
        $this->assertNotSame($shown, $current);

        $this->sign($this->morgue, '14:00', ['waiver_template_id' => $this->escapeWaiver->id, 'waiver_template_version' => $shown])
            ->assertStatus(409)
            ->assertJsonValidationErrors(['waiver_template_version']);
        $this->assertSame(0, Waiver::count());

        $this->sign($this->morgue, '14:00', ['waiver_template_id' => $this->escapeWaiver->id, 'waiver_template_version' => $current])
            ->assertCreated();
        $this->sign($this->morgue, '14:00', ['adult_email' => 'older-page@example.test'])->assertCreated();

        $morgueOnly = $this->makeTemplate('Morgue waiver', WaiverTemplate::KIND_ESCAPE_ROOM, false, [$this->morgue->id]);
        $replacedVersion = (int) $morgueOnly->versions()->first()->version;

        $this->sign($this->morgue, '14:00', [
            'adult_email' => 'reassigned@example.test',
            'waiver_template_id' => $this->escapeWaiver->id,
            'waiver_template_version' => $replacedVersion,
        ])->assertStatus(409)->assertJsonValidationErrors(['waiver_template_version']);
        $this->assertSame(0, Waiver::where('adult_email', 'reassigned@example.test')->count());
    }

    public function test_a_room_frame_is_shown_as_in_use_for_its_room(): void
    {
        $general = $this->makeOverlay('Waterford frame');
        $morgueFrame = $this->makeOverlay('Morgue frame', $this->morgue, 90);

        $overlays = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-overlays?location_id=' . $this->location->id)
            ->assertOk()
            ->json('data.overlays'))->keyBy('id');

        $this->assertTrue($overlays[$general->id]['is_active']);
        $this->assertTrue($overlays[$morgueFrame->id]['is_active']);
        $this->assertSame('The Morgue', $overlays[$morgueFrame->id]['room_name']);

        $morgueFrame->update(['is_enabled' => false]);

        $overlays = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-overlays?location_id=' . $this->location->id)
            ->json('data.overlays'))->keyBy('id');

        $this->assertFalse($overlays[$morgueFrame->id]['is_active']);
        $this->assertTrue($overlays[$general->id]['is_active']);
    }

    public function test_library_send_and_the_staff_qr_refuse_an_escape_room_photo_before_and_after_the_send(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $outsider = $this->signed($this->morgue, '15:00', 'Outsider', 'outsider@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $photoSession = $this->withGroupPhoto($game['id']);
        $photo = $photoSession->photos()->first();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/photo-sessions/{$photoSession->id}/deliver", ['method' => 'staff_qr'])
            ->assertStatus(422);
        $this->assertNull($photoSession->fresh()->qr_expires_at);
        $this->assertNull($photoSession->fresh()->delivery_method);

        $this->complete($game['id'])->assertOk();
        $this->assertTrue($photoSession->fresh()->accessIsActive());

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/photo-library/{$photo->id}/send", ['waiver_ids' => [$outsider->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Escape-room photos are sent from Photos, Escape Rooms, so they only reach the players in that game.');

        $this->assertFalse(PhotoDelivery::where('waiver_id', $outsider->id)->exists());
        $this->assertSame(['avery@example.test'], $this->recipients());
    }

    public function test_editing_a_booking_moves_its_unsent_players_and_leaves_players_already_sent(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:00');
        $avery = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();

        $late = $this->signed($this->morgue, '14:00', 'Late', 'late@example.test');
        $this->assertSame($booking->id, $late->booking_id);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/bookings/{$booking->id}", ['booking_time' => '16:00', 'change_reason' => 'Guest asked to move'])
            ->assertOk();

        $this->assertSame('16:00', EscapeRoomSession::findOrFail($late->fresh()->escape_room_session_id)->timeKey());
        $this->assertSame($game['id'], $avery->fresh()->escape_room_session_id);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/bookings/{$booking->id}", ['package_id' => $this->airlock->id, 'change_reason' => 'Changed room'])
            ->assertOk();

        $moved = EscapeRoomSession::findOrFail($late->fresh()->escape_room_session_id);
        $this->assertSame($this->airlock->id, $moved->package_id);
        $this->assertSame('16:00', $moved->timeKey());
        $this->assertSame($game['id'], $avery->fresh()->escape_room_session_id);
    }

    public function test_a_player_already_sent_the_photo_stays_with_that_game(): void
    {
        $avery = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();

        $base = "/api/escape-rooms/sessions/{$game['id']}/waivers/{$avery->id}";
        $this->actingAs($this->attendant, 'sanctum')->postJson("{$base}/remove")->assertStatus(422);
        $this->actingAs($this->attendant, 'sanctum')->postJson("{$base}/move", ['package_id' => $this->airlock->id, 'time' => '15:00'])->assertStatus(422);
        $this->actingAs($this->attendant, 'sanctum')->postJson("{$base}/booking", ['booking_id' => null])->assertStatus(422);
        $this->assertSame($game['id'], $avery->fresh()->escape_room_session_id);

        $this->signed($this->airlock, '15:00', 'Avery', 'avery@example.test');
        $this->signed($this->airlock, '15:00', 'Other', 'other@example.test');
        $second = $this->openGame($this->airlock, '15:00');
        $this->withGroupPhoto($second['id']);
        $this->complete($second['id'])->assertOk()->assertJsonPath('data.counts.sent', 2);

        $this->assertSame(['avery@example.test', 'avery@example.test', 'other@example.test'], $this->recipients());
    }

    public function test_players_whose_booking_left_the_room_are_left_out(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:00');
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $booking->update(['package_id' => $this->party->id]);
        app(EscapeRoomSessionService::class)->followBooking($booking->fresh());

        $walkIn = $this->signed($this->morgue, '14:00', 'Walkin', 'walkin@example.test');
        $this->assertNull($walkIn->booking_id);

        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk()
            ->assertJsonPath('data.excluded_players.0.excluded_reason', EscapeRoomSessionService::EXCLUDED_BOOKING_MOVED);

        $this->assertSame(['walkin@example.test'], $this->recipients());
    }

    public function test_players_from_a_deleted_booking_or_another_location_are_left_out(): void
    {
        $booking = $this->makeBooking($this->airlock, '15:00');
        $avery = $this->signed($this->airlock, '15:00', 'Avery', 'avery@example.test');
        $this->assertSame($booking->id, $avery->booking_id);
        $booking->delete();

        $this->signed($this->airlock, '15:00', 'Blake', 'blake@example.test');
        $cory = $this->signed($this->airlock, '15:00', 'Cory', 'cory@example.test');
        $cory->forceFill(['location_id' => $this->otherLocation->id])->save();

        $game = $this->openGame($this->airlock, '15:00');
        $reasons = collect($game['excluded_players'])->pluck('excluded_reason', 'waiver_id');

        $this->assertSame(EscapeRoomSessionService::EXCLUDED_BOOKING_REMOVED, $reasons[$avery->id]);
        $this->assertSame(EscapeRoomSessionService::EXCLUDED_OTHER_LOCATION, $reasons[$cory->id]);
        $this->assertSame(1, $game['counts']['players']);
    }

    public function test_the_date_stamp_is_drawn_on_top_of_the_room_frame_in_venue_time(): void
    {
        $this->travelTo(Carbon::parse(self::TODAY . ' 23:30:00', 'America/Detroit'));
        $frame = $this->makeOverlay('Morgue frame', $this->morgue);
        $processor = $this->recordingProcessor();

        $game = $this->openGame($this->morgue, '14:00');
        $photoSession = $this->startPhoto($game['id']);
        $this->uploadGroupPhoto($photoSession->id)->assertCreated();

        $photo = $photoSession->photos()->firstOrFail();
        $this->assertSame(Photo::PROCESSING_READY, $photo->processing_status);
        $this->assertSame($frame->id, $photo->photo_overlay_id);
        $this->assertSame(
            ['overlay:' . $frame->id, 'date:Oct 3, 2026', 'overlay:' . $frame->id, 'date:Oct 3, 2026'],
            $processor->calls
        );
        Storage::disk('photos')->assertExists($photo->delivery_path);
    }

    public function test_an_uploaded_group_photo_gets_its_rooms_frame_and_other_photos_keep_the_general_one(): void
    {
        $general = $this->makeOverlay('Waterford frame', null, 100);
        $morgueFrame = $this->makeOverlay('Morgue frame', $this->morgue, 0);
        $processor = $this->recordingProcessor();

        $morgueGame = $this->openGame($this->morgue, '14:00');
        $morguePhotos = $this->startPhoto($morgueGame['id']);
        $this->uploadGroupPhoto($morguePhotos->id)->assertCreated();
        $this->assertSame($morgueFrame->id, $morguePhotos->photos()->first()->photo_overlay_id);

        $airlockGame = $this->openGame($this->airlock, '15:00');
        $airlockPhotos = $this->startPhoto($airlockGame['id']);
        $this->uploadGroupPhoto($airlockPhotos->id)->assertCreated();
        $airlockPhoto = $airlockPhotos->photos()->first();
        $this->assertSame($general->id, $airlockPhoto->photo_overlay_id);

        $plain = PhotoSession::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'source' => PhotoSession::SOURCE_STAFF,
            'status' => PhotoSession::STATUS_IN_PROGRESS,
            'created_by' => $this->attendant->id,
            'verbal_consent_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);
        $this->uploadGroupPhoto($plain->id)->assertCreated();
        $this->assertSame($general->id, $plain->photos()->first()->photo_overlay_id);
        $this->assertCount(6, array_filter($processor->calls, fn ($call) => str_starts_with($call, 'date:')));

        $this->makeOverlay('Airlock frame', $this->airlock, 50);
        $this->signed($this->airlock, '15:00', 'Avery', 'avery@example.test');
        $this->complete($airlockGame['id'])->assertOk();
        $this->assertSame($general->id, $airlockPhoto->fresh()->photo_overlay_id);
    }

    public function test_a_room_frame_beats_a_higher_priority_general_frame_and_falls_back_when_it_is_off(): void
    {
        $general = $this->makeOverlay('Waterford frame', null, 100);
        $room = $this->makeOverlay('Morgue frame', $this->morgue, 0);
        $processor = app(PhotoProcessingService::class);

        $this->assertSame($room->id, $processor->resolveOverlay($this->location, now(), $this->morgue->id)?->id);

        $room->update(['is_enabled' => false]);
        $this->assertSame($general->id, $processor->resolveOverlay($this->location, now(), $this->morgue->id)?->id);

        $room->update(['is_enabled' => true, 'starts_at' => now()->addDay()]);
        $this->assertSame($general->id, $processor->resolveOverlay($this->location, now(), $this->morgue->id)?->id);

        $room->update(['starts_at' => null, 'ends_at' => now()->subDay()]);
        $this->assertSame($general->id, $processor->resolveOverlay($this->location, now(), $this->morgue->id)?->id);
    }

    public function test_a_room_frame_must_be_one_of_this_locations_escape_rooms(): void
    {
        $post = fn ($packageId) => $this->actingAs($this->admin, 'sanctum')->post('/api/photo-overlays', [
            'location_id' => $this->location->id,
            'name' => 'Frame',
            'image' => UploadedFile::fake()->image('frame.png', 300, 200),
            'package_id' => $packageId,
        ], ['Accept' => 'application/json']);

        $post($this->party->id)->assertStatus(422)->assertJsonValidationErrors(['package_id']);
        $post($this->otherRoom->id)->assertStatus(422)->assertJsonValidationErrors(['package_id']);
        $post(999999)->assertStatus(422)->assertJsonValidationErrors(['package_id']);

        $created = $post($this->morgue->id)->assertCreated()->assertJsonPath('data.package_id', $this->morgue->id);
        $id = $created->json('data.id');

        $this->actingAs($this->admin, 'sanctum')
            ->post("/api/photo-overlays/{$id}", ['package_id' => $this->party->id], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertSame($this->morgue->id, PhotoOverlay::find($id)->package_id);

        $this->actingAs($this->admin, 'sanctum')
            ->post("/api/photo-overlays/{$id}", ['package_id' => ''], ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertNull(PhotoOverlay::find($id)->package_id);
    }

    public function test_the_group_photo_needs_consent_can_be_taken_with_the_camera_and_stops_after_the_send(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/photo-session", ['verbal_consent' => false])
            ->assertStatus(422);
        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/photo-session", [])
            ->assertStatus(422);
        $this->assertNull(EscapeRoomSession::find($game['id'])->photo_session_id);

        $photoSession = $this->startPhoto($game['id']);
        $camera = UploadedFile::fake()->image('camera.jpg', 800, 600);
        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/photo-sessions/{$photoSession->id}/photos", [
            'image' => 'data:image/jpeg;base64,' . base64_encode(file_get_contents($camera->getRealPath())),
            'source' => Photo::SOURCE_CAMERA,
        ])->assertCreated();

        $this->assertSame(Photo::SOURCE_CAMERA, $photoSession->photos()->first()->source);

        $this->complete($game['id'])->assertOk();

        $this->uploadGroupPhoto($photoSession->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This game is complete and its photo has already been sent, so no more photos can be added.');
        $this->assertSame(1, $photoSession->photos()->count());
    }

    public function test_a_game_holds_at_most_three_photos_and_a_failed_photo_does_not_count(): void
    {
        $game = $this->openGame($this->airlock, '15:00');
        $photoSession = $this->startPhoto($game['id']);

        $this->uploadGroupPhoto($photoSession->id)->assertCreated();
        $this->uploadGroupPhoto($photoSession->id)->assertCreated();
        $this->uploadGroupPhoto($photoSession->id)->assertCreated();
        $this->uploadGroupPhoto($photoSession->id)->assertStatus(422);
        $this->assertSame(3, $photoSession->photos()->count());

        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $failedGame = $this->openGame($this->morgue, '14:00');
        $failed = $this->withGroupPhoto($failedGame['id']);
        $failed->photos()->update(['processing_status' => Photo::PROCESSING_FAILED]);

        $this->complete($failedGame['id'])->assertStatus(422)->assertJsonPath('message', 'Take or upload the group photo first.');
        $this->assertContains('Take or upload the group photo.', $this->gameDetail($failedGame['id'])['blockers']);
    }

    public function test_complete_says_exactly_what_is_missing(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->complete($game['id'])->assertStatus(422)->assertJsonPath('message', 'Take or upload the group photo first.');

        $empty = $this->openGame($this->airlock, '15:00');
        $this->withGroupPhoto($empty['id']);
        $this->complete($empty['id'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nobody has signed a waiver for this game yet, so there is no one to send the photo to.');

        $this->assertNull(EscapeRoomSession::find($game['id'])->completed_at);
        $this->assertNull(EscapeRoomSession::find($empty['id'])->completed_at);
    }

    public function test_other_standard_tools_refuse_the_escape_room_waiver(): void
    {
        $manager = $this->makeUser('location_manager', $this->location, 'manager');

        $this->actingAs($manager, 'sanctum')->postJson('/api/waiver-bulk-invites', [
            'waiver_template_id' => $this->escapeWaiver->id,
            'selected_date' => self::TODAY,
            'chaperone_name' => 'Coach Taylor',
        ])->assertStatus(422)->assertJsonValidationErrors(['waiver_template_id']);
        $this->assertSame(0, WaiverBulkInvite::count());

        config(['waivers.returning_enabled' => true]);
        $this->postJson("/api/waivers/kiosk/{$this->escapeWaiver->id}/lookup", ['phone' => '2485550142', 'last_name' => 'Player'])
            ->assertNotFound();

        $kiosk = $this->actingAs($this->attendant, 'sanctum')->postJson('/api/waivers/kiosk-session', [
            'source_type' => 'package',
            'source_id' => $this->party->id,
            'template_id' => $this->escapeWaiver->id,
        ])->assertOk();
        $this->assertSame($this->general->id, Waiver::where('access_token', $kiosk->json('data.access_token'))->value('waiver_template_id'));

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/waiver-templates', ['title' => 'Odd waiver', 'body_text' => '<p>x</p>', 'kind' => 'escape'])
            ->assertStatus(422);

        $listed = $this->actingAs($this->admin, 'sanctum')->getJson('/api/waiver-templates?kind=escape_room')->assertOk();
        $this->assertSame([$this->escapeWaiver->id], collect($listed->json('data.waiver_templates'))->pluck('id')->all());

        $packages = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/waiver-templates/available-activities?type=package&kind=escape_room')
            ->assertOk()
            ->json('data.available'))->pluck('id');
        $this->assertContains($this->airlock->id, $packages);
        $this->assertNotContains($this->party->id, $packages);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/waiver-templates/available-activities?type=attraction&kind=escape_room')
            ->assertOk()
            ->assertJsonPath('data.available', []);
    }

    public function test_the_escape_room_page_never_falls_back_to_the_general_waiver(): void
    {
        $this->escapeWaiver->update(['status' => WaiverTemplate::STATUS_DRAFT]);

        $this->getJson("/api/waivers/escape-room/{$this->location->id}")->assertOk()->assertJsonCount(0, 'data.rooms');
        $this->getJson("/api/waivers/escape-room/{$this->location->id}/rooms/{$this->morgue->id}")->assertNotFound();
        $this->sign($this->morgue, '14:00')->assertStatus(422)->assertJsonValidationErrors(['package_id']);
        $this->assertSame(0, Waiver::count());

        $this->escapeWaiver->update([
            'status' => WaiverTemplate::STATUS_ACTIVE,
            'is_default' => false,
            'assigned_package_ids' => [$this->airlock->id],
        ]);

        $rooms = collect($this->getJson("/api/waivers/escape-room/{$this->location->id}")->assertOk()->json('data.rooms'));
        $this->assertSame(['Airlock Escape'], $rooms->pluck('name')->all());

        foreach ([$this->morgue, $this->party, $this->otherRoom] as $room) {
            $this->getJson("/api/waivers/escape-room/{$this->location->id}/rooms/{$room->id}")->assertNotFound();
        }

        $form = $this->getJson("/api/waivers/escape-room/{$this->location->id}/rooms/{$this->airlock->id}")->assertOk();
        $this->assertSame($this->escapeWaiver->id, $form->json('data.template.id'));
        $this->assertStringContainsString('Airlock Escape', $form->json('data.body'));
    }

    public function test_creating_an_escape_room_waiver_refuses_a_package_that_is_not_an_escape_room(): void
    {
        $arcade = $this->makePackage('Arcade Party', $this->location, false);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/waiver-templates', [
            'title' => 'Bad escape waiver',
            'body_text' => '<p>Text</p>',
            'kind' => WaiverTemplate::KIND_ESCAPE_ROOM,
            'assigned_package_ids' => [$arcade->id],
        ])->assertStatus(422)->assertJsonPath('errors.assigned_package_ids.0', $arcade->id);

        $created = $this->actingAs($this->admin, 'sanctum')->postJson('/api/waiver-templates', [
            'title' => 'Airlock waiver',
            'body_text' => '<p>Text</p>',
            'kind' => WaiverTemplate::KIND_ESCAPE_ROOM,
            'assigned_package_ids' => [$this->airlock->id],
            'assigned_attraction_ids' => [12345],
            'assigned_event_ids' => [777],
        ])->assertCreated();

        $template = WaiverTemplate::findOrFail($created->json('data.id'));
        $this->assertSame(WaiverTemplate::KIND_ESCAPE_ROOM, $template->kind);
        $this->assertSame([$this->airlock->id], array_map('intval', $template->assigned_package_ids));
        $this->assertEmpty($template->assigned_attraction_ids ?? []);
        $this->assertEmpty($template->assigned_event_ids ?? []);
    }

    public function test_a_standard_booking_waiver_still_signs_and_keeps_its_duplicate_rule(): void
    {
        $first = app(WaiverService::class)->ensureForBooking($this->makeBooking($this->party, '15:00'));
        $second = app(WaiverService::class)->ensureForBooking($this->makeBooking($this->party, '17:00'));

        $this->signViaLink($first)->assertOk();
        $this->assertSame($this->general->id, $first->fresh()->waiver_template_id);
        $this->assertNull($first->fresh()->escape_room_session_id);

        $this->signViaLink($second)->assertStatus(409);
        $this->assertSame(Waiver::STATUS_PENDING, $second->fresh()->status);

        $roomOne = app(WaiverService::class)->ensureForBooking($this->makeBooking($this->morgue, '15:00'));
        $roomTwo = app(WaiverService::class)->ensureForBooking($this->makeBooking($this->airlock, '16:00'));
        $this->signViaLink($roomOne)->assertOk();
        $this->signViaLink($roomTwo)->assertOk();
    }

    public function test_a_standard_photo_email_is_unchanged(): void
    {
        $session = PhotoSession::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'source' => PhotoSession::SOURCE_STAFF,
            'status' => PhotoSession::STATUS_READY,
            'created_by' => $this->attendant->id,
            'verbal_consent_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);
        $session->startQrWindow();
        $session->save();
        Storage::disk('photos')->put('std/delivery.jpg', 'standard-bytes');
        Photo::create([
            'photo_session_id' => $session->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'position' => 1,
            'source' => Photo::SOURCE_CAMERA,
            'processing_status' => Photo::PROCESSING_READY,
            'original_path' => 'std/original.jpg',
            'delivery_path' => 'std/delivery.jpg',
            'slideshow_path' => 'std/slideshow.jpg',
            'thumbnail_path' => 'std/thumb.jpg',
            'captured_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);

        $delivery = PhotoDelivery::create([
            'photo_session_id' => $session->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'kind' => PhotoDelivery::KIND_IMMEDIATE,
            'channel' => PhotoDelivery::CHANNEL_EMAIL,
            'destination' => 'std@example.test',
            'recipient_name' => 'Standard Guest',
            'status' => PhotoDelivery::STATUS_QUEUED,
        ]);

        $this->assertTrue(app(PhotoDeliveryService::class)->send($delivery));

        $email = collect($this->allEmails())->first(fn ($message) => $message->getTo()[0]->getAddress() === 'std@example.test');
        $this->assertNotNull($email);
        $this->assertCount(0, $email->getAttachments());
        $this->assertStringContainsString('https://zapzone.test/photos/' . $session->access_token, $email->getHtmlBody());
        $this->assertStringNotContainsString('Your group escaped', $email->getHtmlBody());
        $this->assertStringNotContainsString('group photo is attached', $email->getHtmlBody());
        $this->assertSame([], $this->sentPhotoEmails());
    }

    public function test_booking_emails_and_lookups_use_the_bookers_waiver_not_a_players_sign_in(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:00');
        $own = app(WaiverService::class)->ensureForBooking($booking);
        $player = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $this->assertSame($booking->id, $player->booking_id);

        $this->assertSame($own->id, app(WaiverService::class)->ensureForBooking($booking->fresh())->id);

        $variables = app(EmailNotificationService::class)->buildVariables($booking->fresh(), 'booking', false);
        $this->assertStringContainsString($own->access_token, (string) $variables['waiver_link']);
        $this->assertStringNotContainsString($player->access_token, (string) $variables['waiver_link']);
    }

    public function test_removing_or_moving_a_sign_in_drops_its_booking_link(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:00');
        $stranger = $this->signed($this->morgue, '14:00', 'Stranger', 'stranger@example.test');
        $this->assertSame($booking->id, $stranger->booking_id);
        $game = $this->openGame($this->morgue, '14:00');

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/waivers/{$stranger->id}/remove")
            ->assertOk();
        $this->assertNull($stranger->fresh()->booking_id);
        $this->assertNull($stranger->fresh()->escape_room_session_id);

        $kiosk = $this->actingAs($this->attendant, 'sanctum')->postJson('/api/waivers/kiosk-session', [
            'source_type' => 'booking',
            'source_id' => $booking->id,
        ])->assertOk();
        $this->assertNotSame($stranger->access_token, $kiosk->json('data.access_token'));

        $later = $this->makeBooking($this->airlock, '15:00');
        $mover = $this->signed($this->morgue, '14:00', 'Mover', 'mover@example.test');
        $this->assertSame($booking->id, $mover->booking_id);

        $target = $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/waivers/{$mover->id}/move", ['package_id' => $this->airlock->id, 'time' => '15:00'])
            ->assertOk();

        $this->assertSame($later->id, $mover->fresh()->booking_id);
        $moved = $this->gameDetail($mover->fresh()->escape_room_session_id);
        $this->assertSame(1, $moved['counts']['players']);
        $this->assertSame(0, $moved['counts']['excluded']);
        $this->assertSame(0, $target->json('data.counts.players'));
    }

    public function test_staff_can_link_a_sign_in_only_to_one_of_its_games_bookings(): void
    {
        $familyA = $this->makeBooking($this->airlock, '15:00', 'Family A');
        $this->makeBooking($this->airlock, '15:00', 'Family B');
        $later = $this->makeBooking($this->airlock, '16:00', 'Later');
        $gone = $this->makeBooking($this->airlock, '15:00', 'Gone', 'cancelled');

        $avery = $this->signed($this->airlock, '15:00', 'Avery', 'avery@example.test');
        $this->assertNull($avery->booking_id);
        $game = $this->openGame($this->airlock, '15:00');
        $link = fn ($bookingId) => $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/waivers/{$avery->id}/booking", ['booking_id' => $bookingId]);

        $link($familyA->id)->assertOk()->assertJsonPath('data.players.0.booking_id', $familyA->id);
        $link($later->id)->assertStatus(422);
        $link($gone->id)->assertStatus(422);
        $this->assertSame($familyA->id, $avery->fresh()->booking_id);

        $link(null)->assertOk();
        $this->assertNull($avery->fresh()->booking_id);
    }

    public function test_a_cancelled_booking_is_never_linked_to_a_new_sign_in(): void
    {
        $this->makeBooking($this->morgue, '14:00', 'Gone', 'cancelled');
        $live = $this->makeBooking($this->morgue, '14:00', 'Live');
        $this->assertSame($live->id, $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test')->booking_id);

        $this->makeBooking($this->airlock, '15:00', 'Gone Too', 'cancelled');
        $blake = $this->signed($this->airlock, '15:00', 'Blake', 'blake@example.test');
        $this->assertNull($blake->booking_id);

        $game = $this->openGame($this->airlock, '15:00');
        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk()->assertJsonPath('data.counts.excluded', 0);
        $this->assertSame(['blake@example.test'], $this->recipients());
    }

    public function test_the_package_api_round_trips_the_escape_room_switch(): void
    {
        $this->getJson("/api/packages/{$this->morgue->id}")->assertOk()->assertJsonPath('data.is_escape_room', true);
        $this->getJson("/api/packages/{$this->party->id}")->assertOk()->assertJsonPath('data.is_escape_room', false);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/packages/{$this->morgue->id}", ['name' => 'The Morgue (renamed)'])
            ->assertOk();
        $this->assertTrue((bool) $this->morgue->fresh()->is_escape_room);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/packages/{$this->party->id}", ['is_escape_room' => true])
            ->assertOk();
        $this->assertTrue((bool) $this->party->fresh()->is_escape_room);
    }

    public function test_a_signed_standard_waiver_still_saves_from_the_builder(): void
    {
        $pending = app(WaiverService::class)->ensureForBooking($this->makeBooking($this->party, '15:00'));
        $this->signViaLink($pending)->assertOk();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/waiver-templates/{$this->general->id}", [
            'title' => 'General (edited)',
            'kind' => WaiverTemplate::KIND_STANDARD,
            'assigned_package_ids' => [$this->party->id, $this->morgue->id],
        ])->assertOk();

        $this->assertSame('General (edited)', $this->general->fresh()->title);
        $this->assertSame(WaiverTemplate::KIND_STANDARD, $this->general->fresh()->kind);
    }

    public function test_code_deployed_before_the_migrations_keeps_the_standard_flow(): void
    {
        $this->escapeWaiver->update(['status' => WaiverTemplate::STATUS_DRAFT, 'is_default' => false]);
        $booking = $this->makeBooking($this->morgue, '15:00');
        $columns = new \ReflectionProperty(SchemaSupport::class, 'columns');
        $tables = new \ReflectionProperty(SchemaSupport::class, 'tables');

        try {
            $columns->setValue(null, [
                'packages.is_escape_room' => false,
                'waiver_templates.kind' => false,
                'waivers.escape_room_session_id' => false,
                'photo_overlays.package_id' => false,
            ]);
            $tables->setValue(null, ['escape_room_sessions' => false]);

            $this->assertFalse(app(EscapeRoomSessionService::class)->isEnabled());
            $this->assertSame($this->general->id, app(WaiverService::class)->ensureForBooking($booking)->waiver_template_id);
            $this->getJson("/api/waivers/escape-room/{$this->location->id}")->assertNotFound();

            $this->actingAs($this->attendant, 'sanctum')->postJson('/api/waivers/kiosk-session', [
                'source_type' => 'package',
                'source_id' => $this->morgue->id,
            ])->assertOk();

            $this->assertNull(app(PhotoProcessingService::class)->resolveOverlay($this->location));

            $package = Package::create([
                'location_id' => $this->location->id,
                'name' => 'New room before migrate',
                'description' => 'Test package',
                'category' => 'Adventure',
                'price' => 30,
                'pricing_type' => 'base',
                'duration' => 60,
                'duration_unit' => 'minutes',
                'is_active' => true,
                'is_escape_room' => true,
            ]);
            $this->assertNotNull($package->id);

            $this->actingAs($this->admin, 'sanctum')->postJson('/api/waiver-templates', [
                'title' => 'Escape waiver too early',
                'body_text' => '<p>Text</p>',
                'kind' => WaiverTemplate::KIND_ESCAPE_ROOM,
            ])->assertStatus(422)->assertJsonValidationErrors(['kind']);

            $this->actingAs($this->admin, 'sanctum')->postJson('/api/waiver-templates', [
                'title' => 'Standard waiver still fine',
                'body_text' => '<p>Text</p>',
                'kind' => WaiverTemplate::KIND_STANDARD,
            ])->assertCreated();
        } finally {
            SchemaSupport::flush();
        }

        $this->assertFalse((bool) Package::where('name', 'New room before migrate')->value('is_escape_room'));
    }

    public function test_the_email_links_to_this_games_photo_page_and_attaches_the_photo(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $photoSession = $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();
        $photoSession->refresh();

        $email = $this->sentPhotoEmails()[0];
        $html = $email->getHtmlBody();
        $this->assertStringContainsString('https://zapzone.test/photos/' . $photoSession->access_token, $html);
        $this->assertStringContainsString($photoSession->access_expires_at->copy()->setTimezone('America/Detroit')->format('M j, Y'), $html);

        $attachment = $email->getAttachments()[0];
        $this->assertSame('image/jpeg', $attachment->getContentType());
        $this->assertSame('the-morgue-photo-1.jpg', $attachment->getFilename());
        $this->assertSame('fake-jpeg-bytes', $attachment->getBody());

        $page = $this->getJson("/api/photos/access/{$photoSession->access_token}")->assertOk();
        $this->assertSame(
            $photoSession->photos()->pluck('id')->all(),
            collect($page->json('data.photos'))->pluck('id')->all()
        );

        $this->travel(31)->days();
        $this->getJson("/api/photos/access/{$photoSession->access_token}")->assertStatus(410);
    }

    public function test_signing_after_the_photo_is_taken_still_sends_nothing(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:00');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);

        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $pending = app(WaiverService::class)->ensureForBooking($booking);
        $this->signViaLink($pending, ['adult_email' => 'booker@example.test'])->assertOk();

        $this->artisan('photos:send-scheduled')->assertExitCode(0);

        $this->assertSame(0, PhotoDelivery::count());
        $this->assertSame([], $this->sentPhotoEmails());
        $this->assertNull(EscapeRoomSession::find($game['id'])->completed_at);
        $this->assertSame(2, $this->gameDetail($game['id'])['counts']['players']);
    }

    public function test_a_guest_can_still_sign_for_a_game_that_ended_within_the_last_hour(): void
    {
        $this->assertNotContains('12:00', $this->guestTimesFor('The Morgue'));

        $response = $this->sign($this->morgue, '12:00')->assertCreated();
        $this->assertSame('12:00', EscapeRoomSession::findOrFail(Waiver::find($response->json('data.id'))->escape_room_session_id)->timeKey());

        $this->sign($this->morgue, '11:00')->assertStatus(422)->assertJsonValidationErrors(['session_time']);
    }

    public function test_a_booked_time_off_the_schedule_is_a_game_too(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:30');

        $slots = $this->daySlots('The Morgue');
        $this->assertCount(10, $slots);
        $this->assertSame($booking->id, $slots['14:30']['bookings'][0]['id']);
        $this->assertContains('14:30', $this->guestTimesFor('The Morgue'));

        $response = $this->sign($this->morgue, '14:30:00')->assertCreated();
        $waiver = Waiver::findOrFail($response->json('data.id'));
        $this->assertSame($booking->id, $waiver->booking_id);
        $this->assertSame('14:30', EscapeRoomSession::findOrFail($waiver->escape_room_session_id)->timeKey());

        $this->openGame($this->morgue, '14:30');
        $this->actingAs($this->attendant, 'sanctum')->postJson('/api/escape-rooms/sessions', [
            'location_id' => $this->location->id,
            'package_id' => $this->morgue->id,
            'date' => self::TODAY,
            'time' => '14:45',
        ])->assertStatus(422);

        PackageAvailabilitySchedule::where('package_id', $this->airlock->id)->delete();
        $this->makeBooking($this->airlock, '15:30');
        $this->assertSame(['15:30'], $this->daySlots('Airlock Escape')->keys()->all());

        $this->airlock->update(['is_active' => false]);
        $this->assertSame(['15:30'], $this->daySlots('Airlock Escape')->keys()->all());
        $this->assertNotContains('Airlock Escape', collect($this->getJson("/api/waivers/escape-room/{$this->location->id}")->json('data.rooms'))->pluck('name')->all());
        $this->sign($this->airlock, '15:30')->assertStatus(422)->assertJsonValidationErrors(['package_id']);
    }

    public function test_closures_hide_overlapping_games_including_recurring_and_location_wide_ones(): void
    {
        DayOff::create([
            'location_id' => $this->location->id,
            'date' => self::TODAY,
            'time_start' => '16:30',
            'time_end' => '17:30',
            'reason' => 'Staff meeting',
        ]);
        DayOff::create([
            'location_id' => $this->location->id,
            'date' => '2025-10-03',
            'is_recurring' => true,
            'reason' => 'Anniversary deep clean',
            'package_ids' => [$this->airlock->id],
        ]);
        DayOff::create([
            'location_id' => $this->location->id,
            'date' => self::TODAY,
            'time_start' => '11:00',
            'time_end' => '13:00',
            'reason' => 'Party room only',
            'room_ids' => [987654],
        ]);
        DayOff::create([
            'location_id' => $this->location->id,
            'date' => '2026-10-04',
            'reason' => 'Tomorrow only',
            'package_ids' => [$this->morgue->id],
        ]);

        $service = app(EscapeRoomSessionService::class);

        $this->assertSame(['11:00', '12:00', '13:00', '14:00', '15:00', '18:00', '19:00'], $service->scheduledTimes($this->morgue->fresh(), self::TODAY));
        $this->assertSame([], $service->scheduledTimes($this->airlock->fresh(), self::TODAY));
        $this->assertSame([], $service->scheduledTimes($this->morgue->fresh(), '2026-10-04'));
    }

    public function test_the_escape_room_sign_in_completes_the_waiver_the_normal_way(): void
    {
        $signedNotices = [];
        $this->partialMock(EmailNotificationService::class, function ($mock) use (&$signedNotices) {
            $mock->shouldReceive('triggerWaiverNotification')->andReturnUsing(function (Waiver $waiver, string $trigger) use (&$signedNotices) {
                $signedNotices[] = [$waiver->id, $trigger];
            });
        });

        $response = $this->sign($this->morgue, '14:00', [
            'minors' => [[
                'first_name' => 'Kid',
                'last_name' => 'Player',
                'date_of_birth' => '2016-04-04',
                'relationship' => 'Child',
            ]],
        ])->assertCreated();

        $waiver = Waiver::with('minors')->findOrFail($response->json('data.id'));
        $this->assertCount(1, $waiver->minors);
        $this->assertSame('Casey Player', $waiver->typed_legal_name);
        $this->assertNotNull($waiver->submitted_at);
        $this->assertNotNull($waiver->pdf_path);
        $this->assertTrue($waiver->auditEvents()->exists());

        $this->sign($this->morgue, '14:00', ['adult_dob' => '2015-01-01'])->assertStatus(422)->assertJsonValidationErrors(['adult_dob']);
        $this->sign($this->morgue, '14:00', ['adult_phone' => '123'])->assertStatus(422);
        $this->assertSame(1, Waiver::count());
        $this->assertSame([[$waiver->id, \App\Models\EmailNotification::TRIGGER_WAIVER_SIGNED]], $signedNotices);
    }

    public function test_the_day_view_shows_each_games_progress_and_other_days(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $sent = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($sent['id']);
        $this->complete($sent['id'])->assertOk();

        $ready = $this->openGame($this->morgue, '15:00');
        $this->withGroupPhoto($ready['id']);

        $slots = $this->daySlots('The Morgue');
        $this->assertSame('sent', $slots['14:00']['status']);
        $this->assertSame('47:12', $slots['14:00']['completion_label']);
        $this->assertSame(1, $slots['14:00']['sent']);
        $this->assertSame('photo_ready', $slots['15:00']['status']);
        $this->assertSame(1, $slots['15:00']['photos']);
        $this->assertSame('waiting', $slots['16:00']['status']);
        $this->assertTrue($slots['12:00']['is_past']);
        $this->assertFalse($slots['16:00']['is_past']);

        $tomorrow = $this->dayData('2026-10-04');
        $this->assertSame('2026-10-04', $tomorrow['date']);
        $this->assertFalse($tomorrow['is_today']);
        $this->assertSame(self::TODAY, $tomorrow['today']);
        $this->assertTrue(collect(collect($tomorrow['rooms'])->firstWhere('name', 'The Morgue')['slots'])->every(fn ($slot) => $slot['session_id'] === null));

        $this->actingAs($this->attendant, 'sanctum')->postJson('/api/escape-rooms/sessions', [
            'location_id' => $this->location->id,
            'package_id' => $this->morgue->id,
            'date' => '2026-10-04',
            'time' => '14:00',
        ])->assertCreated()->assertJsonPath('data.session_date', '2026-10-04');
    }

    public function test_opening_a_game_only_accepts_this_locations_rooms_and_staff(): void
    {
        $open = fn (Package $room, string $time, ?User $as = null) => $this->actingAs($as ?? $this->attendant, 'sanctum')
            ->postJson('/api/escape-rooms/sessions', [
                'location_id' => $this->location->id,
                'package_id' => $room->id,
                'date' => self::TODAY,
                'time' => $time,
            ]);

        $open($this->party, '14:00')->assertStatus(422);
        $open($this->otherRoom, '14:00')->assertStatus(422);
        $open($this->morgue, '14:45')->assertStatus(422);

        $avery = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $base = "/api/escape-rooms/sessions/{$game['id']}";

        $open($this->morgue, '14:00', $this->otherAttendant)->assertForbidden();
        $this->actingAs($this->otherAttendant, 'sanctum')->getJson('/api/escape-rooms/rooms?location_id=' . $this->location->id)->assertForbidden();
        $this->actingAs($this->otherAttendant, 'sanctum')->postJson("{$base}/photo-session", ['verbal_consent' => true])->assertForbidden();
        $this->actingAs($this->otherAttendant, 'sanctum')->postJson("{$base}/send-new")->assertForbidden();
        $this->actingAs($this->otherAttendant, 'sanctum')->postJson("{$base}/waivers/{$avery->id}/move", ['package_id' => $this->airlock->id, 'time' => '15:00'])->assertForbidden();
        $this->actingAs($this->otherAttendant, 'sanctum')->postJson("{$base}/waivers/{$avery->id}/booking", ['booking_id' => null])->assertForbidden();

        $otherCompany = Company::create([
            'company_name' => 'Other Company',
            'email' => 'other@company.test',
            'phone' => '5550000000',
            'address' => '9 Elsewhere',
        ]);
        $outsider = User::create([
            'first_name' => 'Out',
            'last_name' => 'Sider',
            'email' => 'outsider@company.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $otherCompany->id,
        ]);
        $this->actingAs($outsider, 'sanctum')->getJson($base)->assertForbidden();
        $this->actingAs($outsider, 'sanctum')->getJson('/api/escape-rooms/day?location_id=' . $this->location->id)->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson("{$base}/complete", ['escaped' => true, 'completion_time' => '40:00'])->assertUnauthorized();
        $this->postJson("{$base}/send-new")->assertUnauthorized();

        $this->assertSame($game['id'], $avery->fresh()->escape_room_session_id);
        $this->assertSame([], $this->recipients());
    }

    public function test_complete_is_refused_when_email_is_off_or_nobody_has_an_email(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);

        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/escape-rooms/sessions/{$game['id']}/send-new")->assertStatus(422);

        config(['mail.default' => 'log']);
        $detail = $this->gameDetail($game['id']);
        $this->assertFalse($detail['can_complete']);
        $this->assertContains('Email is not switched on for this site yet.', $detail['blockers']);
        $this->complete($game['id'])->assertStatus(422);

        config(['mail.default' => 'array']);
        Waiver::query()->update(['adult_email' => null]);
        $this->complete($game['id'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'None of the players in this game has an email address on their waiver, so the photo cannot be emailed.');

        $this->assertNull(EscapeRoomSession::find($game['id'])->completed_at);
        $this->assertSame(0, PhotoDelivery::count());
    }

    public function test_a_game_completed_from_another_screen_is_not_completed_twice(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $stale = EscapeRoomSession::findOrFail($game['id']);

        $this->complete($game['id'])->assertOk();

        try {
            app(EscapeRoomSessionService::class)->complete($stale, true, 100, $this->attendant);
            $this->fail('A stale screen completed the game a second time.');
        } catch (EscapeRoomException $e) {
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(2832, EscapeRoomSession::find($game['id'])->completion_seconds);
        $this->assertCount(1, $this->sentPhotoEmails());
    }

    public function test_send_to_new_players_is_refused_after_the_photo_link_expires(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $photoSession = $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();
        $this->signed($this->morgue, '14:00', 'Late', 'late@example.test');

        $photoSession->refresh()->forceFill(['access_expires_at' => now()->subMinute()])->save();

        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/escape-rooms/sessions/{$game['id']}/send-new")->assertStatus(422);
        $this->assertSame(['avery@example.test'], $this->recipients());
    }

    public function test_an_address_already_sent_is_not_emailed_again_and_a_fresh_send_is_not_stuck(): void
    {
        $this->signed($this->morgue, '14:00', 'Parent', 'family@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $photoSession = $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();

        $this->signed($this->morgue, '14:00', 'Other', 'family@example.test');
        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/escape-rooms/sessions/{$game['id']}/send-new")->assertOk();
        $this->assertSame(['family@example.test'], $this->recipients());

        PhotoDelivery::create([
            'photo_session_id' => $photoSession->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'kind' => PhotoDelivery::KIND_ESCAPE_ROOM,
            'channel' => PhotoDelivery::CHANNEL_EMAIL,
            'destination' => 'fresh@example.test',
            'recipient_name' => 'Fresh Player',
            'status' => PhotoDelivery::STATUS_QUEUED,
        ]);
        $this->travel(1)->minutes();

        $detail = $this->gameDetail($game['id']);
        $this->assertSame(0, $detail['counts']['stuck']);
        $this->assertSame(1, $detail['counts']['sending']);
    }

    public function test_an_email_that_ran_out_of_retries_is_shown_as_not_delivered(): void
    {
        $avery = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $blake = $this->signed($this->morgue, '14:00', 'Blake', 'blake@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();

        PhotoDelivery::where('waiver_id', $avery->id)->update(['status' => PhotoDelivery::STATUS_FAILED, 'attempts' => PhotoDelivery::MAX_ATTEMPTS]);
        PhotoDelivery::where('waiver_id', $blake->id)->update(['status' => PhotoDelivery::STATUS_FAILED, 'attempts' => 1]);

        $detail = $this->gameDetail($game['id']);
        $players = collect($detail['players'])->keyBy('waiver_id');

        $this->assertSame(1, $detail['counts']['failed']);
        $this->assertSame(1, $detail['counts']['retrying']);
        $this->assertTrue($players[$avery->id]['delivery']['gave_up']);
        $this->assertFalse($players[$blake->id]['delivery']['gave_up']);
    }

    public function test_guest_input_edge_cases(): void
    {
        $submit = "/api/waivers/escape-room/{$this->location->id}/submit";

        $this->postJson($submit, $this->signPayload() + ['package_id' => $this->morgue->id])->assertStatus(422)->assertJsonValidationErrors(['session_time']);
        $this->postJson($submit, $this->signPayload() + ['session_time' => '14:00'])->assertStatus(422)->assertJsonValidationErrors(['package_id']);
        $this->getJson('/api/waivers/escape-room/999999')->assertNotFound();
        $this->postJson('/api/waivers/escape-room/999999/submit', $this->signPayload() + ['package_id' => $this->morgue->id, 'session_time' => '14:00'])->assertNotFound();
        $this->assertSame(0, Waiver::count());

        $this->travelTo(Carbon::parse(self::TODAY . ' 08:30:00', 'America/Detroit'));
        PackageAvailabilitySchedule::where('package_id', $this->morgue->id)->update(['time_slot_start' => '09:00']);
        $booking = $this->makeBooking($this->morgue, '09:00');

        $first = Waiver::findOrFail($this->sign($this->morgue, '9:00')->assertCreated()->json('data.id'));
        $second = Waiver::findOrFail($this->sign($this->morgue, '09:00:00', ['adult_email' => 'second@example.test'])->assertCreated()->json('data.id'));

        $this->assertSame($first->escape_room_session_id, $second->escape_room_session_id);
        $this->assertSame('09:00', EscapeRoomSession::findOrFail($first->escape_room_session_id)->timeKey());
        $this->assertSame($booking->id, $first->booking_id);
        $this->assertSame($booking->id, $second->booking_id);

        $slot = $this->daySlots('The Morgue')['09:00'];
        $this->assertSame(2, $slot['signed']);
        $this->assertSame($booking->id, $slot['bookings'][0]['id']);
    }

    public function test_the_public_escape_room_routes_keep_their_rate_limits(): void
    {
        $routes = app('router')->getRoutes();
        $kiosk = $routes->match(Request::create("/api/waivers/escape-room/{$this->location->id}", 'GET'));
        $form = $routes->match(Request::create("/api/waivers/escape-room/{$this->location->id}/rooms/{$this->morgue->id}", 'GET'));
        $submit = $routes->match(Request::create("/api/waivers/escape-room/{$this->location->id}/submit", 'POST'));

        $this->assertContains('throttle:escape-room-kiosk', $kiosk->gatherMiddleware());
        $this->assertContains('throttle:escape-room-kiosk', $form->gatherMiddleware());
        $this->assertContains('throttle:escape-room-submit', $submit->gatherMiddleware());
        $this->assertNotNull(RateLimiter::limiter('escape-room-kiosk'));
        $this->assertNotNull(RateLimiter::limiter('escape-room-submit'));
    }

    public function test_player_names_are_escaped_and_the_booker_and_walk_ins_share_one_game(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:00');
        $pending = app(WaiverService::class)->ensureForBooking($booking);
        $this->signViaLink($pending, [
            'adult_first_name' => '<b>Bo</b>',
            'adult_email' => 'booker@example.test',
            'typed_legal_name' => '<b>Bo</b> Player',
        ])->assertOk();

        $walkIn = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $this->assertSame($pending->fresh()->escape_room_session_id, $walkIn->escape_room_session_id);

        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();

        $this->assertSame(['avery@example.test', 'booker@example.test'], $this->recipients());
        $html = collect($this->sentPhotoEmails())->first(fn ($message) => $message->getTo()[0]->getAddress() === 'booker@example.test')->getHtmlBody();
        $this->assertStringContainsString('&lt;b&gt;Bo&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Bo</b>', $html);
    }

    public function test_finish_times_are_read_the_way_staff_type_them(): void
    {
        $service = app(EscapeRoomSessionService::class);

        $this->assertSame(2832, $service->parseCompletionTime('47:12'));
        $this->assertSame(2825, $service->parseCompletionTime('47:05'));
        $this->assertSame(2820, $service->parseCompletionTime('47'));
        $this->assertSame(3725, $service->parseCompletionTime('1:02:05'));
        $this->assertSame(36000, $service->parseCompletionTime('10:00:00'));
        $this->assertNull($service->parseCompletionTime('0:00'));
        $this->assertNull($service->parseCompletionTime('47:60'));
        $this->assertNull($service->parseCompletionTime('10:00:01'));
        $this->assertNull($service->parseCompletionTime('forty'));
        $this->assertNull($service->parseCompletionTime(''));

        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'], true, '47:05')->assertOk()->assertJsonPath('data.completion_label', '47:05');
        $this->assertStringContainsString('47:05', $this->sentPhotoEmails()[0]->getHtmlBody());
    }

    public function test_two_guests_opening_a_new_game_at_the_same_moment_share_it(): void
    {
        $raced = false;
        EscapeRoomSession::creating(function (EscapeRoomSession $session) use (&$raced) {
            if ($raced) {
                return;
            }
            $raced = true;
            \Illuminate\Support\Facades\DB::table('escape_room_sessions')->insert([
                'company_id' => $session->company_id,
                'location_id' => $session->location_id,
                'package_id' => $session->package_id,
                'session_date' => $session->session_date instanceof \DateTimeInterface ? $session->session_date->format('Y-m-d') : $session->session_date,
                'session_time' => $session->session_time,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $response = $this->sign($this->morgue, '14:00')->assertCreated();

        $this->assertTrue($raced);
        $this->assertSame(1, EscapeRoomSession::count());
        $this->assertSame(EscapeRoomSession::value('id'), Waiver::findOrFail($response->json('data.id'))->escape_room_session_id);
    }

    public function test_a_booking_waiver_signed_after_its_booking_was_cancelled_joins_no_game(): void
    {
        $booking = $this->makeBooking($this->morgue, '16:00');
        $pending = app(WaiverService::class)->ensureForBooking($booking);
        $booking->update(['status' => 'cancelled']);

        $this->signViaLink($pending)->assertOk();

        $this->assertNull($pending->fresh()->escape_room_session_id);
        $this->assertSame(0, EscapeRoomSession::count());
    }

    public function test_starting_the_photo_again_keeps_the_same_photos(): void
    {
        $game = $this->openGame($this->morgue, '14:00');
        $first = $this->withGroupPhoto($game['id']);
        $second = $this->startPhoto($game['id']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PhotoSession::count());
        $this->assertSame(1, $second->photos()->count());
    }

    public function test_complete_checks_the_groups_photo_consent_again(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $photoSession = $this->withGroupPhoto($game['id']);
        PhotoSession::whereKey($photoSession->id)->update(['verbal_consent_at' => null]);

        $this->complete($game['id'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Confirm that the group agreed to have their photo taken.');
        $this->assertSame([], $this->recipients());
    }

    public function test_every_change_to_a_game_waits_for_any_other_change_to_it(): void
    {
        $locks = 0;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$locks) {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'for update') && str_contains($sql, 'escape_room_sessions')) {
                $locks++;
            }
        });
        $locked = function (callable $action) use (&$locks): int {
            $before = $locks;
            $action();

            return $locks - $before;
        };

        $booking = $this->makeBooking($this->morgue, '14:00');
        $this->makeBooking($this->airlock, '15:00');
        $avery = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $blake = $this->signed($this->morgue, '14:00', 'Blake', 'blake@example.test');
        $casey = $this->signed($this->morgue, '14:00', 'Casey', 'casey2@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $base = "/api/escape-rooms/sessions/{$game['id']}";

        $this->assertGreaterThan(0, $locked(fn () => $this->actingAs($this->attendant, 'sanctum')->postJson("{$base}/photo-session", ['verbal_consent' => true])->assertOk()));
        $this->assertGreaterThan(0, $locked(fn () => $this->actingAs($this->attendant, 'sanctum')->postJson("{$base}/waivers/{$blake->id}/booking", ['booking_id' => null])->assertOk()));
        $this->assertGreaterThan(0, $locked(fn () => $this->actingAs($this->attendant, 'sanctum')->postJson("{$base}/waivers/{$blake->id}/remove")->assertOk()));
        $this->assertGreaterThan(0, $locked(fn () => $this->actingAs($this->attendant, 'sanctum')->postJson("{$base}/waivers/{$casey->id}/move", ['package_id' => $this->airlock->id, 'time' => '15:00'])->assertOk()));
        $this->assertGreaterThan(0, $locked(function () use ($booking) {
            $booking->update(['booking_time' => '16:00']);
            app(EscapeRoomSessionService::class)->followBooking($booking->fresh());
        }));

        $game = $this->openGame($this->morgue, '16:00');
        $this->withGroupPhoto($game['id']);
        $this->assertGreaterThan(0, $locked(fn () => $this->complete($game['id'])->assertOk()));
        $this->signed($this->morgue, '16:00', 'Late', 'late@example.test');
        $this->assertGreaterThan(0, $locked(fn () => $this->actingAs($this->attendant, 'sanctum')->postJson("/api/escape-rooms/sessions/{$game['id']}/send-new")->assertOk()));

        $this->assertSame(['avery@example.test', 'late@example.test'], $this->recipients());
        $this->assertSame($game['id'], $avery->fresh()->escape_room_session_id);
    }

    public function test_kiosk_and_counter_photo_emails_keep_their_own_wording(): void
    {
        PhotoMessageTemplate::forCompany($this->company->id, PhotoMessageTemplate::KIND_KIOSK)->update(['email_subject' => 'Kiosk wording marker']);
        PhotoMessageTemplate::forCompany($this->company->id, PhotoMessageTemplate::KIND_IMMEDIATE)->update(['email_subject' => 'Counter wording marker']);

        $session = PhotoSession::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'source' => PhotoSession::SOURCE_KIOSK,
            'status' => PhotoSession::STATUS_READY,
            'verbal_consent_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);
        $session->startQrWindow();
        $session->save();

        foreach ([PhotoDelivery::KIND_KIOSK => 'kiosk@example.test', PhotoDelivery::KIND_IMMEDIATE => 'counter@example.test'] as $kind => $address) {
            app(PhotoDeliveryService::class)->send(PhotoDelivery::create([
                'photo_session_id' => $session->id,
                'company_id' => $this->company->id,
                'location_id' => $this->location->id,
                'kind' => $kind,
                'channel' => PhotoDelivery::CHANNEL_EMAIL,
                'destination' => $address,
                'recipient_name' => 'Guest',
                'status' => PhotoDelivery::STATUS_QUEUED,
            ]));
        }

        $subjects = collect($this->allEmails())->mapWithKeys(fn ($message) => [$message->getTo()[0]->getAddress() => (string) $message->getSubject()]);
        $this->assertSame('Kiosk wording marker', $subjects['kiosk@example.test']);
        $this->assertSame('Counter wording marker', $subjects['counter@example.test']);
    }

    public function test_a_staff_kiosk_booking_waiver_stays_the_bookings_own_waiver_after_it_joins_the_game(): void
    {
        $booking = $this->makeBooking($this->morgue, '16:00');
        $kiosk = fn () => $this->actingAs($this->attendant, 'sanctum')->postJson('/api/waivers/kiosk-session', [
            'source_type' => 'booking',
            'source_id' => $booking->id,
        ])->assertOk();

        $pending = Waiver::where('access_token', $kiosk()->json('data.access_token'))->firstOrFail();
        $this->signViaLink($pending)->assertOk();
        $signed = $pending->fresh();
        $this->assertNotNull($signed->escape_room_session_id);
        $this->assertTrue((bool) $signed->is_manager_assigned);

        $this->assertSame($signed->id, app(WaiverService::class)->ensureForBooking($booking->fresh())->id);
        $again = $kiosk();
        $this->assertTrue($again->json('data.already_completed'));
        $this->assertSame($signed->access_token, $again->json('data.access_token'));

        $variables = app(EmailNotificationService::class)->buildVariables($booking->fresh(), 'booking', false);
        $this->assertStringContainsString($signed->access_token, (string) $variables['waiver_link']);
        $this->assertSame(1, Waiver::where('booking_id', $booking->id)->count());
    }

    public function test_a_booking_edit_still_saves_if_its_players_cannot_follow_it(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:00');
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');

        $this->partialMock(EscapeRoomSessionService::class, function ($mock) {
            $mock->shouldReceive('followBooking')->andThrow(
                new \Illuminate\Database\QueryException('mysql', 'update waivers set escape_room_session_id = ?', [1], new \Exception('Deadlock found'))
            );
        });

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/bookings/{$booking->id}", ['booking_time' => '16:00', 'change_reason' => 'Guest asked to move'])
            ->assertOk();

        $this->assertSame('16:00', substr((string) $booking->fresh()->getRawOriginal('booking_time'), 0, 5));
    }

    public function test_an_escape_room_waiver_can_list_a_room_that_the_general_waiver_also_lists(): void
    {
        $this->assertContains($this->morgue->id, $this->general->assigned_package_ids);

        $available = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/waiver-templates/available-activities?type=package&kind=escape_room')
            ->assertOk()
            ->json('data.available'))->pluck('id');
        $this->assertContains($this->morgue->id, $available);

        $created = $this->actingAs($this->admin, 'sanctum')->postJson('/api/waiver-templates', [
            'title' => 'Morgue only waiver',
            'body_text' => '<p>Morgue rules</p>',
            'kind' => WaiverTemplate::KIND_ESCAPE_ROOM,
            'status' => WaiverTemplate::STATUS_ACTIVE,
            'assigned_package_ids' => [$this->morgue->id],
        ])->assertCreated();

        $this->assertSame(
            $created->json('data.id'),
            WaiverTemplate::resolveForEscapeRoom($this->company->id, $this->location->id, $this->morgue->id)?->id
        );

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/waiver-templates', [
            'title' => 'Second morgue waiver',
            'body_text' => '<p>Text</p>',
            'kind' => WaiverTemplate::KIND_ESCAPE_ROOM,
            'assigned_package_ids' => [$this->morgue->id],
        ])->assertStatus(422)->assertJsonPath('errors.assigned_package_ids.0', $this->morgue->id);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/waiver-templates', [
            'title' => 'Second standard waiver',
            'body_text' => '<p>Text</p>',
            'kind' => WaiverTemplate::KIND_STANDARD,
            'assigned_package_ids' => [$this->party->id],
        ])->assertStatus(422);
    }

    public function test_the_waiver_record_shows_the_agreement_text_and_its_game(): void
    {
        $waiver = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');

        $response = $this->actingAs($this->admin, 'sanctum')->getJson("/api/waivers/{$waiver->id}")->assertOk();

        $this->assertStringContainsString('I release ZapZone Test for The Morgue.', strip_tags($response->json('data.rendered_body')));
        $this->assertSame('The Morgue', $response->json('data.waiver.escape_room_session.package.name'));
        $this->assertSame('14:00:00', $response->json('data.waiver.escape_room_session.session_time'));

        $listed = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/waivers')->assertOk()->json('data.waivers'))
            ->firstWhere('id', $waiver->id);
        $this->assertSame('The Morgue', $listed['escape_room_session']['package']['name']);

        $exported = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/waivers/export')->assertOk()->json('data.waivers'))
            ->firstWhere('id', $waiver->id);
        $this->assertSame('The Morgue', $exported['escape_room']);
        $this->assertSame('2:00 PM', $exported['escape_room_game_time']);
    }

    public function test_the_signed_pdf_contains_the_agreement_text_and_the_game(): void
    {
        $captured = [];
        $rendered = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $rendered->shouldReceive('output')->andReturn('pdf-bytes');
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->andReturnUsing(function (string $view, array $data) use (&$captured, $rendered) {
            $captured[] = $data;

            return $rendered;
        });

        $waiver = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');

        $this->assertNotEmpty($captured);
        $this->assertStringContainsString('I release ZapZone Test for The Morgue.', strip_tags($captured[0]['renderedBody']));
        $this->assertNotNull($waiver->fresh()->pdf_path);

        $html = view('waivers.print', ['waiver' => $waiver->fresh()->load(['template', 'version', 'location', 'minors', 'company', 'auditEvents']), 'renderedBody' => 'Body'])->render();
        $this->assertStringContainsString('The Morgue · 2:00 PM game', $html);
        $this->assertMatchesRegularExpression('/<td class="label">\s*Escape room\s*<\/td>/', $html);
    }

    public function test_the_pdf_rebuild_command_keeps_the_old_file_and_rebuilds_from_the_signed_version(): void
    {
        $waiver = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $disk = Storage::disk(config('filesystems.default'));
        $disk->put($waiver->pdf_path, 'old-broken-pdf');
        Waiver::whereKey($waiver->id)->update(['pdf_hash' => hash('sha256', 'old-broken-pdf')]);

        $this->artisan('waivers:regenerate-pdfs', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame('old-broken-pdf', $disk->get($waiver->pdf_path));

        $this->artisan('waivers:regenerate-pdfs')->assertExitCode(0);

        $fresh = $waiver->fresh();
        $this->assertNotSame(hash('sha256', 'old-broken-pdf'), $fresh->pdf_hash);
        $this->assertNotSame('old-broken-pdf', $disk->get($fresh->pdf_path));
        $kept = sprintf('waivers/%d/superseded/waiver-%d-%s.pdf', $waiver->company_id, $waiver->id, substr(hash('sha256', 'old-broken-pdf'), 0, 12));
        $this->assertSame('old-broken-pdf', $disk->get($kept));
    }

    public function test_permanently_deleting_a_booking_takes_its_players_out_of_the_game(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:00');
        $own = app(WaiverService::class)->ensureForBooking($booking);
        $this->signViaLink($own, ['adult_email' => 'booker@example.test'])->assertOk();
        $player = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $this->assertSame($booking->id, $player->booking_id);
        $walkIn = $this->signed($this->morgue, '14:00', 'Walkin', 'walkin@example.test');
        $walkIn->forceFill(['booking_id' => null])->save();

        $booking->delete();
        $booking->forceDelete();

        $this->assertNull($own->fresh()->escape_room_session_id);
        $this->assertNotNull($player->fresh()->escape_room_session_id);
        $this->assertNull($player->fresh()->booking_id);
        $this->assertNotNull($walkIn->fresh()->escape_room_session_id);

        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();

        $this->assertSame(['avery@example.test', 'walkin@example.test'], $this->recipients());

        $second = $this->makeBooking($this->airlock, '15:00');
        $sentPlayer = $this->signed($this->airlock, '15:00', 'Sent', 'sent@example.test');
        $this->assertSame($second->id, $sentPlayer->booking_id);
        $airlockGame = $this->openGame($this->airlock, '15:00');
        $this->withGroupPhoto($airlockGame['id']);
        $this->complete($airlockGame['id'])->assertOk();

        $second->delete();
        $second->forceDelete();

        $this->assertSame($airlockGame['id'], $sentPlayer->fresh()->escape_room_session_id);
    }

    public function test_send_to_new_players_and_resend_stop_when_the_photo_is_gone(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $photoSession = $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();
        $this->signed($this->morgue, '14:00', 'Late', 'late@example.test');

        $detail = $this->gameDetail($game['id']);
        $this->assertTrue($detail['can_send_new']);
        $this->assertTrue($detail['can_resend']);
        $this->assertNull($detail['send_blocker']);
        $this->assertSame('https://zapzone.test/photos/' . $photoSession->fresh()->access_token, $detail['photo_link']);

        $photoSession->photos()->update(['processing_status' => Photo::PROCESSING_FAILED]);

        $detail = $this->gameDetail($game['id']);
        $this->assertFalse($detail['can_send_new']);
        $this->assertFalse($detail['can_resend']);
        $this->assertNull($detail['photo_link']);
        $this->assertStringContainsString('removed', $detail['send_blocker']);

        $photoSession->photos()->update(['processing_status' => Photo::PROCESSING_READY]);
        $photoSession->fresh()->forceFill(['access_expires_at' => now()->subMinute()])->save();

        $detail = $this->gameDetail($game['id']);
        $this->assertFalse($detail['can_send_new']);
        $this->assertStringContainsString('expired', $detail['send_blocker']);
    }

    public function test_the_day_list_flags_a_sent_game_whose_emails_did_not_go_through(): void
    {
        $avery = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $this->signed($this->morgue, '14:00', 'Blake', 'blake@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();

        $this->assertSame('sent', $this->daySlots('The Morgue')['14:00']['status']);

        PhotoDelivery::where('waiver_id', $avery->id)->update(['status' => PhotoDelivery::STATUS_FAILED, 'attempts' => 1]);

        $slot = $this->daySlots('The Morgue')['14:00'];
        $this->assertSame('send_problem', $slot['status']);
        $this->assertSame(1, $slot['not_delivered']);

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/waivers/{$avery->id}/resend")
            ->assertOk();

        $slot = $this->daySlots('The Morgue')['14:00'];
        $this->assertSame('sent', $slot['status']);
        $this->assertSame(0, $slot['not_delivered']);
        $detail = $this->gameDetail($game['id']);
        $this->assertSame(2, $detail['counts']['emailed']);
        $this->assertSame(0, $detail['counts']['retrying']);
    }

    public function test_just_opening_a_game_does_not_keep_its_time_on_the_schedule(): void
    {
        $this->openGame($this->morgue, '16:00');
        $this->assertContains('16:00', $this->guestTimesFor('The Morgue'));

        DayOff::create([
            'location_id' => $this->location->id,
            'date' => self::TODAY,
            'time_start' => '16:00',
            'time_end' => '17:00',
            'reason' => 'Repairs',
            'package_ids' => [$this->morgue->id],
        ]);

        $this->assertNotContains('16:00', $this->guestTimesFor('The Morgue'));
        $this->assertArrayNotHasKey('16:00', $this->daySlots('The Morgue')->all());

        $this->signed($this->airlock, '15:00', 'Avery', 'avery@example.test');
        DayOff::create([
            'location_id' => $this->location->id,
            'date' => self::TODAY,
            'time_start' => '15:00',
            'time_end' => '16:00',
            'reason' => 'Repairs',
            'package_ids' => [$this->airlock->id],
        ]);
        $this->assertArrayHasKey('15:00', $this->daySlots('Airlock Escape')->all());
    }

    public function test_players_are_checked_in_when_their_photo_is_sent(): void
    {
        $avery = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $noEmail = $this->signed($this->morgue, '14:00', 'Nomail', 'nomail@example.test');
        Waiver::whereKey($noEmail->id)->update(['adult_email' => null]);
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);

        $this->assertNull($avery->fresh()->checked_in_at);
        $this->complete($game['id'])->assertOk();

        $this->assertNotNull($avery->fresh()->checked_in_at);
        $this->assertSame($this->attendant->id, $avery->fresh()->checked_in_by);
        $this->assertNotNull($noEmail->fresh()->checked_in_at);

        $late = $this->signed($this->morgue, '14:00', 'Late', 'late@example.test');
        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/escape-rooms/sessions/{$game['id']}/send-new")->assertOk();
        $this->assertNotNull($late->fresh()->checked_in_at);

        $other = $this->signed($this->morgue, '15:00', 'Other', 'other@example.test');
        $this->assertNull($other->fresh()->checked_in_at);
    }

    public function test_staff_can_resend_the_photo_to_a_player_including_to_a_corrected_address(): void
    {
        $avery = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $outsider = $this->signed($this->morgue, '15:00', 'Outsider', 'outsider@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $base = "/api/escape-rooms/sessions/{$game['id']}/waivers";

        $this->actingAs($this->attendant, 'sanctum')->postJson("{$base}/{$avery->id}/resend")->assertStatus(422);

        $this->complete($game['id'])->assertOk();

        $this->actingAs($this->attendant, 'sanctum')->postJson("{$base}/{$avery->id}/resend")->assertOk();
        $this->actingAs($this->attendant, 'sanctum')->postJson("{$base}/{$avery->id}/resend", ['email' => 'Avery.Fixed@Example.test'])->assertOk();
        $this->actingAs($this->attendant, 'sanctum')->postJson("{$base}/{$avery->id}/resend", ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
        $this->actingAs($this->attendant, 'sanctum')->postJson("{$base}/{$outsider->id}/resend")->assertNotFound();
        $this->actingAs($this->otherAttendant, 'sanctum')->postJson("{$base}/{$avery->id}/resend")->assertForbidden();

        $this->assertSame(['avery.fixed@example.test', 'avery@example.test', 'avery@example.test'], $this->recipients());
        $this->assertSame('avery@example.test', $avery->fresh()->adult_email);
        $this->assertFalse(PhotoDelivery::where('waiver_id', $outsider->id)->exists());
    }

    public function test_staff_can_correct_the_recorded_result_without_sending_again(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/result", ['escaped' => true, 'completion_time' => '40:00'])
            ->assertStatus(422);

        $this->complete($game['id'], true, '12:48')->assertOk();

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/result", ['escaped' => true, 'completion_time' => '47:12'])
            ->assertOk()
            ->assertJsonPath('data.completion_label', '47:12');
        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/result", ['escaped' => true, 'completion_time' => '99:99'])
            ->assertStatus(422);
        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/result", ['escaped' => false])
            ->assertOk()
            ->assertJsonPath('data.completion_label', 'Did not escape');

        $this->assertCount(1, $this->sentPhotoEmails());
        $this->assertTrue(\App\Models\ActivityLog::where('action', 'escape_room_result_corrected')->exists());
    }

    public function test_the_default_email_reads_naturally_for_several_photos_and_a_group_that_did_not_escape(): void
    {
        $this->location->update(['phone' => '2485559000']);
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $photoSession = $this->withGroupPhoto($game['id']);
        Storage::disk('photos')->put('x/' . $photoSession->id . '/delivery2.jpg', 'second-photo');
        Photo::create([
            'photo_session_id' => $photoSession->id,
            'company_id' => $photoSession->company_id,
            'location_id' => $photoSession->location_id,
            'position' => 2,
            'source' => Photo::SOURCE_UPLOAD,
            'processing_status' => Photo::PROCESSING_READY,
            'original_path' => 'x/' . $photoSession->id . '/original2.jpg',
            'delivery_path' => 'x/' . $photoSession->id . '/delivery2.jpg',
            'slideshow_path' => 'x/' . $photoSession->id . '/slideshow2.jpg',
            'thumbnail_path' => 'x/' . $photoSession->id . '/thumb2.jpg',
            'captured_at' => now(),
            'capture_date' => OperatingDay::calendarDateFor($this->location, now()),
            'operating_day' => OperatingDay::forLocation($this->location, now()),
        ]);

        $this->complete($game['id'], false, null)->assertOk();

        $email = $this->sentPhotoEmails()[0];
        $html = $email->getHtmlBody();
        $this->assertStringContainsString('escape this time. Come back and try again!', $html);
        $this->assertStringContainsString('Your 2 group photos are in this email.', $html);
        $this->assertStringContainsString('2485559000', $html);
        $this->assertStringNotContainsString('noreply', $html);
        $this->assertCount(2, $email->getAttachments());
        $this->assertSame(1, collect($email->getAttachments())->filter(fn ($part) => $part->getDisposition() === 'inline')->count());
    }

    public function test_a_custom_wording_that_lost_the_time_gets_it_back_before_the_sign_off(): void
    {
        $this->thanksForPlaying()->update(['body' => '<p>Hi {{first_name}}</p><p>Thanks for coming!</p><p>The Team</p>']);

        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();

        $html = $this->sentPhotoEmails()[0]->getHtmlBody();
        $time = strpos($html, 'Your group escaped in 47:12!');
        $signOff = strpos($html, 'The Team');
        $this->assertNotFalse($time);
        $this->assertNotFalse($signOff);
        $this->assertLessThan($signOff, $time);
    }

    public function test_the_photo_page_names_the_room_game_and_result(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $photoSession = $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();

        $this->getJson('/api/photos/access/' . $photoSession->fresh()->access_token)
            ->assertOk()
            ->assertJsonPath('data.escape_room.room_name', 'The Morgue')
            ->assertJsonPath('data.escape_room.session_time', '2:00 PM')
            ->assertJsonPath('data.escape_room.result', 'Your group escaped in 47:12!');
    }

    public function test_the_booking_confirmation_tells_the_group_how_to_sign_and_get_the_photo(): void
    {
        $escapeBooking = $this->makeBooking($this->morgue, '14:00');
        app(WaiverService::class)->ensureForBooking($escapeBooking);
        $partyBooking = $this->makeBooking($this->party, '14:00');
        app(WaiverService::class)->ensureForBooking($partyBooking);

        $escape = app(EmailNotificationService::class)->buildVariables($escapeBooking->fresh(), 'booking', false);
        $gameLink = 'https://zapzone.test/waiver/escape-room/' . $this->location->id
            . '?room=' . $this->morgue->id . '&time=14:00&date=' . $escapeBooking->booking_date->toDateString()
            . '&sig=' . app(EscapeRoomSessionService::class)->gameLinkSignature($this->location->id, $this->morgue->id, $escapeBooking->booking_date->toDateString(), '14:00');
        $this->assertSame($gameLink, $escape['escape_room_checkin_link']);
        $this->assertStringContainsString('Every player signs their own waiver.', $escape['waiver_section']);
        $this->assertStringContainsString(e($gameLink), $escape['waiver_section']);
        $this->assertStringContainsString('any time before the game', $escape['waiver_section']);
        $this->assertStringContainsString('Every player signs their own waiver', $escape['waiver_line']);
        $this->assertStringContainsString('any time before the game: ' . $gameLink, $escape['waiver_line']);

        $party = app(EmailNotificationService::class)->buildVariables($partyBooking->fresh(), 'booking', false);
        $this->assertSame('', $party['escape_room_checkin_link']);
        $this->assertStringNotContainsString('Every player signs', $party['waiver_section']);
        $this->assertStringContainsString('Complete Your Waiver', $party['waiver_section']);

        $this->escapeWaiver->update(['status' => WaiverTemplate::STATUS_DRAFT]);
        $noEscapeWaiver = app(EmailNotificationService::class)->buildVariables($escapeBooking->fresh(), 'booking', false);
        $this->assertSame('', $noEscapeWaiver['escape_room_checkin_link']);
    }

    public function test_the_waiver_signed_email_names_the_room_and_game_time(): void
    {
        $waiver = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');

        $variables = app(EmailNotificationService::class)->buildWaiverVariables($waiver->fresh());

        $this->assertSame('The Morgue', $variables['activity_name']);
        $this->assertSame('The Morgue', $variables['escape_room_name']);
        $this->assertSame('2:00 PM', $variables['escape_room_time']);
    }

    public function test_a_party_invitation_for_an_escape_room_explains_signing_and_the_photo(): void
    {
        $escapeBooking = $this->makeBooking($this->morgue, '14:00')->load('location.company', 'package');
        $partyBooking = $this->makeBooking($this->party, '14:00')->load('location.company', 'package');
        $render = fn (Booking $booking) => view('emails.party-invitation', [
            'booking' => $booking,
            'guestName' => 'Guest',
            'hostName' => 'Host',
            'packageName' => $booking->package->name,
            'guestOfHonor' => null,
            'guestOfHonorAge' => null,
            'bookingDate' => 'October 3, 2026',
            'bookingTime' => '2:00 PM',
            'locationName' => 'Waterford',
            'locationAddress' => '1 Test Way',
            'locationPhone' => '2485551234',
            'rsvpUrl' => 'https://zapzone.test/rsvp',
        ])->render();

        $escapeHtml = $render($escapeBooking);
        $this->assertStringContainsString('an escape room, with their group', $escapeHtml);
        $this->assertStringContainsString('/waiver/escape-room/' . $this->location->id, $escapeHtml);

        $partyHtml = $render($partyBooking);
        $this->assertStringContainsString('fun celebration', $partyHtml);
        $this->assertStringNotContainsString('an escape room, with their group', $partyHtml);
    }

    public function test_the_booking_page_summarises_its_escape_room_game(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:00');
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);

        $this->actingAs($this->attendant, 'sanctum')->getJson("/api/escape-rooms/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.session_id', $game['id'])
            ->assertJsonPath('data.room_name', 'The Morgue')
            ->assertJsonPath('data.players_signed', 1)
            ->assertJsonPath('data.players_booked', 4)
            ->assertJsonPath('data.photo_taken', true)
            ->assertJsonPath('data.completed', false);

        $this->complete($game['id'])->assertOk();
        $this->actingAs($this->attendant, 'sanctum')->getJson("/api/escape-rooms/bookings/{$booking->id}")
            ->assertJsonPath('data.completed', true)
            ->assertJsonPath('data.sent', 1)
            ->assertJsonPath('data.completion_label', '47:12');

        $party = $this->makeBooking($this->party, '14:00');
        $this->actingAs($this->attendant, 'sanctum')->getJson("/api/escape-rooms/bookings/{$party->id}")->assertOk()->assertJsonPath('data', null);

        $brighton = $this->makeBooking($this->otherRoom, '14:00');
        $this->actingAs($this->attendant, 'sanctum')->getJson("/api/escape-rooms/bookings/{$brighton->id}")->assertForbidden();
    }

    public function test_reports_and_logs_show_escape_room_games(): void
    {
        $avery = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $this->signed($this->airlock, '15:00', 'Blake', 'blake@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();
        $other = $this->openGame($this->airlock, '15:00');
        $this->withGroupPhoto($other['id']);
        $this->complete($other['id'], false, null)->assertOk();

        $activity = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-reports/activity?from=' . self::TODAY . '&to=' . self::TODAY)
            ->assertOk()
            ->json('data');
        $this->assertSame(2, $activity['escape_room_games_sent']);
        $this->assertSame(1, $activity['escape_room_games_escaped']);
        $this->assertSame(1, $activity['escape_room_games_not_escaped']);
        $this->assertSame('47:12', $activity['escape_room_average_finish_time']);

        $byRoom = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/waivers/reports/by-escape-room?start_date=' . self::TODAY . '&end_date=' . self::TODAY)
            ->assertOk()
            ->json('data'))->keyBy('label');
        $this->assertSame(1, $byRoom['The Morgue']['count']);
        $this->assertSame(1, $byRoom['Airlock Escape']['count']);

        $log = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-deliveries?location_id=' . $this->location->id)
            ->assertOk()
            ->json('data.data'))->firstWhere('waiver_id', $avery->id);
        $this->assertSame('The Morgue', $log['escape_room']['room_name']);
        $this->assertSame('14:00', $log['escape_room']['session_time']);
    }

    public function test_deleting_an_escape_room_waiver_records_its_game(): void
    {
        $waiver = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/waivers/{$waiver->id}", ['reason' => 'Duplicate'])->assertOk();

        $snapshot = \App\Models\WaiverDeletionLog::where('waiver_id', $waiver->id)->firstOrFail()->snapshot;
        $this->assertSame('The Morgue 2:00 PM', $snapshot['escape_room']);
        $this->assertSame($waiver->escape_room_session_id, $snapshot['escape_room_session_id']);
        $this->assertArrayHasKey('adult_email', $snapshot);
    }

    public function test_the_bookings_list_says_which_packages_are_escape_rooms(): void
    {
        $this->makeBooking($this->morgue, '14:00');
        $this->makeBooking($this->party, '15:00');

        $bookings = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/bookings?booking_date=' . self::TODAY)
            ->assertOk()
            ->json('data.bookings'));

        $this->assertTrue((bool) $bookings->firstWhere('package_id', $this->morgue->id)['package']['is_escape_room']);
        $this->assertFalse((bool) $bookings->firstWhere('package_id', $this->party->id)['package']['is_escape_room']);
    }

    public function test_signed_waiver_text_keeps_the_signing_date_and_the_venue_address(): void
    {
        $this->location->update(['address' => '1490 N Oakland Blvd', 'city' => 'Waterford', 'state' => 'MI', 'zip_code' => '48327']);
        $this->escapeWaiver->update(['body_text' => '<p>Signed {{current_date}} at {{location_name}}, {{location_address}}.</p>']);
        app(WaiverService::class)->syncVersion($this->escapeWaiver->fresh());

        $waiver = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $this->travel(40)->days();

        $body = strip_tags($this->actingAs($this->admin, 'sanctum')->getJson("/api/waivers/{$waiver->id}")->assertOk()->json('data.rendered_body'));
        $this->assertStringContainsString('Signed October 3, 2026', $body);
        $this->assertStringContainsString('1490 N Oakland Blvd, Waterford, MI, 48327', $body);
    }

    public function test_the_waiver_record_names_a_booking_waivers_activity(): void
    {
        $this->general->update(['body_text' => '<p>Activity: {{activity_name}}</p>', 'assigned_package_ids' => [$this->party->id]]);
        app(WaiverService::class)->syncVersion($this->general->fresh());
        $pending = app(WaiverService::class)->ensureForBooking($this->makeBooking($this->party, '15:00'));
        $this->signViaLink($pending)->assertOk();

        $body = strip_tags($this->actingAs($this->admin, 'sanctum')->getJson("/api/waivers/{$pending->id}")->assertOk()->json('data.rendered_body'));
        $this->assertStringContainsString('Activity: Birthday Party', $body);
    }

    public function test_another_companys_admin_cannot_see_a_bookings_game(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:00');
        $otherCompany = Company::create(['company_name' => 'Other Co', 'email' => 'o@co.test', 'phone' => '5550000001', 'address' => '2 Else']);
        $outsider = User::create([
            'first_name' => 'Out',
            'last_name' => 'Sider',
            'email' => 'outsider2@company.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $otherCompany->id,
        ]);

        $this->actingAs($outsider, 'sanctum')->getJson("/api/escape-rooms/bookings/{$booking->id}")->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/escape-rooms/bookings/{$booking->id}")->assertOk();
    }

    public function test_a_booking_email_escape_waiver_pdf_names_its_game(): void
    {
        $captured = [];
        $rendered = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $rendered->shouldReceive('output')->andReturn('pdf-bytes');
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->andReturnUsing(function (string $view, array $data) use (&$captured, $rendered) {
            $captured[] = $data['waiver']->escape_room_session_id;

            return $rendered;
        });

        $pending = app(WaiverService::class)->ensureForBooking($this->makeBooking($this->morgue, '16:00'));
        $this->signViaLink($pending)->assertOk();

        $this->assertNotNull($pending->fresh()->escape_room_session_id);
        $this->assertSame($pending->fresh()->escape_room_session_id, end($captured));
    }

    public function test_changing_a_waivers_type_checks_its_rooms_against_that_type(): void
    {
        $this->escapeWaiver->update(['assigned_package_ids' => [$this->airlock->id], 'is_default' => false]);
        $spare = $this->makeTemplate('Spare waiver', WaiverTemplate::KIND_STANDARD, false);
        $spare->update(['assigned_package_ids' => [$this->airlock->id]]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/waiver-templates/{$spare->id}", ['kind' => WaiverTemplate::KIND_ESCAPE_ROOM])
            ->assertStatus(422)
            ->assertJsonPath('errors.assigned_package_ids.0', $this->airlock->id);
        $this->assertSame(WaiverTemplate::KIND_STANDARD, $spare->fresh()->kind);
    }

    public function test_a_resend_to_a_late_player_pins_and_checks_them_in(): void
    {
        $this->escapeWaiver->update(['status' => WaiverTemplate::STATUS_DRAFT]);
        $booking = $this->makeBooking($this->morgue, '14:00');
        $own = app(WaiverService::class)->ensureForBooking($booking);
        $this->escapeWaiver->update(['status' => WaiverTemplate::STATUS_ACTIVE]);

        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();

        $own->forceFill(['waiver_template_id' => $this->general->id])->save();
        app(WaiverService::class)->completeSubmission($own->fresh(), $this->signPayload(['adult_email' => 'booker@example.test']), ['source' => Waiver::SOURCE_CONFIRMATION_EMAIL]);
        $this->assertNull($own->fresh()->escape_room_session_id);

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/waivers/{$own->id}/resend")
            ->assertOk();

        $this->assertSame($game['id'], $own->fresh()->escape_room_session_id);
        $this->assertNotNull($own->fresh()->checked_in_at);

        $noEmail = $this->signed($this->morgue, '14:00', 'Nomail', 'nomail@example.test');
        Waiver::whereKey($noEmail->id)->update(['adult_email' => null]);
        $this->signed($this->morgue, '14:00', 'Late', 'late@example.test');
        $this->actingAs($this->attendant, 'sanctum')->postJson("/api/escape-rooms/sessions/{$game['id']}/send-new")->assertOk();
        $this->assertNotNull($noEmail->fresh()->checked_in_at);
    }

    private function futureDate(int $days): string
    {
        return Carbon::parse(self::TODAY)->addDays($days)->toDateString();
    }

    private function customer(string $email): Customer
    {
        return Customer::create([
            'first_name' => 'Jordan',
            'last_name' => 'Rivera',
            'email' => $email,
            'phone' => '2485550100',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);
    }

    private function portal(Customer $customer, array $bookingIds)
    {
        $this->app['auth']->forgetGuards();
        $this->withMiddleware(ThrottleRequests::class);

        $response = $this->withHeader('Authorization', 'Bearer ' . $customer->createToken($customer->email)->plainTextToken)
            ->getJson('/api/customer-bookings/waivers?' . http_build_query(['ids' => $bookingIds]));

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $response;
    }

    private function finishedGameWithPhoto(array $consents, string $time = '14:00'): array
    {
        foreach ($consents as $i => $consent) {
            $overrides = [
                'adult_first_name' => 'Player' . $i,
                'adult_email' => "player{$i}@example.test",
                'typed_legal_name' => "Player{$i} Player",
            ];

            if ($consent !== null) {
                $overrides['photo_video_consent'] = $consent;
            }

            $this->sign($this->morgue, $time, $overrides)->assertCreated();
        }

        $game = $this->openGame($this->morgue, $time);
        $photoSession = $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();

        return [$game, Photo::where('photo_session_id', $photoSession->id)->firstOrFail()];
    }

    private function showOnSlideshow(Photo $photo, bool $confirm = false)
    {
        return $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/slideshow-photos/{$photo->id}/inclusion", array_merge(['include' => true], $confirm ? ['confirm_release' => true] : []));
    }

    public function test_the_booking_game_link_names_the_room_time_and_date(): void
    {
        $booking = $this->makeBooking($this->morgue, '15:00');
        $booking->update(['booking_date' => $this->futureDate(4)]);
        $service = app(EscapeRoomSessionService::class);
        $expected = 'https://zapzone.test/waiver/escape-room/' . $this->location->id . '?room=' . $this->morgue->id . '&time=15:00&date=' . $this->futureDate(4)
            . '&sig=' . $service->gameLinkSignature($this->location->id, $this->morgue->id, $this->futureDate(4), '15:00');

        $this->assertSame($expected, $service->checkInLinkForBooking($booking->fresh()));
        $this->assertSame($expected, $service->bookingGameSummary($booking->fresh())['kiosk_url']);

        $html = view('emails.party-invitation', [
            'booking' => $booking->fresh()->load('location.company', 'package'),
            'guestName' => 'Sam',
            'hostName' => 'Jordan',
            'packageName' => 'The Morgue',
            'guestOfHonor' => null,
            'guestOfHonorAge' => null,
            'bookingDate' => 'October 7, 2026',
            'bookingTime' => '3:00 PM',
            'locationName' => 'Waterford',
            'locationAddress' => '1 Test Way',
            'locationPhone' => '2485551234',
            'rsvpUrl' => 'https://zapzone.test/rsvp',
        ])->render();

        $this->assertStringContainsString('You can sign on your phone now or any time before the game', $html);
        $this->assertStringContainsString(e($expected), $html);
    }

    public function test_a_guest_can_sign_ahead_for_a_booked_game_on_a_later_day(): void
    {
        $date = $this->futureDate(2);
        $booking = $this->makeBooking($this->morgue, '15:00');
        $booking->update(['booking_date' => $date]);
        $this->makeBooking($this->morgue, '17:00', 'Someone Else')->update(['booking_date' => $date]);

        $sig = app(EscapeRoomSessionService::class)->gameLinkSignature($this->location->id, $this->morgue->id, $date, '15:00');
        $query = "room={$this->morgue->id}&time=15:00&date={$date}&sig={$sig}";
        $kiosk = $this->getJson("/api/waivers/escape-room/{$this->location->id}?{$query}")->assertOk()->json('data');

        $this->assertTrue($kiosk['ahead']);
        $this->assertSame($date, $kiosk['date']);
        $this->assertSame(self::TODAY, $kiosk['today']);
        $this->assertSame(['The Morgue'], array_column($kiosk['rooms'], 'name'));
        $this->assertSame(['15:00'], collect($kiosk['rooms'][0]['times'])->pluck('time')->all());
        $this->assertSame($date, $kiosk['rooms'][0]['times'][0]['date']);
        $this->assertSame(['15:00'], collect($this->getJson("/api/waivers/escape-room/{$this->location->id}/rooms/{$this->morgue->id}?{$query}")->assertOk()->json('data.times'))->pluck('time')->all());

        $response = $this->postJson("/api/waivers/escape-room/{$this->location->id}/submit", array_merge($this->signPayload([
            'adult_first_name' => 'Early',
            'adult_email' => 'early@example.test',
            'typed_legal_name' => 'Early Player',
        ]), ['package_id' => $this->morgue->id, 'session_time' => '15:00', 'session_date' => $date, 'game_signature' => $sig]))->assertCreated();

        $waiver = Waiver::findOrFail($response->json('data.id'));
        $session = EscapeRoomSession::findOrFail($waiver->escape_room_session_id);

        $this->assertSame($date, $waiver->selected_date->toDateString());
        $this->assertSame($date, $session->dateKey());
        $this->assertSame('15:00', $session->timeKey());
        $this->assertSame($booking->id, $waiver->booking_id);
        $this->assertSame(1, $this->daySlots('The Morgue', $date)['15:00']['signed']);
        $this->assertSame(0, $this->daySlots('The Morgue')['15:00']['signed']);
    }

    public function test_signing_ahead_needs_a_live_booking_and_a_real_date_within_a_year(): void
    {
        $date = $this->futureDate(2);
        $booking = $this->makeBooking($this->morgue, '15:00');
        $booking->update(['booking_date' => $date]);
        $service = app(EscapeRoomSessionService::class);
        $submit = fn (string $time, string $day, ?string $sig = null) => $this->postJson("/api/waivers/escape-room/{$this->location->id}/submit", array_merge(
            $this->signPayload(),
            ['package_id' => $this->morgue->id, 'session_time' => $time, 'session_date' => $day, 'game_signature' => $sig ?? $service->gameLinkSignature($this->location->id, $this->morgue->id, $day, $time)]
        ));

        $submit('15:00', $date, 'not-the-signature')->assertStatus(422)->assertJsonValidationErrors('session_time');
        $submit('15:00', $date, $service->gameLinkSignature($this->location->id, $this->morgue->id, $date, '16:00'))->assertStatus(422);
        $this->assertFalse($this->getJson("/api/waivers/escape-room/{$this->location->id}?room={$this->morgue->id}&time=15:00&date={$date}&sig=wrong")->assertOk()->json('data.ahead'));
        $this->assertFalse($this->getJson("/api/waivers/escape-room/{$this->location->id}?room={$this->morgue->id}&time=15:00&date={$date}")->assertOk()->json('data.ahead'));
        $submit('16:00', $date)->assertStatus(422)->assertJsonValidationErrors('session_time');
        $booking->update(['status' => 'cancelled']);
        $submit('15:00', $date)->assertStatus(422)->assertJsonValidationErrors('session_time');
        $booking->update(['status' => 'confirmed', 'booking_date' => $this->futureDate(400)]);
        $submit('15:00', $this->futureDate(400))->assertStatus(422);

        $this->assertSame(0, Waiver::where('adult_email', 'casey@example.test')->count());

        foreach (['2026-02-30', 'soon', Carbon::parse(self::TODAY)->subDay()->toDateString()] as $bad) {
            $kiosk = $this->getJson("/api/waivers/escape-room/{$this->location->id}?date={$bad}")->assertOk()->json('data');
            $this->assertFalse($kiosk['ahead']);
            $this->assertSame(self::TODAY, $kiosk['date']);
        }
    }

    public function test_just_finished_games_are_listed_only_when_the_page_asks_for_them(): void
    {
        $plain = collect(collect($this->getJson("/api/waivers/escape-room/{$this->location->id}")->assertOk()->json('data.rooms'))->firstWhere('name', 'The Morgue')['times']);
        $recent = collect(collect($this->getJson("/api/waivers/escape-room/{$this->location->id}?recent=1")->assertOk()->json('data.rooms'))->firstWhere('name', 'The Morgue')['times']);
        $form = collect($this->getJson("/api/waivers/escape-room/{$this->location->id}/rooms/{$this->morgue->id}?recent=1")->assertOk()->json('data.times'));

        $this->assertNotContains('12:00', $plain->pluck('time')->all());
        $this->assertNotContains('11:00', $recent->pluck('time')->all());
        $this->assertTrue($recent->firstWhere('time', '12:00')['just_finished']);
        $this->assertFalse($recent->firstWhere('time', '13:00')['just_finished']);
        $this->assertTrue($recent->firstWhere('time', '13:00')['in_progress']);
        $this->assertTrue($form->firstWhere('time', '12:00')['just_finished']);
        $this->assertFalse($plain->firstWhere('time', '13:00')['just_finished']);
    }

    public function test_staff_can_record_the_result_without_a_photo_and_nothing_is_emailed(): void
    {
        $avery = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');

        $this->assertTrue($this->gameDetail($game['id'])['can_complete_without_photo']);

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/complete", ['escaped' => false, 'without_photo' => true])
            ->assertOk();

        $detail = $this->gameDetail($game['id']);
        $session = EscapeRoomSession::findOrFail($game['id']);

        $this->assertNotNull($session->completed_at);
        $this->assertFalse($session->escaped);
        $this->assertSame([], $this->sentPhotoEmails());
        $this->assertSame(0, PhotoDelivery::count());
        $this->assertNotNull($avery->fresh()->checked_in_at);
        $this->assertSame($game['id'], $avery->fresh()->escape_room_session_id);
        $this->assertTrue($detail['completed_without_photo']);
        $this->assertTrue($detail['can_send_new']);
        $this->assertSame(1, $detail['counts']['new_players']);
        $this->assertFalse($detail['can_resend']);
        $this->assertFalse($detail['can_complete_without_photo']);
        $this->assertNull($detail['send_blocker']);
        $this->assertSame('finished', $this->daySlots('The Morgue')['14:00']['status']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'escape_room_session_completed_without_photo', 'entity_id' => $game['id']]);

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/photo-session", ['verbal_consent' => true])
            ->assertStatus(409);
        $this->assertNull($session->fresh()->photo_session_id);

        $report = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-reports/activity?from=' . self::TODAY . '&to=' . self::TODAY)
            ->assertOk()
            ->json('data');
        $this->assertSame(1, $report['escape_room_games_completed']);
        $this->assertSame(0, $report['escape_room_games_sent']);
        $this->assertSame(1, $report['escape_room_games_without_photo']);
    }

    public function test_recording_without_a_photo_is_refused_once_a_photo_exists(): void
    {
        $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);

        $this->assertFalse($this->gameDetail($game['id'])['can_complete_without_photo']);

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/complete", ['escaped' => true, 'completion_time' => '40:00', 'without_photo' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This game has a group photo. Send it with Complete & Send, or remove the photo first.');

        $this->assertNull(EscapeRoomSession::findOrFail($game['id'])->completed_at);
    }

    public function test_the_day_lists_earlier_games_that_were_never_sent(): void
    {
        $sessionFor = function (int $daysAgo, string $time, bool $completed = false) {
            return EscapeRoomSession::create([
                'company_id' => $this->company->id,
                'location_id' => $this->location->id,
                'package_id' => $this->morgue->id,
                'session_date' => Carbon::parse(self::TODAY)->subDays($daysAgo)->toDateString(),
                'session_time' => $time . ':00',
                'completed_at' => $completed ? now() : null,
            ]);
        };

        $forgotten = $sessionFor(2, '15:00');
        $sent = $sessionFor(3, '15:00', true);
        $tooOld = $sessionFor(9, '15:00');
        $empty = $sessionFor(1, '16:00');
        $otherRoom = EscapeRoomSession::create([
            'company_id' => $this->company->id,
            'location_id' => $this->otherLocation->id,
            'package_id' => $this->otherRoom->id,
            'session_date' => Carbon::parse(self::TODAY)->subDays(2)->toDateString(),
            'session_time' => '15:00:00',
        ]);

        foreach ([$forgotten, $sent, $tooOld, $otherRoom] as $index => $session) {
            $this->signed($this->morgue, '14:00', 'Old' . $index, "old{$index}@example.test")
                ->forceFill(['escape_room_session_id' => $session->id])
                ->save();
        }

        $todaysGame = $this->signed($this->morgue, '15:00', 'Today', 'today@example.test');
        $earlier = $this->dayData()['unsent_earlier'];

        $this->assertCount(1, $earlier);
        $this->assertSame($forgotten->id, $earlier[0]['session_id']);
        $this->assertSame(1, $earlier[0]['players']);
        $this->assertSame('3:00 PM', $earlier[0]['time_label']);
        $this->assertSame('The Morgue', $earlier[0]['room_name']);
        $this->assertNotContains($empty->id, array_column($earlier, 'session_id'));
        $this->assertNotContains($todaysGame->escape_room_session_id, array_column($earlier, 'session_id'));
    }

    public function test_day_slots_say_which_game_is_playing_and_how_long_check_in_stays_open(): void
    {
        $slots = $this->daySlots('The Morgue');

        $this->assertTrue($slots['13:00']['in_progress']);
        $this->assertFalse($slots['12:00']['in_progress']);
        $this->assertTrue($slots['12:00']['is_past']);
        $this->assertTrue($slots['12:00']['check_in_open']);
        $this->assertFalse($slots['11:00']['check_in_open']);
        $this->assertTrue($slots['15:00']['check_in_open']);
        $this->assertFalse($this->daySlots('The Morgue', $this->futureDate(1))['15:00']['check_in_open']);
    }

    public function test_the_photo_report_breaks_games_down_by_room(): void
    {
        foreach ([['14:00', true, '47:12'], ['15:00', false, null]] as [$time, $escaped, $finish]) {
            EscapeRoomSession::create([
                'company_id' => $this->company->id,
                'location_id' => $this->location->id,
                'package_id' => $this->morgue->id,
                'session_date' => self::TODAY,
                'session_time' => $time . ':00',
                'escaped' => $escaped,
                'completion_seconds' => $finish ? app(EscapeRoomSessionService::class)->parseCompletionTime($finish) : null,
                'completed_at' => now(),
            ]);
        }

        EscapeRoomSession::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'package_id' => $this->airlock->id,
            'session_date' => self::TODAY,
            'session_time' => '14:00:00',
            'escaped' => true,
            'completion_seconds' => 1800,
            'completed_at' => now(),
        ]);

        $rows = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/photo-reports/activity?from=' . self::TODAY . '&to=' . self::TODAY)
            ->assertOk()
            ->json('data.escape_room_by_room'))->keyBy('room');

        $this->assertSame(['Airlock Escape', 'The Morgue'], $rows->keys()->all());
        $this->assertSame(2, $rows['The Morgue']['games']);
        $this->assertSame(1, $rows['The Morgue']['escaped']);
        $this->assertSame(1, $rows['The Morgue']['not_escaped']);
        $this->assertSame(50, $rows['The Morgue']['escape_rate']);
        $this->assertSame('47:12', $rows['The Morgue']['average_finish_time']);
        $this->assertSame('47:12', $rows['The Morgue']['best_finish_time']);
        $this->assertSame(100, $rows['Airlock Escape']['escape_rate']);
        $this->assertSame('30:00', $rows['Airlock Escape']['average_finish_time']);
    }

    public function test_another_companys_admin_cannot_open_a_booking(): void
    {
        $booking = $this->makeBooking($this->morgue, '15:00');
        $otherCompany = Company::create([
            'company_name' => 'Other Venue Co',
            'email' => 'other@venue.test',
            'phone' => '5559990000',
            'address' => '9 Elsewhere',
        ]);
        $otherLocation = Location::create([
            'company_id' => $otherCompany->id,
            'name' => 'Elsewhere',
            'address' => '9 Elsewhere',
            'city' => 'Elsewhere',
            'state' => 'MI',
            'zip_code' => '48000',
            'phone' => '2485550000',
            'email' => 'elsewhere@venue.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $outsider = User::create([
            'first_name' => 'Out',
            'last_name' => 'Sider',
            'email' => 'outsider@venue.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $otherCompany->id,
            'location_id' => $otherLocation->id,
        ]);

        $this->actingAs($outsider, 'sanctum')->getJson("/api/bookings/{$booking->id}")->assertStatus(403);
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/bookings/{$booking->id}")->assertOk();
    }

    public function test_the_customer_portal_shows_only_the_bookers_own_waiver_for_their_own_bookings(): void
    {
        $customer = $this->customer('jordan@example.test');
        $mine = $this->makeBooking($this->party, '15:00');
        $mine->update(['customer_id' => $customer->id, 'booking_date' => $this->futureDate(3)]);
        $theirs = $this->makeBooking($this->party, '16:00');
        $bookerWaiver = app(WaiverService::class)->ensureForBooking($mine->fresh());
        app(WaiverService::class)->ensureForBooking($theirs);

        Waiver::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'waiver_template_id' => $this->general->id,
            'waiver_template_version_id' => $this->general->versions()->first()->id,
            'booking_id' => $mine->id,
            'status' => Waiver::STATUS_PENDING,
            'source' => Waiver::SOURCE_STAFF_SENT,
            'adult_email' => 'other.guest@example.test',
            'selected_date' => $this->futureDate(3),
        ]);

        $response = $this->portal($customer, [$mine->id, $theirs->id])->assertOk();
        $rows = $response->json('data.bookings');

        $this->assertSame([$mine->id], array_column($rows, 'booking_id'));
        $this->assertSame('pending', $rows[0]['waiver']['status']);
        $this->assertSame($bookerWaiver->fresh()->signing_url, $rows[0]['waiver']['signing_url']);
        $this->assertNull($rows[0]['escape_room']);
        $this->assertStringNotContainsString('other.guest@example.test', $response->getContent());
        $this->assertStringNotContainsString((string) $theirs->reference_number, $response->getContent());

        $mine->update(['booking_date' => Carbon::parse(self::TODAY)->subDays(2)->toDateString()]);
        $this->assertNull($this->portal($customer, [$mine->id])->json('data.bookings.0.waiver.signing_url'));
    }

    public function test_the_customer_portal_never_shows_a_staff_sent_waiver_as_the_bookers_own(): void
    {
        $customer = $this->customer('jordan@example.test');
        $booking = $this->makeBooking($this->party, '15:00');
        $booking->update(['customer_id' => $customer->id]);

        Waiver::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'waiver_template_id' => $this->general->id,
            'waiver_template_version_id' => $this->general->versions()->first()->id,
            'booking_id' => $booking->id,
            'status' => Waiver::STATUS_PENDING,
            'source' => Waiver::SOURCE_STAFF_SENT,
            'adult_email' => 'other.guest@example.test',
            'selected_date' => self::TODAY,
        ]);

        $response = $this->portal($customer, [$booking->id])->assertOk();

        $this->assertNull($response->json('data.bookings.0.waiver'));
        $this->assertStringNotContainsString('other.guest@example.test', $response->getContent());
    }

    public function test_the_customer_portal_hides_the_signed_count_when_another_group_shares_the_time(): void
    {
        $customer = $this->customer('jordan@example.test');
        $booking = $this->makeBooking($this->morgue, '14:00');
        $booking->update(['customer_id' => $customer->id]);
        $this->makeBooking($this->morgue, '14:00', 'Other Group');
        $this->signed($this->morgue, '14:00', 'Riley', 'riley@example.test');

        $game = $this->portal($customer, [$booking->id])->assertOk()->json('data.bookings.0.escape_room');

        $this->assertNull($game['players_signed']);
        $this->assertSame(4, $game['players_booked']);
    }

    public function test_the_customer_waiver_list_needs_a_customer_login(): void
    {
        $booking = $this->makeBooking($this->party, '15:00');

        $this->getJson('/api/customer-bookings/waivers?ids[]=' . $booking->id)->assertStatus(401);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/customer-bookings/waivers?ids[]=' . $booking->id)->assertStatus(403);
    }

    public function test_the_customer_portal_shows_an_escape_room_group_link_and_the_photo_after_the_game(): void
    {
        $customer = $this->customer('jordan@example.test');
        $booking = $this->makeBooking($this->morgue, '14:00');
        $booking->update(['customer_id' => $customer->id, 'guest_email' => 'jordan@example.test']);
        $pending = app(WaiverService::class)->ensureForBooking($booking->fresh());
        $this->signed($this->morgue, '14:00', 'Riley', 'riley@example.test');

        $before = $this->portal($customer, [$booking->id])->assertOk();
        $game = $before->json('data.bookings.0.escape_room');

        $this->assertSame(app(EscapeRoomSessionService::class)->gameCheckInUrl($this->location, $this->morgue->id, self::TODAY, '14:00'), $game['check_in_link']);
        $this->assertStringContainsString('?room=' . $this->morgue->id . '&time=14:00&date=' . self::TODAY . '&sig=', $game['check_in_link']);
        $this->assertSame(1, $game['people_covered']);
        $this->assertSame(1, $game['players_signed']);
        $this->assertSame(4, $game['players_booked']);
        $this->assertNull($game['photo_link']);
        $this->assertFalse($game['completed']);
        $this->assertStringNotContainsString('Riley', $before->getContent());
        $this->assertStringNotContainsString('riley@example.test', $before->getContent());
        $this->assertStringNotContainsString('access_token', $before->getContent());

        $this->signViaLink($pending, ['adult_first_name' => 'Jordan', 'adult_email' => 'jordan@example.test', 'typed_legal_name' => 'Jordan Player'])->assertOk();
        $session = $this->openGame($this->morgue, '14:00');
        $photoSession = $this->withGroupPhoto($session['id']);
        $this->complete($session['id'])->assertOk();

        $after = $this->portal($customer, [$booking->id])->assertOk();
        $game = $after->json('data.bookings.0.escape_room');

        $this->assertNull($game['check_in_link']);
        $this->assertTrue($game['completed']);
        $this->assertTrue($game['photo_sent']);
        $this->assertSame(2, $game['players_sent']);
        $this->assertSame('https://zapzone.test/photos/' . $photoSession->fresh()->access_token, $game['photo_link']);
        $this->assertSame('completed', $after->json('data.bookings.0.waiver.status'));
        $this->assertNull($after->json('data.bookings.0.waiver.signing_url'));
    }

    public function test_the_customer_portal_hides_the_photo_link_when_the_booker_was_not_sent_it(): void
    {
        $customer = $this->customer('jordan@example.test');
        $booking = $this->makeBooking($this->morgue, '14:00');
        $booking->update(['customer_id' => $customer->id]);
        app(WaiverService::class)->ensureForBooking($booking->fresh());
        $this->signed($this->morgue, '14:00', 'Riley', 'riley@example.test');
        $session = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($session['id']);
        $this->complete($session['id'])->assertOk();

        $game = $this->portal($customer, [$booking->id])->assertOk()->json('data.bookings.0.escape_room');

        $this->assertTrue($game['photo_sent']);
        $this->assertSame(1, $game['players_sent']);
        $this->assertNull($game['photo_link']);
    }

    public function test_booking_texts_say_escape_room_game_instead_of_party(): void
    {
        $escape = app(EmailNotificationService::class)->buildVariables($this->makeBooking($this->morgue, '14:00')->fresh(), 'booking', false);
        $party = app(EmailNotificationService::class)->buildVariables($this->makeBooking($this->party, '14:00')->fresh(), 'booking', false);

        $this->assertSame('escape room game', $escape['booking_kind']);
        $this->assertSame('Escape room game', $escape['booking_kind_title']);
        $this->assertSame('party', $party['booking_kind']);
        $this->assertSame('Party', $party['booking_kind_title']);

        $definitions = collect(\Database\Seeders\DefaultSmsNotificationSeeder::getDefaultDefinitions())->keyBy('default_key');
        $this->assertStringStartsWith('{{company_name}}: {{booking_kind_title}} booked!', $definitions['booking_confirmation_customer']['body']);
        $this->assertStringContainsString('your {{package_name}} {{booking_kind}} is', $definitions['booking_reminder_customer']['body']);

        $seeded = SmsNotification::where('company_id', $this->company->id)->where('default_key', 'booking_confirmation_customer')->firstOrFail();
        $this->assertStringContainsString('{{booking_kind_title}}', $seeded->getEffectiveBody());
    }

    public function test_the_booking_text_migration_only_rewrites_untouched_defaults(): void
    {
        $oldConfirmation = '{{company_name}}: Party booked! {{package_name}} on {{booking_date}} at {{booking_time}}. Ref {{booking_reference}}. Balance due {{booking_balance}}. Info: {{location_phone}} {{waiver_line}}';
        $oldReminder = '{{company_name}} reminder: your {{package_name}} party is {{booking_date}} at {{booking_time}}, {{location_name}}. See you soon! {{location_phone}}';
        $newConfirmation = '{{company_name}}: {{booking_kind_title}} booked! {{package_name}} on {{booking_date}} at {{booking_time}}. Ref {{booking_reference}}. Balance due {{booking_balance}}. Info: {{location_phone}} {{waiver_line}}';
        $newReminder = '{{company_name}} reminder: your {{package_name}} {{booking_kind}} is {{booking_date}} at {{booking_time}}, {{location_name}}. See you soon! {{location_phone}}';

        $second = Company::create([
            'company_name' => 'Second Co',
            'email' => 'second@zapzone.test',
            'phone' => '5550001111',
            'address' => '2 Main St',
        ]);

        $row = fn (Company $company, string $key) => SmsNotification::where('company_id', $company->id)->where('default_key', $key)->firstOrFail();
        $untouched = $row($this->company, 'booking_confirmation_customer');
        $following = $row($this->company, 'booking_reminder_customer');
        $customized = $row($second, 'booking_confirmation_customer');
        $recased = $row($second, 'booking_reminder_customer');
        $recasedText = str_replace('reminder:', 'REMINDER:', $oldReminder);

        \Illuminate\Support\Facades\DB::table('sms_notifications')->where('id', $untouched->id)->update(['body' => $oldConfirmation, 'default_body' => $oldConfirmation]);
        \Illuminate\Support\Facades\DB::table('sms_notifications')->where('id', $following->id)->update(['body' => null, 'default_body' => $oldReminder]);
        \Illuminate\Support\Facades\DB::table('sms_notifications')->where('id', $customized->id)->update(['body' => 'Our own words', 'default_body' => $oldConfirmation]);
        \Illuminate\Support\Facades\DB::table('sms_notifications')->where('id', $recased->id)->update(['body' => $recasedText, 'default_body' => $oldReminder]);

        $migration = require database_path('migrations/2026_09_26_000001_add_booking_kind_to_default_booking_sms.php');
        $migration->up();

        $this->assertSame([$newConfirmation, $newConfirmation], [$untouched->fresh()->body, $untouched->fresh()->default_body]);
        $this->assertSame([null, $newReminder], [$following->fresh()->body, $following->fresh()->default_body]);
        $this->assertSame(['Our own words', $newConfirmation], [$customized->fresh()->body, $customized->fresh()->default_body]);
        $this->assertSame([$recasedText, $newReminder], [$recased->fresh()->body, $recased->fresh()->default_body]);

        $migration->down();

        $this->assertSame([$oldConfirmation, $oldConfirmation], [$untouched->fresh()->body, $untouched->fresh()->default_body]);
        $this->assertSame([null, $oldReminder], [$following->fresh()->body, $following->fresh()->default_body]);
        $this->assertSame('Our own words', $customized->fresh()->body);
    }

    public function test_a_finished_game_photo_can_go_on_the_slideshow_when_everyone_agreed(): void
    {
        [$game, $photo] = $this->finishedGameWithPhoto([true, true]);

        $this->assertSame(['enabled' => true, 'declined' => 0, 'not_asked' => 0], $this->gameDetail($game['id'])['slideshow']);

        $this->showOnSlideshow($photo)->assertOk();
        $this->assertTrue($photo->fresh()->slideshow_eligible);

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/slideshow-photos/{$photo->id}/inclusion", ['include' => false])
            ->assertOk();
        $this->assertFalse($photo->fresh()->slideshow_eligible);
    }

    public function test_a_declined_photo_release_keeps_the_game_photo_off_the_slideshow(): void
    {
        [$game, $photo] = $this->finishedGameWithPhoto([true, false]);

        $this->assertSame(1, $this->gameDetail($game['id'])['slideshow']['declined']);
        $this->showOnSlideshow($photo, true)->assertStatus(422)->assertJsonPath('code', 'photo_release_declined');
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/slideshow-photos/{$photo->id}/approval", ['status' => Photo::APPROVAL_APPROVED, 'confirm_release' => true])
            ->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/slideshow-photos/{$photo->id}", ['slideshow_state' => Photo::SLIDESHOW_VISIBLE, 'confirm_release' => true])
            ->assertStatus(422);

        $this->assertFalse((bool) $photo->fresh()->slideshow_eligible);
    }

    public function test_players_who_were_not_asked_need_staff_to_confirm_before_the_slideshow(): void
    {
        [$game, $photo] = $this->finishedGameWithPhoto([null]);

        $this->showOnSlideshow($photo)->assertStatus(409)->assertJsonPath('code', 'photo_release_unconfirmed');
        $this->assertFalse((bool) $photo->fresh()->slideshow_eligible);
        $this->showOnSlideshow($photo, true)->assertOk();
        $this->assertTrue($photo->fresh()->slideshow_eligible);
    }

    public function test_an_unfinished_game_photo_cannot_go_on_the_slideshow(): void
    {
        $this->sign($this->morgue, '14:00', ['photo_video_consent' => true])->assertCreated();
        $game = $this->openGame($this->morgue, '14:00');
        $photoSession = $this->withGroupPhoto($game['id']);
        $photo = Photo::where('photo_session_id', $photoSession->id)->firstOrFail();

        $this->showOnSlideshow($photo, true)->assertStatus(422)->assertJsonPath('code', 'escape_room_not_finished');
    }

    public function test_a_late_player_who_declines_takes_the_game_photo_off_the_slideshow(): void
    {
        [$game, $photo] = $this->finishedGameWithPhoto([true]);
        $this->showOnSlideshow($photo)->assertOk();

        $this->sign($this->morgue, '14:00', [
            'adult_first_name' => 'Happy',
            'adult_email' => 'happy@example.test',
            'typed_legal_name' => 'Happy Player',
            'photo_video_consent' => true,
        ])->assertCreated();
        $this->assertTrue((bool) $photo->fresh()->slideshow_eligible);

        $this->sign($this->morgue, '14:00', [
            'adult_first_name' => 'Late',
            'adult_email' => 'late@example.test',
            'typed_legal_name' => 'Late Player',
            'photo_video_consent' => false,
        ])->assertCreated();

        $photo->refresh();
        $this->assertFalse((bool) $photo->slideshow_eligible);
        $this->assertSame(Photo::SLIDESHOW_REMOVED, $photo->slideshow_state);
        $this->assertDatabaseHas('activity_logs', ['action' => 'slideshow_photo_withdrawn', 'entity_id' => $game['id']]);
    }

    public function test_changing_a_players_answer_to_declined_takes_the_game_photo_off_the_slideshow(): void
    {
        [, $photo] = $this->finishedGameWithPhoto([true]);
        $this->showOnSlideshow($photo)->assertOk();
        $otherGamePhoto = $this->finishedGameWithPhoto([true], '15:00')[1];
        $this->showOnSlideshow($otherGamePhoto)->assertOk();

        Waiver::where('adult_email', 'player0@example.test')
            ->whereHas('escapeRoomSession', fn ($q) => $q->where('session_time', '14:00:00'))
            ->firstOrFail()
            ->update(['photo_video_consent' => false]);

        $this->assertFalse((bool) $photo->fresh()->slideshow_eligible);
        $this->assertTrue((bool) $otherGamePhoto->fresh()->slideshow_eligible);
    }

    public function test_approving_all_waiting_photos_leaves_escape_room_photos_that_need_a_check(): void
    {
        [, $photo] = $this->finishedGameWithPhoto([null]);
        $queue = \App\Models\SlideshowQueue::activeFor($this->location);
        $photo->update([
            'slideshow_eligible' => true,
            'slideshow_state' => Photo::SLIDESHOW_VISIBLE,
            'slideshow_queue_id' => $queue->id,
            'slideshow_approval_status' => Photo::APPROVAL_PENDING,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/slideshow-queues/{$queue->id}/approve-pending")
            ->assertOk()
            ->assertJsonPath('message', '1 escape-room photo was left waiting, because the game is not finished or the photo release needs checking. Approve it one at a time.');

        $this->assertSame(Photo::APPROVAL_PENDING, $photo->fresh()->slideshow_approval_status);
    }

    public function test_a_game_link_still_opens_just_after_midnight_while_check_in_is_open(): void
    {
        $this->makeBooking($this->morgue, '23:30');
        $link = "/api/waivers/escape-room/{$this->location->id}?room={$this->morgue->id}&time=23:30&date=" . self::TODAY;

        $this->travelTo(Carbon::parse($this->futureDate(1) . ' 00:40:00', 'America/Detroit'));
        $kiosk = $this->getJson($link)->assertOk()->json('data');

        $this->assertSame(self::TODAY, $kiosk['date']);
        $this->assertFalse($kiosk['ahead']);
        $this->assertSame(['The Morgue'], array_column($kiosk['rooms'], 'name'));
        $this->assertSame(['23:30'], collect($kiosk['rooms'][0]['times'])->pluck('time')->all());

        $this->travelTo(Carbon::parse($this->futureDate(1) . ' 02:00:00', 'America/Detroit'));
        $later = $this->getJson($link)->assertOk()->json('data');

        $this->assertSame($this->futureDate(1), $later['date']);
        $this->assertGreaterThan(1, count($later['rooms']));
    }

    public function test_a_plain_kiosk_submit_for_today_still_needs_no_signature(): void
    {
        $this->postJson("/api/waivers/escape-room/{$this->location->id}/submit", array_merge(
            $this->signPayload(),
            ['package_id' => $this->morgue->id, 'session_time' => '15:00', 'session_date' => self::TODAY]
        ))->assertCreated();
    }

    public function test_the_earlier_games_list_ignores_games_whose_only_players_were_left_out(): void
    {
        $booking = $this->makeBooking($this->morgue, '15:00');
        $player = $this->signed($this->morgue, '15:00', 'Gone', 'gone@example.test');
        EscapeRoomSession::findOrFail($player->escape_room_session_id)
            ->update(['session_date' => Carbon::parse(self::TODAY)->subDays(2)->toDateString()]);
        $booking->update(['status' => 'cancelled']);

        $this->assertSame([], $this->dayData()['unsent_earlier']);
    }

    public function test_the_customer_portal_hides_the_game_for_a_cancelled_booking(): void
    {
        $customer = $this->customer('jordan@example.test');
        $cancelled = $this->makeBooking($this->morgue, '14:00', 'Jordan Rivera', 'cancelled');
        $cancelled->update(['customer_id' => $customer->id]);
        $this->makeBooking($this->morgue, '14:00', 'Other Group');
        $this->signed($this->morgue, '14:00', 'Riley', 'riley@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();

        $response = $this->portal($customer, [$cancelled->id])->assertOk();

        $this->assertNull($response->json('data.bookings.0.escape_room'));
        $this->assertStringNotContainsString('47:12', $response->getContent());
    }

    public function test_staff_can_confirm_the_release_when_approving_or_showing_from_the_queue(): void
    {
        [, $photo] = $this->finishedGameWithPhoto([null]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/slideshow-photos/{$photo->id}/approval", ['status' => Photo::APPROVAL_APPROVED])
            ->assertStatus(409)
            ->assertJsonPath('code', 'photo_release_unconfirmed');
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/slideshow-photos/{$photo->id}/approval", ['status' => Photo::APPROVAL_APPROVED, 'confirm_release' => true])
            ->assertOk();
        $this->assertTrue((bool) $photo->fresh()->slideshow_eligible);

        $photo->update(['slideshow_state' => Photo::SLIDESHOW_HIDDEN]);
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/slideshow-photos/{$photo->id}", ['slideshow_state' => Photo::SLIDESHOW_VISIBLE])
            ->assertStatus(409);
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/slideshow-photos/{$photo->id}", ['slideshow_state' => Photo::SLIDESHOW_VISIBLE, 'confirm_release' => true])
            ->assertOk();
        $this->assertSame(Photo::SLIDESHOW_VISIBLE, $photo->fresh()->slideshow_state);
    }

    public function test_recording_the_result_only_needs_a_booking_or_a_player(): void
    {
        $game = $this->openGame($this->airlock, '16:00');

        $this->assertFalse($this->gameDetail($game['id'])['can_complete_without_photo']);
        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/complete", ['escaped' => false, 'without_photo' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nobody booked or signed for this game, so there is no result to record.');
        $this->assertNull(EscapeRoomSession::findOrFail($game['id'])->completed_at);

        $this->makeBooking($this->airlock, '16:00');

        $this->assertTrue($this->gameDetail($game['id'])['can_complete_without_photo']);
        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/complete", ['escaped' => false, 'without_photo' => true])
            ->assertOk();
    }

    public function test_a_later_days_game_cannot_be_recorded_as_played(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:00');
        $booking->update(['booking_date' => $this->futureDate(1)]);
        $game = $this->actingAs($this->attendant, 'sanctum')
            ->postJson('/api/escape-rooms/sessions', [
                'location_id' => $this->location->id,
                'package_id' => $this->morgue->id,
                'date' => $this->futureDate(1),
                'time' => '14:00',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertFalse($this->gameDetail($game['id'])['can_complete_without_photo']);
        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/complete", ['escaped' => false, 'without_photo' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This game is on a later day. Record its result after it has been played.');
        $this->assertNull(EscapeRoomSession::findOrFail($game['id'])->completed_at);
    }

    public function test_signed_counts_include_minors_and_a_result_only_game_stops_taking_check_ins(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:00');
        $this->sign($this->morgue, '14:00', [
            'adult_first_name' => 'Parent',
            'adult_email' => 'parent@example.test',
            'typed_legal_name' => 'Parent Player',
            'minors' => [
                ['first_name' => 'Kid', 'last_name' => 'One', 'date_of_birth' => '2015-01-01', 'relationship' => 'Parent'],
                ['first_name' => 'Kid', 'last_name' => 'Two', 'date_of_birth' => '2016-01-01', 'relationship' => 'Parent'],
            ],
        ])->assertCreated();

        $service = app(EscapeRoomSessionService::class);
        $summary = $service->bookingGameSummary($booking->fresh());
        $this->assertSame(1, $summary['players_signed']);
        $this->assertSame(3, $summary['people_covered']);
        $this->assertFalse($summary['completed_without_photo']);

        $game = $this->openGame($this->morgue, '14:00');
        $this->assertSame(3, $this->gameDetail($game['id'])['counts']['people']);
        $this->assertContains('14:00', $this->guestTimesFor('The Morgue'));

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/complete", ['escaped' => true, 'completion_time' => '40:00', 'without_photo' => true])
            ->assertOk();

        $this->assertTrue($service->bookingGameSummary($booking->fresh())['completed_without_photo']);
        $this->assertNotContains('14:00', $this->guestTimesFor('The Morgue'));
        $this->sign($this->morgue, '14:00', ['adult_email' => 'late@example.test'])->assertStatus(422);
    }

    public function test_resending_without_an_address_uses_the_last_address_the_photo_reached(): void
    {
        $avery = $this->signed($this->morgue, '14:00', 'Avery', 'avery@example.test');
        $game = $this->openGame($this->morgue, '14:00');
        $this->withGroupPhoto($game['id']);
        $this->complete($game['id'])->assertOk();

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/waivers/{$avery->id}/resend", ['email' => 'avery.fixed@example.test'])
            ->assertOk();
        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/escape-rooms/sessions/{$game['id']}/waivers/{$avery->id}/resend")
            ->assertOk();

        $this->assertSame(
            ['avery@example.test', 'avery.fixed@example.test', 'avery.fixed@example.test'],
            collect($this->sentPhotoEmails())->map(fn ($email) => $email->getTo()[0]->getAddress())->all()
        );
    }

    public function test_a_booker_who_declines_through_their_booking_link_takes_the_photo_off_the_slideshow(): void
    {
        $booking = $this->makeBooking($this->morgue, '14:00');
        $pending = app(WaiverService::class)->ensureForBooking($booking->fresh());
        [, $photo] = $this->finishedGameWithPhoto([true]);
        $this->showOnSlideshow($photo)->assertOk();

        $this->signViaLink($pending, [
            'adult_first_name' => 'Booker',
            'adult_email' => 'booker@example.test',
            'typed_legal_name' => 'Booker Player',
            'photo_video_consent' => false,
        ])->assertOk();

        $this->assertFalse((bool) $photo->fresh()->slideshow_eligible);
    }
}
