<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\RegistrationContact;
use App\Models\RegistrationEntry;
use App\Models\RegistrationEvent;
use App\Models\User;
use App\Models\WaiverProfile;
use App\Support\SchemaSupport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\EventRegistrationFixtures;
use Tests\TestCase;

class EventRegistrationTest extends TestCase
{
    use RefreshDatabase;
    use EventRegistrationFixtures;

    private Company $company;

    private Location $brighton;

    private Location $farmington;

    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false, 'app.frontend_url' => 'https://booking.example.test']);
        Mail::fake();
        Storage::fake('public');
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = $this->makeCompany('Fun Co');
        $this->brighton = $this->makeVenue($this->company, 'Brighton');
        $this->farmington = $this->makeVenue($this->company, 'Farmington');
        $this->admin = $this->makeStaff($this->company, null, 'boss', 'company_admin');
        $this->manager = $this->makeStaff($this->company, $this->brighton, 'brightonmanager', 'location_manager');

        $this->at('2026-10-15 18:00:00');
    }

    public function test_the_public_form_shows_only_what_a_guest_needs(): void
    {
        $form = $this->makeForm($this->brighton, 'Halloween Bash 2026', '2026-10-31', [
            'description' => "Costumes welcome.\nAges 5+.",
            'confirmation_message' => 'See you there!',
            'header_image_path' => 'registration-events/poster.jpg',
            'header_image_width' => 1600,
            'header_image_height' => 800,
        ]);
        $this->signUp($form, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();

        $response = $this->getJson('/api/registration-forms/' . $form->public_code)
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => [
                    'code' => $form->public_code,
                    'name' => 'Halloween Bash 2026',
                    'description' => "Costumes welcome.\nAges 5+.",
                    'event_date' => '2026-10-31',
                    'starts_on' => null,
                    'status' => 'open',
                    'location_name' => 'Brighton | Zap Zone',
                    'location_logo_path' => null,
                    'header_image_path' => 'registration-events/poster.jpg',
                    'header_image_width' => 1600,
                    'header_image_height' => 800,
                    'confirmation_message' => 'See you there!',
                    'next_edition' => null,
                ],
            ]);

        $this->assertStringNotContainsString('jane@example.com', $response->getContent());
        $this->getJson('/api/registration-forms/' . strtoupper($form->public_code))->assertOk();
        $this->assertMatchesRegularExpression('/^halloween-bash-2026-[a-z2-9]{6}$/', $form->public_code);
    }

    public function test_unknown_and_deleted_forms_are_not_found(): void
    {
        $form = $this->makeForm($this->brighton, 'Christmas Party', '2026-12-20');
        $form->delete();

        $this->getJson('/api/registration-forms/nothing-here-abcdef')->assertNotFound()
            ->assertJsonPath('message', 'This registration form could not be found.');
        $this->getJson('/api/registration-forms/' . $form->public_code)->assertNotFound();
        $this->signUp($form, 'Jane Doe', 'jane@example.com', '7345550142')->assertNotFound();

        $this->assertSame(0, RegistrationEntry::count());
    }

    public function test_a_registration_stores_one_entry_linked_to_a_shared_contact(): void
    {
        $form = $this->makeForm($this->brighton, 'Halloween Bash', '2026-10-31', ['confirmation_message' => 'Bring a costume']);

        $response = $this->signUp($form, '  Jane   Doe ', ' Jane.Doe@Example.COM ', '(734) 555-0142')
            ->assertCreated()
            ->assertExactJson([
                'success' => true,
                'data' => ['event_name' => 'Halloween Bash', 'confirmation_message' => 'Bring a costume'],
            ]);

        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));

        $entry = RegistrationEntry::sole();
        $contact = RegistrationContact::sole();
        $this->assertSame('Jane Doe', $entry->name);
        $this->assertSame('janedoe', $entry->name_key);
        $this->assertSame('Jane.Doe@Example.COM', $entry->email);
        $this->assertSame('7345550142', $entry->phone);
        $this->assertSame($contact->id, $entry->registration_contact_id);
        $this->assertSame($this->brighton->id, $entry->location_id);
        $this->assertSame($this->company->id, $entry->company_id);
        $this->assertSame('jane.doe@example.com', $contact->email_key);
        $this->assertSame('+17345550142', $contact->phone_key);
    }

    public function test_different_spellings_of_the_same_phone_and_email_share_one_contact(): void
    {
        $form = $this->makeForm($this->brighton, 'Halloween Bash', '2026-10-31');

        $this->signUp($form, 'Ana Diaz', 'Ana.Diaz@Example.com', '(734) 555-0142')->assertCreated();
        $this->signUp($form, 'Ana Diaz', 'ana.diaz@example.com', '+1 734-555-0142')->assertCreated();
        $this->signUp($form, 'Ana Diaz', 'ANA.DIAZ@EXAMPLE.COM', '734.555.0142')->assertCreated();
        $this->signUp($form, 'Ana Diaz', 'ana.diaz@example.com', '1 (734) 555 0142')->assertCreated();

        $this->assertSame(1, RegistrationContact::count());
        $this->assertSame(4, RegistrationEntry::count());
    }

    public function test_sharing_only_a_phone_or_only_an_email_makes_a_different_contact(): void
    {
        $form = $this->makeForm($this->brighton, 'Halloween Bash', '2026-10-31');

        $this->signUp($form, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $this->signUp($form, 'John Doe', 'john@example.com', '7345550142')->assertCreated();
        $this->signUp($form, 'Jane Doe', 'jane@example.com', '7345550199')->assertCreated();

        $this->assertSame(3, RegistrationContact::count());
        $this->actAs($this->admin);
        $this->getJson("/api/registration-events/{$form->id}")
            ->assertOk()
            ->assertJsonPath('data.counts.total_entries', 3)
            ->assertJsonPath('data.counts.unique_registrations', 3)
            ->assertJsonPath('data.counts.duplicate_groups', 0);
    }

    public function test_validation_messages_are_friendly_and_never_repeat_what_was_typed(): void
    {
        $form = $this->makeForm($this->brighton, 'Halloween Bash', '2026-10-31');

        $this->postJson('/api/registration-forms/' . $form->public_code, ['submission_key' => $this->nextSubmissionKey()])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'Please enter your name.')
            ->assertJsonPath('errors.email.0', 'Please enter your email address.')
            ->assertJsonPath('errors.phone.0', 'Please enter your phone number.');

        $this->signUp($form, 'J', 'jane@example.com', '7345550142')
            ->assertUnprocessable()->assertJsonPath('errors.name.0', 'Please enter your full name.');
        $this->signUp($form, '12345', 'jane@example.com', '7345550142')
            ->assertUnprocessable()->assertJsonPath('errors.name.0', 'Please enter your name using letters.');

        $badEmail = $this->signUp($form, 'Jane Doe', 'jane-at-example', '7345550142')
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Please enter a valid email address, like name@example.com.');
        $this->assertStringNotContainsString('jane-at-example', $badEmail->getContent());

        $this->signUp($form, 'Jane Doe', 'jane@example.com', '555-0142')
            ->assertUnprocessable()->assertJsonPath('errors.phone.0', WaiverProfile::PHONE_NOT_RECOGNISED);
        $this->signUp($form, 'Jane Doe', 'jane@example.com', '+4792233445')
            ->assertUnprocessable()->assertJsonPath('errors.phone.0', WaiverProfile::COUNTRY_CODE_NEEDED);

        $this->signUp($form, 'Jane Doe', 'jane@example.com', '+47 922 33 445')->assertCreated();
        $this->assertSame('+4792233445', RegistrationContact::sole()->phone_key);
        $this->assertSame(1, RegistrationEntry::count());
    }

    public function test_odd_input_types_get_a_422_rather_than_a_server_error(): void
    {
        $form = $this->makeForm($this->brighton, 'Halloween Bash', '2026-10-31');
        $url = '/api/registration-forms/' . $form->public_code;

        $this->postJson($url, ['name' => ['x'], 'email' => ['y'], 'phone' => ['z' => 1], 'submission_key' => $this->nextSubmissionKey()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'phone']);
        $this->postJson($url, ['name' => 'Jane Doe', 'email' => 'jane@example.com', 'phone' => '7345550142'])
            ->assertUnprocessable()->assertJsonPath('errors.submission_key.0', 'Please reload the page and try again.');
        $this->postJson($url, ['name' => 'Jane Doe', 'email' => 'jane@example.com', 'phone' => '7345550142', 'submission_key' => 'bad key!'])
            ->assertUnprocessable()->assertJsonValidationErrors(['submission_key']);
        $this->postJson($url, ['name' => "Jane\u{202E}eoD", 'email' => 'jane@example.com', 'phone' => '7345550142', 'submission_key' => $this->nextSubmissionKey()])
            ->assertCreated();
        $this->postJson($url, ['name' => "Ann \u{200B} Smith\t\u{2060}Jr", 'email' => 'ann@example.com', 'phone' => '7345550143', 'submission_key' => $this->nextSubmissionKey()])
            ->assertCreated();
        $this->postJson($url, ['name' => "Bo\u{FEFF}b\u{3000}Ray\u{0085}s", 'email' => 'bob@example.com', 'phone' => '7345550144', 'submission_key' => $this->nextSubmissionKey()])
            ->assertCreated();

        $this->assertSame(['JaneeoD', 'Ann Smith Jr', 'Bob Rays'], RegistrationEntry::orderBy('id')->pluck('name')->all());
    }

    public function test_closed_and_past_forms_refuse_new_registrations(): void
    {
        $closed = $this->makeForm($this->brighton, 'Closed Party', '2026-10-31', ['is_open' => false]);
        $past = $this->makeForm($this->brighton, 'Fall Fest', '2026-10-01');

        $this->getJson('/api/registration-forms/' . $closed->public_code)->assertJsonPath('data.status', 'closed');
        $this->getJson('/api/registration-forms/' . $past->public_code)->assertJsonPath('data.status', 'past');

        $this->signUp($closed, 'Jane Doe', 'jane@example.com', '7345550142')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'REGISTRATION_CLOSED')
            ->assertJsonPath('reason', 'closed');
        $this->signUp($past, 'Jane Doe', 'jane@example.com', '7345550142')
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'past');

        $this->assertSame(0, RegistrationEntry::count());
        $this->assertSame(0, RegistrationContact::count());

        $this->at('2026-10-31 23:30:00');
        $this->assertSame('open', $this->makeForm($this->brighton, 'Tonight', '2026-10-31')->status());
    }

    public function test_an_old_form_points_guests_at_this_years_edition(): void
    {
        $this->at('2025-10-10 12:00:00');
        $old = $this->makeForm($this->brighton, 'Halloween Bash 2025', '2025-10-31');
        $this->at('2026-10-15 18:00:00');
        $new = $this->makeForm($this->brighton, 'Halloween Bash 2026', '2026-10-31', ['previous_edition_id' => $old->id]);

        $this->getJson('/api/registration-forms/' . $old->public_code)
            ->assertOk()
            ->assertJsonPath('data.status', 'past')
            ->assertJsonPath('data.next_edition.name', 'Halloween Bash 2026')
            ->assertJsonPath('data.next_edition.url', 'https://booking.example.test/register/' . $new->public_code);

        $new->update(['is_open' => false]);
        $this->getJson('/api/registration-forms/' . $old->public_code)->assertJsonPath('data.next_edition', null);
    }

    public function test_repeated_taps_with_one_submission_key_store_a_single_entry(): void
    {
        $form = $this->makeForm($this->brighton, 'Halloween Bash', '2026-10-31');
        $key = $this->nextSubmissionKey();

        $this->signUp($form, 'Jane Doe', 'jane@example.com', '7345550142', $key)->assertCreated();
        $this->signUp($form, 'Jane Doe', 'jane@example.com', '7345550142', $key)
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('data.event_name', 'Halloween Bash');
        $this->signUp($form, 'jane  doe', 'JANE@example.com', '(734) 555-0142', $key)->assertOk()->assertJsonPath('replayed', true);

        $this->signUp($form, 'Somebody Else', 'other@example.com', '7345550100', $key)
            ->assertUnprocessable()
            ->assertJsonPath('errors.submission_key.0', 'Please reload the page and try again.');

        $form->update(['is_open' => false]);
        $this->signUp($form, 'Jane Doe', 'jane@example.com', '7345550142', $key)->assertOk()->assertJsonPath('replayed', true);

        $this->assertSame(1, RegistrationEntry::count());
        $this->assertSame(1, RegistrationContact::count());
    }

    public function test_a_racing_duplicate_submission_is_answered_as_a_replay(): void
    {
        $form = $this->makeForm($this->brighton, 'Halloween Bash', '2026-10-31');
        $key = $this->nextSubmissionKey();
        $raced = false;

        RegistrationEntry::creating(function (RegistrationEntry $entry) use (&$raced) {
            if ($raced) {
                return;
            }
            $raced = true;
            DB::table('registration_entries')->insert(array_merge($entry->getAttributes(), [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        });

        $this->signUp($form, 'Jane Doe', 'jane@example.com', '7345550142', $key)
            ->assertOk()
            ->assertJsonPath('replayed', true);

        $this->assertTrue($raced);
        $this->assertSame(1, RegistrationEntry::count());
    }

    public function test_same_contact_entries_are_grouped_and_counted_once_within_one_event_only(): void
    {
        $halloween = $this->makeForm($this->brighton, 'Halloween Bash', '2026-10-31');
        $christmas = $this->makeForm($this->brighton, 'Christmas Party', '2026-12-20');

        $this->signUp($halloween, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $this->signUp($halloween, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $this->signUp($halloween, 'Bob Ray', 'bob@example.com', '7345550143')->assertCreated();
        $this->signUp($christmas, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();

        $this->actAs($this->manager);
        $this->getJson("/api/registration-events/{$halloween->id}")
            ->assertOk()
            ->assertJsonPath('data.counts.total_entries', 3)
            ->assertJsonPath('data.counts.unique_registrations', 2)
            ->assertJsonPath('data.counts.duplicate_groups', 1)
            ->assertJsonPath('data.counts.entries_in_duplicate_groups', 2);
        $this->getJson("/api/registration-events/{$christmas->id}")
            ->assertOk()
            ->assertJsonPath('data.counts.total_entries', 1)
            ->assertJsonPath('data.counts.unique_registrations', 1)
            ->assertJsonPath('data.counts.duplicate_groups', 0);

        $groups = $this->getJson("/api/registration-events/{$halloween->id}/registrations")
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2)
            ->json('data.groups');
        $this->assertSame('Bob Ray', $groups[0]['names'][0]);
        $this->assertFalse($groups[0]['is_duplicate']);
        $this->assertTrue($groups[1]['is_duplicate']);
        $this->assertSame(2, $groups[1]['entry_count']);
        $this->assertCount(2, $groups[1]['entries']);
        $this->assertSame(['Jane Doe'], $groups[1]['names']);
        $this->assertSame(1, $groups[1]['other_event_count']);

        $this->getJson("/api/registration-events/{$halloween->id}/registrations?filter=duplicates")
            ->assertOk()
            ->assertJsonCount(1, 'data.groups')
            ->assertJsonPath('data.groups.0.names', ['Jane Doe']);
        $this->getJson("/api/registration-events/{$christmas->id}/registrations?filter=duplicates")
            ->assertOk()
            ->assertJsonCount(0, 'data.groups');
    }

    public function test_siblings_on_one_parents_details_share_a_group_but_only_the_returning_child_is_registered_before(): void
    {
        $this->at('2025-10-20 18:00:00');
        $last = $this->makeForm($this->brighton, 'Halloween Bash 2025', '2025-10-31');
        $this->signUp($last, 'Emma Carter', 'carter.family@example.com', '7345550150')->assertCreated();

        $this->at('2026-10-15 18:00:00');
        $this_ = $this->makeForm($this->brighton, 'Halloween Bash 2026', '2026-10-31', ['previous_edition_id' => $last->id]);
        $this->signUp($this_, 'Liam Carter', 'carter.family@example.com', '7345550150')->assertCreated();
        $this->signUp($this_, 'Emma Carter', 'carter.family@example.com', '7345550150')->assertCreated();

        $this->actAs($this->admin);
        $this->getJson("/api/registration-events/{$this_->id}")
            ->assertOk()
            ->assertJsonPath('data.counts.total_entries', 2)
            ->assertJsonPath('data.counts.unique_registrations', 1)
            ->assertJsonPath('data.counts.returning_contacts', 1)
            ->assertJsonPath('data.counts.previous_edition_registrants', 1)
            ->assertJsonPath('data.previous_edition.name', 'Halloween Bash 2025');

        $group = $this->getJson("/api/registration-events/{$this_->id}/registrations")->assertOk()->json('data.groups.0');
        $this->assertSame(['Liam Carter', 'Emma Carter'], $group['names']);
        $this->assertTrue($group['is_duplicate']);
        $this->assertTrue($group['returning']);
        $this->assertTrue($group['registered_previous_edition']);
        $this->assertFalse($group['registered_before_other_name']);
        $this->assertSame('Liam Carter', $group['entries'][0]['name']);
        $this->assertFalse($group['entries'][0]['returning']);
        $this->assertFalse($group['entries'][0]['registered_previous_edition']);
        $this->assertSame('Emma Carter', $group['entries'][1]['name']);
        $this->assertTrue($group['entries'][1]['returning']);
        $this->assertTrue($group['entries'][1]['registered_previous_edition']);
    }

    public function test_a_child_new_to_these_details_gets_a_neutral_note_instead_of_registered_before(): void
    {
        $this->at('2025-10-20 18:00:00');
        $last = $this->makeForm($this->brighton, 'Halloween Bash 2025', '2025-10-31');
        $this->signUp($last, 'Emma Carter', 'carter.family@example.com', '7345550150')->assertCreated();

        $this->at('2026-10-15 18:00:00');
        $this_ = $this->makeForm($this->brighton, 'Halloween Bash 2026', '2026-10-31', ['previous_edition_id' => $last->id]);
        $this->signUp($this_, 'Noah Carter', 'carter.family@example.com', '7345550150')->assertCreated();

        $this->actAs($this->admin);
        $this->getJson("/api/registration-events/{$this_->id}")
            ->assertJsonPath('data.counts.returning_contacts', 0)
            ->assertJsonPath('data.counts.previous_edition_registrants', 0);
        $this->getJson("/api/registration-events/{$this_->id}/registrations")
            ->assertJsonPath('data.groups.0.returning', false)
            ->assertJsonPath('data.groups.0.registered_previous_edition', false)
            ->assertJsonPath('data.groups.0.registered_before_other_name', true)
            ->assertJsonPath('data.groups.0.other_event_count', 1);
        $this->getJson("/api/registration-events/{$this_->id}/registrations?filter=returning")->assertJsonCount(0, 'data.groups');
        $this->getJson("/api/registration-events/{$this_->id}/registrations?filter=previous_edition")->assertJsonCount(0, 'data.groups');
    }

    public function test_the_same_person_typed_differently_is_still_registered_before(): void
    {
        $this->at('2025-10-20 18:00:00');
        $last = $this->makeForm($this->brighton, 'Halloween Bash 2025', '2025-10-31');
        $this->signUp($last, "Sean O'Brien", 'sean@example.com', '7345550160')->assertCreated();

        $this->at('2026-10-15 18:00:00');
        $this_ = $this->makeForm($this->brighton, 'Halloween Bash 2026', '2026-10-31', ['previous_edition_id' => $last->id]);
        $this->signUp($this_, 'sean obrien', 'SEAN@example.com', '+1 (734) 555-0160')->assertCreated();

        $this->actAs($this->admin);
        $this->getJson("/api/registration-events/{$this_->id}/registrations?filter=returning")
            ->assertJsonCount(1, 'data.groups')
            ->assertJsonPath('data.groups.0.entries.0.returning', true)
            ->assertJsonPath('data.groups.0.entries.0.registered_previous_edition', true);
    }

    public function test_forms_outside_any_event_still_count_as_registered_before(): void
    {
        $this->at('2025-10-20 18:00:00');
        $last = $this->makeForm($this->brighton, 'Halloween Bash 2025', '2025-10-31', [], grouped: false);
        $this->signUp($last, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();

        $this->at('2026-10-15 18:00:00');
        $form = $this->makeForm($this->brighton, 'Halloween Bash 2026', '2026-10-31', [], grouped: false);
        $this->signUp($form, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();

        $this->actAs($this->admin);
        $this->getJson("/api/registration-events/{$form->id}/registrations")
            ->assertOk()
            ->assertJsonPath('data.counts.returning_contacts', 1)
            ->assertJsonPath('data.groups.0.returning', true);
    }

    public function test_signing_up_for_two_upcoming_events_in_one_sitting_is_not_registered_before(): void
    {
        $halloween = $this->makeForm($this->brighton, 'Halloween Bash', '2026-10-31');
        $christmas = $this->makeForm($this->brighton, 'Christmas Party', '2026-12-20');

        $this->signUp($halloween, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $this->signUp($christmas, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();

        $this->actAs($this->admin);
        $this->getJson("/api/registration-events/{$christmas->id}")->assertJsonPath('data.counts.returning_contacts', 0);
        $this->getJson("/api/registration-events/{$halloween->id}")->assertJsonPath('data.counts.returning_contacts', 0);

        $this->at('2026-11-05 10:00:00');
        $this->actAs(null);
        $this->signUp($christmas, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();

        $this->actAs($this->admin);
        $group = $this->getJson("/api/registration-events/{$christmas->id}/registrations")->json('data.groups.0');
        $this->assertFalse($group['entries'][0]['returning']);
        $this->assertTrue($group['entries'][1]['returning']);
        $this->assertTrue($group['returning']);
        $this->getJson("/api/registration-events/{$christmas->id}")->assertJsonPath('data.counts.returning_contacts', 1);
    }

    public function test_a_brand_new_contact_is_never_marked_as_registered_before(): void
    {
        $this->at('2025-10-20 18:00:00');
        $last = $this->makeForm($this->brighton, 'Halloween Bash 2025', '2025-10-31');
        $this->signUp($last, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();

        $this->at('2026-10-15 18:00:00');
        $this_ = $this->makeForm($this->brighton, 'Halloween Bash 2026', '2026-10-31', ['previous_edition_id' => $last->id]);
        $this->signUp($this_, 'Bob Ray', 'bob@example.com', '7345550143')->assertCreated();
        $this->signUp($this_, 'Jane Doe', 'jane@example.com', '7345550999')->assertCreated();

        $this->actAs($this->admin);
        $this->getJson("/api/registration-events/{$this_->id}")
            ->assertJsonPath('data.counts.returning_contacts', 0)
            ->assertJsonPath('data.counts.previous_edition_registrants', 0);

        foreach ($this->getJson("/api/registration-events/{$this_->id}/registrations")->json('data.groups') as $group) {
            $this->assertFalse($group['returning']);
            $this->assertFalse($group['registered_previous_edition']);
            $this->assertFalse($group['registered_before_other_name']);
            $this->assertSame(0, $group['other_event_count']);
        }
    }

    public function test_hidden_entries_stop_counting_everywhere_and_can_be_restored(): void
    {
        $this->at('2025-10-20 18:00:00');
        $last = $this->makeForm($this->brighton, 'Halloween Bash 2025', '2025-10-31');
        $this->signUp($last, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();

        $this->at('2026-10-15 18:00:00');
        $form = $this->makeForm($this->brighton, 'Halloween Bash 2026', '2026-10-31', ['previous_edition_id' => $last->id]);
        $this->signUp($form, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $this->signUp($form, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $this->signUp($form, 'Bob Ray', 'bob@example.com', '7345550143')->assertCreated();
        $janeEntry = RegistrationEntry::where('registration_event_id', $form->id)->where('name', 'Jane Doe')->orderBy('id')->first();

        $this->actAs($this->manager);
        $this->postJson("/api/registration-events/{$form->id}/entries/{$janeEntry->id}/hide")
            ->assertOk()
            ->assertJsonPath('data.counts.total_entries', 2)
            ->assertJsonPath('data.counts.unique_registrations', 2)
            ->assertJsonPath('data.counts.duplicate_groups', 0)
            ->assertJsonPath('data.counts.hidden_entries', 1);

        $this->getJson("/api/registration-events/{$form->id}/registrations?filter=hidden")
            ->assertOk()
            ->assertJsonCount(1, 'data.groups')
            ->assertJsonPath('data.groups.0.entries.0.id', $janeEntry->id)
            ->assertJsonPath('data.groups.0.entries.0.returning', false);

        $secondJane = RegistrationEntry::where('registration_event_id', $form->id)->where('name', 'Jane Doe')->whereKeyNot($janeEntry->id)->sole();
        $this->postJson("/api/registration-events/{$form->id}/entries/{$secondJane->id}/hide")->assertOk();
        $this->getJson("/api/registration-events/{$form->id}/registrations?filter=hidden")
            ->assertJsonCount(1, 'data.groups')
            ->assertJsonPath('data.groups.0.entry_count', 2)
            ->assertJsonPath('data.groups.0.is_duplicate', false);
        $this->postJson("/api/registration-events/{$form->id}/entries/{$secondJane->id}/restore")->assertOk();
        $this->assertNotNull($this->getJson("/api/registration-events/{$form->id}/registrations?filter=hidden")->json('data.groups.0.entries.0.hidden_at'));
        $this->assertSame($this->manager->id, $janeEntry->fresh()->hidden_by);

        $lastEntry = RegistrationEntry::where('registration_event_id', $last->id)->sole();
        $this->postJson("/api/registration-events/{$last->id}/entries/{$lastEntry->id}/hide")->assertOk();
        $this->getJson("/api/registration-events/{$form->id}")
            ->assertJsonPath('data.counts.returning_contacts', 0)
            ->assertJsonPath('data.counts.previous_edition_registrants', 0);

        $this->postJson("/api/registration-events/{$form->id}/entries/{$janeEntry->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.counts.total_entries', 3)
            ->assertJsonPath('data.counts.duplicate_groups', 1)
            ->assertJsonPath('data.counts.hidden_entries', 0);
        $this->assertNull($janeEntry->fresh()->hidden_by);

        $other = $this->makeForm($this->brighton, 'Other', '2026-11-01');
        $this->postJson("/api/registration-events/{$other->id}/entries/{$janeEntry->id}/hide")->assertNotFound();
        $this->assertSame(4, RegistrationEntry::count());
    }

    public function test_staff_create_a_form_with_a_previous_edition_from_the_same_location(): void
    {
        $this->at('2025-10-10 12:00:00');
        $last = $this->makeForm($this->brighton, 'Halloween Bash 2025', '2025-10-31');
        $elsewhere = $this->makeForm($this->farmington, 'Farmington Halloween 2025', '2025-10-31');
        $later = $this->makeForm($this->brighton, 'Spring Fling 2027', '2027-04-01');
        $this->at('2026-10-15 18:00:00');

        $this->actAs($this->manager);
        $payload = ['name' => 'Halloween Bash 2026', 'location_id' => $this->brighton->id, 'event_date' => '2026-10-31'];

        $this->post('/api/registration-events', $payload + ['previous_edition_id' => $elsewhere->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.previous_edition_id.0', 'Choose an earlier event from the same location.');
        $this->post('/api/registration-events', $payload + ['previous_edition_id' => $later->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['previous_edition_id']);

        $created = $this->post('/api/registration-events', $payload + [
            'previous_edition_id' => $last->id,
            'description' => '  ',
            'confirmation_message' => '',
            'is_open' => '1',
        ])
            ->assertCreated()
            ->assertJsonPath('data.previous_edition.id', $last->id)
            ->assertJsonPath('data.previous_edition.event_date', '2025-10-31')
            ->assertJsonPath('data.event_date', '2026-10-31')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.confirmation_message', null)
            ->assertJsonPath('data.location_locked', false)
            ->assertJsonPath('data.counts.total_entries', 0)
            ->json('data');

        $this->assertStringStartsWith('https://booking.example.test/register/halloween-bash-2026-', $created['public_url']);
        $this->assertSame($this->manager->id, RegistrationEvent::find($created['id'])->created_by);
        $this->getJson("/api/registration-events/{$last->id}")->assertJsonPath('data.next_edition.id', $created['id'])
            ->assertJsonPath('data.location_locked', true);
        $this->assertDatabaseHas('activity_logs', ['action' => 'registration_event_created', 'entity_id' => $created['id']]);
    }

    public function test_editing_a_form_keeps_the_edition_link_honest(): void
    {
        $this->at('2025-10-10 12:00:00');
        $last = $this->makeForm($this->brighton, 'Halloween Bash 2025', '2025-10-31');
        $this->at('2026-10-15 18:00:00');
        $form = $this->makeForm($this->brighton, 'Halloween Bash 2026', '2026-10-31', ['previous_edition_id' => $last->id], grouped: false);

        $this->actAs($this->admin);
        $this->post("/api/registration-events/{$form->id}", ['location_id' => $this->farmington->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['previous_edition_id']);
        $this->post("/api/registration-events/{$form->id}", ['event_date' => '2025-10-01'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.event_date.0', "The date must stay between last year's edition and next year's edition.");
        $this->post("/api/registration-events/{$last->id}", ['event_date' => '2026-11-15'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.event_date.0', "The date must stay between last year's edition and next year's edition.");

        $this->post("/api/registration-events/{$form->id}", ['name' => 'Halloween Bash 2026!'])->assertOk()
            ->assertJsonPath('data.previous_edition.id', $last->id);

        $this->post("/api/registration-events/{$form->id}", ['location_id' => $this->farmington->id, 'previous_edition_id' => ''])
            ->assertOk()
            ->assertJsonPath('data.location_id', $this->farmington->id)
            ->assertJsonPath('data.previous_edition', null);

        $this->post("/api/registration-events/{$form->id}", ['location_id' => $this->brighton->id, 'previous_edition_id' => $last->id])->assertOk();
        $last->delete();
        $this->post("/api/registration-events/{$form->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.previous_edition', null);
        $this->assertNull($form->fresh()->previous_edition_id);
    }

    public function test_the_location_is_locked_once_people_have_registered(): void
    {
        $form = $this->makeForm($this->brighton, 'Halloween Bash', '2026-10-31', grouped: false);
        $this->signUp($form, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();

        $this->actAs($this->admin);
        $this->getJson("/api/registration-events/{$form->id}")->assertJsonPath('data.location_locked', true);
        $this->post("/api/registration-events/{$form->id}", ['location_id' => $this->farmington->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.location_id.0', "The location can't be changed once people have registered, another form links to this one, or a photo frame uses it.");
        $this->assertSame($this->brighton->id, $form->fresh()->location_id);
    }

    public function test_a_form_another_form_links_to_cannot_be_deleted_and_deleting_hides_its_link(): void
    {
        $this->at('2025-10-10 12:00:00');
        $last = $this->makeForm($this->brighton, 'Halloween Bash 2025', '2025-10-31');
        $this->at('2026-10-15 18:00:00');
        $form = $this->makeForm($this->brighton, 'Halloween Bash 2026', '2026-10-31', ['previous_edition_id' => $last->id]);

        $this->actAs($this->manager);
        $this->deleteJson("/api/registration-events/{$last->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', '"Halloween Bash 2026" links to this form as last year\'s edition. Unlink it there first.');

        $this->deleteJson("/api/registration-events/{$form->id}")->assertOk()->assertJsonPath('message', 'Registration form deleted.');
        $this->assertSoftDeleted('registration_events', ['id' => $form->id]);
        $this->getJson("/api/registration-events/{$form->id}")->assertNotFound();
        $this->deleteJson("/api/registration-events/{$last->id}")->assertOk();
    }

    public function test_deleted_forms_drop_out_of_registered_before_and_history(): void
    {
        $this->at('2025-10-20 18:00:00');
        $last = $this->makeForm($this->brighton, 'Halloween Bash 2025', '2025-10-31');
        $this->signUp($last, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();

        $this->at('2026-10-15 18:00:00');
        $form = $this->makeForm($this->brighton, 'Christmas Party 2026', '2026-12-20');
        $this->signUp($form, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $contact = RegistrationContact::sole();

        $this->actAs($this->admin);
        $this->getJson("/api/registration-events/{$form->id}")->assertJsonPath('data.counts.returning_contacts', 1);
        $this->getJson("/api/registration-events/{$form->id}/contacts/{$contact->id}/history")->assertJsonCount(2, 'data.history');

        $this->deleteJson("/api/registration-events/{$last->id}")->assertOk();

        $this->getJson("/api/registration-events/{$form->id}")->assertJsonPath('data.counts.returning_contacts', 0);
        $this->getJson("/api/registration-events/{$form->id}/contacts/{$contact->id}/history")
            ->assertJsonCount(1, 'data.history')
            ->assertJsonPath('data.history.0.event_name', 'Christmas Party 2026');
        $this->assertSame(2, RegistrationEntry::count());
    }

    public function test_history_never_shows_a_manager_names_typed_at_another_venue(): void
    {
        $this->at('2026-10-01 18:00:00');
        $farmingtonForm = $this->makeForm($this->farmington, 'Farmington Halloween', '2026-10-31');
        $this->signUp($farmingtonForm, 'Ava Carter', 'carter.family@example.com', '7345550150')->assertCreated();
        $this->signUp($farmingtonForm, 'Eli Carter', 'carter.family@example.com', '7345550150')->assertCreated();

        $this->at('2026-11-05 18:00:00');
        $brightonForm = $this->makeForm($this->brighton, 'Brighton Christmas', '2026-12-20');
        $this->signUp($brightonForm, 'Noah Carter', 'carter.family@example.com', '7345550150')->assertCreated();
        $contact = RegistrationContact::sole();

        $this->actAs($this->manager);
        $response = $this->getJson("/api/registration-events/{$brightonForm->id}/contacts/{$contact->id}/history")
            ->assertOk()
            ->assertJsonCount(1, 'data.history')
            ->assertJsonPath('data.history.0.name', 'Noah Carter')
            ->assertJsonPath('data.history.0.is_current_event', true)
            ->assertJsonPath('data.other_location_registrations', 1)
            ->assertJsonPath('data.contact.email', 'carter.family@example.com')
            ->assertJsonPath('data.contact.phone', '7345550150');
        $this->assertStringNotContainsString('Ava', $response->getContent());
        $this->assertStringNotContainsString('Farmington Halloween', $response->getContent());
        $this->assertSame($response->json('data.contact.first_registered_at'), $response->json('data.contact.last_registered_at'));

        $this->actAs($this->admin);
        $this->getJson("/api/registration-events/{$brightonForm->id}/contacts/{$contact->id}/history")
            ->assertOk()
            ->assertJsonCount(3, 'data.history')
            ->assertJsonPath('data.history.2.name', 'Ava Carter')
            ->assertJsonPath('data.history.2.location_name', 'Farmington | Zap Zone')
            ->assertJsonPath('data.other_location_registrations', 0);

        $stranger = $this->makeForm($this->brighton, 'Someone else', '2026-12-21');
        $this->getJson("/api/registration-events/{$stranger->id}/contacts/{$contact->id}/history")->assertNotFound();
    }

    public function test_export_lists_every_visible_entry_with_its_flags(): void
    {
        $this->at('2025-10-20 18:00:00');
        $last = $this->makeForm($this->brighton, 'Halloween Bash 2025', '2025-10-31');
        $this->signUp($last, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();

        $this->at('2026-10-15 18:00:00');
        $form = $this->makeForm($this->brighton, 'Halloween Bash 2026', '2026-10-31', ['previous_edition_id' => $last->id]);
        $this->signUp($form, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $this->signUp($form, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $this->signUp($form, '=HYPERLINK("x")', 'bob@example.com', '7345550143')->assertCreated();
        $janeEntries = RegistrationEntry::where('registration_event_id', $form->id)->where('name', 'Jane Doe')->orderBy('id')->get();
        $janeEntries[1]->forceFill(['hidden_at' => now()])->save();

        $this->actAs($this->admin);
        $export = $this->getJson("/api/registration-events/{$form->id}/registrations/export")
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.capped', false)
            ->json('data.rows');

        $this->assertCount(2, $export);
        $this->assertNotContains($janeEntries[1]->id, array_column($export, 'entry_id'));
        $this->assertSame(['entry_id', 'registered_at', 'name', 'email', 'phone', 'contact_id', 'same_contact_entries', 'is_duplicate', 'returning', 'registered_previous_edition'], array_keys($export[0]));
        $this->assertSame($janeEntries[0]->id, $export[0]['entry_id']);
        $this->assertSame(1, $export[0]['same_contact_entries']);
        $this->assertFalse($export[0]['is_duplicate']);
        $this->assertTrue($export[0]['returning']);
        $this->assertTrue($export[0]['registered_previous_edition']);
        $this->assertStringEndsWith('-04:00', $export[0]['registered_at']);
        $this->assertSame('=HYPERLINK("x")', $export[1]['name']);
        $this->assertFalse($export[1]['returning']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'registration_entries_exported', 'entity_id' => $form->id]);
    }

    public function test_search_finds_names_emails_and_any_spelling_of_a_phone(): void
    {
        $form = $this->makeForm($this->brighton, 'Halloween Bash', '2026-10-31');
        $this->signUp($form, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $this->signUp($form, 'Bob Ray', 'bob_99%@example.com', '7345550143')->assertCreated();

        $this->actAs($this->admin);
        $url = "/api/registration-events/{$form->id}/registrations?search=";
        $this->getJson($url . 'jane')->assertJsonCount(1, 'data.groups')->assertJsonPath('data.groups.0.names', ['Jane Doe']);
        $this->getJson($url . urlencode('+1 (734) 555-0142'))->assertJsonCount(1, 'data.groups')->assertJsonPath('data.groups.0.names', ['Jane Doe']);
        $this->getJson($url . '5550143')->assertJsonCount(1, 'data.groups')->assertJsonPath('data.groups.0.names', ['Bob Ray']);
        $this->getJson($url . urlencode('99%'))->assertJsonCount(1, 'data.groups')->assertJsonPath('data.groups.0.names', ['Bob Ray']);
        $this->getJson($url . urlencode('%'))->assertJsonCount(1, 'data.groups');
        $this->getJson($url . 'nobody')->assertJsonCount(0, 'data.groups')->assertJsonPath('data.pagination.total', 0);
    }

    public function test_groups_page_newest_first(): void
    {
        $form = $this->makeForm($this->brighton, 'Halloween Bash', '2026-10-31');
        foreach (range(1, 5) as $i) {
            $this->signUp($form, "Guest Number{$i}", "guest{$i}@example.com", '73455501' . str_pad((string) $i, 2, '0', STR_PAD_LEFT))->assertCreated();
        }
        $this->signUp($form, 'Guest Number1', 'guest1@example.com', '7345550101')->assertCreated();

        $this->actAs($this->admin);
        $first = $this->getJson("/api/registration-events/{$form->id}/registrations?per_page=2&page=1")->assertOk();
        $first->assertJsonPath('data.pagination.total', 5)->assertJsonPath('data.pagination.last_page', 3);
        $this->assertSame(['Guest Number1'], $first->json('data.groups.0.names'));
        $this->assertSame(['Guest Number5'], $first->json('data.groups.1.names'));
        $this->getJson("/api/registration-events/{$form->id}/registrations?per_page=2&page=99")
            ->assertJsonPath('data.pagination.current_page', 3)
            ->assertJsonCount(1, 'data.groups');
        $this->getJson("/api/registration-events/{$form->id}/registrations?per_page=500")->assertUnprocessable();
        $this->getJson("/api/registration-events/{$form->id}/registrations?filter=everything")->assertUnprocessable();
    }

    public function test_header_images_are_re_encoded_resized_and_cleaned_up(): void
    {
        $this->actAs($this->admin);
        $created = $this->post('/api/registration-events', [
            'name' => 'Halloween Bash',
            'location_id' => $this->brighton->id,
            'event_date' => '2026-10-31',
            'header_image' => UploadedFile::fake()->image('poster.png', 2400, 1200),
        ])->assertCreated()->json('data');

        $first = $created['header_image_path'];
        $this->assertMatchesRegularExpression('#^registration-events/[A-Za-z0-9]{40}\.jpg$#', $first);
        $this->assertSame(1600, $created['header_image_width']);
        $this->assertSame(800, $created['header_image_height']);
        Storage::disk('public')->assertExists($first);
        $this->assertStringStartsWith("\xFF\xD8", Storage::disk('public')->get($first));

        $withExif = $this->jpegWithExif();
        $replaced = $this->post("/api/registration-events/{$created['id']}", [
            'header_image' => UploadedFile::fake()->createWithContent('photo.jpg', $withExif),
        ])->assertOk()->json('data');

        $second = $replaced['header_image_path'];
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        $this->assertStringNotContainsString('Exif', Storage::disk('public')->get($second));
        $this->assertStringNotContainsString('GPS-SECRET', Storage::disk('public')->get($second));

        $this->post("/api/registration-events/{$created['id']}", [
            'header_image' => UploadedFile::fake()->createWithContent('poster.png', 'this is not an image'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['header_image']);
        $tooBig = tempnam(sys_get_temp_dir(), 'evreg');
        file_put_contents($tooBig, $withExif);
        $this->post("/api/registration-events/{$created['id']}", [
            'name' => 'Renamed while uploading',
            'header_image' => new UploadedFile($tooBig, 'big.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true),
        ])->assertUnprocessable()->assertJsonValidationErrors(['header_image']);
        $this->assertSame('Halloween Bash', RegistrationEvent::find($created['id'])->name);
        Storage::disk('public')->assertExists($second);

        $this->post("/api/registration-events/{$created['id']}", ['remove_header_image' => '1'])
            ->assertOk()
            ->assertJsonPath('data.header_image_path', null)
            ->assertJsonPath('data.header_image_width', null);
        Storage::disk('public')->assertMissing($second);
    }

    public function test_a_shared_header_image_is_kept_while_another_form_uses_it(): void
    {
        Storage::disk('public')->put('registration-events/shared.jpg', 'jpeg');
        $one = $this->makeForm($this->brighton, 'One', '2026-10-31', ['header_image_path' => 'registration-events/shared.jpg']);
        $this->makeForm($this->brighton, 'Two', '2026-11-30', ['header_image_path' => 'registration-events/shared.jpg'], grouped: false);

        $this->actAs($this->admin);
        $this->post("/api/registration-events/{$one->id}", ['remove_header_image' => '1'])->assertOk();

        Storage::disk('public')->assertExists('registration-events/shared.jpg');
    }

    public function test_the_public_answer_is_the_same_for_new_returning_and_repeat_people(): void
    {
        $this->at('2025-10-20 18:00:00');
        $last = $this->makeForm($this->brighton, 'Halloween Bash 2025', '2025-10-31');
        $this->signUp($last, 'Jane Secret', 'jane.secret@example.com', '7345550142')->assertCreated();

        $this->at('2026-10-15 18:00:00');
        $form = $this->makeForm($this->brighton, 'Halloween Bash 2026', '2026-10-31', ['previous_edition_id' => $last->id]);

        $bodies = [
            $this->signUp($form, 'Brand New', 'new@example.com', '7345550100')->assertCreated()->json(),
            $this->signUp($form, 'Jane Secret', 'jane.secret@example.com', '7345550142')->assertCreated()->json(),
            $this->signUp($form, 'Jane Secret', 'jane.secret@example.com', '7345550142')->assertCreated()->json(),
            $this->signUp($form, 'Someone Else', 'jane.secret@example.com', '7345550142')->assertCreated()->json(),
        ];

        foreach ($bodies as $body) {
            $this->assertSame($bodies[0], $body);
            $this->assertStringNotContainsString('Jane', json_encode($body));
        }
    }

    public function test_a_database_failure_answers_retryably_without_logging_guest_details(): void
    {
        $form = $this->makeForm($this->brighton, 'Halloween Bash', '2026-10-31');
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged) {
            $logged[] = $message->message . ' ' . json_encode($message->context);
        });

        RegistrationEntry::creating(function () {
            throw new QueryException('mysql', 'insert into registration_entries (name) values (?)', ['Jane Secret'], new \PDOException('lost connection'));
        });

        $this->signUp($form, 'Jane Secret', 'jane.secret@example.com', '7345550142')
            ->assertStatus(503)
            ->assertJsonPath('message', "We couldn't save your registration. Please tap Register again — you won't be registered twice.");

        $this->assertSame(0, RegistrationEntry::count());
        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString('Jane', $line);
            $this->assertStringNotContainsString('jane.secret', $line);
            $this->assertStringNotContainsString('5550142', $line);
        }
    }

    public function test_a_staff_database_failure_answers_cleanly_without_logging_the_query(): void
    {
        $form = $this->makeForm($this->brighton, 'Halloween Bash', '2026-10-31');
        $this->signUp($form, 'Jane Secret', 'jane.secret@example.com', '7345550142')->assertCreated();
        $entry = RegistrationEntry::sole();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged) {
            $logged[] = $message->message . ' ' . json_encode($message->context);
        });

        RegistrationEntry::saving(function () {
            throw new QueryException('mysql', 'update registration_entries set hidden_by = ? where name = ?', [1, 'Jane Secret'], new \PDOException('lock wait timeout'));
        });
        RegistrationEvent::deleting(function () {
            throw new QueryException('mysql', 'update registration_events set deleted_at = ? where name = ?', ['now', 'Jane Secret'], new \PDOException('lock wait timeout'));
        });

        $this->actAs($this->manager);
        $this->postJson("/api/registration-events/{$form->id}/entries/{$entry->id}/hide")
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Something went wrong. Please try again.');
        $this->deleteJson("/api/registration-events/{$form->id}")
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Something went wrong. Please try again.');

        $this->assertNull($entry->fresh()->hidden_at);
        $this->assertNull($form->fresh()->deleted_at);
        $this->assertCount(2, array_filter($logged, fn (string $line) => str_starts_with($line, 'Registration forms request failed')));
        foreach ($logged as $line) {
            $this->assertStringNotContainsString('Jane', $line);
            $this->assertStringNotContainsString(' set ', $line);
        }
    }

    public function test_everything_answers_not_set_up_until_the_tables_exist(): void
    {
        $form = $this->makeForm($this->brighton, 'Halloween Bash', '2026-10-31');
        $tables = new \ReflectionProperty(SchemaSupport::class, 'tables');

        try {
            $tables->setValue(null, ['registration_events' => false]);

            $this->getJson('/api/registration-forms/' . $form->public_code)->assertNotFound();
            $this->actAs($this->admin);
            $this->getJson('/api/registration-events')->assertStatus(503)->assertJsonPath('code', 'MODULE_UNAVAILABLE');
            $this->getJson("/api/registration-events/{$form->id}")->assertStatus(503)->assertJsonPath('code', 'MODULE_UNAVAILABLE');
            $this->getJson("/api/registration-events/{$form->id}/registrations")->assertStatus(503);
            $this->post('/api/registration-events', ['name' => 'X'])->assertStatus(503);
        } finally {
            SchemaSupport::flush();
        }
    }

    private function jpegWithExif(): string
    {
        $image = imagecreatetruecolor(400, 300);
        imagefilledrectangle($image, 0, 0, 400, 300, imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        $payload = "Exif\0\0" . str_repeat('GPS-SECRET', 8);
        $segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        return substr($jpeg, 0, 2) . $segment . substr($jpeg, 2);
    }
}
