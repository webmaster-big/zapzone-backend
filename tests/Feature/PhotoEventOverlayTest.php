<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Location;
use App\Models\PhotoOverlay;
use App\Models\RegistrationEvent;
use App\Models\RegistrationEventGroup;
use App\Models\StaffTerminal;
use App\Models\User;
use App\Services\PhotoProcessingService;
use App\Support\SchemaSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\EventRegistrationFixtures;
use Tests\TestCase;

class PhotoEventOverlayTest extends TestCase
{
    use RefreshDatabase;
    use EventRegistrationFixtures;

    private Company $company;

    private Location $brighton;

    private Location $farmington;

    private Location $lansing;

    private User $admin;

    private User $manager;

    private RegistrationEventGroup $trunk;

    private RegistrationEvent $brightonTrunk;

    private RegistrationEvent $farmingtonTrunk;

    private RegistrationEvent $lansingTrunk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');
        Storage::fake('photos');
        config(['gmail.enabled' => false]);

        $this->company = $this->makeCompany('ZapZone Frames');
        $this->brighton = $this->makeVenue($this->company, 'Brighton');
        $this->farmington = $this->makeVenue($this->company, 'Farmington');
        $this->lansing = $this->makeVenue($this->company, 'Lansing');
        $this->admin = $this->makeStaff($this->company, null, 'admin', 'company_admin');
        $this->manager = $this->makeStaff($this->company, $this->brighton, 'manager', 'location_manager');

