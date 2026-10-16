<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageAvailabilitySchedule;
use App\Models\Payment;
use App\Models\Room;
use App\Models\User;
use App\Support\CacheGroups;
use App\Support\OnlineBookingGate;
use App\Support\SchemaSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BackendOnlyPackageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $brighton;

    private Location $waterford;

    private Room $brightonRoom;

    private Room $waterfordRoom;

    private Package $online;

    private Package $backendOnly;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = Company::create([
            'company_name' => 'Backend Only Co',
            'email' => 'owner@zapzone.test',
            'phone' => '5551230000',
            'address' => '1 Main St',
        ]);

        $this->brighton = $this->makeLocation('Brighton');
        $this->waterford = $this->makeLocation('Waterford');
        $this->brightonRoom = $this->makeRoom($this->brighton);
        $this->waterfordRoom = $this->makeRoom($this->waterford);

        $this->online = $this->makePackage($this->brighton, 'Birthday Bash', true);
        $this->backendOnly = $this->makePackage($this->waterford, 'Birthday Bash', false);
    }

    private function makeLocation(string $city): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'name' => "{$city} | Zap Zone",
            'address' => '1 Test Way',
            'city' => $city,
            'state' => 'MI',
            'zip_code' => '48327',
            'phone' => '2485551234',
            'email' => strtolower($city) . '@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    private function makeRoom(Location $location): Room
    {
        return Room::create([
            'location_id' => $location->id,
            'name' => "{$location->city} Room",
            'capacity' => 20,
            'is_available' => true,
            'booking_interval' => 15,
        ]);
    }

    private function makePackage(Location $location, string $name, bool $availableOnline): Package
    {
        return Package::create([
            'location_id' => $location->id,
            'name' => $name,
            'description' => 'Test package',
            'category' => 'Birthday',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 40,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
            'available_online' => $availableOnline,
        ]);
    }

    private function staff(string $role, ?Location $location = null): User
    {
        return User::create([
            'first_name' => 'Staff',
            'last_name' => ucfirst($role),
            'email' => $role . '.' . uniqid() . '@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'company_id' => $this->company->id,
            'location_id' => ($location ?? $this->waterford)->id,
        ]);
    }

    private function tokenFor(User|Customer $account, array $abilities = ['*']): string
    {
        return $account->createToken('test', $abilities)->plainTextToken;
    }

    private function outsider(): User
    {
        $otherCompany = Company::create([
            'company_name' => 'Elsewhere Co',
            'email' => 'owner.' . uniqid() . '@elsewhere.test',
            'phone' => '5559870000',
            'address' => '9 Far Rd',
        ]);
        $otherLocation = Location::create([
            'company_id' => $otherCompany->id,
            'name' => 'Lansing | Elsewhere',
            'address' => '9 Far Rd',
            'city' => 'Lansing',
            'state' => 'MI',
            'zip_code' => '48901',
            'phone' => '5179870000',
            'email' => 'lansing.' . uniqid() . '@elsewhere.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        return User::create([
            'first_name' => 'Out',
            'last_name' => 'Sider',
            'email' => 'outsider.' . uniqid() . '@elsewhere.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $otherCompany->id,
            'location_id' => $otherLocation->id,
        ]);
    }

    private function customerToken(): string
    {
        $customer = Customer::create([
            'first_name' => 'Pat',
            'last_name' => 'Guest',
            'email' => 'pat.' . uniqid() . '@example.com',
            'phone' => '7345550000',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);

        return $this->tokenFor($customer);
    }

    private function as(?string $token): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $token === null
            ? $this->withHeaders(['Accept' => 'application/json'])
            : $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    private function bookingPayload(Package $package, string $time = '18:00', ?string $email = null): array
    {
        $room = (int) $package->location_id === (int) $this->brighton->id ? $this->brightonRoom : $this->waterfordRoom;

        return [
            'guest_name' => 'Online Guest',
            'guest_email' => $email ?? 'guest.' . uniqid() . '@example.com',
            'guest_phone' => '2485559999',
            'package_id' => $package->id,
            'location_id' => $package->location_id,
            'room_id' => $room->id,
            'type' => 'package',
            'booking_date' => now()->addDays(10)->toDateString(),
            'booking_time' => $time,
            'participants' => 8,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'amount_paid' => 0,
        ];
    }

    private function assertRefusedOnline($response): void
    {
        $response->assertStatus(422)
            ->assertJsonPath('code', OnlineBookingGate::PACKAGE_CODE)
            ->assertJsonPath('message', 'This package is not available for online booking. Please call the venue to book it.');
    }

    private function assertRefusedAsRemoved($response): void
    {
        $response->assertStatus(422)
            ->assertJsonPath('code', OnlineBookingGate::REMOVED_PACKAGE_CODE)
            ->assertJsonPath('message', 'This package is no longer available. Please choose another package.');
    }

    private function packageForm(Location $location, array $overrides = []): array
    {
        return array_merge([
            'location_id' => $location->id,
            'name' => 'Glow Party ' . uniqid(),
            'description' => 'Neon party',
            'category' => 'Birthday',
            'price' => 150,
            'duration' => 90,
            'duration_unit' => 'minutes',
        ], $overrides);
    }

    public function test_a_new_package_is_available_online_unless_someone_turns_it_off(): void
    {
        $manager = $this->staff('location_manager');

        $response = $this->as($this->tokenFor($manager))
            ->postJson('/api/packages', $this->packageForm($this->waterford))
            ->assertCreated()
            ->assertJsonPath('data.available_online', true);

        $this->assertTrue((bool) Package::find($response->json('data.id'))->available_online);

        $plain = Package::create([
            'location_id' => $this->waterford->id,
            'name' => 'Created Without The Field',
            'description' => 'x',
            'category' => 'Birthday',
            'price' => 10,
            'duration' => 60,
            'duration_unit' => 'minutes',
        ]);

        $this->assertTrue((bool) $plain->fresh()->available_online);
    }

    public function test_guests_customers_and_bad_tokens_cannot_book_a_backend_only_package(): void
    {
        $before = DB::table('bookings')->count();

        $guest = $this->as(null)->postJson('/api/bookings', $this->bookingPayload($this->backendOnly));
        $this->assertRefusedOnline($guest);
        $guest->assertJsonMissingPath('previous_attempt');
        $this->assertRefusedOnline($this->as($this->customerToken())->postJson('/api/bookings', $this->bookingPayload($this->backendOnly)));
        $this->assertRefusedOnline($this->as('999|not-a-real-token')->postJson('/api/bookings', $this->bookingPayload($this->backendOnly)));

        $this->assertSame($before, DB::table('bookings')->count(), 'a refused request must not create anything');
    }

    public function test_every_staff_role_can_still_book_a_backend_only_package(): void
    {
        $times = ['attendant' => '12:00', 'location_manager' => '14:30', 'company_admin' => '17:00'];

        foreach ($times as $role => $time) {
            $response = $this->as($this->tokenFor($this->staff($role)))
                ->postJson('/api/bookings', $this->bookingPayload($this->backendOnly, $time));

            $this->assertNotSame(OnlineBookingGate::PACKAGE_CODE, $response->json('code'), $role . ': ' . $response->getContent());
            $response->assertSuccessful();
        }

        $this->assertSame(3, Booking::where('package_id', $this->backendOnly->id)->count());
    }

    public function test_a_paid_checkout_still_gets_its_confirmation_after_the_package_goes_backend_only(): void
    {
        $this->backendOnly->update(['available_online' => true]);
        $payload = $this->bookingPayload($this->backendOnly, '13:00') + ['checkout_key' => 'paid-key-123'];

        $first = $this->as(null)->postJson('/api/bookings', $payload)->assertSuccessful();
        Booking::whereKey($first->json('data.id'))->update(['status' => 'confirmed']);

        $this->backendOnly->update(['available_online' => false]);

        $this->as(null)->postJson('/api/bookings', $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'ALREADY_BOOKED');

        $this->assertRefusedOnline($this->as(null)->postJson('/api/bookings', $this->bookingPayload($this->backendOnly, '16:00') + ['checkout_key' => 'fresh-key-456']));
    }

    public function test_an_unpaid_attempt_cannot_be_resubmitted_after_the_package_goes_backend_only(): void
    {
        $this->backendOnly->update(['available_online' => true]);
        $payload = $this->bookingPayload($this->backendOnly, '13:00') + ['checkout_key' => 'pending-key-789'];

        $this->as(null)->postJson('/api/bookings', $payload)->assertSuccessful();
        $this->backendOnly->update(['available_online' => false]);

        $refused = $this->as(null)->postJson('/api/bookings', $payload);
        $this->assertRefusedOnline($refused);
        $refused->assertJsonPath('previous_attempt', true);
        $this->assertSame(1, Booking::where('package_id', $this->backendOnly->id)->count());
    }

    public function test_a_card_attempt_is_told_to_wait_and_an_abandoned_one_is_released_when_refused(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $this->backendOnly->update(['available_online' => true]);
        $payload = $this->bookingPayload($this->backendOnly, '13:00') + ['checkout_key' => 'card-key-321', 'payment_method' => 'authorize.net'];

        $attemptId = $this->as(null)->postJson('/api/bookings', $payload)->assertSuccessful()->json('data.id');
        $reference = Booking::findOrFail($attemptId)->reference_number;
        $this->backendOnly->update(['available_online' => false]);

        $this->as(null)->postJson('/api/bookings', $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'ATTEMPT_IN_PROGRESS');
        $this->assertNotNull(Booking::find($attemptId), 'an attempt that may still be charging is never released');

        $this->travel(3)->minutes();

        $refused = $this->as(null)->postJson('/api/bookings', $payload);
        $this->assertRefusedOnline($refused);
        $refused->assertJsonPath('previous_attempt', true);

        $this->assertNull(Booking::withTrashed()->find($attemptId), 'the abandoned, uncharged attempt is released as on any retry');
        $this->assertSame(
            "Unpaid checkout attempt {$reference} was released because its package can no longer be booked online",
            DB::table('activity_logs')->where('action', 'Booking Force Deleted (Abandoned Checkout)')->where('entity_id', $attemptId)->value('description')
        );
    }

    public function test_a_charged_card_attempt_still_gets_its_confirmation_after_the_package_goes_backend_only(): void
    {
        $this->backendOnly->update(['available_online' => true]);
        $payload = $this->bookingPayload($this->backendOnly, '13:00') + ['checkout_key' => 'charged-key-654', 'payment_method' => 'authorize.net'];

        $attemptId = $this->as(null)->postJson('/api/bookings', $payload)->assertSuccessful()->json('data.id');
        Payment::create([
            'location_id' => $this->waterford->id,
            'amount' => 100,
            'currency' => 'USD',
            'method' => 'authorize.net',
            'status' => 'completed',
            'transaction_id' => 'TXN' . uniqid(),
            'payable_type' => Payment::TYPE_BOOKING,
            'payable_id' => $attemptId,
            'paid_at' => now(),
        ]);
        $this->backendOnly->update(['available_online' => false]);

        $this->as(null)->postJson('/api/bookings', $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'ALREADY_BOOKED');

        $this->assertNotNull(Booking::find($attemptId));
    }

    public function test_only_the_same_checkout_learns_that_it_tried_before(): void
    {
        $this->backendOnly->update(['available_online' => true]);
        $payload = $this->bookingPayload($this->backendOnly, '13:00') + ['checkout_key' => 'edited-key-777'];

        $this->as(null)->postJson('/api/bookings', $payload)->assertSuccessful();
        $this->backendOnly->update(['available_online' => false]);

        $edited = $this->as(null)->postJson('/api/bookings', ['guest_name' => 'Edited Name'] + $payload);
        $this->assertRefusedOnline($edited);
        $edited->assertJsonPath('previous_attempt', true);

        $phoneBooking = $this->bookingPayload($this->backendOnly, '15:00', 'phone.guest@example.com');
        $this->as($this->tokenFor($this->staff('attendant')))->postJson('/api/bookings', $phoneBooking)->assertSuccessful();

        $probe = $this->as(null)->postJson('/api/bookings', $phoneBooking);
        $this->assertRefusedOnline($probe);
        $probe->assertJsonMissingPath('previous_attempt');

        Booking::where('guest_email', 'phone.guest@example.com')->update(['status' => 'confirmed']);

        $keyedProbe = $this->as(null)->postJson('/api/bookings', $phoneBooking + ['checkout_key' => 'probe-key-111']);
        $this->assertRefusedOnline($keyedProbe);
        $keyedProbe->assertJsonMissingPath('previous_attempt');
    }

    public function test_an_attempt_whose_charge_is_still_running_is_never_released(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $this->backendOnly->update(['available_online' => true]);
        $payload = $this->bookingPayload($this->backendOnly, '13:00') + ['checkout_key' => 'locked-key-888', 'payment_method' => 'authorize.net'];

        $attemptId = $this->as(null)->postJson('/api/bookings', $payload)->assertSuccessful()->json('data.id');
        $this->backendOnly->update(['available_online' => false]);
        $this->travel(3)->minutes();

        $charging = Cache::lock('payable-charge:' . Payment::TYPE_BOOKING . ':' . $attemptId, 180);
        $this->assertTrue($charging->get());

        $this->as(null)->postJson('/api/bookings', $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'ATTEMPT_IN_PROGRESS');
        $this->assertNotNull(Booking::find($attemptId), 'a charge that is still running keeps its booking');

        $charging->release();

        $this->assertRefusedOnline($this->as(null)->postJson('/api/bookings', $payload));
        $this->assertNull(Booking::withTrashed()->find($attemptId));
    }

    public function test_only_a_pending_card_attempt_from_the_same_checkout_is_released(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $this->backendOnly->update(['available_online' => true]);
        $unkeyed = $this->bookingPayload($this->backendOnly, '13:00', 'unkeyed.guest@example.com') + ['payment_method' => 'authorize.net'];
        $keyed = $this->bookingPayload($this->backendOnly, '16:00') + ['checkout_key' => 'cancelled-key-999', 'payment_method' => 'authorize.net'];

        $unkeyedId = $this->as(null)->postJson('/api/bookings', $unkeyed)->assertSuccessful()->json('data.id');
        $cancelledId = $this->as(null)->postJson('/api/bookings', $keyed)->assertSuccessful()->json('data.id');
        Booking::whereKey($cancelledId)->update(['status' => 'cancelled']);
        $this->backendOnly->update(['available_online' => false]);
        $this->travel(3)->minutes();

        $this->assertRefusedOnline($this->as(null)->postJson('/api/bookings', $unkeyed));
        $this->assertNotNull(Booking::find($unkeyedId), 'without the checkout key nothing is released');

        $this->assertRefusedOnline($this->as(null)->postJson('/api/bookings', $keyed));
        $this->assertNotNull(Booking::find($cancelledId), 'only a pending attempt is released');
    }

    public function test_a_deleted_package_cannot_be_booked_online(): void
    {
        $this->online->delete();
        $before = DB::table('bookings')->count();

        $this->assertRefusedAsRemoved($this->as(null)->postJson('/api/bookings', $this->bookingPayload($this->online)));
        $this->assertSame($before, DB::table('bookings')->count());
    }

    public function test_the_package_form_rejects_a_blank_setting_instead_of_crashing(): void
    {
        $token = $this->tokenFor($this->staff('location_manager'));

        $this->as($token)->putJson("/api/packages/{$this->backendOnly->id}", ['available_online' => ''])->assertStatus(422);
        $this->as($token)->putJson("/api/packages/{$this->backendOnly->id}", ['available_online' => null])->assertStatus(422);
        $this->as($token)->postJson('/api/packages', $this->packageForm($this->waterford, ['available_online' => '']))->assertStatus(422);
        $this->assertFalse((bool) $this->backendOnly->fresh()->available_online);
    }

    public function test_the_staff_mobile_list_labels_backend_only_packages(): void
    {
        $flags = collect($this->as($this->tokenFor($this->staff('company_admin')))->getJson('/api/mobile/packages')->assertOk()->json('data.packages'))
            ->pluck('available_online', 'id');

        $this->assertSame(false, $flags[$this->backendOnly->id] ?? null);
        $this->assertSame(true, $flags[$this->online->id] ?? null);
    }

    public function test_the_public_mobile_day_list_still_lists_backend_only_packages_flagged(): void
    {
        $date = now()->addDays(10)->toDateString();

        PackageAvailabilitySchedule::create([
            'package_id' => $this->backendOnly->id,
            'availability_type' => 'daily',
            'day_configuration' => null,
            'time_slot_start' => '10:00',
            'time_slot_end' => '20:00',
            'time_slot_interval' => 60,
            'is_active' => true,
        ]);

        $packages = collect($this->as(null)->getJson("/api/mobile/locations/{$this->waterford->id}/packages?date={$date}")->assertOk()->json('data.packages'))
            ->keyBy('id');

        $this->assertTrue($packages->has($this->backendOnly->id));
        $this->assertSame(false, $packages[$this->backendOnly->id]['available_online']);
    }

    public function test_staff_of_another_company_cannot_book_a_backend_only_or_deleted_package(): void
    {
        $outsider = $this->outsider();
        $before = DB::table('bookings')->count();

        $this->assertRefusedOnline($this->as($this->tokenFor($outsider))->postJson('/api/bookings', $this->bookingPayload($this->backendOnly)));

        $this->online->delete();
        $ownLocationPayload = ['location_id' => $outsider->location_id] + $this->bookingPayload($this->online);
        $this->assertRefusedAsRemoved($this->as($this->tokenFor($outsider))->postJson('/api/bookings', $ownLocationPayload));

        $this->assertSame($before, DB::table('bookings')->count());
    }

    public function test_another_company_cannot_use_the_switch_or_the_package_form(): void
    {
        $token = $this->tokenFor($this->outsider());

        $this->as($token)->patchJson("/api/packages/{$this->backendOnly->id}/online-availability", ['available_online' => true])->assertForbidden();
        $this->as($token)->putJson("/api/packages/{$this->backendOnly->id}", ['available_online' => true])->assertForbidden();

        $this->assertFalse((bool) $this->backendOnly->fresh()->available_online);
    }

    public function test_code_deployed_before_the_migration_keeps_every_package_online(): void
    {
        $columns = new \ReflectionProperty(SchemaSupport::class, 'columns');
        $columns->setAccessible(true);

        try {
            $columns->setValue(null, ['packages.available_online' => false]);

            $created = Package::create([
                'location_id' => $this->waterford->id,
                'name' => 'Made Before The Migration',
                'description' => 'x',
                'category' => 'Birthday',
                'price' => 10,
                'duration' => 60,
                'duration_unit' => 'minutes',
                'available_online' => false,
            ]);

            $this->assertSame(1, (int) DB::table('packages')->where('id', $created->id)->value('available_online'), 'the unsaved flag must fall back to the column default');

            $this->as($this->tokenFor($this->staff('location_manager')))
                ->patchJson("/api/packages/{$this->backendOnly->id}/online-availability", ['available_online' => true])
                ->assertStatus(503);

            $catalog = collect($this->as(null)->getJson('/api/packages/grouped-by-name')->assertOk()->json('data'))
                ->flatMap(fn ($group) => array_column($group['locations'], 'package_id'))
                ->all();
            $this->assertContains($this->backendOnly->id, $catalog, 'without the column every package stays on the storefront');

            $this->as(null)->postJson('/api/bookings', $this->bookingPayload($this->backendOnly))->assertSuccessful();
        } finally {
            SchemaSupport::flush();
        }
    }

    public function test_staff_can_move_an_existing_booking_onto_a_backend_only_package(): void
    {
        $online = $this->makePackage($this->waterford, 'Waterford Online Party', true);
        $this->as(null)->postJson('/api/bookings', $this->bookingPayload($online, '11:00'))->assertSuccessful();
        $booking = Booking::where('package_id', $online->id)->firstOrFail();

        $this->as($this->tokenFor($this->staff('attendant')))
            ->putJson("/api/bookings/{$booking->id}", [
                'package_id' => $this->backendOnly->id,
                'change_reason' => 'Guest asked for the staff-only party',
            ])
            ->assertOk();

        $this->assertSame($this->backendOnly->id, (int) $booking->fresh()->package_id);
    }

    public function test_a_manager_of_several_locations_uses_the_switch_at_the_selected_location(): void
    {
        $manager = $this->staff('location_manager', $this->brighton);
        $manager->locations()->sync([$this->waterford->id]);
        $token = $this->tokenFor($manager);
        $url = "/api/packages/{$this->backendOnly->id}/online-availability";

        $this->as($token)->patchJson($url, ['available_online' => true])
            ->assertForbidden()
            ->assertJsonPath('switch_location_id', $this->waterford->id);
        $this->assertFalse((bool) $this->backendOnly->fresh()->available_online);

        $this->as($token)->putJson('/api/staff-locations/active', ['location_id' => $this->waterford->id])->assertOk();

        $this->as($token)->patchJson($url, ['available_online' => true])->assertOk();
        $this->assertTrue((bool) $this->backendOnly->fresh()->available_online);
    }

    public function test_an_attendant_from_another_location_is_still_staff(): void
    {
        $attendant = $this->staff('attendant', $this->brighton);

        $this->as($this->tokenFor($attendant))
            ->postJson('/api/bookings', $this->bookingPayload($this->backendOnly))
            ->assertSuccessful();
    }

    public function test_guests_book_an_online_package_as_before(): void
    {
        $this->as(null)->postJson('/api/bookings', $this->bookingPayload($this->online))->assertSuccessful();
    }

    public function test_the_storefront_catalog_leaves_out_backend_only_rows_but_keeps_the_same_name_elsewhere(): void
    {
        $this->makePackage($this->waterford, 'Staff Only Escape', false);

        $groups = collect($this->as(null)->getJson('/api/packages/grouped-by-name')->assertOk()->json('data'))->keyBy('name');

        $this->assertFalse($groups->has('Staff Only Escape'));
        $this->assertTrue($groups->has('Birthday Bash'));
        $this->assertSame([$this->brighton->id], array_column($groups['Birthday Bash']['locations'], 'location_id'));
        $this->assertSame([$this->online->id], array_column($groups['Birthday Bash']['booking_links'], 'package_id'));

        CacheGroups::flush([CacheGroups::PACKAGES]);

        $staffView = collect($this->as($this->tokenFor($this->staff('company_admin')))->getJson('/api/packages/grouped-by-name')->assertOk()->json('data'))->keyBy('name');

        $this->assertFalse($staffView->has('Staff Only Escape'), 'the storefront catalog is the same for everyone');
        $this->assertSame([$this->brighton->id], array_column($staffView['Birthday Bash']['locations'], 'location_id'));
    }

    public function test_the_storefront_catalog_updates_when_the_setting_changes(): void
    {
        $catalogNames = fn () => collect($this->as(null)->getJson('/api/packages/grouped-by-name')->assertOk()->json('data'))
            ->flatMap(fn ($group) => array_column($group['locations'], 'package_id'))
            ->all();

        $this->assertNotContains($this->backendOnly->id, $catalogNames());

        $this->as($this->tokenFor($this->staff('location_manager')))
            ->patchJson("/api/packages/{$this->backendOnly->id}/online-availability", ['available_online' => true])
            ->assertOk();

        $this->assertContains($this->backendOnly->id, $catalogNames());
    }

    public function test_staff_screens_read_the_setting_from_the_package_endpoints(): void
    {
        $token = $this->tokenFor($this->staff('attendant'));

        $this->as($token)->getJson("/api/packages/{$this->backendOnly->id}")
            ->assertOk()
            ->assertJsonPath('data.available_online', false);

        $flags = collect($this->as($token)->getJson("/api/packages/location/{$this->waterford->id}")->assertOk()->json('data'))
            ->pluck('available_online', 'id');

        $this->assertSame(false, $flags[$this->backendOnly->id] ?? null);
    }

    public function test_the_public_location_package_list_still_lists_backend_only_packages_flagged(): void
    {
        $flags = collect($this->as(null)->getJson("/api/packages/location/{$this->waterford->id}")->assertOk()->json('data'))
            ->pluck('available_online', 'id');

        $this->assertSame(false, $flags[$this->backendOnly->id] ?? null);
    }

    public function test_the_public_package_endpoint_reports_the_setting(): void
    {
        $this->as(null)->getJson("/api/packages/{$this->backendOnly->id}")
            ->assertOk()
            ->assertJsonPath('data.available_online', false);

        $this->as(null)->getJson("/api/packages/{$this->online->id}")
            ->assertOk()
            ->assertJsonPath('data.available_online', true);
    }

    public function test_staff_lists_still_include_backend_only_packages(): void
    {
        $ids = collect($this->as($this->tokenFor($this->staff('attendant')))->getJson('/api/packages?per_page=50')->assertOk()->json('data.packages'))
            ->pluck('available_online', 'id');

        $this->assertSame(false, $ids[$this->backendOnly->id] ?? null);
    }

    public function test_turning_it_off_keeps_the_package_its_schedule_and_its_bookings(): void
    {
        $this->backendOnly->update(['available_online' => true]);

        $schedule = PackageAvailabilitySchedule::create([
            'package_id' => $this->backendOnly->id,
            'availability_type' => 'daily',
            'day_configuration' => null,
            'time_slot_start' => '10:00',
            'time_slot_end' => '20:00',
            'time_slot_interval' => 60,
            'is_active' => true,
        ]);

        $this->as(null)->postJson('/api/bookings', $this->bookingPayload($this->backendOnly))->assertSuccessful();
        $booking = Booking::where('package_id', $this->backendOnly->id)->firstOrFail();
        $statusBefore = $booking->status;

        $manager = $this->staff('location_manager');
        $managerToken = $this->tokenFor($manager);

        $this->as($managerToken)
            ->patchJson("/api/packages/{$this->backendOnly->id}/online-availability", ['available_online' => false])
            ->assertOk()
            ->assertJsonPath('data.available_online', false);

        $package = Package::find($this->backendOnly->id);
        $this->assertNotNull($package);
        $this->assertTrue((bool) $package->is_active);
        $this->assertFalse((bool) $package->available_online);
        $this->assertTrue((bool) $schedule->fresh()->is_active);
        $this->assertSame($statusBefore, $booking->fresh()->status);
        $this->assertNull($booking->fresh()->deleted_at);

        $this->as($managerToken)->getJson("/api/bookings/{$booking->id}")->assertOk()->assertJsonPath('data.id', $booking->id);
    }

    public function test_turning_it_back_on_lets_guests_book_again(): void
    {
        $this->assertRefusedOnline($this->as(null)->postJson('/api/bookings', $this->bookingPayload($this->backendOnly)));

        $this->as($this->tokenFor($this->staff('company_admin')))
            ->patchJson("/api/packages/{$this->backendOnly->id}/online-availability", ['available_online' => true])
            ->assertOk()
            ->assertJsonPath('data.available_online', true);

        $this->as(null)->postJson('/api/bookings', $this->bookingPayload($this->backendOnly))->assertSuccessful();
    }

    public function test_only_managers_and_admins_can_use_the_switch(): void
    {
        $url = "/api/packages/{$this->backendOnly->id}/online-availability";

        $this->as($this->tokenFor($this->staff('attendant')))->patchJson($url, ['available_online' => true])->assertForbidden();
        $this->as($this->customerToken())->patchJson($url, ['available_online' => true])->assertForbidden();
        $this->as(null)->patchJson($url, ['available_online' => true])->assertUnauthorized();
        $this->assertFalse((bool) $this->backendOnly->fresh()->available_online);

        $this->as($this->tokenFor($this->staff('location_manager', $this->brighton)))->patchJson($url, ['available_online' => true])->assertForbidden();
        $this->assertFalse((bool) $this->backendOnly->fresh()->available_online, 'a manager of another location cannot change it');

        $this->as($this->tokenFor($this->staff('location_manager')))->patchJson($url, ['available_online' => true])->assertOk();
        $this->assertTrue((bool) $this->backendOnly->fresh()->available_online);

        $this->as($this->tokenFor($this->staff('company_admin', $this->brighton)))->patchJson($url, ['available_online' => false])->assertOk();
        $this->assertFalse((bool) $this->backendOnly->fresh()->available_online);
    }

    public function test_the_switch_requires_a_clear_yes_or_no(): void
    {
        $token = $this->tokenFor($this->staff('location_manager'));
        $url = "/api/packages/{$this->backendOnly->id}/online-availability";

        $this->as($token)->patchJson($url, [])->assertStatus(422);
        $this->as($token)->patchJson($url, ['available_online' => ''])->assertStatus(422);
        $this->as($token)->patchJson($url, ['available_online' => 'maybe'])->assertStatus(422);
        $this->assertFalse((bool) $this->backendOnly->fresh()->available_online);
    }

    public function test_a_manager_signed_in_with_an_attendant_pin_cannot_change_it(): void
    {
        $manager = $this->staff('location_manager');
        $narrowed = $this->tokenFor($manager, ['role:attendant']);

        $this->as($narrowed)
            ->patchJson("/api/packages/{$this->backendOnly->id}/online-availability", ['available_online' => true])
            ->assertForbidden();

        $this->as($narrowed)
            ->putJson("/api/packages/{$this->backendOnly->id}", ['available_online' => true])
            ->assertForbidden();

        $this->assertFalse((bool) $this->backendOnly->fresh()->available_online);
    }

    public function test_an_attendant_cannot_change_it_through_the_package_form(): void
    {
        $attendantToken = $this->tokenFor($this->staff('attendant'));

        $this->as($attendantToken)
            ->putJson("/api/packages/{$this->backendOnly->id}", ['available_online' => true])
            ->assertForbidden()
            ->assertJsonPath('message', 'Only a location manager or an administrator can change whether customers can book this package online.');
        $this->assertFalse((bool) $this->backendOnly->fresh()->available_online);

        $this->as($attendantToken)
            ->putJson("/api/packages/{$this->backendOnly->id}", ['name' => 'Birthday Bash Deluxe', 'available_online' => false])
            ->assertOk()
            ->assertJsonPath('data.available_online', false);
        $this->assertSame('Birthday Bash Deluxe', $this->backendOnly->fresh()->name);

        $this->as($attendantToken)
            ->putJson("/api/packages/{$this->backendOnly->id}", ['price' => 120])
            ->assertOk();
        $this->assertFalse((bool) $this->backendOnly->fresh()->available_online, 'leaving the field out keeps it');
    }

    public function test_managers_and_admins_can_change_it_through_the_package_form(): void
    {
        $this->as($this->tokenFor($this->staff('location_manager')))
            ->putJson("/api/packages/{$this->backendOnly->id}", ['available_online' => true])
            ->assertOk()
            ->assertJsonPath('data.available_online', true);

        $this->as($this->tokenFor($this->staff('company_admin')))
            ->putJson("/api/packages/{$this->backendOnly->id}", ['available_online' => false])
            ->assertOk()
            ->assertJsonPath('data.available_online', false);

        $this->assertFalse((bool) $this->backendOnly->fresh()->available_online);
    }

    public function test_only_managers_and_admins_can_create_a_backend_only_package(): void
    {
        $before = Package::count();

        $this->as($this->tokenFor($this->staff('attendant')))
            ->postJson('/api/packages', $this->packageForm($this->waterford, ['available_online' => false]))
            ->assertForbidden();

        $this->assertSame($before, Package::count());

        $this->as($this->tokenFor($this->staff('attendant')))
            ->postJson('/api/packages', $this->packageForm($this->waterford, ['available_online' => true]))
            ->assertCreated()
            ->assertJsonPath('data.available_online', true);

        $this->as($this->tokenFor($this->staff('location_manager')))
            ->postJson('/api/packages', $this->packageForm($this->waterford, ['available_online' => false]))
            ->assertCreated()
            ->assertJsonPath('data.available_online', false);
    }

    public function test_bulk_import_keeps_backend_only_packages_for_managers_and_refuses_them_for_attendants(): void
    {
        $rows = fn () => [
            ['location_id' => $this->waterford->id, 'name' => 'Imported Online ' . uniqid(), 'description' => 'Imported', 'price' => 50, 'category' => 'Birthday', 'duration' => 60, 'duration_unit' => 'minutes'],
            ['location_id' => $this->waterford->id, 'name' => 'Imported Staff Only ' . uniqid(), 'description' => 'Imported', 'price' => 50, 'category' => 'Birthday', 'duration' => 60, 'duration_unit' => 'minutes', 'available_online' => false],
        ];

        $managerImport = $this->as($this->tokenFor($this->staff('location_manager')))
            ->postJson('/api/packages/bulk-import', ['packages' => $rows()])
            ->assertCreated();

        $imported = collect($managerImport->json('data.imported'))->pluck('available_online', 'name');
        $this->assertSame([true, false], $imported->values()->all());

        $attendantImport = $this->as($this->tokenFor($this->staff('attendant')))
            ->postJson('/api/packages/bulk-import', ['packages' => $rows()])
            ->assertStatus(207);

        $this->assertSame(1, $attendantImport->json('data.imported_count'));
        $this->assertSame(1, $attendantImport->json('data.failed_count'));
        $this->assertStringContainsString('Only a location manager or an administrator can import a backend-only package', $attendantImport->json('errors.0.error'));
    }

    public function test_an_attendant_pin_cannot_create_or_import_a_backend_only_package(): void
    {
        $narrowed = $this->tokenFor($this->staff('location_manager'), ['role:attendant']);
        $before = Package::count();

        $this->as($narrowed)
            ->postJson('/api/packages', $this->packageForm($this->waterford, ['available_online' => false]))
            ->assertForbidden();
        $this->assertSame($before, Package::count());

        $import = $this->as($narrowed)
            ->postJson('/api/packages/bulk-import', ['packages' => [
                ['location_id' => $this->waterford->id, 'name' => 'Pin Import ' . uniqid(), 'description' => 'Imported', 'price' => 50, 'category' => 'Birthday', 'duration' => 60, 'duration_unit' => 'minutes', 'available_online' => false],
            ]])
            ->assertStatus(207);
        $this->assertSame(0, $import->json('data.imported_count'));
        $this->assertSame($before, Package::count());
    }

    public function test_a_company_admin_can_import_a_backend_only_package(): void
    {
        $import = $this->as($this->tokenFor($this->staff('company_admin')))
            ->postJson('/api/packages/bulk-import', ['packages' => [
                ['location_id' => $this->waterford->id, 'name' => 'Admin Import ' . uniqid(), 'description' => 'Imported', 'price' => 50, 'category' => 'Birthday', 'duration' => 60, 'duration_unit' => 'minutes', 'available_online' => false],
            ]])
            ->assertCreated();

        $this->assertSame(false, $import->json('data.imported.0.available_online'));
    }

    public function test_creating_or_importing_a_backend_only_package_is_logged(): void
    {
        $manager = $this->staff('location_manager');

        $created = $this->as($this->tokenFor($manager))
            ->postJson('/api/packages', $this->packageForm($this->waterford, ['available_online' => false]))
            ->assertCreated();

        $this->as($this->tokenFor($manager))
            ->postJson('/api/packages', $this->packageForm($this->waterford))
            ->assertCreated();

        $imported = $this->as($this->tokenFor($manager))
            ->postJson('/api/packages/bulk-import', ['packages' => [
                ['location_id' => $this->waterford->id, 'name' => 'Logged Import ' . uniqid(), 'description' => 'Imported', 'price' => 50, 'category' => 'Birthday', 'duration' => 60, 'duration_unit' => 'minutes', 'available_online' => false],
            ]])
            ->assertCreated();

        $logged = DB::table('activity_logs')->where('action', 'Package Made Backend Only')->orderBy('id')->get();

        $this->assertSame(
            [(int) $created->json('data.id'), (int) $imported->json('data.imported.0.id')],
            $logged->pluck('entity_id')->map(fn ($id) => (int) $id)->all()
        );
        $this->assertSame([$manager->id, $manager->id], $logged->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(0, DB::table('activity_logs')->where('action', 'Package Available Online')->count(), 'a package created online is not logged as a change');
    }

    public function test_changes_to_the_setting_are_logged(): void
    {
        $manager = $this->staff('location_manager');

        $this->as($this->tokenFor($manager))
            ->patchJson("/api/packages/{$this->backendOnly->id}/online-availability", ['available_online' => true])
            ->assertOk();

        $this->as($this->tokenFor($manager))
            ->putJson("/api/packages/{$this->backendOnly->id}", ['available_online' => false])
            ->assertOk();

        $this->as($this->tokenFor($manager))
            ->patchJson("/api/packages/{$this->backendOnly->id}/online-availability", ['available_online' => false])
            ->assertOk();

        $logs = DB::table('activity_logs')
            ->where('entity_type', 'package')
            ->where('entity_id', $this->backendOnly->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame(['Package Available Online', 'Package Made Backend Only'], $logs, 'an unchanged value is not logged again');

        $rows = DB::table('activity_logs')
            ->where('entity_type', 'package')
            ->where('entity_id', $this->backendOnly->id)
            ->orderBy('id')
            ->get();

        foreach ($rows as $index => $row) {
            $this->assertSame($manager->id, (int) $row->user_id);
            $this->assertSame($index === 0, json_decode($row->metadata, true)['available_online']);
        }

        $this->assertSame([
            "Package 'Birthday Bash' can be seen and booked online again",
            "Package 'Birthday Bash' is backend only: hidden from the booking site and not bookable online",
        ], $rows->pluck('description')->all());

        $switchedOff = $this->makePackage($this->waterford, 'Retired Party', false);
        $switchedOff->update(['is_active' => false]);
        $this->as($this->tokenFor($manager))
            ->patchJson("/api/packages/{$switchedOff->id}/online-availability", ['available_online' => true])
            ->assertOk()
            ->assertJsonPath('message', 'Customers can book this package online once it is switched on.');

        $this->assertSame(
            "Package 'Retired Party' can be booked online once it is switched on",
            DB::table('activity_logs')->where('entity_type', 'package')->where('entity_id', $switchedOff->id)->value('description')
        );
    }
}
