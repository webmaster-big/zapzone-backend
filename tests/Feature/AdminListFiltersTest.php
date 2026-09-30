<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\GiftCard;
use App\Models\Location;
use App\Models\Promo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminListFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $attendant;

    private array $locations = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $company = Company::create([
            'company_name' => 'Lists Co',
            'email' => 'lists@zapzone.test',
            'phone' => '5551230001',
            'address' => '2 List St',
        ]);

        foreach (['Brighton', 'Waterford'] as $name) {
            $this->locations[] = Location::create([
                'company_id' => $company->id,
                'name' => $name,
                'address' => '1 Test Way',
                'city' => $name,
                'state' => 'MI',
                'zip_code' => '48327',
                'phone' => '2485551234',
                'email' => strtolower($name) . '.lists@zapzone.test',
                'timezone' => 'America/Detroit',
                'is_active' => true,
            ]);
        }

        $make = fn (string $role, string $email, ?int $locationId) => User::create([
            'first_name' => ucfirst(str_replace('_', ' ', $role)),
            'last_name' => 'Lists',
            'email' => $email,
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'company_id' => $company->id,
            'location_id' => $locationId,
        ]);

        $this->admin = $make('company_admin', 'lists.admin@zapzone.test', $this->locations[0]->id);
        $this->manager = $make('location_manager', 'lists.manager@zapzone.test', $this->locations[0]->id);
        $this->attendant = $make('attendant', 'lists.attendant@zapzone.test', $this->locations[1]->id);
    }

    private function giftCard(string $code, string $status, string $expiry, bool $deleted = false): GiftCard
    {
        return GiftCard::create([
            'code' => $code,
            'type' => 'fixed',
            'initial_value' => 50,
            'balance' => 50,
            'max_usage' => 1,
            'status' => $status,
            'expiry_date' => $expiry,
            'created_by' => $this->admin->id,
            'location_id' => $this->locations[0]->id,
            'deleted' => $deleted,
        ]);
    }

    private function promo(string $code, ?string $batchId = null): Promo
    {
        return Promo::create([
            'code' => $code,
            'name' => $code,
            'type' => 'fixed',
            'value' => 10,
            'start_date' => '2026-01-01',
            'end_date' => '2027-12-31',
            'usage_limit_per_user' => 1,
            'status' => 'active',
            'created_by' => $this->admin->id,
            'location_ids' => null,
            'deleted' => false,
            'code_mode' => $batchId ? 'unique' : 'single',
            'batch_id' => $batchId,
        ]);
    }

    private function collect(string $path, string $key, int $perPage, array $query = []): array
    {
        $ids = [];
        $page = 1;

        do {
            $data = $this->actingAs($this->admin, 'sanctum')
                ->getJson($path . '?' . http_build_query(array_merge($query, ['per_page' => $perPage, 'page' => $page])))
                ->assertOk()
                ->json('data');

            foreach ($data[$key] as $row) {
                $ids[] = $row['id'];
            }

            $lastPage = $data['pagination']['last_page'];
            $page++;
        } while ($page <= $lastPage);

        return $ids;
    }

    public function test_gift_cards_status_all_returns_every_non_deleted_card_and_the_default_is_unchanged(): void
    {
        $active = $this->giftCard('GC-ACTIVE', 'active', '2030-01-01');
        $inactive = $this->giftCard('GC-INACTIVE', 'inactive', '2030-01-01');
        $redeemed = $this->giftCard('GC-REDEEMED', 'redeemed', '2030-01-01');
        $expired = $this->giftCard('GC-EXPIRED', 'active', '2020-01-01');
        $deleted = $this->giftCard('GC-DELETED', 'active', '2030-01-01', true);

        $all = $this->collect('/api/gift-cards', 'gift_cards', 2, ['status' => 'all', 'include_expired' => 1]);
        sort($all);
        $expected = [$active->id, $inactive->id, $redeemed->id, $expired->id];
        sort($expected);
        $this->assertSame($expected, $all);
        $this->assertNotContains($deleted->id, $all);

        $default = $this->collect('/api/gift-cards', 'gift_cards', 50);
        $this->assertSame([$active->id], $default);
    }

    public function test_promos_can_exclude_batch_codes_and_page_stably_when_created_together(): void
    {
        $singles = [];
        for ($i = 0; $i < 3; $i++) {
            $singles[] = $this->promo('SINGLE-' . $i)->id;
        }

        $batch = (string) Str::uuid();
        $batchIds = [];
        for ($i = 0; $i < 25; $i++) {
            $batchIds[] = $this->promo('BATCH-' . $i, $batch)->id;
        }

        Promo::query()->update(['created_at' => '2026-09-01 10:00:00']);

        $withoutBatches = $this->collect('/api/promos', 'promos', 50, ['status' => 'all', 'exclude_batches' => 1]);
        sort($withoutBatches);
        $this->assertSame($singles, $withoutBatches);

        $everything = $this->collect('/api/promos', 'promos', 4, ['status' => 'all']);
        $this->assertCount(28, $everything);
        $this->assertSame(count($everything), count(array_unique($everything)));
        $this->assertSame([], array_values(array_diff(array_merge($singles, $batchIds), $everything)));
    }

    public function test_activity_logs_filter_by_role_and_by_several_users_or_locations(): void
    {
        $write = function (User $user, int $locationId, int $count) {
            $ids = [];
            for ($i = 0; $i < $count; $i++) {
                $ids[] = ActivityLog::create([
                    'user_id' => $user->id,
                    'actor_name' => $user->first_name,
                    'actor_role' => $user->role,
                    'location_id' => $locationId,
                    'action' => 'updated',
                    'category' => 'booking',
                    'entity_type' => 'booking',
                    'entity_id' => $i + 1,
                    'description' => 'Test log ' . $i,
                ])->id;
            }

            return $ids;
        };

        $managerLogs = $write($this->manager, $this->locations[0]->id, 6);
        $attendantLogs = $write($this->attendant, $this->locations[1]->id, 9);
        ActivityLog::query()->update(['created_at' => '2026-09-01 10:00:00']);

        $attendantOnly = $this->collect('/api/activity-logs', 'activity_logs', 4, ['user_role' => 'attendant', 'sort_by' => 'created_at', 'sort_order' => 'desc']);
        sort($attendantOnly);
        $this->assertSame($attendantLogs, $attendantOnly);

        $both = $this->collect('/api/activity-logs', 'activity_logs', 4, ['user_role' => 'attendant,location_manager']);
        $this->assertCount(15, $both);
        $this->assertSame(15, count(array_unique($both)));

        $bothLocations = $this->collect('/api/activity-logs', 'activity_logs', 50, ['location_id' => $this->locations[0]->id . ',' . $this->locations[1]->id]);
        $this->assertCount(15, $bothLocations);

        $bothUsers = $this->collect('/api/activity-logs', 'activity_logs', 50, ['user_id' => [$this->manager->id, $this->attendant->id]]);
        $this->assertCount(15, $bothUsers);

        $oneUser = $this->collect('/api/activity-logs', 'activity_logs', 50, ['user_id' => $this->manager->id]);
        sort($oneUser);
        $this->assertSame($managerLogs, $oneUser);
    }

    public function test_missing_waiver_report_says_how_many_there_are_and_whether_it_was_cut(): void
    {
        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/waivers/reports/missing')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $data['total']);
        $this->assertFalse($data['truncated']);
        $this->assertSame(0, $data['count']);
    }
}
