<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingInternalNote;
use App\Models\BookingTombstone;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingSyncTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $brighton;

    private Location $canton;

    private Location $rival;

    private array $packages = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(Carbon::parse('2026-10-03 11:00:00', config('app.timezone')));

        $this->company = $this->makeCompany('Zap Zone');
        $this->brighton = $this->makeLocation($this->company, 'Brighton');
        $this->canton = $this->makeLocation($this->company, 'Canton');
        $this->rival = $this->makeLocation($this->makeCompany('Other Arcade'), 'Toledo');
    }

    public function test_a_sync_download_carries_a_cursor_and_a_plain_list_does_not(): void
    {
        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $this->book($this->brighton);

        $plain = $this->as($token)->getJson('/api/bookings?per_page=100')->assertOk()->json('data');
        $this->assertArrayNotHasKey('sync', $plain);

        $sync = $this->list($token, ['sync' => 1])['sync'];
        $this->assertSame((string) (now()->getTimestamp() - 300), $sync['cursor']);
        $this->assertIsString($sync['scope']);
        $this->assertArrayNotHasKey('total', $sync);
    }

    public function test_changes_since_a_cursor_are_only_what_changed_in_scope(): void
    {
        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $edited = $this->book($this->brighton);
        $noted = $this->book($this->brighton);
        $correctedNote = $this->note($this->book($this->brighton), 'Allergy: peanuts');
        $untouched = $this->book($this->brighton);
        $cancelled = $this->book($this->brighton);
        $elsewhere = $this->book($this->canton);
        $elsewhereCancelled = $this->book($this->canton);

        $this->travel(10)->minutes();
        $cursor = $this->list($token, ['sync' => 1])['sync']['cursor'];

        $this->travel(10)->minutes();
        $edited->update(['participants' => 12]);
        $this->note($noted, 'Guest paid the deposit in cash');
        $correctedNote->update(['body' => 'Allergy: tree nuts', 'edited_at' => now()]);
        $new = $this->book($this->brighton);
        $cancelled->delete();
        $elsewhere->update(['participants' => 3]);
        $elsewhereCancelled->delete();

        $changes = $this->list($token, ['updated_since' => $cursor]);

        $this->assertEqualsCanonicalizing(
            [$edited->id, $noted->id, $correctedNote->booking_id, $new->id],
            array_column($changes['bookings'], 'id')
        );
        $this->assertNotContains($untouched->id, array_column($changes['bookings'], 'id'));
        $this->assertSame([$cancelled->id], $changes['sync']['deleted_ids']);
        $this->assertSame(5, $changes['sync']['total']);
        $this->assertSame((string) (now()->getTimestamp() - 300), $changes['sync']['cursor']);
        $this->assertStringContainsString('Guest paid the deposit in cash', collect($changes['bookings'])->firstWhere('id', $noted->id)['internal_notes']);
        $this->assertStringContainsString('Allergy: tree nuts', collect($changes['bookings'])->firstWhere('id', $correctedNote->booking_id)['internal_notes']);
    }

    public function test_replaying_the_changes_onto_the_first_download_matches_a_fresh_download(): void
    {
        foreach (['location_manager', 'company_admin'] as $role) {
            $token = $this->tokenFor($this->staff($role, $this->brighton));
            $keep = $this->book($this->brighton);
            $other = $this->book($this->canton);
            $drop = $this->book($this->brighton);
            $this->book($this->rival);

            $first = $this->list($token, ['sync' => 1]);
            $cached = collect($first['bookings'])->keyBy('id');

            $this->travel(7)->minutes();
            $keep->update(['participants' => 20]);
            $other->update(['participants' => 2]);
            $drop->delete();
            $this->book($this->canton);
            $this->book($this->brighton);
            $this->book($this->rival);

            $changes = $this->list($token, ['updated_since' => $first['sync']['cursor']]);
            foreach ($changes['sync']['deleted_ids'] as $id) {
                $cached->forget($id);
            }
            foreach ($changes['bookings'] as $booking) {
                $cached->put($booking['id'], $booking);
            }

            $fresh = collect($this->list($token, ['sync' => 1])['bookings'])->keyBy('id');

            $this->assertSame($fresh->keys()->sort()->values()->all(), $cached->keys()->sort()->values()->all(), $role);
            $this->assertNotContains($this->rival->id, $fresh->pluck('location_id')->all(), $role . ' never sees another company');
            $this->assertSame($fresh->count(), $changes['sync']['total'], $role);
            $this->assertSame(20, $cached[$keep->id]['participants'], $role);
            $this->assertSame($first['sync']['scope'], $changes['sync']['scope'], $role);

            Booking::withTrashed()->forceDelete();
            $this->travel(1)->hours();
        }
    }

    public function test_a_booking_moved_out_of_a_managers_location_shows_up_as_a_count_mismatch(): void
    {
        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $this->book($this->brighton);
        $moved = $this->book($this->brighton);

        $this->travel(10)->minutes();
        $first = $this->list($token, ['sync' => 1]);
        $this->assertCount(2, $first['bookings']);

        $this->travel(7)->minutes();
        $moved->update(['location_id' => $this->canton->id, 'package_id' => $this->packages[$this->canton->id]->id]);

        $changes = $this->list($token, ['updated_since' => $first['sync']['cursor']]);

        $this->assertSame([], $changes['bookings']);
        $this->assertSame([], $changes['sync']['deleted_ids']);
        $this->assertSame(1, $changes['sync']['total']);
    }

    public function test_the_overlap_catches_a_save_that_landed_just_before_the_cursor(): void
    {
        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $this->travel(-2)->minutes();
        $late = $this->book($this->brighton);
        $this->travel(2)->minutes();

        $cursor = $this->list($token, ['sync' => 1])['sync']['cursor'];

        $this->assertContains($late->id, array_column($this->list($token, ['updated_since' => $cursor])['bookings'], 'id'));
    }

    public function test_an_unrecognised_cursor_is_refused(): void
    {
        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));

        $this->as($token)->getJson('/api/bookings?updated_since=' . (now()->getTimestamp() - 4 * 3600))->assertStatus(422);
        $this->list($token, ['updated_since' => now()->getTimestamp() - 2 * 3600]);

        foreach (['abc', '12.5', '-60', '1234567890123', ''] as $cursor) {
            $this->as($token)->getJson('/api/bookings?' . http_build_query(['updated_since' => $cursor]))
                ->assertStatus(422);
        }

        $this->as($token)->getJson('/api/bookings?updated_since[]=1')->assertStatus(422);
    }

    public function test_only_staff_can_sync_and_never_together_with_filters(): void
    {
        $this->book($this->brighton);
        $customer = Customer::create([
            'first_name' => 'Pat',
            'last_name' => 'Guest',
            'email' => 'pat@example.com',
            'phone' => '7345550000',
            'password' => bcrypt('secret-password'),
            'status' => 'active',
        ]);
        $customerToken = $customer->createToken('portal')->plainTextToken;
        $staffToken = $this->tokenFor($this->staff('location_manager', $this->brighton));

        $this->as($customerToken)->getJson('/api/bookings?sync=1')->assertForbidden();
        $this->as($customerToken)->getJson('/api/bookings?updated_since=' . now()->getTimestamp())->assertForbidden();

        foreach (['status' => 'confirmed', 'search' => 'Test', 'date_from' => '2026-10-01', 'customer_id' => 1, 'reference_number' => 'BK1'] as $filter => $value) {
            $this->as($staffToken)->getJson('/api/bookings?' . http_build_query(['sync' => 1, $filter => $value]))->assertStatus(422);
            $this->as($staffToken)->getJson('/api/bookings?' . http_build_query(['updated_since' => now()->getTimestamp(), $filter => $value]))->assertStatus(422);
        }

        $this->list($staffToken, ['sync' => 1, 'location_id' => $this->brighton->id]);
    }

    public function test_guest_contact_changes_reach_the_change_list(): void
    {
        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $guest = Customer::create([
            'first_name' => 'Robin',
            'last_name' => 'Guest',
            'email' => 'robin@example.com',
            'phone' => '7345550001',
            'password' => bcrypt('secret-password'),
            'status' => 'active',
        ]);
        $forGuest = $this->book($this->brighton);
        $forGuest->update(['customer_id' => $guest->id]);
        $elsewhere = $this->book($this->canton);
        $elsewhere->update(['customer_id' => $guest->id]);
        $untouched = $this->book($this->brighton);

        $this->travel(10)->minutes();
        $cursor = $this->list($token, ['sync' => 1])['sync']['cursor'];

        $this->travel(10)->minutes();
        $guest->update(['phone' => '7345559999']);

        $changed = array_column($this->list($token, ['updated_since' => $cursor])['bookings'], 'id');

        $this->assertSame([$forGuest->id], $changed, 'the same guest booked at another location stays out of this list');
        $this->assertNotContains($untouched->id, $changed);
    }

    public function test_editing_only_a_bookings_add_ons_marks_it_changed(): void
    {
        config(['booking_rules.change_reason' => 'off']);
        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $booking = $this->book($this->brighton);
        $this->as($token)->putJson("/api/bookings/{$booking->id}", ['additional_addons' => []])->assertOk();

        $this->travel(10)->minutes();
        $cursor = $this->list($token, ['sync' => 1])['sync']['cursor'];
        $settled = $booking->fresh()->getAttributes();

        $this->travel(10)->minutes();
        $this->as($token)->putJson("/api/bookings/{$booking->id}", ['additional_addons' => []])->assertOk();

        $this->assertSame(['updated_at'], array_keys(array_diff_assoc(array_map('strval', $booking->fresh()->getAttributes()), array_map('strval', $settled))));
        $this->assertSame(0, BookingInternalNote::where('booking_id', $booking->id)->count());
        $this->assertSame([$booking->id], array_column($this->list($token, ['updated_since' => $cursor])['bookings'], 'id'));
    }

    public function test_a_hard_deleted_booking_is_reported_only_to_lists_that_could_see_it(): void
    {
        $managerToken = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $adminToken = $this->tokenFor($this->staff('company_admin', $this->brighton));
        $here = $this->book($this->brighton);
        $there = $this->book($this->canton);
        $elsewhere = $this->book($this->rival);
        $kept = $this->book($this->brighton);

        $this->travel(10)->minutes();
        $managerCursor = $this->list($managerToken, ['sync' => 1])['sync']['cursor'];
        $adminCursor = $this->list($adminToken, ['sync' => 1])['sync']['cursor'];

        $this->travel(10)->minutes();
        foreach ([$here, $there, $elsewhere] as $booking) {
            $booking->forceDelete();
        }

        $manager = $this->list($managerToken, ['updated_since' => $managerCursor])['sync'];
        $admin = $this->list($adminToken, ['updated_since' => $adminCursor])['sync'];

        $this->assertSame([$here->id], $manager['deleted_ids']);
        $this->assertSame(1, $manager['total']);
        $this->assertEqualsCanonicalizing([$here->id, $there->id], $admin['deleted_ids']);
        $this->assertSame(1, $admin['total']);
        $this->assertNotNull(Booking::find($kept->id));

        $this->travel(BookingTombstone::KEEP_HOURS + 1)->hours();
        $this->book($this->brighton)->forceDelete();
        $this->assertSame(1, BookingTombstone::count(), 'old records are cleared as new ones arrive');
    }

    public function test_an_edit_in_the_repeated_hour_after_clocks_fall_back_is_not_missed(): void
    {
        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $this->travelTo(Carbon::parse('2026-10-31 12:00:00', 'America/Detroit'));
        $booking = $this->book($this->brighton);

        $this->travelTo(Carbon::parse('2026-11-01 05:50:00', 'UTC'));
        $cursor = $this->list($token, ['sync' => 1])['sync']['cursor'];

        DB::table('bookings')->where('id', $booking->id)->update(['updated_at' => '2026-11-01 01:10:00']);

        $this->travelTo(Carbon::parse('2026-11-01 07:30:00', 'UTC'));
        $this->assertSame([$booking->id], array_column($this->list($token, ['updated_since' => $cursor])['bookings'], 'id'));
    }

    public function test_sync_pages_follow_booking_ids_so_nothing_is_skipped(): void
    {
        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $ids = [];
        foreach (range(1, 7) as $n) {
            $ids[] = $this->book($this->brighton)->id;
        }

        $first = $this->list($token, ['sync' => 1, 'per_page' => 3]);
        $seen = array_column($first['bookings'], 'id');
        Booking::whereKey($ids[5])->delete();
        $beforeId = min($seen);
        for ($pages = 0; $pages < 5; $pages++) {
            $page = $this->list($token, ['sync' => 1, 'per_page' => 3, 'before_id' => $beforeId])['bookings'];
            $seen = array_merge($seen, array_column($page, 'id'));
            if (count($page) < 3) {
                break;
            }
            $beforeId = min(array_column($page, 'id'));
        }

        $this->assertSame(array_reverse($ids), $seen, 'a booking deleted from an earlier page does not push another one out');

        $this->travel(10)->minutes();
        $cursor = $this->list($token, ['sync' => 1])['sync']['cursor'];
        $this->travel(10)->minutes();
        foreach ($ids as $id) {
            Booking::whereKey($id)->update(['participants' => 9]);
        }

        $changes = $this->list($token, ['updated_since' => $cursor, 'per_page' => 4]);
        $this->assertSame(6, $changes['sync']['total']);
        $more = $this->list($token, ['updated_since' => $cursor, 'per_page' => 4, 'before_id' => min(array_column($changes['bookings'], 'id'))]);
        $this->assertSame(6, $more['sync']['total'], 'the total always covers the whole list');
        $this->assertEqualsCanonicalizing(
            array_values(array_diff($ids, [$ids[5]])),
            array_merge(array_column($changes['bookings'], 'id'), array_column($more['bookings'], 'id'))
        );
    }

    public function test_bad_paging_values_are_refused_or_clamped(): void
    {
        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $this->book($this->brighton);
        $this->book($this->brighton);

        $this->as($token)->getJson('/api/bookings?sync=1&before_id=abc')->assertStatus(422);
        $this->assertCount(1, $this->as($token)->getJson('/api/bookings?per_page=-1')->assertOk()->json('data.bookings'));
        $this->assertCount(1, $this->as($token)->getJson('/api/bookings?per_page=0')->assertOk()->json('data.bookings'));
        $this->assertCount(2, $this->as($token)->getJson('/api/bookings?before_id=1&per_page=10')->assertOk()->json('data.bookings'), 'before_id only applies to sync requests');
    }

    public function test_sync_pages_ignore_the_callers_sort_and_page_number(): void
    {
        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $ids = [];
        foreach (range(1, 4) as $n) {
            $ids[] = $this->book($this->brighton)->id;
        }

        $page = $this->list($token, ['sync' => 1, 'per_page' => 2, 'before_id' => $ids[3], 'sort_by' => 'booking_date', 'sort_order' => 'asc', 'page' => 5]);

        $this->assertSame([$ids[2], $ids[1]], array_column($page['bookings'], 'id'));
    }

    public function test_too_many_changes_ask_for_a_fresh_download(): void
    {
        config(['booking_rules.sync_max_changed_ids' => 2]);
        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $cursor = $this->list($token, ['sync' => 1])['sync']['cursor'];

        $this->book($this->brighton);
        $this->book($this->brighton);
        $this->list($token, ['updated_since' => $cursor]);

        $this->book($this->brighton);
        $this->as($token)->getJson('/api/bookings?updated_since=' . $cursor)->assertStatus(422);
    }

    public function test_change_checks_keep_working_before_the_removed_bookings_table_exists(): void
    {
        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $gone = $this->book($this->brighton);
        $this->travel(10)->minutes();
        $cursor = $this->list($token, ['sync' => 1])['sync']['cursor'];
        $this->travel(10)->minutes();
        $gone->delete();

        BookingTombstone::forgetTableCheck(false);
        try {
            $this->assertSame([$gone->id], $this->list($token, ['updated_since' => $cursor])['sync']['deleted_ids']);
        } finally {
            BookingTombstone::forgetTableCheck();
        }
    }

    public function test_the_scope_changes_when_the_people_or_location_behind_the_list_change(): void
    {
        $manager = $this->staff('location_manager', $this->brighton);
        $manager->locations()->sync([$this->canton->id]);
        $managerToken = $this->tokenFor($manager);
        $otherManagerToken = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $adminToken = $this->tokenFor($this->staff('company_admin', $this->brighton));

        $atBrighton = $this->list($managerToken, ['sync' => 1])['sync']['scope'];
        $this->as($managerToken)->putJson('/api/staff-locations/active', ['location_id' => $this->canton->id])->assertOk();
        $atCanton = $this->list($managerToken, ['sync' => 1])['sync']['scope'];

        $this->assertNotSame($atBrighton, $atCanton);
        $this->assertNotSame($atBrighton, $this->list($otherManagerToken, ['sync' => 1])['sync']['scope']);
        $this->assertNotSame(
            $this->list($adminToken, ['sync' => 1])['sync']['scope'],
            $this->list($adminToken, ['sync' => 1, 'location_id' => $this->canton->id])['sync']['scope']
        );
    }

    private function makeCompany(string $name): Company
    {
        return Company::create([
            'company_name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)) . '@example.test',
            'phone' => '5551230000',
            'address' => '1 Test St',
        ]);
    }

    private function makeLocation(Company $company, string $city): Location
    {
        $location = Location::create([
            'company_id' => $company->id,
            'name' => "{$city} | Zap Zone",
            'address' => '1 Test Way',
            'city' => $city,
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => strtolower($city) . '@example.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $this->packages[$location->id] = Package::create([
            'location_id' => $location->id,
            'name' => 'Arcade Party',
            'description' => 'Party package',
            'category' => 'party',
            'price' => 199.00,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 40,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);

        return $location;
    }

    private function staff(string $role, Location $location): User
    {
        return User::create([
            'first_name' => ucfirst($role),
            'last_name' => 'Staff',
            'email' => $role . '.' . uniqid() . '@zapzone.test',
            'password' => 'secret-password',
            'role' => $role,
            'company_id' => $location->company_id,
            'location_id' => $location->id,
            'status' => 'active',
        ]);
    }

    private function book(Location $location): Booking
    {
        return Booking::create([
            'reference_number' => 'BK' . strtoupper(uniqid()),
            'location_id' => $location->id,
            'package_id' => $this->packages[$location->id]->id,
            'guest_name' => 'Test Guest',
            'guest_email' => 'guest@test.com',
            'booking_date' => now()->addDays(7)->toDateString(),
            'booking_time' => '14:00',
            'participants' => 8,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'total_amount' => 199.00,
            'amount_paid' => 0,
            'payment_status' => 'pending',
            'status' => 'confirmed',
            'payment_method' => 'in-store',
        ]);
    }

    private function note(Booking $booking, string $body): BookingInternalNote
    {
        return BookingInternalNote::create([
            'booking_id' => $booking->id,
            'author_name' => 'Desk Staff',
            'author_role' => 'attendant',
            'category' => 'general',
            'body' => $body,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    private function list(string $token, array $query): array
    {
        return $this->as($token)
            ->getJson('/api/bookings?' . http_build_query($query + ['per_page' => 100, 'sort_by' => 'id', 'sort_order' => 'desc']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data');
    }
}
