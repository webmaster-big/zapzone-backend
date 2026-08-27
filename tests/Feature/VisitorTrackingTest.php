<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\PageView;
use App\Models\User;
use App\Models\VisitorIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VisitorTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Location $location;
    protected Location $otherLocation;
    protected User $admin;
    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $this->location = $this->makeLocation('Brighton', 'brighton@zapzone.test');
        $this->otherLocation = $this->makeLocation('Canton', 'canton@zapzone.test');

        $this->admin = $this->makeUser('company_admin', 'boss@zapzone.test', null);
        $this->manager = $this->makeUser('location_manager', 'manager@zapzone.test', $this->location);

        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    public function test_a_guest_can_identify_themselves(): void
    {
        $this->postJson('/api/analytics/identify', [
            'name' => 'Clark Raven',
            'phone' => '(810) 555-0101',
            'visitor_id' => 'visitor-abc',
            'location_id' => $this->location->id,
        ])->assertStatus(201)->assertJsonPath('data.recorded', true);

        $identity = VisitorIdentity::where('visitor_id', 'visitor-abc')->first();
        $this->assertNotNull($identity);
        $this->assertSame('Clark Raven', $identity->name);
        $this->assertSame($this->location->id, $identity->location_id);
    }

    public function test_identifying_again_updates_the_same_visitor(): void
    {
        $this->postJson('/api/analytics/identify', [
            'name' => 'Clark Raven',
            'phone' => '8105550101',
            'visitor_id' => 'visitor-abc',
        ])->assertStatus(201);

        $this->postJson('/api/analytics/identify', [
            'name' => 'Clark R. Raven',
            'phone' => '8105550102',
            'visitor_id' => 'visitor-abc',
        ])->assertStatus(201);

        $this->assertSame(1, VisitorIdentity::count());
        $this->assertSame('Clark R. Raven', VisitorIdentity::first()->name);
    }

    public function test_identify_without_a_visitor_id_is_silently_dropped(): void
    {
        $this->postJson('/api/analytics/identify', [
            'name' => 'Nobody Traceable',
            'phone' => '8105550199',
        ])->assertStatus(200)->assertJsonPath('data.recorded', false);

        $this->assertSame(0, VisitorIdentity::count());
    }

    public function test_identify_rejects_an_incomplete_phone(): void
    {
        $this->postJson('/api/analytics/identify', [
            'name' => 'Clark Raven',
            'phone' => '12345',
            'visitor_id' => 'visitor-abc',
        ])->assertStatus(422);
    }

    public function test_sessions_group_one_visitor_day_per_row(): void
    {
        $this->trackView('visitor-abc', '/brighton', '2026-08-25 10:00:00');
        $this->trackView('visitor-abc', '/book/package/brighton/party-4', '2026-08-25 10:05:00');
        $this->trackClick('visitor-abc', '/book/package/brighton/party-4', '2026-08-25 10:06:00', 'Book Now');
        $this->trackView('visitor-abc', '/brighton', '2026-08-26 19:00:00');

        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/visitor-sessions')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(2, $data['sessions']);

        $newest = $data['sessions'][0];
        $this->assertSame('2026-08-26', $newest['session_date']);

        $older = $data['sessions'][1];
        $this->assertSame(2, $older['page_views']);
        $this->assertSame(1, $older['clicks']);
        $this->assertSame('/brighton', $older['entry_page']);
        $this->assertSame('/book/package/brighton/party-4', $older['exit_page']);
    }

    public function test_identity_appears_on_the_session_row(): void
    {
        $this->trackView('visitor-abc', '/brighton', '2026-08-25 10:00:00');
        VisitorIdentity::create([
            'visitor_id' => 'visitor-abc',
            'name' => 'Clark Raven',
            'phone' => '8105550101',
        ]);

        $sessions = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/visitor-sessions')
            ->json('data.sessions');

        $this->assertSame('Clark Raven', $sessions[0]['guest_name']);
        $this->assertSame('8105550101', $sessions[0]['guest_phone']);
    }

    public function test_a_manager_only_sees_sessions_that_touched_their_location(): void
    {
        $this->trackView('visitor-here', '/brighton', '2026-08-25 10:00:00', $this->location->id);
        $this->trackView('visitor-elsewhere', '/canton', '2026-08-25 11:00:00', $this->otherLocation->id);

        $sessions = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/visitor-sessions')
            ->assertStatus(200)
            ->json('data.sessions');

        $this->assertCount(1, $sessions);
        $this->assertSame('visitor-here', $sessions[0]['visitor_id']);
    }

    public function test_the_detail_timeline_is_ordered_and_labeled(): void
    {
        $this->trackView('visitor-abc', '/brighton', '2026-08-25 10:00:00');
        $this->trackClick('visitor-abc', '/brighton', '2026-08-25 10:02:00', 'Call to Book');

        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/visitor-sessions/detail?visitor_id=visitor-abc&date=2026-08-25')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('page_view', $data['timeline'][0]['event_type']);
        $this->assertSame('engagement', $data['timeline'][1]['event_type']);
        $this->assertSame('Call to Book', $data['timeline'][1]['label']);
        $this->assertSame(1, $data['summary']['clicks']);
    }

    public function test_a_manager_cannot_open_a_session_from_another_location(): void
    {
        $this->trackView('visitor-elsewhere', '/canton', '2026-08-25 11:00:00', $this->otherLocation->id);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/visitor-sessions/detail?visitor_id=visitor-elsewhere&date=2026-08-25')
            ->assertStatus(403);
    }

    public function test_export_includes_a_timestamped_action_log(): void
    {
        $this->trackView('visitor-abc', '/brighton', '2026-08-25 10:00:00');
        $this->trackClick('visitor-abc', '/brighton', '2026-08-25 10:02:00', 'Book Now');

        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/visitor-sessions/export')
            ->assertStatus(200)
            ->json('data');

        $this->assertFalse($data['truncated']);
        $this->assertStringContainsString('Viewed', $data['sessions'][0]['actions']);
        $this->assertStringContainsString('Clicked "Book Now"', $data['sessions'][0]['actions']);
    }

    public function test_search_finds_sessions_by_phone_digits(): void
    {
        $this->trackView('visitor-abc', '/brighton', '2026-08-25 10:00:00');
        $this->trackView('visitor-xyz', '/brighton', '2026-08-25 12:00:00');
        VisitorIdentity::create([
            'visitor_id' => 'visitor-abc',
            'name' => 'Clark Raven',
            'phone' => '(810) 555-0101',
        ]);

        $sessions = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/visitor-sessions?search=8105550101')
            ->json('data.sessions');

        $this->assertCount(1, $sessions);
        $this->assertSame('visitor-abc', $sessions[0]['visitor_id']);
    }

    public function test_sessions_split_between_known_and_anonymous(): void
    {
        $this->trackView('visitor-known', '/brighton', '2026-08-25 10:00:00');
        $this->trackView('visitor-anon', '/brighton', '2026-08-25 11:00:00');
        VisitorIdentity::create([
            'visitor_id' => 'visitor-known',
            'name' => 'Clark Raven',
            'phone' => '8105550101',
        ]);

        $known = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/visitor-sessions?identified=known')
            ->assertStatus(200)
            ->json('data.sessions');
        $this->assertCount(1, $known);
        $this->assertSame('visitor-known', $known[0]['visitor_id']);

        $anonymous = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/visitor-sessions?identified=anonymous')
            ->json('data.sessions');
        $this->assertCount(1, $anonymous);
        $this->assertSame('visitor-anon', $anonymous[0]['visitor_id']);
    }

    public function test_sessions_can_be_filtered_by_activity(): void
    {
        $this->trackView('visitor-looker', '/brighton', '2026-08-25 10:00:00');
        $this->trackView('visitor-clicker', '/brighton', '2026-08-25 11:00:00');
        $this->trackClick('visitor-clicker', '/brighton', '2026-08-25 11:01:00', 'Book Now');

        $clicked = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/visitor-sessions?activity=clicked')
            ->assertStatus(200)
            ->json('data.sessions');

        $this->assertCount(1, $clicked);
        $this->assertSame('visitor-clicker', $clicked[0]['visitor_id']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/visitor-sessions?activity=bogus')
            ->assertStatus(422);
    }

    public function test_export_honors_the_same_filters(): void
    {
        $this->trackView('visitor-known', '/brighton', '2026-08-25 10:00:00');
        $this->trackView('visitor-anon', '/brighton', '2026-08-25 11:00:00');
        VisitorIdentity::create([
            'visitor_id' => 'visitor-known',
            'name' => 'Clark Raven',
            'phone' => '8105550101',
        ]);

        $rows = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/visitor-sessions/export?identified=known&date_from=2026-08-25&date_to=2026-08-25')
            ->assertStatus(200)
            ->json('data.sessions');

        $this->assertCount(1, $rows);
        $this->assertSame('Clark Raven', $rows[0]['guest_name']);
    }

    public function test_statistics_report_scoped_session_counts(): void
    {
        $this->trackView('visitor-abc', '/brighton', now()->format('Y-m-d H:i:s'));

        $stats = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/visitor-sessions/statistics')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(1, $stats['sessions_today']);
        $this->assertSame(1, $stats['sessions_week']);
    }

    private function trackView(string $visitorId, string $path, string $at, ?int $locationId = null): void
    {
        $row = PageView::create([
            'visitor_id' => $visitorId,
            'session_id' => $visitorId . '-session',
            'event_type' => 'page_view',
            'event_name' => 'page_view',
            'page_path' => $path,
            'page_title' => 'ZapZone — ' . $path,
            'location_id' => $locationId,
            'company_id' => $locationId ? $this->company->id : null,
            'duration_ms' => 30000,
        ]);

        DB::table('page_views')->where('id', $row->id)->update(['created_at' => $at, 'updated_at' => $at]);
    }

    private function trackClick(string $visitorId, string $path, string $at, string $label): void
    {
        $row = PageView::create([
            'visitor_id' => $visitorId,
            'session_id' => $visitorId . '-session',
            'event_type' => 'engagement',
            'event_name' => 'click',
            'page_path' => $path,
            'metadata' => ['label' => $label, 'tag' => 'button'],
        ]);

        DB::table('page_views')->where('id', $row->id)->update(['created_at' => $at, 'updated_at' => $at]);
    }

    private function makeLocation(string $name, string $email): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'address' => '123 Main St',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105550100',
            'email' => $email,
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    private function makeUser(string $role, string $email, ?Location $location): User
    {
        return User::create([
            'company_id' => $this->company->id,
            'location_id' => $location?->id,
            'first_name' => 'Test',
            'last_name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
