<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactClearableFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $location = Location::create([
            'company_id' => $company->id,
            'name' => 'Madison Heights | Escape Room',
            'address' => '1 Test Way',
            'city' => 'Madison Heights',
            'state' => 'MI',
            'zip_code' => '48071',
            'phone' => '2485551234',
            'email' => 'madison@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'first_name' => 'Company',
            'last_name' => 'Admin',
            'email' => 'admin.user@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);

        $this->contact = Contact::create([
            'company_id' => $company->id,
            'location_id' => $location->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.test',
            'phone' => '2485550000',
            'company_name' => 'Analytical Engines',
            'job_title' => 'Mathematician',
            'address' => '1 Difference Way',
            'city' => 'London',
            'state' => 'MI',
            'zip' => '48071',
            'country' => 'US',
            'source' => 'walk-in',
            'notes' => 'Prefers mornings',
            'status' => 'active',
        ]);
    }

    public function test_every_optional_contact_field_can_be_emptied(): void
    {
        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/contacts/{$this->contact->id}", [
                'first_name' => null,
                'last_name' => null,
                'phone' => null,
                'company_name' => null,
                'job_title' => null,
                'address' => null,
                'city' => null,
                'state' => null,
                'zip' => null,
                'country' => null,
                'source' => null,
                'notes' => null,
                'date_of_birth' => null,
            ])
            ->assertOk();

        $fresh = $this->contact->fresh();

        foreach (['first_name', 'last_name', 'phone', 'company_name', 'job_title',
                  'address', 'city', 'state', 'zip', 'country', 'source', 'notes'] as $field) {
            $this->assertNull($fresh->{$field}, "{$field} should have been cleared");
        }

        $this->assertSame('ada@example.test', $fresh->email, 'the last way of reaching a contact is kept');
    }

    public function test_a_null_status_is_refused_cleanly_rather_than_crashing(): void
    {
        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/contacts/{$this->contact->id}", ['status' => null])
            ->assertStatus(422);

        $this->assertSame('active', $this->contact->fresh()->status);
    }

    public function test_a_null_sms_consent_is_refused_cleanly(): void
    {
        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/contacts/{$this->contact->id}", ['sms_consent' => null])
            ->assertStatus(422);
    }

    public function test_status_can_still_be_changed(): void
    {
        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/contacts/{$this->contact->id}", ['status' => 'inactive'])
            ->assertOk();

        $this->assertSame('inactive', $this->contact->fresh()->status);
    }

    public function test_a_contact_cannot_lose_both_its_email_and_its_phone(): void
    {
        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/contacts/{$this->contact->id}", ['email' => null, 'phone' => null])
            ->assertStatus(422);

        $fresh = $this->contact->fresh();

        $this->assertSame('ada@example.test', $fresh->email);
        $this->assertSame('2485550000', $fresh->phone);
    }

    public function test_one_of_the_two_can_still_be_cleared(): void
    {
        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/contacts/{$this->contact->id}", ['phone' => null])
            ->assertOk();

        $this->assertNull($this->contact->fresh()->phone);
        $this->assertSame('ada@example.test', $this->contact->fresh()->email);
    }

    public function test_a_field_left_out_is_untouched(): void
    {
        $this->actingAs($this->staff, 'sanctum')
            ->putJson("/api/contacts/{$this->contact->id}", ['city' => 'Detroit'])
            ->assertOk();

        $fresh = $this->contact->fresh();

        $this->assertSame('Detroit', $fresh->city);
        $this->assertSame('Ada', $fresh->first_name);
        $this->assertSame('active', $fresh->status);
    }
}
