<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageAvailabilitySchedule;
use App\Models\User;
use App\Models\Waiver;
use App\Models\WaiverMinor;
use App\Models\WaiverTemplate;
use App\Services\WaiverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WaiverParticipantCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-10-03';

    private Company $company;

    private Location $location;

    private Location $otherLocation;

    private User $admin;

    private User $attendant;

    private WaiverTemplate $general;

    private Package $room;

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

        $this->admin = $this->makeUser('company_admin', 'admin');
        $this->attendant = $this->makeUser('attendant', 'attendant');

        $this->room = Package::create([
            'location_id' => $this->location->id,
            'name' => 'The Morgue',
            'description' => 'Test room',
            'category' => 'Escape Room',
            'price' => 30,
            'pricing_type' => 'per_person',
            'min_participants' => 1,
            'max_participants' => 10,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'is_active' => true,
            'is_escape_room' => true,
        ]);

        PackageAvailabilitySchedule::create([
            'package_id' => $this->room->id,
            'availability_type' => 'daily',
            'day_configuration' => [],
            'time_slot_start' => '11:00',
            'time_slot_end' => '20:00',
            'time_slot_interval' => 60,
            'priority' => 0,
            'is_active' => true,
        ]);

        $this->general = $this->makeTemplate('General Activity Waiver', WaiverTemplate::KIND_STANDARD);
        $this->makeTemplate('Escape Room Waiver', WaiverTemplate::KIND_ESCAPE_ROOM);
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

    private function makeUser(string $role, string $key): User
    {
        return User::create([
            'first_name' => ucfirst($key),
            'last_name' => 'Staff',
            'email' => $key . '@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    private function makeTemplate(string $title, string $kind): WaiverTemplate
    {
        $template = WaiverTemplate::create([
            'company_id' => $this->company->id,
            'title' => $title,
            'status' => WaiverTemplate::STATUS_ACTIVE,
            'is_default' => true,
            'body_text' => '<p>I release {{company_name}} for {{activity_name}}.</p>',
            'kind' => $kind,
            'assigned_package_ids' => [],
            'electronic_consent_enabled' => true,
            'minor_section_enabled' => true,
            'max_minors' => 5,
        ]);

        app(WaiverService::class)->syncVersion($template);

        return $template->fresh();
    }

    private function booking(): Booking
    {
        return Booking::create([
            'reference_number' => 'BKCOVER' . uniqid(),
            'booking_date' => self::TODAY,
            'booking_time' => '16:00',
            'duration' => 60,
            'duration_unit' => 'minutes',
            'location_id' => $this->location->id,
            'package_id' => $this->room->id,
            'participants' => 5,
            'total_amount' => 150,
            'status' => 'confirmed',
            'guest_name' => 'Jordan Rivera',
            'guest_email' => 'jordan@example.test',
            'guest_phone' => '2485550100',
        ]);
    }

    private function form(string $status, string $first, array $minors = [], array $extra = []): Waiver
    {
        $waiver = Waiver::create(array_merge([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'waiver_template_id' => $this->general->id,
            'waiver_template_version_id' => $this->general->versions()->first()->id,
            'status' => $status,
            'source' => Waiver::SOURCE_STAFF_SENT,
            'selected_date' => self::TODAY,
            'adult_first_name' => $first,
            'adult_last_name' => 'Guest',
            'adult_email' => strtolower($first) . '@example.test',
            'submitted_at' => $status === Waiver::STATUS_COMPLETED ? now() : null,
        ], $extra));

        foreach ($minors as $minor) {
            WaiverMinor::create([
                'waiver_id' => $waiver->id,
                'first_name' => $minor,
                'last_name' => 'Guest',
                'date_of_birth' => '2016-04-04',
                'relationship' => 'Child',
            ]);
        }

        return $waiver;
    }

    private function signAtKiosk(string $first, array $minors = [])
    {
        return $this->postJson("/api/waivers/escape-room/{$this->location->id}/submit", [
            'adult_first_name' => $first,
            'adult_last_name' => 'Player',
            'adult_email' => strtolower($first) . '@example.test',
            'adult_phone' => '(248) 555-0142',
            'adult_dob' => '1990-05-05',
            'typed_legal_name' => $first . ' Player',
            'agreement_accepted' => true,
            'electronic_consent_accepted' => true,
            'package_id' => $this->room->id,
            'session_time' => '14:00',
            'minors' => array_map(fn (string $name) => [
                'first_name' => $name,
                'last_name' => 'Player',
                'date_of_birth' => '2016-04-04',
                'relationship' => 'Child',
            ], $minors),
        ])->assertCreated();
    }

    public function test_a_booking_shows_five_participants_covered_by_four_signed_forms(): void
    {
        $booking = $this->booking();
        $guardian = $this->form(Waiver::STATUS_COMPLETED, 'Avery', ['Riley'], ['booking_id' => $booking->id]);
        $this->form(Waiver::STATUS_COMPLETED, 'Blake', [], ['booking_id' => $booking->id]);
        $this->form(Waiver::STATUS_COMPLETED, 'Casey', [], ['booking_id' => $booking->id]);
        $this->form(Waiver::STATUS_COMPLETED, 'Devon', [], ['booking_id' => $booking->id]);
        $unsigned = $this->form(Waiver::STATUS_PENDING, 'Emery', [], ['booking_id' => $booking->id]);

        $response = $this->actingAs($this->attendant, 'sanctum')
            ->getJson('/api/waivers/for?type=booking&id=' . $booking->id)
            ->assertOk();

        $this->assertSame([
            'total' => 5,
            'completed' => 4,
            'pending' => 1,
            'checked_in' => 0,
            'people_covered' => 5,
            'minors_covered' => 1,
            'people_checked_in' => 0,
        ], $response->json('data.summary'));

        $rows = collect($response->json('data.waivers'))->keyBy('id');
        $this->assertSame(2, $rows[$guardian->id]['people_covered']);
        $this->assertSame(['Riley Guest'], $rows[$guardian->id]['minors']);
        $this->assertSame(0, $rows[$unsigned->id]['people_covered']);
        $this->assertSame(4, $rows->where('status', Waiver::STATUS_COMPLETED)->count(), 'a minor never gets a row of their own');

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/waivers/{$guardian->id}/check-in")
            ->assertOk();

        $after = $this->actingAs($this->attendant, 'sanctum')
            ->getJson('/api/waivers/for?type=booking&id=' . $booking->id)
            ->assertOk()
            ->json('data.summary');
        $this->assertSame(1, $after['checked_in']);
        $this->assertSame(2, $after['people_checked_in']);
        $this->assertSame(5, $after['people_covered']);
    }

    public function test_the_check_in_list_counts_the_whole_day_not_just_the_loaded_page(): void
    {
        $this->form(Waiver::STATUS_COMPLETED, 'Avery', ['Riley', 'Quinn'], ['checked_in_at' => now(), 'checked_in_by' => $this->attendant->id]);
        $this->form(Waiver::STATUS_COMPLETED, 'Blake');
        $this->form(Waiver::STATUS_COMPLETED, 'Casey');
        $this->form(Waiver::STATUS_PENDING, 'Devon');
        $this->form(Waiver::STATUS_COMPLETED, 'Elsewhere', ['Kid'], ['location_id' => $this->otherLocation->id]);
        $this->form(Waiver::STATUS_COMPLETED, 'Tomorrow', ['Kid'], ['selected_date' => Carbon::parse(self::TODAY)->addDay()->toDateString()]);

        $signed = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/waivers?' . http_build_query([
                'date' => self::TODAY,
                'status' => 'completed',
                'per_page' => 1,
                'location_id' => $this->location->id,
                'with_coverage' => 1,
            ]))
            ->assertOk();

        $this->assertCount(1, $signed->json('data.waivers'));
        $this->assertSame(3, $signed->json('data.pagination.total'));
        $this->assertSame([
            'forms_signed' => 3,
            'people_covered' => 5,
            'minors_covered' => 2,
            'people_checked_in' => 3,
        ], $signed->json('data.coverage'));

        $unsigned = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/waivers?' . http_build_query([
                'date' => self::TODAY,
                'status' => 'pending',
                'location_id' => $this->location->id,
                'with_coverage' => 1,
            ]))
            ->assertOk();
        $this->assertSame(1, $unsigned->json('data.pagination.total'));
        $this->assertSame(0, $unsigned->json('data.coverage.people_covered'));
        $this->assertSame(0, $unsigned->json('data.coverage.forms_signed'));

        $scoped = $this->actingAs($this->attendant, 'sanctum')
            ->getJson('/api/waivers?' . http_build_query(['date' => self::TODAY, 'status' => 'completed', 'with_coverage' => 1]))
            ->assertOk();
        $this->assertSame(5, $scoped->json('data.coverage.people_covered'), "another location's waivers never count");
    }

    public function test_coverage_is_only_added_when_asked_for(): void
    {
        $this->form(Waiver::STATUS_COMPLETED, 'Avery', ['Riley']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/waivers?date=' . self::TODAY . '&location_id=' . $this->location->id)
            ->assertOk()
            ->assertJsonMissingPath('data.coverage')
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_escape_room_games_count_minors_as_players_not_as_a_plus_one(): void
    {
        $this->signAtKiosk('Avery');
        $this->signAtKiosk('Blake', ['Kid One', 'Kid Two']);

        $day = $this->actingAs($this->attendant, 'sanctum')
            ->getJson('/api/escape-rooms/day?location_id=' . $this->location->id)
            ->assertOk()
            ->json('data');
        $slot = collect(collect($day['rooms'])->firstWhere('id', $this->room->id)['slots'])->firstWhere('time', '14:00');
        $this->assertSame(2, $slot['signed']);
        $this->assertSame(4, $slot['people_covered']);

        $game = $this->actingAs($this->attendant, 'sanctum')
            ->postJson('/api/escape-rooms/sessions', [
                'location_id' => $this->location->id,
                'package_id' => $this->room->id,
                'date' => self::TODAY,
                'time' => '14:00',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(2, $game['counts']['players']);
        $this->assertSame(4, $game['counts']['people']);
        $blake = collect($game['players'])->firstWhere('name', 'Blake Player');
        $this->assertSame(2, $blake['minors']);
        $this->assertSame(['Kid One Player', 'Kid Two Player'], $blake['minor_names']);
        $this->assertSame([], collect($game['players'])->firstWhere('name', 'Avery Player')['minor_names']);
        $this->assertCount(2, $game['players'], 'minors stay under the waiver their guardian signed');

        $report = collect($this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/waivers/reports/by-escape-room?start_date=' . self::TODAY . '&end_date=' . self::TODAY)
            ->assertOk()
            ->json('data'))->keyBy('label');
        $this->assertSame(2, $report['The Morgue']['count']);
        $this->assertSame(4, $report['The Morgue']['participants_covered']);
    }

    public function test_an_unsent_earlier_game_counts_its_players_covered(): void
    {
        $this->travelTo(Carbon::parse(self::TODAY . ' 13:30:00', 'America/Detroit')->subDay());
        $this->signAtKiosk('Blake', ['Kid One', 'Kid Two']);
        $this->travelTo(Carbon::parse(self::TODAY . ' 13:30:00', 'America/Detroit'));

        $unsent = $this->actingAs($this->attendant, 'sanctum')
            ->getJson('/api/escape-rooms/day?location_id=' . $this->location->id)
            ->assertOk()
            ->json('data.unsent_earlier');

        $this->assertCount(1, $unsent);
        $this->assertSame(1, $unsent[0]['players']);
        $this->assertSame(3, $unsent[0]['people_covered']);
    }
}
