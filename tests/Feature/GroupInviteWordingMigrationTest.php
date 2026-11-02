<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EmailNotification;
use App\Models\Location;
use App\Models\SmsNotification;
use Database\Seeders\DefaultEmailNotificationSeeder;
use Database\Seeders\DefaultSmsNotificationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupInviteWordingMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PARAGRAPH = "Your group needs each participant (or a parent/guardian for minors) to complete a waiver before visiting {{location_name}}. Use the link below to add your group's contacts and send them their waiver invites, and to track who has completed theirs.";
    private const NEW_PARAGRAPH = 'Everyone in your group needs a signed waiver before visiting {{location_name}}: each adult signs their own, and a parent or guardian signs for each child. Use the link below to add the people in your group. Each person gets their invite as soon as you add them, and you can see who has signed.';
    private const OLD_TEXT = '{{company_name}}: your group needs waivers completed. Add contacts and track completion here: {{waiver_link}}';
    private const NEW_TEXT = '{{company_name}}: everyone in your group needs a signed waiver. Add the people in your group and see who has signed here: {{waiver_link}}';

    private function company(string $name): Company
    {
        return Company::create([
            'company_name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)) . '@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);
    }

    private function migration(string $file): object
    {
        return require database_path("migrations/{$file}");
    }

    private function putBackOldWording(Company $company): void
    {
        foreach ([EmailNotification::class, SmsNotification::class] as $model) {
            $model::where('company_id', $company->id)->where('default_key', 'waiver_bulk_chaperone')
                ->update(['name' => 'Bulk Waiver Invite (Chaperone)']);
            $model::where('company_id', $company->id)->where('default_key', 'waiver_parent_invite')
                ->update(['name' => 'Waiver Invite (Parent/Guardian)']);
        }

        $email = EmailNotification::where('company_id', $company->id)->where('default_key', 'waiver_bulk_chaperone')->sole();
        DB::table('email_notifications')->where('id', $email->id)->update([
            'description' => 'Sent to a group organizer/chaperone so they can invite parents/guardians to complete waivers for their group.',
            'body' => str_replace(self::NEW_PARAGRAPH, self::OLD_PARAGRAPH, $email->body),
            'default_body' => str_replace(self::NEW_PARAGRAPH, self::OLD_PARAGRAPH, $email->default_body),
        ]);
        DB::table('email_notifications')->where('company_id', $company->id)->where('default_key', 'waiver_parent_invite')
            ->update(['description' => 'Sent to a parent/guardian by a chaperone to complete a waiver for their minor(s).']);
        DB::table('sms_notifications')->where('company_id', $company->id)->where('default_key', 'waiver_bulk_chaperone')->update([
            'description' => 'Sent to a group organizer/chaperone to manage and send group waivers.',
            'body' => self::OLD_TEXT,
            'default_body' => self::OLD_TEXT,
        ]);
    }

    public function test_new_companies_get_the_group_invite_wording(): void
    {
        $company = $this->company('Fresh Venue');
        DefaultEmailNotificationSeeder::seedForCompany($company);
        DefaultSmsNotificationSeeder::seedForCompany($company);

        $email = EmailNotification::where('company_id', $company->id)->where('default_key', 'waiver_bulk_chaperone')->sole();
        $this->assertSame('Group Waiver Invite (Chaperone)', $email->name);
        $this->assertStringContainsString(self::NEW_PARAGRAPH, $email->body);
        $this->assertSame('Group Waiver Invite (Group Member)', EmailNotification::where('company_id', $company->id)->where('default_key', 'waiver_parent_invite')->value('name'));
        $this->assertSame(self::NEW_TEXT, SmsNotification::where('company_id', $company->id)->where('default_key', 'waiver_bulk_chaperone')->value('body'));
        $this->assertSame('Group Waiver Invite (Chaperone)', EmailNotification::getDefaultKeys()['waiver_bulk_chaperone']);
    }

    public function test_old_default_wording_is_updated_and_wording_staff_changed_is_kept(): void
    {
        $untouched = $this->company('Untouched Venue');
        $edited = $this->company('Edited Venue');
        foreach ([$untouched, $edited] as $company) {
            DefaultEmailNotificationSeeder::seedForCompany($company);
            DefaultSmsNotificationSeeder::seedForCompany($company);
            $this->putBackOldWording($company);
        }
        EmailNotification::where('company_id', $edited->id)->where('default_key', 'waiver_bulk_chaperone')
            ->update(['name' => 'Coach Invite', 'body' => '<p>Our own words for {{customer_name}}.</p>']);
        SmsNotification::where('company_id', $edited->id)->where('default_key', 'waiver_bulk_chaperone')
            ->update(['body' => 'Our own text: {{waiver_link}}']);

        $migration = $this->migration('2026_11_02_000001_update_group_waiver_invite_notification_wording.php');
        $migration->up();

        $email = EmailNotification::where('company_id', $untouched->id)->where('default_key', 'waiver_bulk_chaperone')->sole();
        $this->assertSame('Group Waiver Invite (Chaperone)', $email->name);
        $this->assertStringStartsWith('Sent to the chaperone (group leader)', $email->description);
        $this->assertStringContainsString(self::NEW_PARAGRAPH, $email->body);
        $this->assertStringNotContainsString(self::OLD_PARAGRAPH, $email->body);
        $this->assertStringContainsString(self::NEW_PARAGRAPH, $email->default_body);
        $member = EmailNotification::where('company_id', $untouched->id)->where('default_key', 'waiver_parent_invite')->sole();
        $this->assertSame('Group Waiver Invite (Group Member)', $member->name);
        $this->assertStringStartsWith('Sent to each person a chaperone adds', $member->description);
        $text = SmsNotification::where('company_id', $untouched->id)->where('default_key', 'waiver_bulk_chaperone')->sole();
        $this->assertSame('Group Waiver Invite (Chaperone)', $text->name);
        $this->assertSame(self::NEW_TEXT, $text->body);
        $this->assertSame(self::NEW_TEXT, $text->default_body);
        $this->assertSame('Group Waiver Invite (Group Member)', SmsNotification::where('company_id', $untouched->id)->where('default_key', 'waiver_parent_invite')->value('name'));

        $editedEmail = EmailNotification::where('company_id', $edited->id)->where('default_key', 'waiver_bulk_chaperone')->sole();
        $this->assertSame('Coach Invite', $editedEmail->name);
        $this->assertSame('<p>Our own words for {{customer_name}}.</p>', $editedEmail->body);
        $this->assertStringContainsString(self::NEW_PARAGRAPH, $editedEmail->default_body);
        $this->assertSame('Our own text: {{waiver_link}}', SmsNotification::where('company_id', $edited->id)->where('default_key', 'waiver_bulk_chaperone')->value('body'));

        $migration->down();

        $email->refresh();
        $this->assertSame('Bulk Waiver Invite (Chaperone)', $email->name);
        $this->assertStringContainsString(self::OLD_PARAGRAPH, $email->body);
        $this->assertSame(self::OLD_TEXT, $text->fresh()->body);
        $this->assertSame('Coach Invite', $editedEmail->fresh()->name);
    }

    public function test_only_the_exact_test_location_is_switched_off_and_drops_off_the_public_list(): void
    {
        $company = $this->company('Zone Entertainment');
        $test = Location::forceCreate([
            'id' => 14,
            'company_id' => $company->id,
            'name' => 'Test',
            'slug' => 'test',
            'address' => '1 Test Way',
            'city' => 'Test',
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => 'test@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $real = Location::forceCreate([
            'company_id' => $company->id,
            'name' => 'Testing Lab',
            'slug' => 'testing-lab',
            'address' => '2 Real Way',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '2485550000',
            'email' => 'lab@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $this->assertContains('Test', collect($this->getJson('/api/locations')->json('data'))->pluck('name')->all());

        $migration = $this->migration('2026_11_02_000002_switch_off_test_location.php');
        $migration->up();

        $this->assertFalse($test->fresh()->is_active);
        $this->assertTrue($real->fresh()->is_active);
        $names = collect($this->getJson('/api/locations')->json('data'))->pluck('name')->all();
        $this->assertNotContains('Test', $names);
        $this->assertContains('Testing Lab', $names);

        $migration->down();
        $this->assertTrue($test->fresh()->is_active);
    }

    public function test_a_real_location_that_happens_to_have_id_14_is_left_alone(): void
    {
        $company = $this->company('Zone Entertainment');
        $canton = Location::forceCreate([
            'id' => 14,
            'company_id' => $company->id,
            'name' => 'Canton',
            'slug' => 'canton',
            'address' => '3 Canton Way',
            'city' => 'Canton',
            'state' => 'MI',
            'zip_code' => '48187',
            'phone' => '7345550000',
            'email' => 'canton@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $migration = $this->migration('2026_11_02_000002_switch_off_test_location.php');
        $migration->up();
        $this->assertTrue($canton->fresh()->is_active);

        DB::table('locations')->where('id', 14)->update(['slug' => 'test']);
        $migration->up();
        $this->assertTrue($canton->fresh()->is_active);

        DB::table('locations')->where('id', 14)->update(['slug' => 'canton', 'name' => 'Test']);
        $migration->up();
        $this->assertTrue($canton->fresh()->is_active);
    }
}
