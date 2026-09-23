<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Location;
use App\Models\Package;
use App\Models\Promo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoCreationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Location $madison;
    private Location $brighton;
    private Package $package;
    private User $admin;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $this->madison = $this->location('Madison Heights | Escape Room', 'madison@zapzone.test');
        $this->brighton = $this->location('Brighton', 'brighton@zapzone.test');

        $this->package = Package::create([
            'location_id' => $this->madison->id,
            'name' => 'Mummys Revenge',
            'description' => 'Test package',
            'category' => 'escape',
            'price' => 120,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 10,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'first_name' => 'Company',
            'last_name' => 'Admin',
            'email' => 'company.admin@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $this->company->id,
            'location_id' => $this->madison->id,
        ]);

        $this->manager = User::create([
            'first_name' => 'Madison',
            'last_name' => 'Manager',
            'email' => 'madison.manager@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'location_manager',
            'company_id' => $this->company->id,
            'location_id' => $this->madison->id,
        ]);
    }

    private function location(string $name, string $email): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'address' => '1 Test Way',
            'city' => 'Madison Heights',
            'state' => 'MI',
            'zip_code' => '48071',
            'phone' => '2485551234',
            'email' => $email,
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    private function browserPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Unlock',
            'code' => 'Unlock',
            'type' => 'fixed',
            'value' => 30,
            'start_date' => '2026-09-19',
            'end_date' => '2026-12-31',
            'usage_limit_total' => 100,
            'usage_limit_per_user' => 1,
            'description' => '',
            'status' => 'active',
            'created_by' => $this->admin->id,
            'location_ids' => [$this->madison->id],
            'package_ids' => [$this->package->id],
            'attraction_ids' => null,
            'event_ids' => null,
        ], $overrides);
    }

    public function test_the_payload_the_browser_sends_creates_a_promo(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload());

        $response->assertStatus(201);
        $this->assertDatabaseHas('promos', ['code' => 'Unlock', 'created_by' => $this->admin->id]);
    }

    public function test_a_code_freed_by_deleting_a_promo_can_be_used_again(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload())
            ->assertStatus(201);

        $promo = Promo::where('code', 'Unlock')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/promos/{$promo->id}")
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['value' => 45]))
            ->assertStatus(201);
    }

    public function test_a_duplicate_live_code_says_so_in_plain_words(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload())
            ->assertStatus(201);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload());

        $response->assertStatus(422);
        $this->assertStringContainsString('code', strtolower((string) $response->json('message')));
    }

    public function test_a_manager_cannot_aim_a_promo_at_another_location(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload([
                'code' => 'MGR1',
                'location_ids' => [$this->brighton->id],
                'package_ids' => null,
            ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('promos', ['code' => 'MGR1']);
    }

    public function test_a_manager_cannot_make_a_promo_for_every_location(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload([
                'code' => 'MGR2',
                'location_ids' => null,
                'package_ids' => null,
            ]));

        $response->assertStatus(201);
        $this->assertSame([$this->madison->id], Promo::where('code', 'MGR2')->first()->location_ids);
    }

    public function test_an_admin_can_aim_a_promo_at_several_locations(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload([
                'code' => 'BOTH',
                'location_ids' => [$this->madison->id, $this->brighton->id],
                'package_ids' => null,
            ]))
            ->assertStatus(201);

        $this->assertSame(
            [$this->madison->id, $this->brighton->id],
            Promo::where('code', 'BOTH')->first()->location_ids
        );
    }

    public function test_the_creator_comes_from_the_token_not_the_payload(): void
    {
        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload([
                'code' => 'WHOAMI',
                'created_by' => $this->admin->id,
                'package_ids' => null,
            ]))
            ->assertStatus(201);

        $this->assertSame($this->manager->id, Promo::where('code', 'WHOAMI')->first()->created_by);
    }

    public function test_a_promo_can_be_created_without_a_creator_in_the_payload(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', array_diff_key($this->browserPayload(['code' => 'NOCREATOR']), ['created_by' => null]))
            ->assertStatus(201);
    }

    public function test_a_code_can_run_for_a_single_day(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload([
                'code' => 'ONEDAY',
                'start_date' => '2026-12-31',
                'end_date' => '2026-12-31',
            ]))
            ->assertStatus(201);
    }

    public function test_an_end_date_before_the_start_is_still_refused(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload([
                'code' => 'BACKWARDS',
                'start_date' => '2026-12-31',
                'end_date' => '2026-01-01',
            ]))
            ->assertStatus(422);
    }

    public function test_a_manager_editing_a_company_wide_promo_does_not_re_pin_it(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload([
                'code' => 'COMPANYWIDE',
                'location_ids' => null,
                'package_ids' => null,
            ]))
            ->assertStatus(201);

        $promo = Promo::where('code', 'COMPANYWIDE')->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/promos/{$promo->id}", [
                'value' => 12,
                'location_ids' => [$this->madison->id],
            ])
            ->assertOk();

        $fresh = $promo->fresh();

        $this->assertNull($fresh->location_ids, 'a manager must not re-aim a company-wide promo at their own venue');
        $this->assertSame('12.00', $fresh->value);
    }

    public function test_a_manager_can_still_switch_off_a_promo_that_covers_their_venue(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload([
                'code' => 'EVERYVENUE',
                'location_ids' => null,
                'package_ids' => null,
            ]))
            ->assertStatus(201);

        $promo = Promo::where('code', 'EVERYVENUE')->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/promos/{$promo->id}/toggle-status")
            ->assertOk();

        $this->assertSame('inactive', $promo->fresh()->status);
    }

    public function test_a_manager_still_cannot_touch_another_venues_promo(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload([
                'code' => 'BRIGHTONONLY',
                'location_ids' => [$this->brighton->id],
                'package_ids' => null,
            ]))
            ->assertStatus(201);

        $promo = Promo::where('code', 'BRIGHTONONLY')->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/promos/{$promo->id}", ['value' => 99])
            ->assertStatus(422);

        $this->assertSame('30.00', $promo->fresh()->value);
    }

    public function test_editing_a_deleted_promo_does_not_rename_its_own_code(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'SELFRENAME', 'package_ids' => null]))
            ->assertStatus(201);

        $promo = Promo::where('code', 'SELFRENAME')->firstOrFail();
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/promos/{$promo->id}")->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/promos/{$promo->id}", ['code' => 'SELFRENAME', 'name' => 'Renamed'])
            ->assertOk();

        $this->assertSame('SELFRENAME', $promo->fresh()->code);
    }

    public function test_a_manager_cannot_widen_an_existing_promo_to_every_location(): void
    {
        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'MINE', 'package_ids' => null]))
            ->assertStatus(201);

        $promo = Promo::where('code', 'MINE')->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/promos/{$promo->id}", ['location_ids' => null])
            ->assertOk();

        $this->assertSame([$this->madison->id], $promo->fresh()->location_ids);

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/promos/{$promo->id}", ['location_ids' => [$this->brighton->id]])
            ->assertOk();

        $this->assertSame(
            [$this->madison->id],
            $promo->fresh()->location_ids,
            'a manager may edit their own promo but never re-aim it at another venue'
        );
    }

    public function test_a_manager_can_work_on_a_company_wide_promo_that_covers_their_venue(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload([
                'code' => 'EVERYWHERE',
                'location_ids' => null,
                'package_ids' => null,
            ]))
            ->assertStatus(201);

        $promo = Promo::where('code', 'EVERYWHERE')->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/promos/{$promo->id}", ['value' => 5])
            ->assertOk();

        $this->assertSame('5.00', $promo->fresh()->value);
        $this->assertNull($promo->fresh()->location_ids);

        $this->assertFalse((bool) $promo->fresh()->deleted);
    }

    public function test_a_bulk_batch_from_a_manager_is_pinned_to_their_location(): void
    {
        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/promos/generate-bulk', [
                'name' => 'Manager batch',
                'type' => 'fixed',
                'value' => 5,
                'start_date' => '2026-09-19',
                'end_date' => '2026-12-31',
                'quantity' => 3,
                'created_by' => $this->admin->id,
            ])
            ->assertStatus(201);

        $batch = Promo::whereNotNull('batch_id')->get();

        $this->assertCount(3, $batch);
        foreach ($batch as $promo) {
            $this->assertSame([$this->madison->id], $promo->location_ids);
            $this->assertSame($this->manager->id, $promo->created_by);
        }
    }

    public function test_a_bulk_batch_from_an_admin_can_cover_chosen_locations(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos/generate-bulk', [
                'name' => 'Admin batch',
                'type' => 'fixed',
                'value' => 5,
                'start_date' => '2026-09-19',
                'end_date' => '2026-12-31',
                'quantity' => 2,
                'location_ids' => [$this->madison->id, $this->brighton->id],
                'package_ids' => [$this->package->id],
            ])
            ->assertStatus(201);

        foreach (Promo::whereNotNull('batch_id')->get() as $promo) {
            $this->assertSame([$this->madison->id, $this->brighton->id], $promo->location_ids);
            $this->assertSame([$this->package->id], $promo->package_ids);
        }
    }

    public function test_reusing_a_deleted_code_starts_a_brand_new_promo(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'REUSE']))
            ->assertStatus(201);

        $original = Promo::where('code', 'REUSE')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/promos/{$original->id}")
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'REUSE', 'value' => 45]))
            ->assertStatus(201);

        $fresh = Promo::where('code', 'REUSE')->firstOrFail();

        $this->assertNotSame($original->id, $fresh->id);
        $this->assertSame(0, (int) $fresh->current_usage);
        $this->assertFalse((bool) $fresh->deleted);

        $retired = Promo::find($original->id);
        $this->assertTrue((bool) $retired->deleted);
        $this->assertNotSame('REUSE', $retired->code);
    }

    public function test_a_reissued_code_does_not_inherit_the_old_codes_redemptions(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'RETURNING']))
            ->assertStatus(201);

        $original = Promo::where('code', 'RETURNING')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/promos/{$original->id}")->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'RETURNING']))
            ->assertStatus(201);

        $fresh = Promo::where('code', 'RETURNING')->firstOrFail();

        $this->assertSame(
            0,
            \App\Models\Booking::where('promo_id', $fresh->id)->count(),
            'a reissued code must start with no redemption history attached to its id'
        );
    }

    public function test_a_manager_keeps_control_of_a_promo_they_made_before_the_rule_existed(): void
    {
        $legacy = Promo::create([
            'code' => 'LEGACY',
            'name' => 'Legacy',
            'type' => 'fixed',
            'value' => 10,
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
            'usage_limit_per_user' => 1,
            'status' => 'active',
            'created_by' => $this->manager->id,
            'location_ids' => null,
            'deleted' => false,
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/promos/{$legacy->id}", ['value' => 12])
            ->assertOk();

        $this->actingAs($this->manager, 'sanctum')
            ->deleteJson("/api/promos/{$legacy->id}")
            ->assertOk();
    }

    public function test_a_promo_with_no_total_limit_can_be_edited(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'NOLIMIT', 'usage_limit_total' => null]))
            ->assertStatus(201);

        $promo = Promo::where('code', 'NOLIMIT')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/promos/{$promo->id}", ['usage_limit_total' => null, 'value' => 15])
            ->assertOk();

        $this->assertSame('15.00', $promo->fresh()->value);
    }

    public function test_a_percentage_over_one_hundred_is_refused_even_when_only_the_type_changes(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'FLIP', 'type' => 'fixed', 'value' => 500]))
            ->assertStatus(201);

        $promo = Promo::where('code', 'FLIP')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/promos/{$promo->id}", ['type' => 'percentage'])
            ->assertStatus(422);

        $this->assertSame('fixed', $promo->fresh()->type);
    }

    public function test_a_pasted_code_with_stray_spaces_is_stored_clean(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => '  SPACED  ']))
            ->assertStatus(201);

        $this->assertDatabaseHas('promos', ['code' => 'SPACED']);
    }

    public function test_a_stale_creator_id_from_the_browser_does_not_break_creation(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'STALEUSER', 'created_by' => 999999]))
            ->assertStatus(201);

        $this->assertSame($this->admin->id, Promo::where('code', 'STALEUSER')->first()->created_by);
    }

    public function test_a_refused_request_does_not_rename_anyone_elses_code(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'KEEPME']))
            ->assertStatus(201);

        $promo = Promo::where('code', 'KEEPME')->firstOrFail();
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/promos/{$promo->id}")->assertOk();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload([
                'code' => 'KEEPME',
                'location_ids' => [$this->brighton->id],
                'package_ids' => null,
            ]))
            ->assertStatus(422);

        $this->assertSame('KEEPME', $promo->fresh()->code);
        $this->assertDatabaseMissing('promos', ['code' => 'KEEPME', 'deleted' => false]);
    }

    public function test_a_retired_rename_that_would_collide_picks_another_name(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'CLASH']))
            ->assertStatus(201);

        $first = Promo::where('code', 'CLASH')->firstOrFail();
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/promos/{$first->id}")->assertOk();

        Promo::create([
            'code' => 'CLASH-retired-' . $first->id,
            'name' => 'Squatter',
            'type' => 'fixed',
            'value' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
            'usage_limit_per_user' => 1,
            'status' => 'active',
            'created_by' => $this->admin->id,
            'deleted' => false,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'CLASH', 'value' => 20]))
            ->assertStatus(201);

        $this->assertSame('CLASH-retired-' . $first->id . '-1', $first->fresh()->code);
        $this->assertSame(2, Promo::where('code', 'like', 'CLASH%')->count() - 1);
    }

    public function test_creating_a_promo_is_written_to_the_activity_log(): void
    {
        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'LOGGED', 'package_ids' => null]))
            ->assertStatus(201);

        $promo = Promo::where('code', 'LOGGED')->firstOrFail();
        $entry = \App\Models\ActivityLog::where('entity_type', 'promo')
            ->where('entity_id', $promo->id)
            ->where('action', 'Promo Created')
            ->firstOrFail();

        $this->assertSame($this->manager->id, $entry->user_id);
        $this->assertSame($this->madison->id, $entry->location_id);
        $this->assertStringContainsString('LOGGED', $entry->description);

        $metadata = is_array($entry->metadata) ? $entry->metadata : json_decode((string) $entry->metadata, true);
        $this->assertSame('location_manager', $metadata['created_by']['role']);
        $this->assertSame([$this->madison->id], $metadata['targeting']['location_ids']);
    }

    public function test_editing_a_promo_logs_what_changed(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'CHANGELOG']))
            ->assertStatus(201);

        $promo = Promo::where('code', 'CHANGELOG')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/promos/{$promo->id}", ['value' => 55])
            ->assertOk();

        $entry = \App\Models\ActivityLog::where('entity_id', $promo->id)
            ->where('action', 'Promo Updated')
            ->firstOrFail();

        $metadata = is_array($entry->metadata) ? $entry->metadata : json_decode((string) $entry->metadata, true);

        $this->assertArrayHasKey('value', $metadata['changes']);
        $this->assertSame('30.00', $metadata['changes']['value']['from']);
        $this->assertSame('55.00', $metadata['changes']['value']['to']);
    }

    public function test_freeing_a_deleted_code_leaves_an_audit_trail(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'AUDIT']))
            ->assertStatus(201);

        $first = Promo::where('code', 'AUDIT')->firstOrFail();
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/promos/{$first->id}")->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'AUDIT']))
            ->assertStatus(201);

        $freed = \App\Models\ActivityLog::where('action', 'Promo Code Freed')->firstOrFail();
        $metadata = is_array($freed->metadata) ? $freed->metadata : json_decode((string) $freed->metadata, true);

        $this->assertSame($first->id, $freed->entity_id);
        $this->assertSame('AUDIT', $metadata['previous_code']);
        $this->assertSame('AUDIT-retired-' . $first->id, $metadata['new_code']);

        $created = \App\Models\ActivityLog::where('action', 'Promo Created')
            ->where('entity_id', Promo::where('code', 'AUDIT')->first()->id)
            ->firstOrFail();
        $createdMeta = is_array($created->metadata) ? $created->metadata : json_decode((string) $created->metadata, true);

        $this->assertSame($first->id, $createdMeta['reused_code_retired_from_promo_id']);
    }

    public function test_turning_a_promo_off_and_on_is_logged(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'TOGGLED']))
            ->assertStatus(201);

        $promo = Promo::where('code', 'TOGGLED')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/promos/{$promo->id}/toggle-status")
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Promo Deactivated',
            'entity_type' => 'promo',
            'entity_id' => $promo->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/promos/{$promo->id}/toggle-status")
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'Promo Activated',
            'entity_type' => 'promo',
            'entity_id' => $promo->id,
        ]);
    }

    public function test_a_taken_code_says_which_promo_holds_it_and_where(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload([
                'code' => 'TAKEN',
                'name' => 'Brighton Summer',
                'location_ids' => [$this->brighton->id],
                'package_ids' => null,
            ]))
            ->assertStatus(201);

        Promo::where('code', 'TAKEN')->update(['status' => 'inactive']);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload([
                'code' => 'TAKEN',
                'location_ids' => [$this->madison->id],
                'package_ids' => null,
            ]))
            ->assertStatus(422);

        $message = (string) $response->json('message');

        $this->assertStringContainsString('Brighton Summer', $message);
        $this->assertStringContainsString('inactive', $message);
        $this->assertStringContainsString('Brighton', $message);
    }

    public function test_a_taken_code_comes_back_with_one_that_is_free(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'UNLOCK', 'package_ids' => null]))
            ->assertStatus(201);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload([
                'code' => 'UNLOCK',
                'location_ids' => null,
                'package_ids' => null,
            ]))
            ->assertStatus(422);

        $suggestion = (string) $response->json('suggested_code');

        $this->assertSame('UNLOCK-MADISON', $suggestion);
        $this->assertDatabaseMissing('promos', ['code' => $suggestion]);
        $this->assertStringContainsString($suggestion, (string) $response->json('message'));
        $this->assertSame('Unlock', $response->json('conflict.name'));

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload([
                'code' => $suggestion,
                'location_ids' => null,
                'package_ids' => null,
            ]))
            ->assertStatus(201);
    }

    public function test_an_inactive_code_can_be_found_from_the_promo_list(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'HIDDEN', 'package_ids' => null]))
            ->assertStatus(201);

        Promo::where('code', 'HIDDEN')->update(['status' => 'inactive']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/promos')
            ->assertOk()
            ->assertJsonMissing(['code' => 'HIDDEN']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/promos?status=all')
            ->assertOk()
            ->assertJsonFragment(['code' => 'HIDDEN']);
    }

    public function test_a_deleted_code_never_shows_in_the_list(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/promos', $this->browserPayload(['code' => 'GONE', 'package_ids' => null]))
            ->assertStatus(201);

        $promo = Promo::where('code', 'GONE')->firstOrFail();
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/promos/{$promo->id}")->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/promos?status=all')
            ->assertOk()
            ->assertJsonMissing(['code' => 'GONE']);
    }

    public function test_a_manager_cannot_aim_a_bulk_batch_elsewhere(): void
    {
        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/promos/generate-bulk', [
                'name' => 'Sneaky batch',
                'type' => 'fixed',
                'value' => 5,
                'start_date' => '2026-09-19',
                'end_date' => '2026-12-31',
                'quantity' => 2,
                'location_ids' => [$this->brighton->id],
            ])
            ->assertStatus(422);

        $this->assertSame(0, Promo::whereNotNull('batch_id')->count());
    }
}
