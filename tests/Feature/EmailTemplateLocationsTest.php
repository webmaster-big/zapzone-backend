<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EmailTemplate;
use App\Models\Location;
use App\Models\PhotoMessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\EventRegistrationFixtures;
use Tests\TestCase;

class EmailTemplateLocationsTest extends TestCase
{
    use RefreshDatabase;
    use EventRegistrationFixtures;

    private Company $company;

    private Location $brighton;

    private Location $waterford;

    private Location $novi;

    private User $admin;

    private User $manager;

    private User $attendant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->company = $this->makeCompany('Zap Zone');
        $this->brighton = $this->makeVenue($this->company, 'Brighton');
        $this->waterford = $this->makeVenue($this->company, 'Waterford');
        $this->novi = $this->makeVenue($this->company, 'Novi');
        $this->admin = $this->makeStaff($this->company, $this->brighton, 'owner', 'company_admin');
        $this->manager = $this->makeStaff($this->company, $this->brighton, 'manager', 'location_manager');
        $this->attendant = $this->makeStaff($this->company, $this->brighton, 'desk', 'attendant');
    }

    public function test_admins_can_pick_several_locations_or_every_location(): void
    {
        $this->actAs($this->admin);
        $several = $this->postJson('/api/email-templates', $this->template(['location_ids' => [$this->brighton->id, $this->waterford->id]]))
            ->assertCreated()
            ->assertJsonPath('data.location_ids', [$this->brighton->id, $this->waterford->id])
            ->assertJsonPath('data.all_locations', false)
            ->json('data');
        $this->assertNull(EmailTemplate::find($several['id'])->location_id);
        $this->assertSame(['Brighton | Zap Zone', 'Waterford | Zap Zone'], $several['location_names']);

        $this->actAs($this->admin);
        $every = $this->postJson('/api/email-templates', $this->template(['location_ids' => []]))
            ->assertCreated()
            ->assertJsonPath('data.all_locations', true)
            ->json('data');
        $this->assertNull(EmailTemplate::find($every['id'])->location_ids);

        $this->actAs($this->admin);
        $legacy = $this->postJson('/api/email-templates', $this->template(['location_id' => $this->novi->id]))
            ->assertCreated()
            ->assertJsonPath('data.location_ids', [$this->novi->id])
            ->json('data');
        $this->assertSame($this->novi->id, (int) EmailTemplate::find($legacy['id'])->location_id);

        $this->actAs($this->admin);
        $this->putJson("/api/email-templates/{$several['id']}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.location_ids', [$this->brighton->id, $this->waterford->id]);

        $elsewhere = $this->makeVenue($this->makeCompany('Other Fun'), 'Lansing');
        $this->actAs($this->admin);
        $this->postJson('/api/email-templates', $this->template(['location_ids' => [$elsewhere->id]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_ids');

        $this->actAs($this->admin);
        $ids = collect($this->getJson('/api/email-templates?location_id=' . $this->novi->id)->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertContains($every['id'], $ids);
        $this->assertContains($legacy['id'], $ids);
        $this->assertNotContains($several['id'], $ids);
    }

    public function test_managers_only_work_with_their_own_locations_templates(): void
    {
        $everywhere = $this->makeTemplate(null);
        $shared = $this->makeTemplate([$this->brighton->id, $this->waterford->id]);
        $waterfordOnly = $this->makeTemplate([$this->waterford->id]);

        $this->actAs($this->manager);
        $mine = $this->postJson('/api/email-templates', $this->template())
            ->assertCreated()
            ->assertJsonPath('data.location_ids', [$this->brighton->id])
            ->json('data');

        $this->actAs($this->manager);
        $this->postJson('/api/email-templates', $this->template(['location_ids' => [$this->waterford->id]]))->assertForbidden();

        foreach ([$everywhere, $shared] as $template) {
            $this->actAs($this->manager);
            $this->putJson("/api/email-templates/{$template->id}", ['subject' => 'Changed'])->assertForbidden();
            $this->actAs($this->manager);
            $this->patchJson("/api/email-templates/{$template->id}/status", ['status' => 'archived'])->assertForbidden();
            $this->actAs($this->manager);
            $this->deleteJson("/api/email-templates/{$template->id}")->assertForbidden();
        }

        $this->actAs($this->manager);
        $this->getJson("/api/email-templates/{$waterfordOnly->id}")->assertForbidden();
        $this->actAs($this->manager);
        $listed = collect($this->getJson('/api/email-templates')->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertContains($everywhere->id, $listed);
        $this->assertNotContains($waterfordOnly->id, $listed);

        $this->actAs($this->manager);
        $this->putJson("/api/email-templates/{$mine['id']}", ['subject' => 'Mine changed'])->assertOk();

        $this->actAs($this->manager);
        $copy = $this->postJson("/api/email-templates/{$everywhere->id}/duplicate")->assertCreated()->json('data');
        $this->assertSame([$this->brighton->id], $copy['location_ids']);
        $this->assertSame('draft', $copy['status']);
        $this->assertSame('Subject', $everywhere->fresh()->subject);
    }

    public function test_event_photo_emails_are_for_managers_and_admins_and_never_overlap(): void
    {
        $this->actAs($this->attendant);
        $this->postJson('/api/email-templates', $this->template(['category' => 'event_photos', 'status' => 'active']))->assertForbidden();

        $this->actAs($this->admin);
        $everywhere = $this->postJson('/api/email-templates', $this->template(['category' => 'event_photos', 'status' => 'active']))
            ->assertCreated()
            ->assertJsonPath('data.attach_photos', true)
            ->json('data');

        $this->actAs($this->admin);
        $this->postJson('/api/email-templates', $this->template(['category' => 'event_photos', 'status' => 'active']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_ids');

        $this->actAs($this->admin);
        $draft = $this->postJson('/api/email-templates', $this->template(['category' => 'event_photos', 'status' => 'draft']))->assertCreated()->json('data');
        $this->actAs($this->admin);
        $this->patchJson("/api/email-templates/{$draft['id']}/status", ['status' => 'active'])->assertStatus(422);

        $this->actAs($this->admin);
        $this->postJson('/api/email-templates', $this->template(['category' => 'event_photos', 'status' => 'active', 'location_ids' => [$this->brighton->id], 'sms_body' => '  ', 'attach_photos' => false]))
            ->assertCreated()
            ->assertJsonPath('data.sms_body', null)
            ->assertJsonPath('data.attach_photos', false);

        $this->actAs($this->admin);
        $this->postJson('/api/email-templates', $this->template(['category' => 'event_photos', 'status' => 'active', 'location_ids' => [$this->brighton->id, $this->novi->id]]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Brighton | Zap Zone already uses the event photo email "Template". Take that location off it first, or save this one as a draft.');

        $this->assertSame('Template', EmailTemplate::eventPhotoFor($this->company->id, $this->brighton->id)->name);
        $this->assertSame($everywhere['id'], EmailTemplate::eventPhotoFor($this->company->id, $this->novi->id)->id);

        $this->actAs($this->attendant);
        $this->getJson('/api/email-templates/variables')->assertOk()->assertJsonPath('data.event.photo_link', 'Link to their photos');
    }

    public function test_the_move_copies_the_event_wording_and_keeps_single_locations(): void
    {
        $legacy = $this->makeTemplate([$this->novi->id]);
        DB::table('email_templates')->where('id', $legacy->id)->update(['location_ids' => null, 'location_id' => $this->novi->id]);
        PhotoMessageTemplate::forCompany($this->company->id, PhotoMessageTemplate::KIND_EVENT_REGISTRATION)
            ->update(['email_subject' => 'Custom {{event_name}}', 'email_body' => "<p>Hi</p>\n<p>{{photo_link}}</p>", 'is_active' => false, 'attach_photos' => false]);

        $formsOnly = $this->makeCompany('Forms Only Fun');
        $formsVenue = $this->makeVenue($formsOnly, 'Okemos');
        $formsAdmin = $this->makeStaff($formsOnly, $formsVenue, 'formsadmin', 'company_admin');
        $this->makeForm($formsVenue, 'Fall Fest', now()->addDays(3)->toDateString());
        $this->assertFalse(PhotoMessageTemplate::where('company_id', $formsOnly->id)->exists());

        $migration = require base_path('database/migrations/2026_10_24_000001_add_location_targeting_and_event_photo_to_email_templates.php');
        $migration->up();
        $migration->up();

        $started = EmailTemplate::where('company_id', $formsOnly->id)->where('category', 'event_photos')->sole();
        $this->assertSame('active', $started->status);
        $this->assertTrue($started->attach_photos);
        $this->assertStringContainsString('{{photo_link}}', $started->body);
        $this->assertSame($formsAdmin->id, (int) $started->created_by);

        $this->assertSame([$this->novi->id], $legacy->fresh()->location_ids);
        $moved = EmailTemplate::where('company_id', $this->company->id)->where('category', 'event_photos')->get();
        $this->assertCount(1, $moved);
        $this->assertSame('Custom {{event_name}}', $moved[0]->subject);
        $this->assertSame('<p>Hi</p><p>{{photo_link}}</p>', $moved[0]->body);
        $this->assertSame('draft', $moved[0]->status);
        $this->assertFalse($moved[0]->attach_photos);
        $this->assertNull($moved[0]->location_ids);
        $this->assertSame($this->admin->id, (int) $moved[0]->created_by);
    }

    public function test_category_lookalikes_stay_ordinary_templates(): void
    {
        $real = $this->makeTemplate(null, ['category' => 'event_photos', 'name' => 'Real event photos']);

        $this->actAs($this->attendant);
        $sneaky = $this->postJson('/api/email-templates', $this->template(['category' => 'EVENT_PHOTOS', 'status' => 'active', 'name' => 'Sneaky']))
            ->assertCreated()
            ->json('data');

        $this->actAs($this->manager);
        $this->postJson('/api/email-templates', $this->template(['category' => 'Event_Photos ', 'status' => 'active', 'name' => 'Spaced']))
            ->assertCreated();

        $this->assertSame($real->id, EmailTemplate::eventPhotoFor($this->company->id, $this->brighton->id)->id);
        $this->assertFalse(EmailTemplate::find($sneaky['id'])->isEventPhotos());

        $this->actAs($this->admin);
        $this->postJson('/api/email-templates', $this->template(['category' => 'event_photos', 'status' => 'active', 'location_ids' => [$this->brighton->id], 'name' => 'Brighton photos']))
            ->assertCreated();
        $this->assertSame('Brighton photos', EmailTemplate::eventPhotoFor($this->company->id, $this->brighton->id)->name);
    }

    public function test_managers_cannot_copy_an_event_photo_email_shared_by_several_locations(): void
    {
        $shared = $this->makeTemplate([$this->brighton->id, $this->waterford->id], ['category' => 'event_photos']);
        $everywhere = $this->makeTemplate(null, ['category' => 'event_photos', 'status' => 'draft']);

        $this->actAs($this->manager);
        $this->postJson("/api/email-templates/{$shared->id}/duplicate")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This event photo email is shared by several locations, so a copy for your location could never be switched on. Ask a company admin to change it, or to take your location off it.');

        $this->actAs($this->manager);
        $this->postJson("/api/email-templates/{$everywhere->id}/duplicate")->assertCreated();

        $this->actAs($this->admin);
        $this->postJson("/api/email-templates/{$shared->id}/duplicate")->assertCreated();
    }

    public function test_staff_without_a_location_cannot_save_templates(): void
    {
        $floating = $this->makeStaff($this->company, null, 'floating', 'location_manager');
        $everywhere = $this->makeTemplate(null);

        $this->actAs($floating);
        $this->postJson('/api/email-templates', $this->template())
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is not linked to a location, so you cannot save templates. Ask a company admin to set your location.');

        $this->actAs($floating);
        $this->postJson("/api/email-templates/{$everywhere->id}/duplicate")->assertForbidden();
        $this->assertSame(1, EmailTemplate::count());
    }

    public function test_malformed_location_lists_get_a_clear_error(): void
    {
        $this->actAs($this->admin);
        $this->postJson('/api/email-templates', $this->template(['location_ids' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_ids');

        $this->actAs($this->admin);
        $this->postJson('/api/email-templates', $this->template(['location_ids' => 'brighton']))
            ->assertStatus(422);
    }

    public function test_a_template_used_by_notifications_cannot_become_an_event_photo_email(): void
    {
        $used = $this->makeTemplate(null, ['category' => 'transactional']);
        \App\Models\EmailNotification::create([
            'company_id' => $this->company->id,
            'name' => 'Booking confirmed',
            'trigger_type' => \App\Models\EmailNotification::TRIGGER_BOOKING_CONFIRMED,
            'entity_type' => \App\Models\EmailNotification::ENTITY_ALL,
            'recipient_types' => [\App\Models\EmailNotification::RECIPIENT_COMPANY_ADMIN],
            'subject' => 'Subject',
            'body' => 'Body',
            'email_template_id' => $used->id,
        ]);

        $this->actAs($this->admin);
        $this->putJson("/api/email-templates/{$used->id}", ['category' => 'event_photos'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
        $this->assertSame('transactional', $used->fresh()->category);

        $this->actAs($this->admin);
        $this->putJson("/api/email-templates/{$used->id}", ['name' => 'Renamed'])->assertOk();
    }

    public function test_previews_follow_the_same_location_rules(): void
    {
        $waterfordOnly = $this->makeTemplate([$this->waterford->id], ['subject' => 'Waterford secret']);
        $this->waterford->forceFill(['phone' => '(248) 555-0199'])->save();
        $this->brighton->forceFill(['phone' => '(810) 555-0101'])->save();

        $this->actAs($this->manager);
        $this->getJson("/api/email-templates/{$waterfordOnly->id}/preview")->assertForbidden();

        $this->actAs($this->manager);
        $this->postJson('/api/email-templates/preview', ['subject' => 'Call {{location_phone}}', 'body' => '<p>x</p>', 'location_id' => $this->waterford->id])
            ->assertOk()
            ->assertJsonPath('data.subject', 'Call (810) 555-0101');

        $this->actAs($this->admin);
        $this->postJson('/api/email-templates/preview', ['subject' => 'Call {{location_phone}}', 'body' => '<p>x</p>', 'location_id' => $this->waterford->id])
            ->assertOk()
            ->assertJsonPath('data.subject', 'Call (248) 555-0199');
    }

    public function test_templates_saved_by_the_old_release_keep_their_location(): void
    {
        $legacy = $this->makeTemplate(null, ['category' => 'event_photos', 'name' => 'Old Waterford photos']);
        DB::table('email_templates')->where('id', $legacy->id)->update(['location_id' => $this->waterford->id, 'location_ids' => null]);
        $everywhere = $this->makeTemplate(null, ['category' => 'event_photos', 'name' => 'Everywhere photos', 'status' => 'draft']);
        DB::table('email_templates')->where('id', $everywhere->id)->update(['status' => 'active']);

        $this->assertSame([$this->waterford->id], $legacy->fresh()->targetLocationIds());
        $this->assertTrue($legacy->fresh()->appliesToLocation($this->waterford->id));
        $this->assertFalse($legacy->fresh()->appliesToLocation($this->brighton->id));
        $this->assertFalse($legacy->fresh()->isAllLocations());
        $this->assertSame('Old Waterford photos', EmailTemplate::eventPhotoFor($this->company->id, $this->waterford->id)->name);
        $this->assertSame('Everywhere photos', EmailTemplate::eventPhotoFor($this->company->id, $this->brighton->id)->name);

        $this->actAs($this->manager);
        $listed = collect($this->getJson('/api/email-templates')->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertNotContains($legacy->id, $listed);
        $this->assertContains($everywhere->id, $listed);

        $this->actAs($this->admin);
        $shown = $this->getJson("/api/email-templates/{$legacy->id}")->assertOk()->json('data');
        $this->assertSame([$this->waterford->id], $shown['location_ids']);
        $this->assertFalse($shown['all_locations']);

        $this->actAs($this->admin);
        $this->putJson("/api/email-templates/{$legacy->id}", ['name' => 'Waterford photos'])->assertOk();
        $this->assertSame([$this->waterford->id], $legacy->fresh()->location_ids);
        $this->assertSame($this->waterford->id, (int) $legacy->fresh()->location_id);
    }

    public function test_inactive_locations_are_listed_and_kept(): void
    {
        $this->novi->forceFill(['is_active' => false])->save();
        $both = $this->makeTemplate([$this->brighton->id, $this->novi->id]);

        $this->actAs($this->admin);
        $shown = $this->getJson("/api/email-templates/{$both->id}")->assertOk()->json('data');
        $this->assertSame([
            ['id' => $this->brighton->id, 'name' => 'Brighton | Zap Zone', 'active' => true],
            ['id' => $this->novi->id, 'name' => 'Novi | Zap Zone', 'active' => false],
        ], $shown['target_locations']);

        $this->actAs($this->admin);
        $this->putJson("/api/email-templates/{$both->id}", ['name' => 'Kept', 'location_ids' => [$this->brighton->id, $this->novi->id]])
            ->assertOk()
            ->assertJsonPath('data.location_ids', [$this->brighton->id, $this->novi->id]);

        $this->actAs($this->admin);
        $this->assertSame([], $this->postJson('/api/email-templates', $this->template())->assertCreated()->json('data.target_locations'));
    }

    private function template(array $overrides = []): array
    {
        return $overrides + ['name' => 'Template', 'subject' => 'Subject', 'body' => '<p>Body</p>'];
    }

    private function makeTemplate(?array $locationIds, array $attributes = []): EmailTemplate
    {
        return EmailTemplate::create($attributes + [
            'company_id' => $this->company->id,
            'location_id' => $locationIds !== null && count($locationIds) === 1 ? $locationIds[0] : null,
            'location_ids' => $locationIds,
            'created_by' => $this->admin->id,
            'name' => 'Existing',
            'subject' => 'Subject',
            'body' => '<p>Body</p>',
            'status' => 'active',
        ]);
    }
}
