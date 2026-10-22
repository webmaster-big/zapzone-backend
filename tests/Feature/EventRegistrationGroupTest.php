<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Event;
use App\Models\Location;
use App\Models\RegistrationEntry;
use App\Models\RegistrationEvent;
use App\Models\RegistrationEventGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\EventRegistrationFixtures;
use Tests\TestCase;

class EventRegistrationGroupTest extends TestCase
{
    use RefreshDatabase;
    use EventRegistrationFixtures;

    private Company $company;

    private Location $brighton;

    private Location $farmington;

    private Location $lansing;

    private Location $elsewhere;

    private User $admin;

    private User $brightonManager;

    private User $farmingtonManager;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false]);
        Mail::fake();
        Storage::fake('public');
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->at('2026-10-15 18:00:00');

        $this->company = $this->makeCompany('Fun Co');
        $this->brighton = $this->makeVenue($this->company, 'Brighton');
        $this->farmington = $this->makeVenue($this->company, 'Farmington');
        $this->lansing = $this->makeVenue($this->company, 'Lansing');
        $this->elsewhere = $this->makeVenue($this->makeCompany('Other Co'), 'Elsewhere');
        $this->admin = $this->makeStaff($this->company, null, 'boss', 'company_admin');
        $this->brightonManager = $this->makeStaff($this->company, $this->brighton, 'brightonmanager', 'location_manager');
        $this->farmingtonManager = $this->makeStaff($this->company, $this->farmington, 'farmingtonmanager', 'location_manager');
    }

    public function test_an_admin_creates_one_event_with_a_form_per_location(): void
    {
        $this->actAs($this->admin);

        $data = $this->post('/api/registration-event-groups', [
            'name' => 'Trunk or Treat 2026',
            'event_date' => '2026-10-25',
            'description' => 'Candy and costumes.',
            'confirmation_message' => 'See you there!',
            'location_ids' => [$this->farmington->id, $this->brighton->id, $this->lansing->id],
            'header_image' => UploadedFile::fake()->image('poster.png', 1200, 600),
        ])->assertCreated()->json('data');

        $group = RegistrationEventGroup::sole();
        $this->assertSame($group->id, $data['id']);
        $this->assertSame('Trunk or Treat 2026', $data['name']);
        $this->assertSame(3, $data['counts']['location_forms']);
        $this->assertSame(3, $data['location_forms_total']);
        $this->assertTrue($data['can_manage']);
        $this->assertSame(['Brighton | Zap Zone', 'Farmington | Zap Zone', 'Lansing | Zap Zone'], array_column($data['forms'], 'location_name'));

        $forms = RegistrationEvent::where('group_id', $group->id)->get();
        $this->assertCount(3, $forms);
        $this->assertCount(3, $forms->pluck('public_code')->unique());
        foreach ($forms as $form) {
            $this->assertSame('Trunk or Treat 2026', $form->name);
            $this->assertSame('2026-10-25', $form->event_date->toDateString());
            $this->assertSame('Candy and costumes.', $form->description);
            $this->assertSame('See you there!', $form->confirmation_message);
            $this->assertSame($group->header_image_path, $form->header_image_path);
        }
        Storage::disk('public')->assertExists($group->header_image_path);
        $this->assertSame(1, ActivityLog::where('action', 'registration_event_group_created')->count());
        $this->assertSame(3, ActivityLog::where('action', 'registration_event_created')->count());
    }

    public function test_a_manager_creates_events_only_for_their_own_location(): void
    {
        $this->actAs($this->brightonManager);

        $this->post('/api/registration-event-groups', [
            'name' => 'Brighton Glow Night',
            'event_date' => '2026-11-06',
            'location_ids' => [$this->brighton->id, $this->farmington->id],
        ])->assertForbidden();
        $this->assertSame(0, RegistrationEventGroup::count());

        $this->post('/api/registration-event-groups', [
            'name' => 'Brighton Glow Night',
            'event_date' => '2026-11-06',
            'location_id' => $this->brighton->id,
        ])->assertCreated()
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.forms.0.location_id', $this->brighton->id);

        $this->post('/api/registration-event-groups', [
            'name' => 'Nope',
            'event_date' => '2026-11-06',
            'location_ids' => [$this->elsewhere->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['location_ids']);
    }

    public function test_the_events_list_counts_each_year_and_follows_the_sidebar_location(): void
    {
        $trunk = $this->makeEvent('Trunk or Treat 2026', '2026-10-25', [$this->brighton, $this->farmington, $this->lansing]);
        $glow = $this->makeEvent('Glow Night', '2026-11-06', [$this->brighton]);
        $this->makeEvent('Trunk or Treat 2025', '2025-10-26', [$this->brighton, $this->farmington]);

        $brightonTrunk = $this->formAt($trunk, $this->brighton);
        $farmingtonTrunk = $this->formAt($trunk, $this->farmington);
        $this->signUp($brightonTrunk, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $this->signUp($brightonTrunk, 'John Doe', 'jane@example.com', '7345550142')->assertCreated();
        $this->signUp($farmingtonTrunk, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $this->signUp($farmingtonTrunk, 'Bob Ray', 'bob@example.com', '7345550143')->assertCreated();
        $this->signUp($this->formAt($glow, $this->brighton), 'Kim Lee', 'kim@example.com', '7345550144')->assertCreated();

        $this->actAs($this->admin);
        $all = $this->getJson('/api/registration-event-groups?year=2026')->assertOk()->json('data');
        $this->assertSame(2026, $all['year']);
        $this->assertSame([2026, 2025], $all['years']);
        $this->assertSame(['total_entries' => 5, 'unique_registrations' => 3, 'events' => 2, 'location_forms' => 4], $all['totals']);
        $this->assertSame(['Glow Night', 'Trunk or Treat 2026'], array_column($all['event_groups'], 'name'));
        $trunkRow = collect($all['event_groups'])->firstWhere('id', $trunk->id);
        $this->assertSame(3, $trunkRow['location_forms']);
        $this->assertSame(4, $trunkRow['total_entries']);
        $this->assertSame(2, $trunkRow['unique_registrations']);
        $this->assertSame('open', $trunkRow['status']);
        $this->assertTrue($trunkRow['can_manage']);

        $farmingtonOnly = $this->getJson("/api/registration-event-groups?year=2026&location_id={$this->farmington->id}")->assertOk()->json('data');
        $this->assertSame(['total_entries' => 2, 'unique_registrations' => 2, 'events' => 1, 'location_forms' => 1], $farmingtonOnly['totals']);
        $this->assertSame([$trunk->id], array_column($farmingtonOnly['event_groups'], 'id'));

        $lastYear = $this->getJson('/api/registration-event-groups?year=2025')->assertOk()->json('data');
        $this->assertSame(1, $lastYear['totals']['events']);
        $this->assertSame('past', $lastYear['event_groups'][0]['status']);

        $this->actAs($this->farmingtonManager);
        $manager = $this->getJson('/api/registration-event-groups?year=2026')->assertOk()->json('data');
        $this->assertSame([$trunk->id], array_column($manager['event_groups'], 'id'));
        $this->assertSame(['total_entries' => 2, 'unique_registrations' => 2, 'events' => 1, 'location_forms' => 1], $manager['totals']);
        $this->assertFalse($manager['event_groups'][0]['can_manage']);
        $this->getJson("/api/registration-event-groups?year=2026&location_id={$this->brighton->id}")
            ->assertOk()->assertJsonPath('data.totals.events', 0);
    }

    public function test_an_event_page_combines_its_locations_and_a_manager_sees_only_their_own(): void
    {
        $this->at('2025-10-20 18:00:00');
        $old = $this->makeEvent('Summer Kickoff', '2025-10-25', [$this->lansing]);
        $this->signUp($this->formAt($old, $this->lansing), 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();

        $this->at('2026-10-15 18:00:00');
        $trunk = $this->makeEvent('Trunk or Treat 2026', '2026-10-25', [$this->brighton, $this->farmington]);
        $this->signUp($this->formAt($trunk, $this->brighton), 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $this->signUp($this->formAt($trunk, $this->farmington), 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $this->signUp($this->formAt($trunk, $this->farmington), 'Bob Ray', 'bob@example.com', '7345550143')->assertCreated();

        $this->actAs($this->admin);
        $this->getJson("/api/registration-event-groups/{$trunk->id}")
            ->assertOk()
            ->assertJsonPath('data.counts.total_entries', 3)
            ->assertJsonPath('data.counts.unique_registrations', 2)
            ->assertJsonPath('data.counts.registered_before', 1)
            ->assertJsonPath('data.counts.location_forms', 2)
            ->assertJsonPath('data.forms.0.location_name', 'Brighton | Zap Zone')
            ->assertJsonPath('data.forms.0.total_entries', 1)
            ->assertJsonPath('data.forms.1.total_entries', 2)
            ->assertJsonPath('data.forms.1.group.id', $trunk->id);

        $this->actAs($this->brightonManager);
        $this->getJson("/api/registration-event-groups/{$trunk->id}")
            ->assertOk()
            ->assertJsonPath('data.can_manage', false)
            ->assertJsonPath('data.location_forms_total', 2)
            ->assertJsonCount(1, 'data.forms')
            ->assertJsonPath('data.forms.0.location_id', $this->brighton->id)
            ->assertJsonPath('data.counts.total_entries', 1)
            ->assertJsonPath('data.counts.unique_registrations', 1);
        $this->getJson("/api/registration-event-groups/{$old->id}")->assertForbidden();

        $this->actAs($this->makeStaff($this->makeCompany('Third Co'), null, 'stranger', 'company_admin'));
        $this->getJson("/api/registration-event-groups/{$trunk->id}")->assertNotFound();
    }

    public function test_editing_an_event_updates_every_location_form(): void
    {
        $trunk = $this->makeEvent('Trunk or Treat', '2026-10-25', [$this->brighton, $this->farmington]);

        $this->actAs($this->admin);
        $this->post("/api/registration-event-groups/{$trunk->id}", [
            'name' => 'Trunk or Treat 2026',
            'event_date' => '2026-10-31',
            'description' => 'Bring a bag.',
            'is_open' => '0',
            'header_image' => UploadedFile::fake()->image('new.png', 1600, 800),
        ])->assertOk()
            ->assertJsonPath('data.name', 'Trunk or Treat 2026')
            ->assertJsonPath('data.event_date', '2026-10-31')
            ->assertJsonPath('data.status', 'closed');

        $trunk->refresh();
        foreach (RegistrationEvent::where('group_id', $trunk->id)->get() as $form) {
            $this->assertSame('Trunk or Treat 2026', $form->name);
            $this->assertSame('2026-10-31', $form->event_date->toDateString());
            $this->assertSame('Bring a bag.', $form->description);
            $this->assertFalse($form->is_open);
            $this->assertSame($trunk->header_image_path, $form->header_image_path);
        }

        $this->post("/api/registration-event-groups/{$trunk->id}", ['remove_header_image' => '1'])->assertOk();
        $this->assertSame([], Storage::disk('public')->allFiles('registration-events'));
        $this->assertSame(0, RegistrationEvent::where('group_id', $trunk->id)->whereNotNull('header_image_path')->count());
        $this->assertTrue(ActivityLog::where('action', 'registration_event_group_updated')->where('entity_id', $trunk->id)->exists());
    }

    public function test_locations_can_be_added_and_removed_and_removing_registrations_needs_confirmation(): void
    {
        $farmingtonEvent = Event::create([
            'location_id' => $this->farmington->id, 'name' => 'Trunk or Treat', 'date_type' => 'one_time', 'start_date' => '2026-10-24',
            'time_start' => '17:00:00', 'time_end' => '20:00:00', 'interval_minutes' => 60, 'price' => 0, 'is_active' => true,
        ]);
        $trunk = $this->makeEvent('Trunk or Treat 2026', '2026-10-25', [$this->brighton, $this->lansing]);
        $this->signUp($this->formAt($trunk, $this->lansing), 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();

        $this->actAs($this->admin);
        $this->post("/api/registration-event-groups/{$trunk->id}", [
            'location_ids' => [$this->brighton->id, $this->lansing->id, $this->farmington->id],
            'event_links' => [$this->farmington->id => $farmingtonEvent->id],
        ])->assertOk()->assertJsonPath('data.counts.location_forms', 3);

        $farmingtonForm = $this->formAt($trunk, $this->farmington);
        $this->assertSame('Trunk or Treat 2026', $farmingtonForm->name);
        $this->assertSame($farmingtonEvent->id, $farmingtonForm->event_id);
        $this->assertSame('2026-10-24', $farmingtonForm->event_date->toDateString());

        $this->post("/api/registration-event-groups/{$trunk->id}", [
            'location_ids' => [$this->farmington->id, $this->lansing->id],
        ])->assertOk()->assertJsonPath('data.counts.location_forms', 2);
        $this->assertNull(RegistrationEvent::where('group_id', $trunk->id)->where('location_id', $this->brighton->id)->first());

        $this->post("/api/registration-event-groups/{$trunk->id}", [
            'location_ids' => [$this->farmington->id],
        ])->assertStatus(409)->assertJsonPath('code', 'REMOVAL_NEEDS_CONFIRMATION');
        $this->assertSame(2, RegistrationEvent::where('group_id', $trunk->id)->count());

        $this->post("/api/registration-event-groups/{$trunk->id}", [
            'location_ids' => [$this->farmington->id],
            'confirm_remove' => '1',
        ])->assertOk()->assertJsonPath('data.counts.location_forms', 1);
        $this->assertSame(1, RegistrationEvent::onlyTrashed()->where('group_id', $trunk->id)->where('location_id', $this->lansing->id)->count());
        $this->assertSame(1, RegistrationEntry::count());

        $this->post("/api/registration-event-groups/{$trunk->id}", [
            'location_ids' => [$this->elsewhere->id],
        ])->assertUnprocessable();
    }

    public function test_only_people_who_manage_every_location_can_change_or_delete_an_event(): void
    {
        $trunk = $this->makeEvent('Trunk or Treat 2026', '2026-10-25', [$this->brighton, $this->farmington]);
        $own = $this->makeEvent('Brighton Glow Night', '2026-11-06', [$this->brighton]);

        $this->actAs($this->brightonManager);
        $this->post("/api/registration-event-groups/{$trunk->id}", ['name' => 'Hijack'])->assertForbidden();
        $this->deleteJson("/api/registration-event-groups/{$trunk->id}")->assertForbidden();
        $this->deleteJson('/api/registration-events/' . $this->formAt($trunk, $this->brighton)->id)->assertForbidden();
        $this->post('/api/registration-events/' . $this->formAt($trunk, $this->brighton)->id, ['description' => 'Our own note.'])
            ->assertOk()->assertJsonPath('data.description', 'Our own note.');
        $this->assertSame('Trunk or Treat 2026', $trunk->fresh()->name);

        $this->post("/api/registration-event-groups/{$own->id}", ['name' => 'Brighton Glow Night 2026'])
            ->assertOk()->assertJsonPath('data.name', 'Brighton Glow Night 2026');
        $this->post("/api/registration-event-groups/{$own->id}", ['location_ids' => [$this->brighton->id, $this->farmington->id]])
            ->assertForbidden();
        $this->deleteJson("/api/registration-event-groups/{$own->id}")->assertOk();
        $this->assertSoftDeleted('registration_event_groups', ['id' => $own->id]);
        $this->assertSame(0, RegistrationEvent::where('group_id', $own->id)->count());

        $this->actAs($this->admin);
        $this->deleteJson("/api/registration-event-groups/{$trunk->id}")->assertOk();
        $this->assertSame(0, RegistrationEvent::where('group_id', $trunk->id)->count());
        $this->getJson("/api/registration-event-groups/{$trunk->id}")->assertNotFound();
    }

    public function test_next_years_event_links_each_location_to_last_years_form(): void
    {
        $this->at('2025-10-01 12:00:00');
        $last = $this->makeEvent('Trunk or Treat 2025', '2025-10-26', [$this->brighton, $this->farmington]);
        $this->at('2026-10-15 18:00:00');

        $this->actAs($this->admin);
        $this->post('/api/registration-event-groups', [
            'name' => 'Trunk or Treat 2026',
            'event_date' => '2025-10-01',
            'location_ids' => [$this->brighton->id],
            'previous_group_id' => $last->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['previous_group_id']);

        $next = $this->post('/api/registration-event-groups', [
            'name' => 'Trunk or Treat 2026',
            'event_date' => '2026-10-25',
            'location_ids' => [$this->brighton->id, $this->farmington->id, $this->lansing->id],
            'previous_group_id' => $last->id,
        ])->assertCreated()->assertJsonPath('data.previous_group.id', $last->id)->json('data');

        $byLocation = collect($next['forms'])->keyBy('location_id');
        $this->assertSame($this->formAt($last, $this->brighton)->id, $byLocation[$this->brighton->id]['previous_edition']['id']);
        $this->assertSame($this->formAt($last, $this->farmington)->id, $byLocation[$this->farmington->id]['previous_edition']['id']);
        $this->assertNull($byLocation[$this->lansing->id]['previous_edition']);

        $this->getJson("/api/registration-event-groups/{$last->id}")->assertJsonPath('data.next_group.id', $next['id']);
        $this->deleteJson("/api/registration-event-groups/{$last->id}")->assertUnprocessable();
        $this->post("/api/registration-event-groups/{$last->id}", ['event_date' => '2026-12-01'])
            ->assertUnprocessable()->assertJsonValidationErrors(['event_date']);

        $this->post("/api/registration-event-groups/{$next['id']}", ['previous_group_id' => ''])->assertOk()->assertJsonPath('data.previous_group', null);
        $this->assertSame(0, RegistrationEvent::where('group_id', $next['id'])->whereNotNull('previous_edition_id')->count());
    }

    public function test_the_event_export_lists_every_visible_location(): void
    {
        $trunk = $this->makeEvent('Trunk or Treat 2026', '2026-10-25', [$this->brighton, $this->farmington]);
        $this->signUp($this->formAt($trunk, $this->brighton), 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $this->signUp($this->formAt($trunk, $this->farmington), 'Bob Ray', 'bob@example.com', '7345550143')->assertCreated();

        $exports = fn (Location $location) => ActivityLog::where('action', 'registration_entries_exported')->where('location_id', $location->id)->count();

        $this->actAs($this->admin);
        $rows = $this->getJson("/api/registration-event-groups/{$trunk->id}/export")->assertOk()->json('data.rows');
        $this->assertSame(['Brighton | Zap Zone', 'Farmington | Zap Zone'], array_column($rows, 'location_name'));
        $this->assertSame(['Jane Doe', 'Bob Ray'], array_column($rows, 'name'));
        $this->assertSame(1, $exports($this->brighton));
        $this->assertSame(1, $exports($this->farmington));

        $this->actAs($this->farmingtonManager);
        $rows = $this->getJson("/api/registration-event-groups/{$trunk->id}/export")->assertOk()->json('data.rows');
        $this->assertSame(['Bob Ray'], array_column($rows, 'name'));
        $this->assertSame(1, $exports($this->brighton));
        $this->assertSame(2, $exports($this->farmington));
        $log = ActivityLog::where('action', 'registration_entries_exported')->where('location_id', $this->farmington->id)->latest('id')->first();
        $this->assertSame(1, $log->metadata['rows']);
        $this->assertSame($trunk->id, $log->metadata['group_id']);
    }

    public function test_a_location_form_takes_its_name_and_location_from_its_event(): void
    {
        $trunk = $this->makeEvent('Trunk or Treat 2026', '2026-10-25', [$this->brighton, $this->farmington]);
        $form = $this->formAt($trunk, $this->brighton);

        $this->actAs($this->admin);
        $this->post("/api/registration-events/{$form->id}", ['name' => 'Renamed'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->post("/api/registration-events/{$form->id}", ['location_id' => $this->lansing->id])->assertUnprocessable()->assertJsonValidationErrors(['location_id']);
        $this->getJson("/api/registration-events/{$form->id}")
            ->assertOk()
            ->assertJsonPath('data.group.id', $trunk->id)
            ->assertJsonPath('data.can_manage_event', true)
            ->assertJsonPath('data.group_form_count', 2);

        $this->actAs($this->brightonManager);
        $this->getJson("/api/registration-events/{$form->id}")->assertJsonPath('data.can_manage_event', false);

        $this->actAs($this->admin);
        $this->deleteJson("/api/registration-events/{$form->id}")
            ->assertOk()
            ->assertJsonPath('group_deleted', false)
            ->assertJsonPath('group_id', $trunk->id);
        $this->assertNotSoftDeleted('registration_event_groups', ['id' => $trunk->id]);
        $this->deleteJson('/api/registration-events/' . $this->formAt($trunk, $this->farmington)->id)
            ->assertOk()
            ->assertJsonPath('group_deleted', true)
            ->assertJsonPath('group_id', $trunk->id);
        $this->assertSoftDeleted('registration_event_groups', ['id' => $trunk->id]);
    }

    public function test_the_old_create_endpoint_makes_an_event_and_a_single_location_event_follows_its_form(): void
    {
        $this->actAs($this->admin);
        $created = $this->post('/api/registration-events', [
            'name' => 'Halloween Bash',
            'event_date' => '2026-10-31',
            'location_ids' => [$this->brighton->id, $this->farmington->id],
        ])->assertCreated()->json('created');

        $groupId = $created[0]['group']['id'];
        $this->assertSame($groupId, $created[1]['group']['id']);
        $this->assertSame(2, RegistrationEvent::where('group_id', $groupId)->count());

        $solo = $this->post('/api/registration-events', [
            'name' => 'Solo Night',
            'event_date' => '2026-11-01',
            'location_id' => $this->brighton->id,
        ])->assertCreated()->json('data');
        $this->post("/api/registration-events/{$solo['id']}", ['description' => 'Updated here.', 'event_date' => '2026-11-02'])->assertOk();
        $group = RegistrationEventGroup::find($solo['group']['id']);
        $this->assertSame('Updated here.', $group->description);
        $this->assertSame('2026-11-02', $group->event_date->toDateString());
    }

    public function test_existing_forms_are_grouped_into_events_by_the_migration(): void
    {
        $createdAt = '2026-10-21 12:00:30';
        $insert = fn (Location $location, string $name, string $at, ?int $by, ?int $previous = null, string $date = '2026-10-25', ?string $deletedAt = null) => DB::table('registration_events')->insertGetId([
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'name' => $name,
            'public_code' => strtolower(str_replace(' ', '-', $name)) . '-' . $location->id . '-' . substr(md5($at . $name . $date), 0, 6),
            'event_date' => $date,
            'is_open' => true,
            'previous_edition_id' => $previous,
            'created_by' => $by,
            'created_at' => $at,
            'updated_at' => $at,
            'deleted_at' => $deletedAt,
        ]);

        $lastYear = $insert($this->brighton, 'Trunk or Treat', '2025-09-01 10:00:00', $this->admin->id, null, '2025-10-26');
        $a = $insert($this->brighton, 'Trunk or Treat', $createdAt, $this->admin->id, $lastYear);
        $b = $insert($this->farmington, 'Trunk or Treat', '2026-10-21 12:00:59', $this->admin->id);
        $c = $insert($this->brighton, 'Trunk or Treat', '2026-10-21 12:00:59', $this->admin->id);
        $d = $insert($this->lansing, 'Glow Night', $createdAt, $this->admin->id);
        $e = $insert($this->lansing, 'Trunk or Treat', $createdAt, $this->brightonManager->id);
        $later = $insert($this->lansing, 'trunk or  treat', '2026-10-21 15:45:00', $this->admin->id);

        $deletedFirst = $insert($this->brighton, 'Fall Fest', '2026-10-21 12:00:10', $this->admin->id, null, '2026-11-01', '2026-10-21 12:00:40');
        $festFarmington = $insert($this->farmington, 'Fall Fest', '2026-10-21 12:00:10', $this->admin->id, null, '2026-11-01');
        $festBrighton = $insert($this->brighton, 'Fall Fest', '2026-10-21 12:00:45', $this->admin->id, null, '2026-11-01');

        $glowLastYear = $insert($this->farmington, 'Glow Night', '2025-09-01 10:00:00', $this->admin->id, null, '2025-11-07');
        $glowBrighton = $insert($this->brighton, 'Glow Night', '2026-10-21 13:00:00', $this->admin->id, null, '2026-11-06');
        $glowFarmington = $insert($this->farmington, 'Glow Night', '2026-10-21 13:00:05', $this->admin->id, $glowLastYear, '2026-11-06');

        $migration = require base_path('database/migrations/2026_10_22_000001_create_registration_event_groups.php');
        $migration->up();

        $groupOf = fn (int $id) => (int) DB::table('registration_events')->where('id', $id)->value('group_id');
        $this->assertSame($groupOf($a), $groupOf($b));
        $this->assertSame($groupOf($a), $groupOf($later));
        $this->assertNotSame($groupOf($a), $groupOf($c));
        $this->assertNotSame($groupOf($a), $groupOf($d));
        $this->assertNotSame($groupOf($a), $groupOf($e));
        $this->assertSame(0, DB::table('registration_events')->whereNull('group_id')->count());
        $this->assertSame($groupOf($lastYear), (int) RegistrationEventGroup::find($groupOf($a))->previous_group_id);

        $fest = RegistrationEventGroup::find($groupOf($festFarmington));
        $this->assertSame($fest->id, $groupOf($festBrighton));
        $this->assertSame('Fall Fest', $fest->name);
        $this->assertSame('2026-11-01', $fest->event_date->toDateString());
        $this->assertNull($fest->deleted_at);
        $this->assertNotSame($fest->id, $groupOf($deletedFirst));
        $this->assertTrue(RegistrationEventGroup::withTrashed()->find($groupOf($deletedFirst))->trashed());

        $glow = RegistrationEventGroup::find($groupOf($glowBrighton));
        $this->assertSame($glow->id, $groupOf($glowFarmington));
        $this->assertSame($groupOf($glowLastYear), (int) $glow->previous_group_id);

        $groups = RegistrationEventGroup::withTrashed()->count();
        $migration->up();
        $this->assertSame($groups, RegistrationEventGroup::withTrashed()->count());
    }

    public function test_forms_left_without_an_event_are_put_into_one_by_the_scheduled_command(): void
    {
        $orphan = $this->makeForm($this->lansing, 'Late Form', '2026-11-01', [], false);
        $this->assertNull($orphan->group_id);

        $this->artisan('registration-events:group-orphans')->assertSuccessful();
        $group = RegistrationEventGroup::findOrFail($orphan->fresh()->group_id);
        $this->assertSame('Late Form', $group->name);
        $this->assertSame('2026-11-01', $group->event_date->toDateString());

        $groups = RegistrationEventGroup::count();
        $this->artisan('registration-events:group-orphans')->assertSuccessful();
        $this->assertSame($groups, RegistrationEventGroup::count());
    }

    public function test_event_changes_are_logged_at_each_location(): void
    {
        $trunk = $this->makeEvent('Trunk or Treat 2026', '2026-10-25', [$this->brighton, $this->farmington, $this->lansing]);
        $logged = fn (string $action, Location $location) => ActivityLog::where('action', $action)->where('location_id', $location->id)->count();

        $this->actAs($this->admin);
        $this->post("/api/registration-event-groups/{$trunk->id}", ['description' => 'Bring a bag.'])->assertOk();
        foreach ([$this->brighton, $this->farmington, $this->lansing] as $location) {
            $this->assertSame(1, $logged('registration_event_updated', $location));
        }

        $this->post("/api/registration-event-groups/{$trunk->id}", ['location_ids' => [$this->brighton->id, $this->farmington->id]])->assertOk();
        $this->assertSame(1, $logged('registration_event_deleted', $this->lansing));

        $this->deleteJson("/api/registration-event-groups/{$trunk->id}")->assertOk();
        $this->assertSame(1, $logged('registration_event_deleted', $this->brighton));
        $this->assertSame(1, $logged('registration_event_deleted', $this->farmington));

        $this->actAs($this->brightonManager);
        $ownId = $this->post('/api/registration-event-groups', [
            'name' => 'Brighton Glow Night',
            'event_date' => '2026-11-06',
            'location_id' => $this->brighton->id,
        ])->assertCreated()->json('data.id');
        $this->post("/api/registration-event-groups/{$ownId}", ['name' => 'Brighton Glow Night 2026'])->assertOk();
        $this->assertTrue(ActivityLog::where('action', 'registration_event_group_updated')->where('entity_id', $ownId)->where('location_id', $this->brighton->id)->exists());
        $this->deleteJson("/api/registration-event-groups/{$ownId}")->assertOk();
        $this->assertTrue(ActivityLog::where('action', 'registration_event_group_deleted')->where('entity_id', $ownId)->where('location_id', $this->brighton->id)->exists());
    }

    public function test_linked_events_at_other_locations_stay_unnamed_for_managers(): void
    {
        $this->at('2025-10-01 12:00:00');
        $last = $this->makeEvent('Trunk or Treat 2025', '2025-10-26', [$this->farmington]);
        $this->at('2026-10-15 18:00:00');

        $this->actAs($this->admin);
        $now = $this->post('/api/registration-event-groups', [
            'name' => 'Trunk or Treat 2026',
            'event_date' => '2026-10-25',
            'location_ids' => [$this->brighton->id, $this->farmington->id],
            'previous_group_id' => $last->id,
        ])->assertCreated()->assertJsonPath('data.previous_group.id', $last->id)->json('data.id');

        $this->actAs($this->brightonManager);
        $this->getJson("/api/registration-event-groups/{$now}")->assertOk()->assertJsonPath('data.previous_group', null);

        $ownId = $this->post('/api/registration-event-groups', [
            'name' => 'Brighton Glow Night',
            'event_date' => '2026-11-06',
            'location_id' => $this->brighton->id,
        ])->assertCreated()->json('data.id');

        $this->actAs($this->admin);
        $this->post('/api/registration-event-groups', [
            'name' => 'Farmington Glow Night 2027',
            'event_date' => '2027-11-05',
            'location_ids' => [$this->farmington->id],
            'previous_group_id' => $ownId,
        ])->assertCreated();

        $hidden = "Another event links to this one as last year's event. Ask a company admin to unlink it first.";
        $this->actAs($this->brightonManager);
        $this->getJson("/api/registration-event-groups/{$ownId}")->assertOk()->assertJsonPath('data.next_group', null);
        $this->deleteJson("/api/registration-event-groups/{$ownId}")->assertUnprocessable()->assertJsonPath('message', $hidden);
        $ownForm = RegistrationEvent::where('group_id', $ownId)->sole();
        $this->deleteJson("/api/registration-events/{$ownForm->id}")->assertUnprocessable()->assertJsonPath('message', $hidden);
        $this->assertNotSoftDeleted('registration_event_groups', ['id' => $ownId]);
        $this->assertNotSoftDeleted('registration_events', ['id' => $ownForm->id]);

        $this->actAs($this->admin);
        $this->deleteJson("/api/registration-event-groups/{$ownId}")
            ->assertUnprocessable()
            ->assertJsonPath('message', '"Farmington Glow Night 2027" links to this event as last year\'s event. Unlink it there first.');
    }

    public function test_event_dates_stay_in_order_with_every_locations_editions(): void
    {
        $this->at('2025-10-01 12:00:00');
        $last = $this->makeEvent('Trunk or Treat 2025', '2025-10-26', [$this->brighton, $this->farmington]);
        $glowLast = $this->makeEvent('Glow Night 2025', '2025-11-07', [$this->farmington]);
        $this->at('2026-10-15 18:00:00');

        $this->actAs($this->admin);
        $now = $this->post('/api/registration-event-groups', [
            'name' => 'Trunk or Treat 2026',
            'event_date' => '2026-10-25',
            'location_ids' => [$this->brighton->id, $this->farmington->id],
            'previous_group_id' => $last->id,
        ])->assertCreated()->json('data.id');

        $this->post('/api/registration-events/' . $this->formAt($last, $this->farmington)->id, ['event_date' => '2025-11-15'])->assertOk();
        $this->post("/api/registration-event-groups/{$now}", ['event_date' => '2025-11-01'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.event_date.0', "That date puts Farmington | Zap Zone's form out of order with its last year's or next year's form.");
        $this->assertSame('2026-10-25', $this->formAt(RegistrationEventGroup::findOrFail($now), $this->farmington)->event_date->toDateString());

        $this->post("/api/registration-event-groups/{$now}", ['event_date' => '2025-10-01'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.event_date.0', "The date must stay after last year's event.");

        $glowNow = $this->post('/api/registration-event-groups', [
            'name' => 'Glow Night 2026',
            'event_date' => '2026-11-06',
            'location_ids' => [$this->brighton->id],
            'previous_group_id' => $glowLast->id,
        ])->assertCreated()->json('data.id');
        $glowForm = RegistrationEvent::where('group_id', $glowNow)->sole();
        $this->assertNull($glowForm->previous_edition_id);
        $this->post("/api/registration-events/{$glowForm->id}", ['event_date' => '2025-11-01'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.event_date.0', "The date must stay after last year's event.");
        $this->assertSame('2026-11-06', RegistrationEventGroup::findOrFail($glowNow)->event_date->toDateString());
    }

    public function test_forms_that_next_years_forms_link_to_cannot_be_removed_or_deleted_with_their_event(): void
    {
        $this->at('2025-10-01 12:00:00');
        $last = $this->makeEvent('Trunk or Treat 2025', '2025-10-26', [$this->brighton, $this->farmington]);
        $this->at('2026-10-15 18:00:00');

        $this->actAs($this->admin);
        $this->post('/api/registration-event-groups', [
            'name' => 'Trunk or Treat 2026',
            'event_date' => '2026-10-25',
            'location_ids' => [$this->brighton->id],
            'previous_group_id' => $last->id,
        ])->assertCreated();

        $this->post("/api/registration-event-groups/{$last->id}", [
            'location_ids' => [$this->farmington->id],
            'confirm_remove' => '1',
        ])->assertUnprocessable()->assertJsonPath('message', '"Trunk or Treat 2026" links to a form you are removing as last year\'s edition. Unlink it there first.');
        $this->assertSame(2, RegistrationEvent::where('group_id', $last->id)->count());

        $this->at('2025-10-01 12:00:00');
        $older = $this->makeEvent('Glow Night 2025', '2025-11-07', [$this->lansing]);
        $this->at('2026-10-15 18:00:00');
        $this->actAs($this->admin);
        $glowId = $this->post('/api/registration-event-groups', [
            'name' => 'Glow Night 2026',
            'event_date' => '2026-11-06',
            'location_ids' => [$this->lansing->id],
        ])->assertCreated()->json('data.id');
        $glowForm = RegistrationEvent::where('group_id', $glowId)->sole();
        $this->post("/api/registration-events/{$glowForm->id}", ['previous_edition_id' => $this->formAt($older, $this->lansing)->id])->assertOk();

        $this->deleteJson("/api/registration-event-groups/{$older->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', '"Glow Night 2026" links to one of these forms as last year\'s edition. Unlink it there first.');
        $this->assertNotSoftDeleted('registration_event_groups', ['id' => $older->id]);
    }

    public function test_signing_up_at_two_locations_of_one_event_is_not_registering_before(): void
    {
        $farmingtonEvent = Event::create([
            'location_id' => $this->farmington->id, 'name' => 'Trunk or Treat', 'date_type' => 'one_time', 'start_date' => '2026-10-24',
            'time_start' => '17:00:00', 'time_end' => '20:00:00', 'interval_minutes' => 60, 'price' => 0, 'is_active' => true,
        ]);

        $this->actAs($this->admin);
        $trunkId = $this->post('/api/registration-event-groups', [
            'name' => 'Trunk or Treat 2026',
            'event_date' => '2026-10-25',
            'location_ids' => [$this->brighton->id, $this->farmington->id],
            'event_links' => [$this->farmington->id => $farmingtonEvent->id],
        ])->assertCreated()->json('data.id');
        $trunk = RegistrationEventGroup::findOrFail($trunkId);
        $this->assertSame('2026-10-24', $this->formAt($trunk, $this->farmington)->event_date->toDateString());

        $this->at('2026-10-20 18:00:00');
        $this->signUp($this->formAt($trunk, $this->farmington), 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $this->at('2026-10-25 12:00:00');
        $this->signUp($this->formAt($trunk, $this->brighton), 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();

        $this->actAs($this->admin);
        $this->getJson("/api/registration-event-groups/{$trunkId}")->assertOk()->assertJsonPath('data.counts.registered_before', 0);
        $this->getJson('/api/registration-events/' . $this->formAt($trunk, $this->brighton)->id . '/registrations')
            ->assertOk()
            ->assertJsonPath('data.counts.returning_contacts', 0)
            ->assertJsonPath('data.groups.0.returning', false)
            ->assertJsonPath('data.groups.0.registered_before_other_name', false);
    }

    public function test_a_location_form_made_separately_can_be_moved_into_the_event(): void
    {
        $lansingManager = $this->makeStaff($this->company, $this->lansing, 'lansingmanager', 'location_manager');

        $this->actAs($lansingManager);
        $separate = $this->post('/api/registration-event-groups', [
            'name' => 'trunk or treat 2026',
            'event_date' => '2026-10-24',
            'location_id' => $this->lansing->id,
        ])->assertCreated()->json('data');
        $lansingForm = RegistrationEvent::where('group_id', $separate['id'])->sole();
        $this->signUp($lansingForm, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $following = $this->post('/api/registration-event-groups', [
            'name' => 'trunk or treat 2027',
            'event_date' => '2027-10-23',
            'location_id' => $this->lansing->id,
            'previous_group_id' => $separate['id'],
        ])->assertCreated()->json('data.id');

        $trunk = $this->makeEvent('Trunk or Treat 2026', '2026-10-25', [$this->brighton, $this->farmington]);
        $glow = $this->makeEvent('Glow Night', '2026-10-25', [$this->lansing]);

        $this->actAs($this->admin);
        $candidates = $this->getJson("/api/registration-event-groups/{$trunk->id}")->assertOk()->json('data.merge_candidates');
        $this->assertSame([$lansingForm->id], array_column($candidates, 'id'));
        $this->assertSame(1, $candidates[0]['total_entries']);
        $this->assertSame(1, $candidates[0]['group_location_forms']);
        $this->assertSame($separate['id'], $candidates[0]['group_id']);
        $this->assertSame('trunk or treat 2026', $candidates[0]['group_name']);
        $this->assertSame('2026-10-24', $candidates[0]['group_event_date']);

        $allThree = [$this->brighton->id, $this->farmington->id, $this->lansing->id];
        $this->post("/api/registration-event-groups/{$trunk->id}", [
            'location_ids' => $allThree,
            'move_form_ids' => [$this->formAt($glow, $this->lansing)->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['move_form_ids']);
        $this->post("/api/registration-event-groups/{$trunk->id}", ['move_form_ids' => [$lansingForm->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['move_form_ids']);
        $this->assertSame(2, RegistrationEvent::where('group_id', $trunk->id)->count());

        $this->post("/api/registration-event-groups/{$trunk->id}", [
            'location_ids' => $allThree,
            'move_form_ids' => [$lansingForm->id],
        ])->assertOk()
            ->assertJsonPath('data.counts.location_forms', 3)
            ->assertJsonPath('data.counts.total_entries', 1)
            ->assertJsonPath('data.merge_candidates', []);

        $moved = $lansingForm->fresh();
        $this->assertSame($trunk->id, $moved->group_id);
        $this->assertSame($lansingForm->public_code, $moved->public_code);
        $this->assertSame('Trunk or Treat 2026', $moved->name);
        $this->assertSame('2026-10-24', $moved->event_date->toDateString());
        $this->assertSame(1, RegistrationEvent::where('group_id', $trunk->id)->where('location_id', $this->lansing->id)->count());
        $this->assertSame(1, RegistrationEntry::where('registration_event_id', $moved->id)->count());
        $this->assertSoftDeleted('registration_event_groups', ['id' => $separate['id']]);
        $this->assertSame($trunk->id, (int) RegistrationEventGroup::findOrFail($following)->previous_group_id);
        $this->assertTrue(ActivityLog::where('action', 'registration_event_moved')->where('location_id', $this->lansing->id)->exists());
        $this->assertTrue(ActivityLog::where('action', 'registration_event_group_merged')->where('entity_id', $separate['id'])->exists());

        $this->actAs($lansingManager);
        $this->getJson("/api/registration-events/{$moved->id}")
            ->assertOk()
            ->assertJsonPath('data.group.id', $trunk->id)
            ->assertJsonPath('data.can_manage_event', false);

        $this->actAs($this->brightonManager);
        $ownId = $this->post('/api/registration-event-groups', [
            'name' => 'Glow Night',
            'event_date' => '2026-10-25',
            'location_id' => $this->brighton->id,
        ])->assertCreated()->json('data.id');
        $this->post("/api/registration-event-groups/{$ownId}", [
            'location_ids' => [$this->brighton->id, $this->lansing->id],
            'move_form_ids' => [$this->formAt($glow, $this->lansing)->id],
        ])->assertForbidden();
        $this->assertSame($glow->id, $this->formAt($glow, $this->lansing)->group_id);
    }

    public function test_event_options_name_the_event_each_linked_form_belongs_to(): void
    {
        $farmingtonEvent = Event::create([
            'location_id' => $this->farmington->id, 'name' => 'Trunk or Treat', 'date_type' => 'one_time', 'start_date' => '2026-10-24',
            'time_start' => '17:00:00', 'time_end' => '20:00:00', 'interval_minutes' => 60, 'price' => 0, 'is_active' => true,
        ]);

        $this->actAs($this->admin);
        $trunkId = $this->post('/api/registration-event-groups', [
            'name' => 'Trunk or Treat 2026',
            'event_date' => '2026-10-25',
            'location_ids' => [$this->brighton->id, $this->farmington->id],
            'event_links' => [$this->farmington->id => $farmingtonEvent->id],
        ])->assertCreated()->json('data.id');

        $trunk = RegistrationEventGroup::findOrFail($trunkId);
        $brightonForm = ['id' => $this->formAt($trunk, $this->brighton)->id, 'location_id' => $this->brighton->id, 'event_id' => null, 'event_date' => '2026-10-25'];
        $farmingtonForm = ['id' => $this->formAt($trunk, $this->farmington)->id, 'location_id' => $this->farmington->id, 'event_id' => $farmingtonEvent->id, 'event_date' => '2026-10-24'];

        $option = collect($this->getJson('/api/registration-events/event-options')->assertOk()->json('data.events'))->firstWhere('id', $farmingtonEvent->id);
        $this->assertSame([['id' => $trunkId, 'name' => 'Trunk or Treat 2026', 'can_manage' => true, 'forms' => [$brightonForm, $farmingtonForm]]], $option['registration_groups']);

        $this->actAs($this->farmingtonManager);
        $option = collect($this->getJson('/api/registration-events/event-options')->assertOk()->json('data.events'))->firstWhere('id', $farmingtonEvent->id);
        $this->assertSame([['id' => $trunkId, 'name' => 'Trunk or Treat 2026', 'can_manage' => false, 'forms' => [$farmingtonForm]]], $option['registration_groups']);
    }

    public function test_a_moved_form_takes_its_manage_events_link_and_last_years_form(): void
    {
        $this->at('2025-10-01 12:00:00');
        $last = $this->makeEvent('Trunk or Treat 2025', '2025-10-26', [$this->brighton, $this->lansing]);
        $this->at('2026-10-15 18:00:00');
        $lansingEvent = Event::create([
            'location_id' => $this->lansing->id, 'name' => 'Trunk or Treat', 'date_type' => 'one_time', 'start_date' => '2026-10-24',
            'time_start' => '17:00:00', 'time_end' => '20:00:00', 'interval_minutes' => 60, 'price' => 0, 'is_active' => true,
        ]);

        $this->actAs($this->admin);
        $trunkId = $this->post('/api/registration-event-groups', [
            'name' => 'Trunk or Treat 2026',
            'event_date' => '2026-10-25',
            'location_ids' => [$this->brighton->id],
            'previous_group_id' => $last->id,
        ])->assertCreated()->json('data.id');
        $separateId = $this->post('/api/registration-event-groups', [
            'name' => 'Trunk or Treat 2026',
            'event_date' => '2026-10-25',
            'location_ids' => [$this->lansing->id],
        ])->assertCreated()->json('data.id');
        $separateForm = RegistrationEvent::where('group_id', $separateId)->sole();
        $this->assertNull($separateForm->event_id);
        $this->assertNull($separateForm->previous_edition_id);

        $this->post("/api/registration-event-groups/{$trunkId}", [
            'location_ids' => [$this->brighton->id, $this->lansing->id],
            'move_form_ids' => [$separateForm->id],
            'event_links' => [$this->lansing->id => $lansingEvent->id],
            'expected_location_ids' => [$this->brighton->id],
        ])->assertOk()->assertJsonPath('data.counts.location_forms', 2);

        $moved = $separateForm->fresh();
        $this->assertSame($trunkId, $moved->group_id);
        $this->assertSame($lansingEvent->id, $moved->event_id);
        $this->assertSame($this->formAt($last, $this->lansing)->id, $moved->previous_edition_id);
        $this->assertSame('2026-10-25', $moved->event_date->toDateString());
        $this->assertSame($last->id, (int) RegistrationEventGroup::findOrFail($trunkId)->previous_group_id);
    }

    public function test_merging_keeps_the_events_own_last_year_choice(): void
    {
        $this->at('2025-10-01 12:00:00');
        $older = $this->makeEvent('Trunk or Treat 2025', '2025-10-26', [$this->lansing]);
        $this->at('2026-10-15 18:00:00');

        $this->actAs($this->admin);
        $trunkId = $this->post('/api/registration-event-groups', [
            'name' => 'Trunk or Treat 2026',
            'event_date' => '2026-10-25',
            'location_ids' => [$this->brighton->id],
        ])->assertCreated()->json('data.id');
        $separateId = $this->post('/api/registration-event-groups', [
            'name' => 'Trunk or Treat 2026',
            'event_date' => '2026-10-25',
            'location_ids' => [$this->lansing->id],
            'previous_group_id' => $older->id,
        ])->assertCreated()->json('data.id');
        $form = RegistrationEvent::where('group_id', $separateId)->sole();

        $this->post("/api/registration-event-groups/{$trunkId}", [
            'location_ids' => [$this->brighton->id, $this->lansing->id],
            'move_form_ids' => [$form->id],
            'previous_group_id' => '',
        ])->assertOk()->assertJsonPath('data.previous_group', null);

        $this->assertNull(RegistrationEventGroup::findOrFail($trunkId)->previous_group_id);
        $this->assertNull($form->fresh()->previous_edition_id);
        $this->assertSoftDeleted('registration_event_groups', ['id' => $separateId]);
        $this->assertTrue(ActivityLog::where('action', 'registration_event_group_merged')->where('entity_id', $separateId)->where('location_id', $this->lansing->id)->exists());

        $keptId = $this->post('/api/registration-event-groups', [
            'name' => 'Glow Night 2026',
            'event_date' => '2026-11-06',
            'location_ids' => [$this->brighton->id],
        ])->assertCreated()->json('data.id');
        $linkedId = $this->post('/api/registration-event-groups', [
            'name' => 'Glow Night 2026',
            'event_date' => '2026-11-06',
            'location_ids' => [$this->lansing->id],
            'previous_group_id' => $older->id,
        ])->assertCreated()->json('data.id');
        $linkedForm = RegistrationEvent::where('group_id', $linkedId)->sole();
        $this->post("/api/registration-event-groups/{$keptId}", [
            'location_ids' => [$this->brighton->id, $this->lansing->id],
            'move_form_ids' => [$linkedForm->id],
        ])->assertOk()->assertJsonPath('data.previous_group', null);
        $this->assertSame($this->formAt($older, $this->lansing)->id, $linkedForm->fresh()->previous_edition_id);
    }

    public function test_new_location_forms_follow_a_closed_event(): void
    {
        $this->actAs($this->admin);
        $closedId = $this->post('/api/registration-event-groups', [
            'name' => 'Lock-in',
            'event_date' => '2026-11-06',
            'location_ids' => [$this->brighton->id],
            'is_open' => '0',
        ])->assertCreated()->json('data.id');
        $this->post("/api/registration-event-groups/{$closedId}", ['location_ids' => [$this->brighton->id, $this->farmington->id]])->assertOk();
        $this->assertFalse($this->formAt(RegistrationEventGroup::findOrFail($closedId), $this->farmington)->is_open);

        $openId = $this->post('/api/registration-event-groups', [
            'name' => 'Open House',
            'event_date' => '2026-11-07',
            'location_ids' => [$this->brighton->id],
        ])->assertCreated()->json('data.id');
        $this->post("/api/registration-event-groups/{$openId}", ['location_ids' => [$this->brighton->id, $this->farmington->id]])->assertOk();
        $this->assertTrue($this->formAt(RegistrationEventGroup::findOrFail($openId), $this->farmington)->is_open);
    }

    public function test_a_save_based_on_old_locations_is_refused(): void
    {
        $trunk = $this->makeEvent('Trunk or Treat 2026', '2026-10-25', [$this->brighton, $this->farmington]);

        $this->actAs($this->admin);
        $this->post("/api/registration-event-groups/{$trunk->id}", [
            'location_ids' => [$this->brighton->id, $this->lansing->id],
            'expected_location_ids' => [$this->brighton->id],
        ])->assertStatus(409)->assertJsonPath('code', 'EVENT_CHANGED');
        $this->assertSame(2, RegistrationEvent::where('group_id', $trunk->id)->count());

        $this->post("/api/registration-event-groups/{$trunk->id}", [
            'location_ids' => [$this->brighton->id, $this->farmington->id, $this->lansing->id],
            'expected_location_ids' => [$this->farmington->id, $this->brighton->id],
        ])->assertOk()->assertJsonPath('data.counts.location_forms', 3);
    }

    public function test_events_left_without_forms_never_block_and_are_retired(): void
    {
        $this->at('2025-10-01 12:00:00');
        $last = $this->makeEvent('Halloween Bash 2025', '2025-10-31', [$this->brighton]);
        $this->at('2026-10-15 18:00:00');

        $this->actAs($this->admin);
        $nextId = $this->post('/api/registration-event-groups', [
            'name' => 'Halloween Bash 2026',
            'event_date' => '2026-10-31',
            'location_ids' => [$this->brighton->id],
            'previous_group_id' => $last->id,
        ])->assertCreated()->json('data.id');
        $this->deleteJson("/api/registration-event-groups/{$last->id}")->assertUnprocessable();

        RegistrationEvent::where('group_id', $nextId)->get()->each->delete();
        $this->assertNotSoftDeleted('registration_event_groups', ['id' => $nextId]);

        $this->post("/api/registration-event-groups/{$last->id}", ['event_date' => '2026-11-05'])->assertOk();
        $lastForm = $this->formAt($last, $this->brighton);
        $this->post("/api/registration-events/{$lastForm->id}", ['event_date' => '2026-11-20'])->assertOk();
        $this->assertSame('2026-11-20', $last->fresh()->event_date->toDateString());
        $this->deleteJson("/api/registration-event-groups/{$last->id}")->assertOk();
        $this->assertNotSoftDeleted('registration_event_groups', ['id' => $nextId]);

        $this->artisan('registration-events:group-orphans')->assertSuccessful();
        $this->assertSoftDeleted('registration_event_groups', ['id' => $nextId]);
    }

    public function test_the_backfill_keeps_one_save_together_and_splits_unrelated_forms(): void
    {
        $this->at('2025-10-01 12:00:00');
        $older = $this->makeEvent('Trunk or Treat 2025', '2025-10-26', [$this->brighton]);
        $this->at('2026-10-15 18:00:00');

        $insert = fn (Location $location, string $name, string $at, string $date, ?int $previous = null) => DB::table('registration_events')->insertGetId([
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'name' => $name,
            'public_code' => strtolower(str_replace(' ', '-', $name)) . '-' . $location->id . '-' . substr(md5($at . $date), 0, 6),
            'event_date' => $date,
            'is_open' => true,
            'previous_edition_id' => $previous,
            'created_by' => $this->admin->id,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $winterBrighton = $insert($this->brighton, 'Winter Wonderland', '2026-10-21 10:00:00', '2026-12-31');
        $winterFarmington = $insert($this->farmington, 'Winter Wonderland', '2026-10-21 10:00:01', '2027-02-28');
        $winterLansing = $insert($this->lansing, 'Winter Wonderland', '2026-10-21 10:03:00', '2027-03-15');
        $runBrighton = $insert($this->brighton, 'Pumpkin Run', '2026-10-21 11:00:00', '2026-10-25');
        $runFarmington = $insert($this->farmington, 'Pumpkin Run', '2026-10-21 11:01:00', '2026-11-25');
        $runLansing = $insert($this->lansing, 'Pumpkin Run', '2026-10-21 11:01:30', '2027-01-15');
        $trunk = $insert($this->brighton, 'Trunk or Treat 2026', '2026-10-21 12:00:00', '2026-10-25', $this->formAt($older, $this->brighton)->id);
        $trunkFarmington = $insert($this->farmington, 'Trunk or Treat 2026', '2026-10-21 12:00:02', '2026-10-25');

        $this->artisan('registration-events:group-orphans')->assertSuccessful();

        $groupOf = fn (int $id) => (int) DB::table('registration_events')->where('id', $id)->value('group_id');
        $this->assertSame($groupOf($winterBrighton), $groupOf($winterFarmington));
        $this->assertNotSame($groupOf($winterBrighton), $groupOf($winterLansing));
        $this->assertSame($groupOf($runBrighton), $groupOf($runFarmington));
        $this->assertNotSame($groupOf($runBrighton), $groupOf($runLansing));
        $this->assertSame($groupOf($trunk), $groupOf($trunkFarmington));
        $this->assertSame($older->id, (int) RegistrationEventGroup::findOrFail($groupOf($trunk))->previous_group_id);
        $this->assertSame(0, DB::table('registration_events')->whereNull('group_id')->count());
    }

    public function test_a_link_to_a_deleted_manage_events_event_counts_as_unlinked(): void
    {
        $manageEvent = fn (string $date) => Event::create([
            'location_id' => $this->lansing->id, 'name' => 'Trunk or Treat', 'date_type' => 'one_time', 'start_date' => $date,
            'time_start' => '17:00:00', 'time_end' => '20:00:00', 'interval_minutes' => 60, 'price' => 0, 'is_active' => true,
        ]);
        $old = $manageEvent('2026-10-24');

        $this->actAs($this->admin);
        $separateId = $this->post('/api/registration-event-groups', [
            'name' => 'Trunk or Treat 2026',
            'event_date' => '2026-10-25',
            'location_ids' => [$this->lansing->id],
            'event_links' => [$this->lansing->id => $old->id],
        ])->assertCreated()->json('data.id');
        $form = RegistrationEvent::where('group_id', $separateId)->sole();
        $this->assertSame($old->id, $form->event_id);

        $old->delete();
        $replacement = $manageEvent('2026-10-24');
        $trunkId = $this->post('/api/registration-event-groups', [
            'name' => 'Trunk or Treat 2026',
            'event_date' => '2026-10-25',
            'location_ids' => [$this->brighton->id],
        ])->assertCreated()->json('data.id');

        $option = collect($this->getJson('/api/registration-events/event-options')->assertOk()->json('data.events'))->firstWhere('id', $replacement->id);
        $this->assertSame([$separateId], array_column($option['registration_groups'], 'id'));
        $this->assertSame([['id' => $form->id, 'location_id' => $this->lansing->id, 'event_id' => null, 'event_date' => '2026-10-24']], $option['registration_groups'][0]['forms']);

        $this->post("/api/registration-event-groups/{$trunkId}", [
            'location_ids' => [$this->brighton->id, $this->lansing->id],
            'move_form_ids' => [$form->id],
            'event_links' => [$this->lansing->id => $replacement->id],
        ])->assertOk();
        $this->assertSame($replacement->id, $form->fresh()->event_id);
        $this->assertSame($trunkId, $form->fresh()->group_id);
    }

    public function test_event_options_find_a_same_named_form_that_was_never_linked(): void
    {
        $halloween = Event::create([
            'location_id' => $this->brighton->id, 'name' => 'Halloween Bash', 'date_type' => 'one_time', 'start_date' => '2026-10-31',
            'time_start' => '17:00:00', 'time_end' => '20:00:00', 'interval_minutes' => 60, 'price' => 0, 'is_active' => true,
        ]);
        Event::create([
            'location_id' => $this->brighton->id, 'name' => 'Winter Fair', 'date_type' => 'one_time', 'start_date' => '2026-12-05',
            'time_start' => '17:00:00', 'time_end' => '20:00:00', 'interval_minutes' => 60, 'price' => 0, 'is_active' => true,
        ]);

        $this->actAs($this->brightonManager);
        $typedId = $this->post('/api/registration-event-groups', [
            'name' => 'Halloween Bash 2026',
            'event_date' => '2026-10-31',
            'location_id' => $this->brighton->id,
        ])->assertCreated()->json('data.id');
        $this->post('/api/registration-event-groups', [
            'name' => 'Halloween Bash 2026',
            'event_date' => '2026-11-15',
            'location_id' => $this->brighton->id,
        ])->assertCreated();
        $this->post('/api/registration-event-groups', [
            'name' => 'Glow Night',
            'event_date' => '2026-10-31',
            'location_id' => $this->brighton->id,
        ])->assertCreated();
        $typedForm = RegistrationEvent::where('group_id', $typedId)->sole();

        $option = collect($this->getJson('/api/registration-events/event-options')->assertOk()->json('data.events'))->firstWhere('id', $halloween->id);
        $this->assertSame([], $option['registration_form_ids']);
        $this->assertSame([[
            'id' => $typedId,
            'name' => 'Halloween Bash 2026',
            'can_manage' => true,
            'forms' => [['id' => $typedForm->id, 'location_id' => $this->brighton->id, 'event_id' => null, 'event_date' => '2026-10-31']],
        ]], $option['registration_groups']);
    }

    public function test_deleting_an_event_from_an_old_page_is_refused(): void
    {
        $trunk = $this->makeEvent('Trunk or Treat 2026', '2026-10-25', [$this->brighton, $this->farmington]);

        $this->actAs($this->admin);
        $this->deleteJson("/api/registration-event-groups/{$trunk->id}", ['expected_location_ids' => [$this->brighton->id]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'EVENT_CHANGED');
        $this->assertSame(2, RegistrationEvent::where('group_id', $trunk->id)->count());
        $this->assertNotSoftDeleted('registration_event_groups', ['id' => $trunk->id]);

        $this->deleteJson("/api/registration-event-groups/{$trunk->id}", ['expected_location_ids' => [$this->farmington->id, $this->brighton->id]])
            ->assertOk();
        $this->assertSoftDeleted('registration_event_groups', ['id' => $trunk->id]);
    }

    public function test_event_options_survive_an_event_moved_to_another_location(): void
    {
        $moved = Event::create([
            'location_id' => $this->brighton->id, 'name' => 'Trunk or Treat', 'date_type' => 'one_time', 'start_date' => '2026-10-25',
            'time_start' => '17:00:00', 'time_end' => '20:00:00', 'interval_minutes' => 60, 'price' => 0, 'is_active' => true,
        ]);

        $this->actAs($this->admin);
        $this->post('/api/registration-event-groups', [
            'name' => 'Trunk or Treat 2026',
            'event_date' => '2026-10-25',
            'location_ids' => [$this->brighton->id],
            'event_links' => [$this->brighton->id => $moved->id],
        ])->assertCreated();
        $moved->update(['location_id' => $this->lansing->id]);
        $lansingId = $this->post('/api/registration-event-groups', [
            'name' => 'Trunk or Treat 2026',
            'event_date' => '2026-10-25',
            'location_ids' => [$this->lansing->id],
        ])->assertCreated()->json('data.id');

        $option = collect($this->getJson('/api/registration-events/event-options')->assertOk()->json('data.events'))->firstWhere('id', $moved->id);
        $this->assertSame([], $option['registration_form_ids']);
        $this->assertSame([$lansingId], array_column($option['registration_groups'], 'id'));
    }

    public function test_an_event_save_racing_another_change_is_refused(): void
    {
        $trunk = $this->makeEvent('Trunk or Treat 2026', '2026-10-25', [$this->brighton, $this->farmington]);
        $sneaked = false;
        $seen = 0;
        RegistrationEventGroup::retrieved(function (RegistrationEventGroup $group) use ($trunk, &$sneaked, &$seen) {
            if ($sneaked || (int) $group->id !== (int) $trunk->id || ++$seen < 2) {
                return;
            }
            $sneaked = true;
            DB::table('registration_events')->insert([
                'company_id' => $this->company->id,
                'group_id' => $trunk->id,
                'location_id' => $this->lansing->id,
                'name' => 'Trunk or Treat 2026',
                'public_code' => 'trunk-or-treat-2026-race01',
                'event_date' => '2026-10-25',
                'is_open' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actAs($this->admin);
        $this->post("/api/registration-event-groups/{$trunk->id}", ['name' => 'Renamed During Race'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'EVENT_CHANGED');
        $this->assertTrue($sneaked);
        $this->assertSame('Trunk or Treat 2026', $trunk->fresh()->name);
        $this->assertSame(0, RegistrationEvent::where('name', 'Renamed During Race')->count());
    }

    public function test_a_form_save_racing_a_move_is_refused(): void
    {
        $source = $this->makeEvent('Glow Night', '2026-11-06', [$this->brighton, $this->farmington]);
        $target = $this->makeEvent('Glow Night', '2026-11-06', [$this->lansing]);
        $form = $this->formAt($source, $this->brighton);
        $sneaked = false;
        $seen = 0;
        RegistrationEventGroup::retrieved(function (RegistrationEventGroup $group) use ($source, $target, $form, &$sneaked, &$seen) {
            if ($sneaked || (int) $group->id !== (int) $source->id || ++$seen < 2) {
                return;
            }
            $sneaked = true;
            DB::table('registration_events')->where('id', $form->id)->update(['group_id' => $target->id]);
        });

        $this->actAs($this->admin);
        $this->post("/api/registration-events/{$form->id}", ['description' => 'Changed during the race'])
            ->assertStatus(409);
        $this->assertTrue($sneaked);
        $this->assertNull(RegistrationEvent::query()->whereKey($form->id)->value('description'));
    }

    public function test_a_manager_save_racing_a_new_location_is_refused(): void
    {
        $this->actAs($this->brightonManager);
        $ownId = $this->post('/api/registration-event-groups', [
            'name' => 'Brighton Glow Night',
            'event_date' => '2026-11-06',
            'location_id' => $this->brighton->id,
        ])->assertCreated()->json('data.id');

        $fired = false;
        DB::listen(function ($query) use ($ownId, &$fired) {
            if ($fired || !str_contains($query->sql, 'select `location_id` from `registration_events`')) {
                return;
            }
            $fired = true;
            DB::table('registration_events')->insert([
                'company_id' => $this->company->id,
                'group_id' => $ownId,
                'location_id' => $this->farmington->id,
                'name' => 'Brighton Glow Night',
                'public_code' => 'brighton-glow-night-race02',
                'event_date' => '2026-11-06',
                'is_open' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->post("/api/registration-event-groups/{$ownId}", ['name' => 'Renamed By Manager'])->assertForbidden();
        $this->assertTrue($fired);
        $this->assertSame(0, RegistrationEvent::where('name', 'Renamed By Manager')->count());
    }

    public function test_a_form_date_racing_next_years_event_is_refused(): void
    {
        $event = $this->makeEvent('Pumpkin Run 2026', '2026-10-25', [$this->brighton]);
        $form = $this->formAt($event, $this->brighton);
        $sneaked = false;
        $seen = 0;
        RegistrationEventGroup::retrieved(function (RegistrationEventGroup $group) use ($event, &$sneaked, &$seen) {
            if ($sneaked || (int) $group->id !== (int) $event->id || ++$seen < 2) {
                return;
            }
            $sneaked = true;
            $nextId = DB::table('registration_event_groups')->insertGetId([
                'company_id' => $this->company->id,
                'name' => 'Pumpkin Run 2027',
                'event_date' => '2026-11-10',
                'previous_group_id' => $event->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('registration_events')->insert([
                'company_id' => $this->company->id,
                'group_id' => $nextId,
                'location_id' => $this->lansing->id,
                'name' => 'Pumpkin Run 2027',
                'public_code' => 'pumpkin-run-2027-race03',
                'event_date' => '2026-11-10',
                'is_open' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actAs($this->admin);
        $this->post("/api/registration-events/{$form->id}", ['event_date' => '2026-11-20'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.event_date.0', "The date must stay before next year's event.");
        $this->assertTrue($sneaked);
        $this->assertSame('2026-10-25', $form->fresh()->event_date->toDateString());
        $this->assertSame('2026-10-25', $event->fresh()->event_date->toDateString());
    }

    private function makeEvent(string $name, string $date, array $locations): RegistrationEventGroup
    {
        $this->actAs($this->admin);
        $id = $this->post('/api/registration-event-groups', [
            'name' => $name,
            'event_date' => $date,
            'location_ids' => array_map(fn (Location $location) => $location->id, $locations),
        ])->assertCreated()->json('data.id');

        return RegistrationEventGroup::findOrFail($id);
    }

    private function formAt(RegistrationEventGroup $group, Location $location): RegistrationEvent
    {
        return RegistrationEvent::where('group_id', $group->id)->where('location_id', $location->id)->firstOrFail();
    }
}