        $this->at('2026-10-20 12:00');
        $this->trunk = $this->makeEvent('Trunk or Treat 2026', '2026-10-31', [$this->brighton, $this->farmington, $this->lansing]);
        $this->brightonTrunk = $this->formAt($this->trunk, $this->brighton);
        $this->farmingtonTrunk = $this->formAt($this->trunk, $this->farmington);
        $this->lansingTrunk = $this->formAt($this->trunk, $this->lansing);
    }

    public function test_an_event_frame_runs_from_6am_on_its_day_to_6am_after_across_the_dst_change(): void
    {
        $general = $this->frame($this->brighton, 'Everyday frame', ['priority' => 100]);
        $event = $this->frame($this->brighton, 'Trunk frame', ['registration_event_id' => $this->brightonTrunk->id]);
        $resolver = app(PhotoProcessingService::class);

        $this->assertSame($general->id, $resolver->resolveOverlay($this->brighton, $this->local('2026-10-31 05:59'))?->id);
        $this->assertSame($event->id, $resolver->resolveOverlay($this->brighton, $this->local('2026-10-31 06:00'))?->id);
        $this->assertSame($event->id, $resolver->resolveOverlay($this->brighton, $this->local('2026-11-01 00:30'))?->id);
        $this->assertSame($event->id, $resolver->resolveOverlay($this->brighton, Carbon::parse('2026-11-01 10:30:00', 'UTC'))?->id);
        $this->assertSame($general->id, $resolver->resolveOverlay($this->brighton, Carbon::parse('2026-11-01 11:00:00', 'UTC'))?->id);
        $this->assertNull($resolver->resolveOverlay($this->farmington, $this->local('2026-10-31 12:00')));
    }

    public function test_a_multi_day_event_covers_its_earlier_days_and_falls_back_when_the_manage_event_goes(): void
    {
        $week = Event::create([
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
        $form = $this->makeForm($this->brighton, 'Spooky Week', '2026-11-03', ['event_id' => $week->id]);
        $frame = $this->frame($this->brighton, 'Week frame', ['registration_event_id' => $form->id]);
        $resolver = app(PhotoProcessingService::class);

        $this->assertSame($frame->id, $resolver->resolveOverlay($this->brighton, $this->local('2026-10-28 12:00'))?->id);

        $week->delete();

        $this->assertNull($resolver->resolveOverlay($this->brighton, $this->local('2026-10-28 12:00')));
        $this->assertSame($frame->id, $resolver->resolveOverlay($this->brighton, $this->local('2026-11-03 12:00'))?->id);
    }

    public function test_escape_room_photos_never_get_an_event_frame(): void
    {
        $general = $this->frame($this->brighton, 'Everyday frame');
        $this->frame($this->brighton, 'Trunk frame', ['registration_event_id' => $this->brightonTrunk->id, 'priority' => 100]);

        $this->assertSame(
            $general->id,
            app(PhotoProcessingService::class)->resolveOverlay($this->brighton, $this->local('2026-10-31 15:00'), 987654)?->id
        );
    }

    public function test_the_capture_screen_names_the_event_frame_only_on_the_day(): void
    {
        $general = $this->frame($this->brighton, 'Everyday frame');
        $event = $this->frame($this->brighton, 'Trunk frame', ['registration_event_id' => $this->brightonTrunk->id]);

        $this->at('2026-10-30 15:00');
        $this->actAs($this->manager);
        $this->getJson('/api/photo-sessions/context?location_id=' . $this->brighton->id)->assertOk()->assertJsonPath('data.active_overlay.id', $general->id);

        $this->at('2026-10-31 15:00');
        $this->actAs($this->manager);
        $this->getJson('/api/photo-sessions/context?location_id=' . $this->brighton->id)->assertOk()->assertJsonPath('data.active_overlay.id', $event->id);

        $this->actAs($this->manager);
        $overlays = collect($this->getJson('/api/photo-overlays?location_id=' . $this->brighton->id)->assertOk()->json('data.overlays'))->keyBy('id');
        $this->assertTrue($overlays[$event->id]['is_active']);
        $this->assertSame('active', $overlays[$event->id]['status']);
        $this->assertSame('2026-10-31T06:00:00-04:00', $overlays[$event->id]['event']['window_start']);
        $this->assertSame('2026-11-01T06:00:00-05:00', $overlays[$event->id]['event']['window_end']);
        $this->assertFalse($overlays[$general->id]['is_active']);
    }

    public function test_a_frame_whose_form_was_removed_or_moved_is_never_used_again(): void
    {
        $general = $this->frame($this->brighton, 'Everyday frame');
        $removed = $this->frame($this->brighton, 'Trunk frame', ['registration_event_id' => $this->brightonTrunk->id, 'priority' => 50]);
        $solo = $this->makeForm($this->brighton, 'Solo Night', '2026-10-31', [], false);
        $moved = $this->frame($this->brighton, 'Solo frame', ['registration_event_id' => $solo->id, 'priority' => 40]);
        $live = $this->makeForm($this->brighton, 'Pop-up Party', '2026-10-31', [], false);
        $kept = $this->frame($this->brighton, 'Pop-up frame', ['registration_event_id' => $live->id, 'priority' => 10]);

        $this->brightonTrunk->delete();
        $solo->forceFill(['location_id' => $this->farmington->id])->save();

        $this->at('2026-10-31 15:00');
        $this->assertSame($kept->id, app(PhotoProcessingService::class)->resolveOverlay($this->brighton)?->id);

        $this->actAs($this->admin);
        $overlays = collect($this->getJson('/api/photo-overlays?location_id=' . $this->brighton->id)->assertOk()->json('data.overlays'))->keyBy('id');

        foreach ([$removed, $moved] as $frame) {
            $this->assertSame('expired', $overlays[$frame->id]['status']);
            $this->assertTrue($overlays[$frame->id]['event']['location_form_removed']);
            $this->assertFalse($overlays[$frame->id]['is_active']);
        }
        $this->assertTrue($overlays[$kept->id]['is_active']);
        $this->assertFalse($overlays[$general->id]['is_active']);
    }

    public function test_own_hours_narrow_the_event_day_and_hours_outside_it_are_refused(): void
    {
        $this->actAs($this->manager);
        $created = $this->upload($this->brighton, [
            'registration_event_id' => $this->brightonTrunk->id,
            'starts_at' => '2026-10-31 17:00:00',
            'ends_at' => '2026-10-31 21:00:00',
        ])->assertCreated()->json('data');

        $resolver = app(PhotoProcessingService::class);
        $this->assertNull($resolver->resolveOverlay($this->brighton, $this->local('2026-10-31 16:59')));
        $this->assertSame($created['id'], $resolver->resolveOverlay($this->brighton, $this->local('2026-10-31 17:00'))?->id);
        $this->assertSame($created['id'], $resolver->resolveOverlay($this->brighton, $this->local('2026-10-31 21:00'))?->id);
        $this->assertNull($resolver->resolveOverlay($this->brighton, $this->local('2026-10-31 21:00')->addSecond()));

        $this->actAs($this->manager);
        $this->upload($this->brighton, [
            'registration_event_id' => $this->brightonTrunk->id,
            'starts_at' => '2026-10-30 10:00:00',
            'ends_at' => '2026-10-30 12:00:00',
        ])->assertStatus(422)->assertJsonPath('message', fn ($message) => str_contains($message, 'fall outside the event'));

        $this->brightonTrunk->forceFill(['event_date' => '2026-11-02'])->save();

        $this->actAs($this->manager);
        $presented = collect($this->getJson('/api/photo-overlays?location_id=' . $this->brighton->id)->json('data.overlays'))->firstWhere('id', $created['id']);
        $this->assertTrue($presented['event']['never_runs']);
        $this->assertSame('expired', $presented['status']);
    }

    public function test_frames_for_two_events_on_one_day_are_flagged_and_the_winner_matches_the_resolver(): void
    {
        $party = $this->makeForm($this->brighton, 'Costume Party', '2026-10-31', [], false);
        $low = $this->frame($this->brighton, 'Trunk frame', ['registration_event_id' => $this->brightonTrunk->id, 'priority' => 10]);
        $high = $this->frame($this->brighton, 'Party frame', ['registration_event_id' => $party->id, 'priority' => 20]);
        $this->frame($this->brighton, 'Everyday frame', ['priority' => 90]);
        $this->frame($this->brighton, 'Old summer frame', ['starts_at' => '2026-07-01 00:00:00', 'ends_at' => '2026-07-31 00:00:00']);
        $this->frame($this->brighton, 'Old fall frame', ['starts_at' => '2026-07-15 00:00:00', 'ends_at' => '2026-08-15 00:00:00']);

        $conflicts = app(PhotoProcessingService::class)->overlayConflicts($this->brighton->id);

        $this->assertCount(1, $conflicts);
        $this->assertSame([$high->id, $low->id], [$conflicts[0]['overlay_id'], $conflicts[0]['conflicts_with_id']]);
        $this->assertSame($high->id, $conflicts[0]['winner_id']);
        $this->assertSame('Party frame', $conflicts[0]['winner_name']);
        $this->assertSame($high->id, app(PhotoProcessingService::class)->resolveOverlay($this->brighton, $this->local('2026-10-31 15:00'))?->id);
    }

    public function test_an_event_day_must_be_this_locations_own_and_still_to_come(): void
    {
        $other = $this->makeCompany('Elsewhere');
        $elsewhere = $this->makeForm($this->makeVenue($other, 'Detroit'), 'Trunk or Treat 2026', '2026-10-31');
        $past = $this->makeForm($this->brighton, 'Harvest Fest', '2026-10-18');

        foreach ([$this->farmingtonTrunk->id, $elsewhere->id, $past->id] as $value) {
            $this->actAs($this->admin);
            $this->upload($this->brighton, ['registration_event_id' => $value])
                ->assertStatus(422)
                ->assertJsonPath('message', 'Choose one of this location\'s upcoming event days, or leave the overlay for all photos.');
        }

        $this->actAs($this->admin);
        $everyday = $this->upload($this->brighton, ['registration_event_id' => ''])->assertCreated();
        $this->assertNull($everyday->json('data.registration_event_id'));
        $this->assertSame(1, PhotoOverlay::count());
        $this->assertCount(1, Storage::disk('public')->allFiles('photo-overlays/' . $this->brighton->id));
    }

    public function test_finished_frames_can_still_be_turned_off_and_a_refused_update_keeps_the_image(): void
    {
        $frame = $this->frame($this->brighton, 'Trunk frame', ['registration_event_id' => $this->brightonTrunk->id]);
        $this->at('2026-11-05 12:00');

        $this->actAs($this->manager);
        $this->post("/api/photo-overlays/{$frame->id}", ['is_enabled' => '0'])->assertOk()->assertJsonPath('data.status', 'disabled');

        $this->actAs($this->manager);
        $this->post("/api/photo-overlays/{$frame->id}", [
            'image' => UploadedFile::fake()->image('new.png', 1920, 1080),
            'package_id' => 4242,
        ])->assertStatus(422);

        Storage::disk('public')->assertExists($frame->image_path);
        $this->assertCount(1, Storage::disk('public')->allFiles('photo-overlays'));
        $this->assertSame($frame->image_path, $frame->fresh()->image_path);
    }

    public function test_admins_can_add_a_frame_to_every_location_in_the_event(): void
    {
        $this->lansingTrunk->forceFill(['event_date' => '2026-10-19'])->save();

        $this->actAs($this->admin);
        $response = $this->upload($this->brighton, ['registration_event_id' => $this->brightonTrunk->id, 'also_other_locations' => '1'])
            ->assertCreated()
            ->assertJsonPath('copies.created.0.location_name', 'Farmington | Zap Zone')
            ->assertJsonPath('copies.skipped.0.location_name', 'Lansing | Zap Zone')
            ->assertJsonPath('copies.skipped.0.reason', 'Its event day has passed.');

        $brightonFrame = PhotoOverlay::findOrFail($response->json('data.id'));
        $copy = PhotoOverlay::where('location_id', $this->farmington->id)->firstOrFail();
        $this->assertSame($this->farmingtonTrunk->id, (int) $copy->registration_event_id);
        $this->assertSame($this->company->id, (int) $copy->company_id);
        $this->assertNotSame($brightonFrame->image_path, $copy->image_path);
        $this->assertStringStartsWith('photo-overlays/' . $this->farmington->id . '/', $copy->image_path);

        $this->actAs($this->admin);
        $this->delete("/api/photo-overlays/{$brightonFrame->id}")->assertOk();
        Storage::disk('public')->assertExists($copy->image_path);

        $this->actAs($this->admin);
        $this->upload($this->brighton, ['registration_event_id' => $this->brightonTrunk->id, 'also_other_locations' => '1'])
            ->assertCreated()
            ->assertJsonPath('copies.created', [])
            ->assertJsonPath('copies.skipped.0.reason', 'It already has a photo frame.');
    }

    public function test_only_company_admins_can_copy_frames_and_a_lone_form_gets_one_frame(): void
    {
        $this->actAs($this->manager);
        $this->upload($this->brighton, ['registration_event_id' => $this->brightonTrunk->id, 'also_other_locations' => '1'])->assertForbidden();

        $terminal = StaffTerminal::forceCreate([
            'company_id' => $this->company->id,
            'location_id' => $this->brighton->id,
            'device_id' => 'front-desk-brighton',
            'label' => 'Front desk',
            'token_hash' => hash('sha256', 'terminal-secret'),
            'ceiling_role' => 'location_manager',
        ]);
        $this->actAs($this->admin, ['pin', 'role:location_manager', 'terminal:' . $terminal->id]);
        $this->upload($this->brighton, ['registration_event_id' => $this->brightonTrunk->id, 'also_other_locations' => '1'])->assertForbidden();
        $this->assertSame(0, PhotoOverlay::count());

        $solo = $this->makeForm($this->brighton, 'Solo Night', '2026-10-31', [], false);
        $this->actAs($this->admin);
        $this->upload($this->brighton, ['registration_event_id' => $solo->id, 'also_other_locations' => '1'])
            ->assertCreated()
            ->assertJsonPath('copies.created', []);
        $this->assertSame(1, PhotoOverlay::count());
    }

    public function test_event_days_are_listed_for_the_location_with_siblings_for_admins_only(): void
    {
        $this->makeForm($this->brighton, 'Harvest Fest', '2026-10-18');
        $this->frame($this->farmington, 'Farmington trunk frame', ['registration_event_id' => $this->farmingtonTrunk->id]);

        $this->actAs($this->admin);
        $forms = $this->getJson('/api/photo-overlays/event-days?location_id=' . $this->brighton->id)
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->json('data.forms');
        $this->assertSame(['Trunk or Treat 2026'], array_column($forms, 'name'));
        $siblings = collect($forms[0]['siblings'])->keyBy('location_name');
        $this->assertFalse($siblings['Farmington | Zap Zone']['usable']);
        $this->assertSame('It already has a photo frame.', $siblings['Farmington | Zap Zone']['reason']);
        $this->assertTrue($siblings['Lansing | Zap Zone']['usable']);

        $this->actAs($this->manager);
        $this->getJson('/api/photo-overlays/event-days?location_id=' . $this->brighton->id)->assertOk()->assertJsonPath('data.forms.0.siblings', []);

        $this->actAs($this->manager);
        $this->getJson('/api/photo-overlays/event-days?location_id=' . $this->farmington->id)->assertForbidden();

        $stranger = $this->makeStaff($this->makeCompany('Elsewhere'), null, 'stranger', 'company_admin');
        $this->actAs($stranger);
        $this->getJson('/api/photo-overlays/event-days?location_id=' . $this->brighton->id)->assertForbidden();

        $columns = new \ReflectionProperty(SchemaSupport::class, 'columns');

        try {
            $columns->setValue(null, ['photo_overlays.registration_event_id' => false]);
            $this->actAs($this->admin);
            $this->getJson('/api/photo-overlays/event-days?location_id=' . $this->brighton->id)
                ->assertOk()
                ->assertJsonPath('data.available', false)
                ->assertJsonPath('data.forms', []);
        } finally {
            SchemaSupport::flush();
        }
    }

    public function test_registration_pages_show_frames_and_a_frame_holds_its_form_in_place(): void
    {
        $frame = $this->frame($this->brighton, 'Trunk frame', ['registration_event_id' => $this->brightonTrunk->id]);
        $solo = $this->makeForm($this->brighton, 'Solo Night', '2026-10-31', [], false);
        $this->frame($this->brighton, 'Solo frame', ['registration_event_id' => $solo->id]);

        $this->actAs($this->admin);
        $this->getJson("/api/registration-events/{$this->brightonTrunk->id}")
            ->assertOk()
            ->assertJsonPath('data.photo_frames.first_day', '2026-10-31')
            ->assertJsonPath('data.photo_frames.frames.0.id', $frame->id)
            ->assertJsonPath('data.photo_frames.frames.0.status', 'scheduled');

        $this->actAs($this->admin);
        $forms = collect($this->getJson("/api/registration-event-groups/{$this->trunk->id}")->assertOk()->json('data.forms'))->keyBy('location_id');
        $this->assertSame('Trunk frame', $forms[$this->brighton->id]['photo_frames']['frames'][0]['name']);
        $this->assertSame([], $forms[$this->farmington->id]['photo_frames']['frames']);

        $this->actAs($this->admin);
        $this->post("/api/registration-events/{$solo->id}", ['location_id' => $this->farmington->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.location_id.0', "The location can't be changed once people have registered, another form links to this one, or a photo frame uses it.");

        $this->actAs($this->admin);
        $this->post("/api/registration-event-groups/{$this->trunk->id}", [
            'location_ids' => [$this->farmington->id, $this->lansing->id],
        ])->assertStatus(409)
            ->assertJsonPath('code', 'REMOVAL_NEEDS_CONFIRMATION')
            ->assertJsonPath('message', 'Removing these locations deletes their forms, QR codes and registration lists: Brighton | Zap Zone (a photo frame). Their photo frames stop being used.');

        $columns = new \ReflectionProperty(SchemaSupport::class, 'columns');

        try {
            $columns->setValue(null, ['photo_overlays.registration_event_id' => false]);
            $this->actAs($this->admin);
            $this->assertArrayNotHasKey('photo_frames', $this->getJson("/api/registration-events/{$this->brightonTrunk->id}")->assertOk()->json('data'));
        } finally {
            SchemaSupport::flush();
        }
    }

    private function frame(Location $location, string $name, array $attributes = []): PhotoOverlay
    {
        $path = 'photo-overlays/' . $location->id . '/' . \Illuminate\Support\Str::slug($name) . '-' . uniqid() . '.png';
        Storage::disk('public')->put($path, UploadedFile::fake()->image('frame.png', 1920, 1080)->getContent());

        return PhotoOverlay::create(array_merge([
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'name' => $name,
            'image_path' => $path,
            'priority' => 0,
        ], $attributes));
    }

    private function upload(Location $location, array $fields): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/photo-overlays', array_merge([
            'location_id' => $location->id,
            'name' => 'Uploaded frame',
            'image' => UploadedFile::fake()->image('frame.png', 1920, 1080),
        ], $fields));
    }

    private function local(string $time): Carbon
    {
        return Carbon::parse($time, 'America/Detroit');
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
