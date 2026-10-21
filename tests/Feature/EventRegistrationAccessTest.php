<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\RegistrationContact;
use App\Models\RegistrationEntry;
use App\Models\RegistrationEvent;
use App\Models\StaffTerminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\EventRegistrationFixtures;
use Tests\TestCase;

class EventRegistrationAccessTest extends TestCase
{
    use RefreshDatabase;
    use EventRegistrationFixtures;

    private Company $company;

    private Location $brighton;

    private Location $farmington;

    private Location $lansing;

    private User $admin;

    private User $brightonManager;

    private User $attendant;

    private User $otherAdmin;

    private RegistrationEvent $brightonForm;

    private RegistrationEvent $farmingtonForm;

    private RegistrationEvent $lansingForm;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false]);
        Mail::fake();
        Storage::fake('public');
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->at('2026-10-15 18:00:00');

        $this->company = $this->makeCompany('Fun Co');
        $other = $this->makeCompany('Other Co');
        $this->brighton = $this->makeVenue($this->company, 'Brighton');
        $this->farmington = $this->makeVenue($this->company, 'Farmington');
        $this->lansing = $this->makeVenue($other, 'Lansing');

        $this->admin = $this->makeStaff($this->company, null, 'boss', 'company_admin');
        $this->brightonManager = $this->makeStaff($this->company, $this->brighton, 'brightonmanager', 'location_manager');
        $this->attendant = $this->makeStaff($this->company, $this->brighton, 'brightonattendant', 'attendant');
        $this->otherAdmin = $this->makeStaff($other, null, 'otherboss', 'company_admin');

        $this->brightonForm = $this->makeForm($this->brighton, 'Brighton Halloween', '2026-10-31');
        $this->farmingtonForm = $this->makeForm($this->farmington, 'Farmington Halloween', '2026-10-31');
        $this->lansingForm = $this->makeForm($this->lansing, 'Lansing Halloween', '2026-10-31');

        $this->actAs(null);
        $this->signUp($this->brightonForm, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $this->signUp($this->farmingtonForm, 'Jane Doe', 'jane@example.com', '7345550142')->assertCreated();
        $this->signUp($this->lansingForm, 'Lana Lee', 'lana@example.com', '5175550100')->assertCreated();
    }

    public function test_guests_customers_and_attendants_are_kept_out_of_every_staff_route(): void
    {
        $customer = $this->makeCustomer();

        foreach ([null, $customer, $this->attendant] as $who) {
            foreach ($this->staffRoutes($this->brightonForm) as [$method, $url]) {
                $this->actAs($who);
                $response = $this->json($method, $url);
                $expected = $who === null ? 401 : 403;
                $this->assertSame($expected, $response->status(), "{$method} {$url} as " . ($who === null ? 'guest' : class_basename($who) . ' ' . ($who->role ?? '')));
                $this->assertStringNotContainsString('jane@example.com', $response->getContent());
            }
        }

        $this->assertSame(3, RegistrationEntry::count());
        $this->assertNull($this->brightonForm->fresh()->deleted_at);
    }

    public function test_a_manager_only_sees_and_changes_their_own_location(): void
    {
        $this->actAs($this->brightonManager);

        $this->getJson('/api/registration-events')
            ->assertOk()
            ->assertJsonCount(1, 'data.events')
            ->assertJsonPath('data.events.0.id', $this->brightonForm->id)
            ->assertJsonPath('data.events.0.total_entries', 1)
            ->assertJsonPath('data.events.0.unique_registrations', 1);
        $this->getJson('/api/registration-events?location_id=' . $this->farmington->id)
            ->assertOk()
            ->assertJsonCount(0, 'data.events');

        foreach ($this->formRoutes($this->farmingtonForm) as [$method, $url]) {
            $this->actAs($this->brightonManager);
            $response = $this->json($method, $url);
            $this->assertSame(403, $response->status(), "{$method} {$url}");
            $this->assertStringNotContainsString('jane@example.com', $response->getContent());
        }

        $this->actAs($this->brightonManager);
        $this->post('/api/registration-events', ['name' => 'Sneaky', 'location_id' => $this->farmington->id, 'event_date' => '2026-12-01'])
            ->assertForbidden();
        $this->post("/api/registration-events/{$this->brightonForm->id}", ['location_id' => $this->farmington->id])
            ->assertStatus(422);
        $this->post('/api/registration-events', ['name' => 'Ours', 'location_id' => $this->brighton->id, 'event_date' => '2026-12-01'])
            ->assertCreated();
        $this->assertSame(1, RegistrationEvent::where('location_id', $this->farmington->id)->count());
    }

    public function test_a_company_admin_sees_the_whole_company_but_never_another_company(): void
    {
        $this->actAs($this->admin);

        $ids = collect($this->getJson('/api/registration-events')->assertOk()->json('data.events'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->brightonForm->id, $this->farmingtonForm->id], $ids);

        $this->getJson('/api/registration-events?location_id=' . $this->farmington->id)
            ->assertJsonCount(1, 'data.events')
            ->assertJsonPath('data.events.0.id', $this->farmingtonForm->id);
        $this->getJson('/api/registration-events?location_id=' . $this->lansing->id)->assertOk()->assertJsonCount(0, 'data.events');

        foreach ($this->formRoutes($this->lansingForm) as [$method, $url]) {
            $this->actAs($this->admin);
            $response = $this->json($method, $url);
            $this->assertSame(404, $response->status(), "{$method} {$url}");
            $this->assertStringNotContainsString('lana@example.com', $response->getContent());
        }

        $this->actAs($this->admin);
        $this->post('/api/registration-events', ['name' => 'Elsewhere', 'location_id' => $this->lansing->id, 'event_date' => '2026-12-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['location_id']);

        $this->actAs($this->otherAdmin);
        $this->getJson('/api/registration-events')->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.id', $this->lansingForm->id);
        $this->getJson("/api/registration-events/{$this->brightonForm->id}")->assertNotFound();
    }

    public function test_an_admin_using_a_pin_on_a_manager_terminal_is_limited_to_that_location(): void
    {
        $terminal = StaffTerminal::forceCreate([
            'company_id' => $this->company->id,
            'location_id' => $this->brighton->id,
            'device_id' => 'front-desk-1',
            'label' => 'Front desk',
            'token_hash' => hash('sha256', 'terminal-secret'),
            'ceiling_role' => 'location_manager',
        ]);

        $this->actAs($this->admin, ['pin', 'role:location_manager', 'terminal:' . $terminal->id]);

        $this->getJson('/api/registration-events')
            ->assertOk()
            ->assertJsonCount(1, 'data.events')
            ->assertJsonPath('data.events.0.id', $this->brightonForm->id);

        foreach ($this->formRoutes($this->farmingtonForm) as [$method, $url]) {
            $this->actAs($this->admin, ['pin', 'role:location_manager', 'terminal:' . $terminal->id]);
            $this->assertSame(403, $this->json($method, $url)->status(), "{$method} {$url}");
        }

        $this->actAs($this->admin, ['pin', 'role:location_manager', 'terminal:' . $terminal->id]);
        $this->post('/api/registration-events', ['name' => 'Sneaky', 'location_id' => $this->farmington->id, 'event_date' => '2026-12-01'])
            ->assertForbidden();
    }

    public function test_a_manager_of_several_venues_is_told_to_switch(): void
    {
        DB::table('location_user')->insert([
            'user_id' => $this->brightonManager->id,
            'location_id' => $this->farmington->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actAs($this->brightonManager);
        $this->getJson("/api/registration-events/{$this->farmingtonForm->id}")
            ->assertForbidden()
            ->assertJsonPath('switch_location_id', $this->farmington->id);
    }

    public function test_a_contact_from_another_company_is_never_reachable_through_history(): void
    {
        $lansingContact = RegistrationContact::where('company_id', $this->lansing->company_id)->sole();

        $this->actAs($this->admin);
        $this->getJson("/api/registration-events/{$this->brightonForm->id}/contacts/{$lansingContact->id}/history")
            ->assertNotFound();
    }

    public function test_the_public_limits_count_per_form_and_never_crash_for_customer_tokens(): void
    {
        $this->withMiddleware(ThrottleRequests::class);
        config(['cache.default' => 'array']);
        $customer = $this->makeCustomer();

        $this->actAs($customer);
        $this->getJson('/api/registration-forms/' . $this->brightonForm->public_code)->assertOk();
        $this->signUp($this->brightonForm, 'Pat Guest', 'pat.guest@example.com', '7345550177')->assertCreated();

        $statuses = [];
        for ($i = 0; $i < 120; $i++) {
            $statuses[] = $this->postJson('/api/registration-forms/' . $this->brightonForm->public_code, ['submission_key' => 'x'])->status();
        }

        $this->assertContains(429, $statuses);
        $this->assertNotContains(500, $statuses);
        $this->postJson('/api/registration-forms/' . $this->farmingtonForm->public_code, [
            'name' => 'Pat Guest',
            'email' => 'pat.guest@example.com',
            'phone' => '7345550177',
            'submission_key' => $this->nextSubmissionKey(),
        ])->assertCreated();
    }

    private function staffRoutes(RegistrationEvent $form): array
    {
        return array_merge([
            ['GET', '/api/registration-events'],
            ['POST', '/api/registration-events'],
        ], $this->formRoutes($form));
    }

    private function formRoutes(RegistrationEvent $form): array
    {
        $entry = RegistrationEntry::where('registration_event_id', $form->id)->firstOrFail();

        return [
            ['GET', "/api/registration-events/{$form->id}"],
            ['POST', "/api/registration-events/{$form->id}"],
            ['GET', "/api/registration-events/{$form->id}/registrations"],
            ['GET', "/api/registration-events/{$form->id}/registrations/export"],
            ['GET', "/api/registration-events/{$form->id}/contacts/{$entry->registration_contact_id}/history"],
            ['POST', "/api/registration-events/{$form->id}/entries/{$entry->id}/hide"],
            ['POST', "/api/registration-events/{$form->id}/entries/{$entry->id}/restore"],
            ['DELETE', "/api/registration-events/{$form->id}"],
        ];
    }
}
