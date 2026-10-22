<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Location;
use App\Models\RegistrationEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\EventRegistrationFixtures;
use Tests\TestCase;

class EventRegistrationEventLinkTest extends TestCase
{
    use RefreshDatabase;
    use EventRegistrationFixtures;

    private Company $company;

    private Location $brighton;

    private Location $farmington;

    private Location $lansing;

    private User $admin;

    private User $manager;

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
        $this->lansing = $this->makeVenue($this->makeCompany('Other Co'), 'Lansing');
        $this->admin = $this->makeStaff($this->company, null, 'boss', 'company_admin');
        $this->manager = $this->makeStaff($this->company, $this->brighton, 'brightonmanager', 'location_manager');
    }

    public function test_an_admin_creates_one_form_per_selected_location(): void
    {
        $this->actAs($this->admin);

        $response = $this->post('/api/registration-events', [
            'name' => 'Halloween Bash 2026',
            'location_ids' => [$this->brighton->id, $this->farmington->id],
            'event_date' => '2026-10-31',
            'description' => 'Costumes welcome.',
        ])->assertCreated();

        $created = $response->json('created');
        $this->assertCount(2, $created);
        $this->assertEqualsCanonicalizing([$this->brighton->id, $this->farmington->id], array_column($created, 'location_id'));
        $this->assertNotSame($created[0]['public_code'], $created[1]['public_code']);
        $this->assertSame($created[0]['id'], $response->json('data.id'));
        $this->assertSame(2, RegistrationEvent::where('description', 'Costumes welcome.')->count());
        $this->assertSame(2, \App\Models\ActivityLog::where('action', 'registration_event_created')->count());

        $this->post('/api/registration-events', [
            'name' => 'Dupes',
            'location_ids' => [$this->brighton->id, $this->brighton->id],
            'event_date' => '2026-10-31',
        ])->assertUnprocessable();
        $this->post('/api/registration-events', [
            'name' => 'Elsewhere',
            'location_ids' => [$this->brighton->id, $this->lansing->id],
            'event_date' => '2026-10-31',
        ])->assertUnprocessable()->assertJsonValidationErrors(['location_ids']);
        $this->assertSame(2, RegistrationEvent::count());
    }

    public function test_a_manager_cannot_create_forms_for_another_location(): void
    {
        $this->actAs($this->manager);

        $this->post('/api/registration-events', [
            'name' => 'Sneaky',
            'location_ids' => [$this->brighton->id, $this->farmington->id],
            'event_date' => '2026-10-31',
        ])->assertForbidden();

        $this->assertSame(0, RegistrationEvent::count());
    }

    public function test_last_years_edition_is_linked_one_location_at_a_time(): void
    {
        $this->at('2025-10-10 12:00:00');
        $last = $this->makeForm($this->brighton, 'Halloween Bash 2025', '2025-10-31');
        $this->at('2026-10-15 18:00:00');

        $this->actAs($this->admin);
        $this->post('/api/registration-events', [
            'name' => 'Halloween Bash 2026',
            'location_ids' => [$this->brighton->id, $this->farmington->id],
            'event_date' => '2026-10-31',
            'previous_edition_id' => $last->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['previous_edition_id']);

        $this->post('/api/registration-events', [
            'name' => 'Halloween Bash 2026',
            'location_ids' => [$this->brighton->id],
            'event_date' => '2026-10-31',
            'previous_edition_id' => $last->id,
        ])->assertCreated()->assertJsonPath('data.previous_edition.id', $last->id);
    }

    public function test_starting_from_an_event_links_each_location_to_its_own_event_and_uses_its_picture(): void
    {
        Storage::disk('public')->put('images/events/halloween.png', $this->pngBytes(2000, 1000));
        $brightonEvent = $this->makeTicketedEvent($this->brighton, 'Halloween Bash', '2026-10-31', 'images/events/halloween.png');
        $farmingtonEvent = $this->makeTicketedEvent($this->farmington, '  halloween bash ', '2026-10-30');
        $this->makeTicketedEvent($this->farmington, 'Christmas Party', '2026-12-20');

        $this->actAs($this->admin);
        $response = $this->post('/api/registration-events', [
            'name' => 'Halloween Bash 2026',
            'location_ids' => [$this->brighton->id, $this->farmington->id],
            'event_date' => '2026-11-01',
            'event_id' => $brightonEvent->id,
            'event_links' => [$this->farmington->id => $farmingtonEvent->id],
            'use_event_image' => '1',
        ])->assertCreated();

        $byLocation = collect($response->json('created'))->keyBy('location_id');
        $this->assertSame($brightonEvent->id, $byLocation[$this->brighton->id]['event']['id']);
        $this->assertSame($farmingtonEvent->id, $byLocation[$this->farmington->id]['event']['id']);
        $this->assertSame('2026-10-31', $byLocation[$this->brighton->id]['event']['start_date']);
        $this->assertSame('2026-11-01', $byLocation[$this->brighton->id]['event_date']);
        $this->assertSame('2026-10-30', $byLocation[$this->farmington->id]['event_date']);

        $path = $byLocation[$this->brighton->id]['header_image_path'];
        $this->assertMatchesRegularExpression('#^registration-events/[A-Za-z0-9]{40}\.jpg$#', $path);
        $this->assertSame($path, $byLocation[$this->farmington->id]['header_image_path']);
        $this->assertSame(1600, $byLocation[$this->farmington->id]['header_image_width']);
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertExists('images/events/halloween.png');
    }

    public function test_without_a_link_a_location_is_left_unlinked_on_its_chosen_date(): void
    {
        $brightonEvent = $this->makeTicketedEvent($this->brighton, 'Halloween Bash', '2026-10-31');
        $this->makeTicketedEvent($this->farmington, 'Halloween Bash', '2025-10-31');

        $this->actAs($this->admin);
        $created = collect($this->post('/api/registration-events', [
            'name' => 'Halloween Bash 2026',
            'location_ids' => [$this->brighton->id, $this->farmington->id],
            'event_date' => '2026-10-31',
            'event_id' => $brightonEvent->id,
        ])->assertCreated()->json('created'))->keyBy('location_id');

        $this->assertSame($brightonEvent->id, $created[$this->brighton->id]['event']['id']);
        $this->assertNull($created[$this->farmington->id]['event']);
        $this->assertSame('2026-10-31', $created[$this->farmington->id]['event_date']);
    }

    public function test_each_link_must_be_an_event_at_that_chosen_location(): void
    {
        $brightonEvent = $this->makeTicketedEvent($this->brighton, 'Halloween Bash', '2026-10-31');
        $farmingtonEvent = $this->makeTicketedEvent($this->farmington, 'Halloween Bash', '2026-10-30');
        $otherCompanyEvent = $this->makeTicketedEvent($this->lansing, 'Halloween Bash', '2026-10-31');
        $deletedEvent = $this->makeTicketedEvent($this->farmington, 'Halloween Bash', '2026-11-02');
        $deletedEvent->delete();

        $this->actAs($this->admin);
        $base = ['name' => 'Halloween Bash 2026', 'event_date' => '2026-10-31', 'location_ids' => [$this->brighton->id, $this->farmington->id]];

        $this->post('/api/registration-events', $base + ['event_links' => [$this->farmington->id => $brightonEvent->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['event_links']);
        $this->post('/api/registration-events', $base + ['event_links' => [$this->farmington->id => $otherCompanyEvent->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['event_links']);
        $this->post('/api/registration-events', $base + ['event_links' => [$this->farmington->id => $deletedEvent->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['event_links']);
        $this->post('/api/registration-events', $base + ['event_links' => [$this->lansing->id => $otherCompanyEvent->id]])
            ->assertUnprocessable()->assertJsonValidationErrors(['event_links']);
        $this->post('/api/registration-events', array_merge($base, ['location_ids' => [$this->brighton->id], 'event_links' => [$this->farmington->id => $farmingtonEvent->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['event_links']);
        $this->assertSame(0, RegistrationEvent::count());

        $this->post('/api/registration-events', $base + ['event_links' => [$this->brighton->id => $brightonEvent->id, $this->farmington->id => '']])
            ->assertCreated();
        $this->assertSame([$brightonEvent->id, null], RegistrationEvent::orderBy('location_id')->pluck('event_id')->all());
    }

    public function test_a_missing_event_picture_still_creates_the_forms(): void
    {
        $event = $this->makeTicketedEvent($this->brighton, 'Halloween Bash', '2026-10-31', 'images/events/gone.png');

        $this->actAs($this->admin);
        $this->post('/api/registration-events', [
            'name' => 'Halloween Bash 2026',
            'location_id' => $this->brighton->id,
            'event_date' => '2026-10-31',
            'event_id' => $event->id,
            'use_event_image' => '1',
        ])->assertCreated()
            ->assertJsonPath('data.event.id', $event->id)
            ->assertJsonPath('data.header_image_path', null);
    }

    public function test_an_event_outside_your_locations_cannot_be_linked(): void
    {
        $farmingtonEvent = $this->makeTicketedEvent($this->farmington, 'Halloween Bash', '2026-10-31');
        $otherCompanyEvent = $this->makeTicketedEvent($this->lansing, 'Halloween Bash', '2026-10-31');

        $this->actAs($this->manager);
        $this->post('/api/registration-events', [
            'name' => 'Halloween Bash 2026',
            'location_id' => $this->brighton->id,
            'event_date' => '2026-10-31',
            'event_id' => $farmingtonEvent->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['event_id']);

        $this->actAs($this->admin);
        $this->post('/api/registration-events', [
            'name' => 'Halloween Bash 2026',
            'location_id' => $this->brighton->id,
            'event_date' => '2026-10-31',
            'event_id' => $otherCompanyEvent->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['event_id']);

        $this->assertSame(0, RegistrationEvent::count());
    }

    public function test_event_options_show_your_events_and_their_forms(): void
    {
        $brightonEvent = $this->makeTicketedEvent($this->brighton, 'Halloween Bash', '2026-10-31', 'data:image/png;base64,AAAA');
        $farmingtonEvent = $this->makeTicketedEvent($this->farmington, 'Halloween Bash', '2026-10-31', 'images/events/f.png');
        $this->makeTicketedEvent($this->lansing, 'Halloween Bash', '2026-10-31');
        $form = $this->makeForm($this->brighton, 'Halloween Bash 2026', '2026-10-31', ['event_id' => $brightonEvent->id]);

        $this->actAs($this->manager);
        $this->getJson('/api/registration-events/event-options')
            ->assertOk()
            ->assertJsonCount(1, 'data.events')
            ->assertJsonPath('data.events.0.id', $brightonEvent->id)
            ->assertJsonPath('data.events.0.image', null)
            ->assertJsonPath('data.events.0.has_image', true)
            ->assertJsonPath('data.events.0.registration_form_ids', [$form->id]);

        $this->actAs($this->admin);
        $ids = collect($this->getJson('/api/registration-events/event-options')->assertOk()->json('data.events'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$brightonEvent->id, $farmingtonEvent->id], $ids);
    }

    public function test_a_form_can_be_linked_and_unlinked_and_moving_it_drops_the_link(): void
    {
        $brightonEvent = $this->makeTicketedEvent($this->brighton, 'Halloween Bash', '2026-10-31');
        $farmingtonEvent = $this->makeTicketedEvent($this->farmington, 'Halloween Bash', '2026-10-31');
        $form = $this->makeForm($this->brighton, 'Halloween Bash 2026', '2026-10-31', grouped: false);

        $this->actAs($this->admin);
        $this->post("/api/registration-events/{$form->id}", ['event_id' => $farmingtonEvent->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['event_id']);
        $this->post("/api/registration-events/{$form->id}", ['event_id' => $brightonEvent->id])
            ->assertOk()->assertJsonPath('data.event.name', 'Halloween Bash');
        $this->getJson('/api/registration-events')->assertJsonPath('data.events.0.event.id', $brightonEvent->id);
        $this->post("/api/registration-events/{$form->id}", ['event_id' => ''])
            ->assertOk()->assertJsonPath('data.event', null);

        $this->post("/api/registration-events/{$form->id}", ['event_id' => $brightonEvent->id])->assertOk();
        $this->post("/api/registration-events/{$form->id}", ['location_id' => $this->farmington->id])
            ->assertOk()
            ->assertJsonPath('data.location_id', $this->farmington->id)
            ->assertJsonPath('data.event', null);

        $stays = $this->makeForm($this->brighton, 'Halloween Bash 2026 B', '2026-10-31', ['event_id' => $brightonEvent->id]);
        $this->getJson("/api/registration-events/{$stays->id}")->assertJsonPath('data.event.id', $brightonEvent->id);
        $brightonEvent->delete();
        $this->getJson("/api/registration-events/{$stays->id}")->assertOk()->assertJsonPath('data.event', null);
        $this->assertNotContains($brightonEvent->id, collect($this->getJson('/api/registration-events/event-options')->json('data.events'))->pluck('id')->all());
        $this->post("/api/registration-events/{$stays->id}", ['event_id' => $brightonEvent->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['event_id']);
    }

    public function test_an_event_moved_to_another_location_no_longer_shows_its_old_form(): void
    {
        $event = $this->makeTicketedEvent($this->brighton, 'Halloween Week', '2026-10-29', null, '2026-10-31');
        $form = $this->makeForm($this->brighton, 'Halloween Week 2026', '2026-10-31', ['event_id' => $event->id]);
        $this->getJson('/api/registration-forms/' . $form->public_code)->assertOk()->assertJsonPath('data.starts_on', '2026-10-29');
        $event->forceFill(['location_id' => $this->farmington->id])->save();

        $this->actAs($this->admin);
        $option = collect($this->getJson('/api/registration-events/event-options')->assertOk()->json('data.events'))->firstWhere('id', $event->id);
        $this->assertSame([], $option['registration_form_ids']);
        $this->getJson("/api/registration-events/{$form->id}")->assertOk()->assertJsonPath('data.event', null);
        $this->getJson('/api/registration-forms/' . $form->public_code)->assertOk()->assertJsonPath('data.starts_on', null);

        $kept = $this->makeTicketedEvent($this->brighton, 'Fall Week', '2026-11-02', null, '2026-11-06');
        $keptForm = $this->makeForm($this->brighton, 'Fall Week 2026', '2026-11-06', ['event_id' => $kept->id]);
        $this->getJson('/api/registration-forms/' . $keptForm->public_code)->assertJsonPath('data.starts_on', '2026-11-02');
        $kept->delete();
        $this->getJson('/api/registration-forms/' . $keptForm->public_code)->assertOk()->assertJsonPath('data.starts_on', null);
    }

    public function test_event_options_can_look_up_one_event_by_id(): void
    {
        $brightonEvent = $this->makeTicketedEvent($this->brighton, 'Halloween Bash', '2026-10-31');
        $farmingtonEvent = $this->makeTicketedEvent($this->farmington, 'Halloween Bash', '2026-10-31');
        $otherCompanyEvent = $this->makeTicketedEvent($this->lansing, 'Halloween Bash', '2026-10-31');

        $this->actAs($this->manager);
        $this->getJson("/api/registration-events/event-options?id={$brightonEvent->id}")
            ->assertOk()->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.id', $brightonEvent->id);
        $this->getJson("/api/registration-events/event-options?id={$farmingtonEvent->id}")->assertOk()->assertJsonCount(0, 'data.events');
        $this->getJson("/api/registration-events/event-options?id={$otherCompanyEvent->id}")->assertOk()->assertJsonCount(0, 'data.events');
        $this->getJson('/api/registration-events/event-options?id=abc')->assertOk()->assertJsonCount(1, 'data.events');
    }

    public function test_a_rejected_link_on_edit_leaves_no_uploaded_file_behind(): void
    {
        $farmingtonEvent = $this->makeTicketedEvent($this->farmington, 'Halloween Bash', '2026-10-31');
        $form = $this->makeForm($this->brighton, 'Halloween Bash 2026', '2026-10-31');

        $this->actAs($this->admin);
        $this->post("/api/registration-events/{$form->id}", [
            'event_id' => $farmingtonEvent->id,
            'header_image' => UploadedFile::fake()->image('poster.png', 1200, 600),
        ])->assertUnprocessable()->assertJsonValidationErrors(['event_id']);

        $this->assertSame([], Storage::disk('public')->allFiles('registration-events'));
        $this->assertNull($form->fresh()->header_image_path);
    }

    public function test_the_public_form_says_when_a_multi_day_event_starts(): void
    {
        $camp = Event::create([
            'location_id' => $this->brighton->id,
            'name' => 'Winter Break Camp',
            'date_type' => 'date_range',
            'start_date' => '2026-12-21',
            'end_date' => '2027-01-03',
            'time_start' => '09:00:00',
            'time_end' => '15:00:00',
            'interval_minutes' => 60,
            'price' => 10,
            'is_active' => true,
        ]);
        $oneDay = $this->makeTicketedEvent($this->brighton, 'Halloween Bash', '2026-10-31');

        $this->actAs($this->admin);
        $campForm = $this->post('/api/registration-events', [
            'name' => 'Winter Break Camp',
            'location_id' => $this->brighton->id,
            'event_date' => '2027-01-03',
            'event_id' => $camp->id,
        ])->assertCreated()->json('data');
        $partyForm = $this->post('/api/registration-events', [
            'name' => 'Halloween Bash 2026',
            'location_id' => $this->brighton->id,
            'event_date' => '2026-10-31',
            'event_id' => $oneDay->id,
        ])->assertCreated()->json('data');

        $this->getJson('/api/registration-forms/' . $campForm['public_code'])
            ->assertOk()
            ->assertJsonPath('data.event_date', '2027-01-03')
            ->assertJsonPath('data.starts_on', '2026-12-21');
        $this->getJson('/api/registration-forms/' . $partyForm['public_code'])->assertOk()->assertJsonPath('data.starts_on', null);

        $lateParty = $this->makeForm($this->brighton, 'Halloween Bash late', '2026-11-01', ['event_id' => $oneDay->id]);
        $this->getJson('/api/registration-forms/' . $lateParty->public_code)->assertOk()->assertJsonPath('data.starts_on', null);
        $lateCamp = $this->makeForm($this->brighton, 'Winter Break Camp late', '2027-01-05', ['event_id' => $camp->id]);
        $this->getJson('/api/registration-forms/' . $lateCamp->public_code)->assertOk()->assertJsonPath('data.starts_on', null);
    }

    public function test_next_years_edition_can_reuse_the_header_image_safely(): void
    {
        $this->at('2025-10-10 12:00:00');
        $this->actAs($this->admin);
        $last = $this->post('/api/registration-events', [
            'name' => 'Halloween Bash 2025',
            'location_id' => $this->brighton->id,
            'event_date' => '2025-10-31',
            'header_image' => UploadedFile::fake()->image('poster.png', 1200, 600),
        ])->assertCreated()->json('data');

        $this->at('2026-10-15 18:00:00');
        $this->actAs($this->admin);
        $next = $this->post('/api/registration-events', [
            'name' => 'Halloween Bash 2026',
            'location_id' => $this->brighton->id,
            'event_date' => '2026-10-31',
            'previous_edition_id' => $last['id'],
            'copy_header_image_from' => $last['id'],
        ])->assertCreated()
            ->assertJsonPath('data.previous_edition.id', $last['id'])
            ->assertJsonPath('data.header_image_path', $last['header_image_path'])
            ->assertJsonPath('data.header_image_width', 1200)
            ->json('data');

        $this->post("/api/registration-events/{$next['id']}", ['remove_header_image' => '1'])->assertOk();
        Storage::disk('public')->assertExists($last['header_image_path']);

        $this->actAs($this->manager);
        $farmingtonForm = $this->makeForm($this->farmington, 'Hidden', '2026-11-01', ['header_image_path' => 'registration-events/secret.jpg']);
        $this->post('/api/registration-events', [
            'name' => 'Copycat',
            'location_id' => $this->brighton->id,
            'event_date' => '2026-11-02',
            'copy_header_image_from' => $farmingtonForm->id,
        ])->assertCreated()->assertJsonPath('data.header_image_path', null);
    }

    private function makeTicketedEvent(Location $location, string $name, string $date, ?string $image = null, ?string $endDate = null): Event
    {
        return Event::create([
            'location_id' => $location->id,
            'name' => $name,
            'description' => "{$name} at {$location->city}",
            'image' => $image,
            'date_type' => $endDate === null ? 'one_time' : 'date_range',
            'start_date' => $date,
            'end_date' => $endDate,
            'time_start' => '18:00:00',
            'time_end' => '21:00:00',
            'interval_minutes' => 60,
            'price' => 10,
            'is_active' => true,
        ]);
    }

    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 120, 30, 160));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }
}
