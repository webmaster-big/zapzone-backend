<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\FeeSupport;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageTimeSlot;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingCsvImportService;
use App\Services\Checkout\BookingRepricer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class BooklyRageImportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Location $location;
    private Package $demolition;
    private Package $hulk;
    private Room $rageRoom;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gmail.enabled' => false,
            'google_calendar.auto_sync' => false,
        ]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'owner@zapzone.test',
            'phone' => '5551230000',
            'address' => '1 Main St',
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Sterling Heights',
            'address' => '1 Test Way',
            'city' => 'Sterling Heights',
            'state' => 'MI',
            'zip_code' => '48312',
            'phone' => '5865551234',
            'email' => 'sterling@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $this->demolition = $this->ragePackage('Demolition Rage', 51.99);
        $this->hulk = $this->ragePackage('Hulk Rage', 78.99);
        Package::create([
            'location_id' => $this->location->id,
            'name' => 'Controlled Chaos',
            'description' => 'Flat rage package',
            'category' => 'Rage Room',
            'price' => 240,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 4,
            'duration' => 30,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);

        $this->rageRoom = Room::create([
            'location_id' => $this->location->id,
            'name' => 'Rage Room 1',
            'capacity' => 4,
            'is_available' => true,
        ]);
        $this->demolition->rooms()->attach($this->rageRoom->id);
        $this->hulk->rooms()->attach($this->rageRoom->id);

        $this->admin = User::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    private function ragePackage(string $name, float $price): Package
    {
        return Package::create([
            'location_id' => $this->location->id,
            'name' => $name,
            'description' => 'Rage room',
            'category' => 'Rage Room',
            'price' => $price,
            'pricing_type' => 'per_person',
            'min_participants' => 1,
            'max_participants' => 2,
            'duration' => 30,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
    }

    private function venueFee(): FeeSupport
    {
        return FeeSupport::create([
            'company_id' => $this->company->id,
            'location_id' => null,
            'fee_name' => 'Venue Fee',
            'fee_amount' => 4.87,
            'fee_calculation_type' => 'percentage',
            'fee_application_type' => 'additive',
            'entity_ids' => [],
            'entity_type' => 'package',
            'applies_to_all' => true,
            'is_active' => true,
        ]);
    }

    private function rageRow(array $overrides = []): array
    {
        return array_merge([
            'ID' => '9001',
            'No.' => '9001-6001',
            'Appointment date' => now()->addDays(7)->format('n/j/Y') . ' 13:00',
            'Staff' => 'Rage Room 1',
            'Customer name' => 'Pat Example',
            'Customer phone' => '15865550100',
            'Customer email' => 'pat@example.test',
            'Service' => 'Rage - Demolition Package',
            'Duration' => '30',
            'Price' => '$51.99 ',
            'Status' => 'Approved',
            'Payment' => '$103.98 Authorize.Net Completed',
            'Notes' => 'For my daughter and her friend',
            'Created' => now()->subDays(3)->format('n/j/Y') . ' 17:33',
            'Internal note' => '',
            'Customer address' => '1 Example Ct, Macomb, Michigan, 48042, ',
            'Customer birthday' => '29-Sep-72',
            'Online meeting' => '',
            'Number of persons' => '2',
            "Guest of Honor's Name" => '',
            "Guest of Honor's Age (or General Age of Group)" => '',
        ], $overrides);
    }

    private function import(array $rows): array
    {
        return (new BookingCsvImportService())->processRows($rows, $this->location->id, $this->admin->id);
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }

        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->admin->createToken('admin')->plainTextToken, 'Accept' => 'application/json']);
    }

    private function packagelessBooking(array $extra = []): Booking
    {
        return Booking::create(array_merge([
            'reference_number' => 'BK' . strtoupper(uniqid()),
            'location_id' => $this->location->id,
            'package_id' => null,
            'room_id' => null,
            'type' => 'package',
            'guest_name' => 'Pat Example',
            'guest_email' => 'pat@example.test',
            'booking_date' => now()->addDays(7)->toDateString(),
            'booking_time' => '13:00',
            'participants' => 2,
            'duration' => 30,
            'duration_unit' => 'minutes',
            'total_amount' => 103.98,
            'amount_paid' => 103.98,
            'payment_status' => 'paid',
            'payment_method' => 'authorize.net',
            'status' => 'confirmed',
        ], $extra));
    }

    public function test_a_rage_export_reads_its_guest_count_package_and_room(): void
    {
        $result = $this->import([
            $this->rageRow(),
            $this->rageRow([
                'ID' => '9002',
                'Appointment date' => now()->addDays(8)->format('n/j/Y') . ' 19:00',
                'Customer email' => 'sam@example.test',
                'Customer name' => 'Sam Example',
                'Service' => 'Rage - Hulk Smash Package',
                'Price' => '$78.99 ',
                'Status' => 'Done',
                'Payment' => '$78.99 Authorize.Net Completed',
                'Number of persons' => '1',
            ]),
        ]);

        $this->assertSame(2, $result['imported']);
        $this->assertSame([], $result['errors']);

        [$demolition, $hulk] = $result['bookings'];

        $this->assertSame(2, (int) $demolition->participants);
        $this->assertSame($this->demolition->id, (int) $demolition->package_id);
        $this->assertSame($this->rageRoom->id, (int) $demolition->room_id);
        $this->assertSame(103.98, (float) $demolition->total_amount);
        $this->assertSame(103.98, (float) $demolition->amount_paid);
        $this->assertSame('paid', $demolition->payment_status);
        $this->assertSame('confirmed', $demolition->status);
        $this->assertSame(30, (int) $demolition->duration);

        $this->assertSame(1, (int) $hulk->participants);
        $this->assertSame($this->hulk->id, (int) $hulk->package_id);
        $this->assertSame($this->rageRoom->id, (int) $hulk->room_id);
        $this->assertSame('completed', $hulk->status);

        $slot = PackageTimeSlot::where('booking_id', $demolition->id)->first();
        $this->assertNotNull($slot);
        $this->assertSame($this->demolition->id, (int) $slot->package_id);
        $this->assertSame($this->rageRoom->id, (int) $slot->room_id);
        $this->assertSame('booked', $slot->status);
    }

    public function test_word_matches_are_reported_once_per_message_with_their_rows(): void
    {
        $result = $this->import([
            $this->rageRow(),
            $this->rageRow(['ID' => '9003', 'Customer email' => 'b@example.test', 'Appointment date' => now()->addDays(9)->format('n/j/Y') . ' 12:00']),
        ]);

        $this->assertSame([[
            'message' => '"Rage - Demolition Package" was matched to the package "Demolition Rage".',
            'rows' => [2, 3],
        ]], $result['warnings']);

        $this->assertStringContainsString(
            'Package matched by name: Rage - Demolition Package → Demolition Rage',
            Booking::find($result['bookings'][0]->id)->internal_notes
        );
    }

    public function test_header_names_match_whatever_their_case_or_spacing(): void
    {
        foreach (['NUMBER OF PERSONS', 'number_of_persons', ' Number  of persons '] as $i => $header) {
            $row = $this->rageRow([
                'ID' => (string) (9100 + $i),
                'Customer email' => "case{$i}@example.test",
                'Appointment date' => now()->addDays(10 + $i)->format('n/j/Y') . ' 13:00',
            ]);
            unset($row['Number of persons']);
            $row[$header] = '1';

            $booking = $this->import([$row])['bookings'][0];

            $this->assertSame(1, (int) $booking->participants, $header);
        }
    }

    public function test_a_missing_guest_count_is_called_out_instead_of_silently_guessed(): void
    {
        $row = $this->rageRow();
        unset($row['Number of persons']);

        $result = $this->import([$row]);
        $booking = $result['bookings'][0];

        $this->assertSame(1, (int) $booking->participants);
        $this->assertContains('The file has no number of persons. The guest count was set to 1.', array_column($result['warnings'], 'message'));
        $this->assertStringContainsString('Number of persons missing from the file; set to 1', Booking::find($booking->id)->internal_notes);
    }

    public function test_an_ambiguous_name_imports_without_a_package_and_says_so(): void
    {
        $this->ragePackage('Rage Demolition XL', 60);

        $result = $this->import([$this->rageRow(['Service' => 'Rage - Demolition'])]);
        $booking = $result['bookings'][0];

        $this->assertNull($booking->package_id);
        $this->assertSame($this->rageRoom->id, (int) $booking->room_id);
        $this->assertSame(0, PackageTimeSlot::where('booking_id', $booking->id)->count());
        $this->assertContains('No package here matches "Rage - Demolition". Imported without a package.', array_column($result['warnings'], 'message'));
    }

    public function test_a_shorter_name_is_not_picked_when_another_package_shares_as_many_words(): void
    {
        $this->ragePackage('Birthday Party', 300);
        $this->ragePackage('VIP Birthday Party', 450);

        $booking = $this->import([$this->rageRow(['Service' => 'Ultimate Birthday Party'])])['bookings'][0];

        $this->assertNull($booking->package_id);
    }

    public function test_an_empty_service_never_picks_a_random_package(): void
    {
        $booking = $this->import([$this->rageRow(['Service' => ''])])['bookings'][0];

        $this->assertNull($booking->package_id);
    }

    public function test_staff_is_only_used_as_the_space_when_the_file_has_no_party_area(): void
    {
        $booking = $this->import([$this->rageRow(['Party Area' => 'Super VIP Room Z'])])['bookings'][0];

        $this->assertNull($booking->room_id);

        $employee = $this->import([$this->rageRow([
            'ID' => '9004',
            'Customer email' => 'emp@example.test',
            'Staff' => 'Jordan',
            'Appointment date' => now()->addDays(12)->format('n/j/Y') . ' 13:00',
        ])])['bookings'][0];

        $this->assertNull($employee->room_id);
    }

    public function test_the_upload_endpoint_returns_the_warnings(): void
    {
        $header = array_keys($this->rageRow());
        $lines = [implode(',', array_map(fn ($h) => '"' . str_replace('"', '""', $h) . '"', $header))];
        $lines[] = implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', $v) . '"', array_values($this->rageRow())));
        $path = tempnam(sys_get_temp_dir(), 'bookly_');
        file_put_contents($path, implode("\n", $lines) . "\n");

        $response = $this->asAdmin()->post('/api/bookings/bulk-import-csv', [
            'file' => new UploadedFile($path, 'Sterling Rage.csv', 'text/csv', null, true),
            'location_id' => $this->location->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.warnings.0.rows', [2]);

        $booking = Booking::where('guest_email', 'pat@example.test')->firstOrFail();
        $this->assertSame(2, (int) $booking->participants);
        $this->assertSame($this->demolition->id, (int) $booking->package_id);
    }

    public function test_deleting_a_bad_import_lets_the_same_file_import_again(): void
    {
        $bad = $this->packagelessBooking([
            'participants' => 10,
            'booking_date' => now()->addDays(7)->toDateString(),
            'booking_time' => '13:00',
        ]);

        $this->assertSame(1, $this->import([$this->rageRow()])['skipped']);

        $bad->delete();

        $result = $this->import([$this->rageRow()]);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(2, (int) $result['bookings'][0]->participants);
        $this->assertSame($this->demolition->id, (int) $result['bookings'][0]->package_id);
    }

    public function test_giving_a_packageless_booking_its_package_keeps_what_the_guest_paid(): void
    {
        $this->venueFee();
        $booking = $this->packagelessBooking();

        $quote = app(BookingRepricer::class)->quote($booking, ['package_id' => $this->demolition->id]);

        $this->assertNotNull($quote);
        $this->assertSame(103.98, $quote['total_amount']);
        $this->assertSame(0.0, $quote['delta']);
        $this->assertSame('paid', $quote['payment_status']);
        $this->assertFalse($quote['pricing_consistent']);
        $this->assertSame('Venue Fee', $quote['fees'][0]['fee_name']);
    }

    public function test_a_packageless_booking_still_prices_a_guest_count_change_made_with_it(): void
    {
        $booking = $this->packagelessBooking(['participants' => 1, 'total_amount' => 51.99, 'amount_paid' => 51.99]);

        $quote = app(BookingRepricer::class)->quote($booking, ['package_id' => $this->demolition->id, 'participants' => 2]);

        $this->assertSame(51.99, $quote['delta']);
        $this->assertSame(103.98, $quote['total_amount']);
        $this->assertSame('partial', $quote['payment_status']);
    }

    public function test_a_packageless_booking_without_a_package_to_price_is_still_refused(): void
    {
        $this->assertNull(app(BookingRepricer::class)->quote($this->packagelessBooking(), ['participants' => 3]));
    }

    public function test_the_reprice_endpoint_answers_for_a_packageless_booking(): void
    {
        $booking = $this->packagelessBooking();

        $this->asAdmin()->postJson("/api/bookings/{$booking->id}/reprice", ['package_id' => $this->demolition->id])
            ->assertOk()
            ->assertJsonPath('data.total_amount', 103.98);
    }

    public function test_a_space_cannot_be_given_to_a_booking_with_no_package(): void
    {
        $booking = $this->packagelessBooking();

        $this->asAdmin()->putJson("/api/bookings/{$booking->id}", [
            'room_id' => $this->rageRoom->id,
            'change_reason' => 'Fix import',
        ])->assertStatus(422)->assertJsonPath('message', 'Choose a package for this booking before giving it a space.');

        $this->assertNull($booking->fresh()->room_id);
        $this->assertSame(0, PackageTimeSlot::where('booking_id', $booking->id)->count());
    }

    public function test_choosing_the_package_holds_the_space_the_booking_already_has(): void
    {
        $booking = $this->packagelessBooking(['room_id' => $this->rageRoom->id]);

        $quote = app(BookingRepricer::class)->quote($booking, ['package_id' => $this->demolition->id]);

        $this->asAdmin()->putJson("/api/bookings/{$booking->id}", [
            'package_id' => $this->demolition->id,
            'total_amount' => $quote['total_amount'],
            'discount_amount' => $quote['discount_amount'],
            'change_reason' => 'Fix import',
        ])->assertOk();

        $fresh = $booking->fresh();
        $this->assertSame($this->demolition->id, (int) $fresh->package_id);
        $this->assertSame(103.98, (float) $fresh->total_amount);
        $this->assertSame('paid', $fresh->payment_status);

        $slot = PackageTimeSlot::where('booking_id', $booking->id)->first();
        $this->assertNotNull($slot);
        $this->assertSame($this->demolition->id, (int) $slot->package_id);
        $this->assertSame($this->rageRoom->id, (int) $slot->room_id);
        $this->assertSame('13:00:00', (string) $slot->time_slot_start);
        $this->assertSame($fresh->booking_date->toDateString(), $slot->booked_date->toDateString());

        $this->asAdmin()->putJson("/api/bookings/{$booking->id}", [
            'room_id' => $this->rageRoom->id,
            'change_reason' => 'Same room',
        ])->assertOk();

        $this->assertSame(1, PackageTimeSlot::where('booking_id', $booking->id)->count());
    }

    public function test_choosing_the_package_records_the_hold_even_when_another_booking_shares_the_start(): void
    {
        $this->import([$this->rageRow(['ID' => '9010', 'Customer email' => 'first@example.test'])]);
        $booking = $this->packagelessBooking(['room_id' => $this->rageRoom->id, 'guest_email' => 'second@example.test']);

        $quote = app(BookingRepricer::class)->quote($booking, ['package_id' => $this->demolition->id]);

        $this->asAdmin()->putJson("/api/bookings/{$booking->id}", [
            'package_id' => $this->demolition->id,
            'total_amount' => $quote['total_amount'],
            'change_reason' => 'Fix import',
        ])->assertOk();

        $this->assertSame(1, PackageTimeSlot::where('booking_id', $booking->id)->where('status', 'booked')->count());
        $this->assertSame(2, PackageTimeSlot::where('room_id', $this->rageRoom->id)->where('time_slot_start', '13:00:00')->count());
    }

    public function test_attractions_dropped_while_choosing_a_package_come_off_the_total(): void
    {
        $attraction = \App\Models\Attraction::create([
            'location_id' => $this->location->id,
            'name' => 'Axe Throwing',
            'description' => 'Lane',
            'category' => 'Attractions',
            'price' => 25,
            'pricing_type' => 'per_unit',
            'duration' => 30,
            'max_capacity' => 10,
            'status' => 'active',
        ]);
        $booking = $this->packagelessBooking(['total_amount' => 143.98, 'amount_paid' => 0, 'payment_status' => 'pending']);
        \App\Models\BookingAttraction::create([
            'booking_id' => $booking->id,
            'attraction_id' => $attraction->id,
            'quantity' => 2,
            'price_at_booking' => 20,
        ]);

        $quote = app(BookingRepricer::class)->quote($booking, ['package_id' => $this->demolition->id, 'additional_attractions' => []]);

        $this->assertSame(-40.0, $quote['delta']);
        $this->assertSame(103.98, $quote['total_amount']);
    }

    public function test_a_package_swap_moves_the_space_hold_to_the_new_package(): void
    {
        $booking = $this->import([$this->rageRow()])['bookings'][0];

        $quote = app(BookingRepricer::class)->quote($booking, ['package_id' => $this->hulk->id]);

        $this->asAdmin()->putJson("/api/bookings/{$booking->id}", [
            'package_id' => $this->hulk->id,
            'total_amount' => $quote['total_amount'],
            'change_reason' => 'Upgrade',
        ])->assertOk();

        $this->assertSame($this->hulk->id, (int) PackageTimeSlot::where('booking_id', $booking->id)->value('package_id'));
        $this->assertSame(round(103.98 + 2 * (78.99 - 51.99), 2), (float) $booking->fresh()->total_amount);
    }
}
