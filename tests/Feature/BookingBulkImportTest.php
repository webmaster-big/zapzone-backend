<?php

namespace Tests\Feature;

use App\Models\AddOn;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Package;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingCsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BookingBulkImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;
    protected Location $location;
    protected Package $package;
    protected Room $room;
    protected AddOn $addon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $this->user = User::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => Hash::make('password'),
            'role' => 'company_admin',
            'company_id' => $this->company->id,
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'ZapZone Canton',
            'address' => '42001 Ford Rd',
            'city' => 'Canton',
            'state' => 'MI',
            'zip_code' => '48187',
            'phone' => '7345551234',
            'email' => 'canton@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $this->package = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Unlimited Activities + Arcade Party',
            'description' => 'Full party package with unlimited activities',
            'category' => 'party',
            'price' => 499.99,
            'max_participants' => 30,
            'min_participants' => 10,
            'duration' => 150,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);

        $this->room = Room::create([
            'location_id' => $this->location->id,
            'name' => 'Party Room A',
            'capacity' => 30,
            'is_available' => true,
        ]);

        $this->addon = AddOn::create([
            'location_id' => $this->location->id,
            'name' => 'Additional 10-Slice Cheese Pizza',
            'price' => 15.99,
            'is_active' => true,
        ]);

        // Attach addon to package
        $this->package->addOns()->attach($this->addon->id);
    }

    /**
     * Helper to create a temporary CSV file from rows.
     */
    protected function createCsvFile(array $header, array $rows): UploadedFile
    {
        $content = implode(',', array_map(function ($h) {
            return strpos($h, ',') !== false || strpos($h, '"') !== false
                ? '"' . str_replace('"', '""', $h) . '"'
                : $h;
        }, $header)) . "\n";

        foreach ($rows as $row) {
            $content .= implode(',', array_map(function ($val) {
                if (strpos($val, ',') !== false || strpos($val, '"') !== false) {
                    return '"' . str_replace('"', '""', $val) . '"';
                }
                return $val;
            }, $row)) . "\n";
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'csv_test_');
        file_put_contents($tempPath, $content);

        return new UploadedFile(
            $tempPath,
            'test_bookings.csv',
            'text/csv',
            null,
            true
        );
    }

    protected function getBooklyHeader(): array
    {
        return [
            'ID', 'Appointment date', 'Party Area', 'Customer name',
            'Customer phone', 'Customer email', 'Service', 'Duration',
            'Status', 'Payment', 'Notes', 'Created', 'Customer address',
            "Guest of Honor's Name", "Guest of Honor's Age (or General Age of Group)",
        ];
    }

    protected function makeRow(array $overrides = []): array
    {
        return array_merge([
            'ID' => '1628',
            'Appointment date' => '4/5/2026 13:00',
            'Party Area' => 'Party Room A',
            'Customer name' => 'Hao Wang',
            'Customer phone' => '12063698863',
            'Customer email' => 'hao@test.com',
            'Service' => 'Unlimited Activities + Arcade Party (2 × Additional 10-Slice Cheese Pizza)',
            'Duration' => '150',
            'Status' => 'Done',
            'Payment' => '$50.00 of $567.93 Authorize.Net Completed',
            'Notes' => 'Allergic to nuts',
            'Created' => '1/9/2026 20:53',
            'Customer address' => '47083 Lyndon Ave, MI, 48187, Canton, ',
            "Guest of Honor's Name" => 'Sean Wang',
            "Guest of Honor's Age (or General Age of Group)" => '10',
        ], $overrides);
    }

    // ============================================================
    // Service Unit Tests (private method testing via reflection)
    // ============================================================

    public function test_parse_service_and_addons_with_quantity(): void
    {
        $service = new BookingCsvImportService();
        $method = (new \ReflectionClass($service))->getMethod('parseServiceAndAddons');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'Unlimited Activities + Arcade Party (2 × Additional 10-Slice Cheese Pizza, Cheesy Bread)');

        $this->assertEquals('Unlimited Activities + Arcade Party', $result['package_name']);
        $this->assertCount(2, $result['addons']);
        $this->assertEquals('Additional 10-Slice Cheese Pizza', $result['addons'][0]['name']);
        $this->assertEquals(2, $result['addons'][0]['quantity']);
        $this->assertEquals('Cheesy Bread', $result['addons'][1]['name']);
        $this->assertEquals(1, $result['addons'][1]['quantity']);
    }

    public function test_parse_service_without_addons(): void
    {
        $service = new BookingCsvImportService();
        $method = (new \ReflectionClass($service))->getMethod('parseServiceAndAddons');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'Friday Night Party');

        $this->assertEquals('Friday Night Party', $result['package_name']);
        $this->assertEmpty($result['addons']);
    }

    public function test_parse_payment_authorize_net_completed(): void
    {
        $service = new BookingCsvImportService();
        $method = (new \ReflectionClass($service))->getMethod('parsePayment');
        $method->setAccessible(true);

        $result = $method->invoke($service, '$50.00 of $567.93 Authorize.Net Completed');

        $this->assertEquals(50.00, $result['amount_paid']);
        $this->assertEquals(567.93, $result['total_amount']);
        $this->assertEquals('authorize.net', $result['payment_method']);
        $this->assertEquals('completed', $result['payment_status_raw']);
    }

    public function test_parse_payment_cash_maps_to_in_store(): void
    {
        $service = new BookingCsvImportService();
        $method = (new \ReflectionClass($service))->getMethod('parsePayment');
        $method->setAccessible(true);

        $result = $method->invoke($service, '$100.00 of $200.00 Cash Completed');

        $this->assertEquals('in-store', $result['payment_method']);
    }

    public function test_parse_payment_empty(): void
    {
        $service = new BookingCsvImportService();
        $method = (new \ReflectionClass($service))->getMethod('parsePayment');
        $method->setAccessible(true);

        $result = $method->invoke($service, '');

        $this->assertEquals(0, $result['amount_paid']);
        $this->assertEquals(0, $result['total_amount']);
        $this->assertNull($result['payment_method']);
    }

    public function test_parse_address_standard_format(): void
    {
        $service = new BookingCsvImportService();
        $method = (new \ReflectionClass($service))->getMethod('parseAddress');
        $method->setAccessible(true);

        $result = $method->invoke($service, '47083 Lyndon Ave, MI, 48187, Canton, ');

        $this->assertEquals('47083 Lyndon Ave', $result['address']);
        $this->assertEquals('MI', $result['state']);
        $this->assertEquals('48187', $result['zip']);
        $this->assertEquals('Canton', $result['city']);
    }

    public function test_split_name(): void
    {
        $service = new BookingCsvImportService();
        $method = (new \ReflectionClass($service))->getMethod('splitName');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'Hao Wang');
        $this->assertEquals('Hao', $result['first_name']);
        $this->assertEquals('Wang', $result['last_name']);

        $result = $method->invoke($service, 'DEE DEE RAWLINS');
        $this->assertEquals('DEE', $result['first_name']);
        $this->assertEquals('DEE RAWLINS', $result['last_name']);
    }

    public function test_status_mapping(): void
    {
        $service = new BookingCsvImportService();
        $reflection = new \ReflectionClass($service);

        $this->assertEquals('completed', $reflection->getConstant('STATUS_MAP')['done']);
        $this->assertEquals('confirmed', $reflection->getConstant('STATUS_MAP')['approved']);
        $this->assertEquals('pending', $reflection->getConstant('STATUS_MAP')['pending']);
        $this->assertEquals('cancelled', $reflection->getConstant('STATUS_MAP')['cancelled']);
    }

    // ============================================================
    // CSV Parsing Tests
    // ============================================================

    public function test_parse_csv_with_valid_data(): void
    {
        $service = new BookingCsvImportService();
        $header = $this->getBooklyHeader();

        $file = $this->createCsvFile($header, [
            ['1628', '4/5/2026 13:00', 'Party Room A', 'Hao Wang', '12063698863', 'test@example.com', 'Unlimited Activities + Arcade Party', '150', 'Done', '$50.00 of $567.93 Authorize.Net Completed', 'Test note', '1/9/2026 20:53', '47083 Lyndon Ave MI 48187 Canton', 'Sean Wang', '10'],
        ]);

        $rows = $service->parseCsv($file->getRealPath());

        $this->assertCount(1, $rows);
        $this->assertEquals('1628', $rows[0]['ID']);
        $this->assertEquals('4/5/2026 13:00', $rows[0]['Appointment date']);
        $this->assertEquals('Hao Wang', $rows[0]['Customer name']);
    }

    public function test_parse_csv_throws_on_empty_file(): void
    {
        $service = new BookingCsvImportService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CSV file is empty or has no header row');

        $tempPath = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tempPath, '');
        $service->parseCsv($tempPath);
    }

    // ============================================================
    // Integration Tests (full import flow)
    // ============================================================

    public function test_import_creates_booking_with_matched_package(): void
    {
        $service = new BookingCsvImportService();

        $result = $service->processRows(
            [$this->makeRow()],
            $this->location->id,
            $this->user->id
        );

        $this->assertEquals(1, $result['imported']);
        $this->assertEquals(0, $result['skipped']);
        $this->assertEmpty($result['errors']);

        $booking = $result['bookings'][0];
        $this->assertEquals('2026-04-05', $booking->booking_date->format('Y-m-d'));
        $this->assertEquals('13:00', $booking->booking_time->format('H:i'));
        $this->assertEquals('completed', $booking->status);
        $this->assertEquals(567.93, (float) $booking->total_amount);
        $this->assertEquals(50.00, (float) $booking->amount_paid);
        $this->assertEquals('authorize.net', $booking->payment_method);
        $this->assertEquals('partial', $booking->payment_status);
        $this->assertEquals('Allergic to nuts', $booking->notes);
        $this->assertEquals('Sean Wang', $booking->guest_of_honor_name);
        $this->assertEquals(10, $booking->guest_of_honor_age);
        $this->assertEquals($this->package->id, $booking->package_id);
        $this->assertEquals($this->room->id, $booking->room_id);
        $this->assertStringContainsString('bookly_id:1628', $booking->internal_notes);
        $this->assertStringContainsString('Imported from Bookly CSV', $booking->internal_notes);

        // Verify customer was created
        $customer = Customer::where('email', 'hao@test.com')->first();
        $this->assertNotNull($customer);
        $this->assertEquals('Hao', $customer->first_name);
        $this->assertEquals('Wang', $customer->last_name);

        // Verify add-ons were attached
        $booking->load('addOns');
        $this->assertCount(1, $booking->addOns);
        $this->assertEquals($this->addon->id, $booking->addOns[0]->id);
        $this->assertEquals(2, $booking->addOns[0]->pivot->quantity);
    }

    public function test_import_records_unmatched_package_in_internal_notes(): void
    {
        $service = new BookingCsvImportService();

        $result = $service->processRows(
            [$this->makeRow([
                'ID' => '9999',
                'Service' => 'Nonexistent Package Name',
                'Customer email' => 'pkg@test.com',
            ])],
            $this->location->id,
            $this->user->id
        );

        $this->assertEquals(1, $result['imported']);
        $booking = $result['bookings'][0];

        $this->assertNull($booking->package_id);
        $this->assertStringContainsString('UNMATCHED PACKAGE', $booking->internal_notes);
        $this->assertStringContainsString('Nonexistent Package Name', $booking->internal_notes);
    }

    public function test_import_records_unmatched_room_in_internal_notes(): void
    {
        $service = new BookingCsvImportService();

        $result = $service->processRows(
            [$this->makeRow([
                'ID' => '8888',
                'Party Area' => 'Super VIP Room Z',
                'Customer email' => 'room@test.com',
            ])],
            $this->location->id,
            $this->user->id
        );

        $this->assertEquals(1, $result['imported']);
        $booking = $result['bookings'][0];

        $this->assertNull($booking->room_id);
        $this->assertStringContainsString('UNMATCHED ROOM', $booking->internal_notes);
        $this->assertStringContainsString('Super VIP Room Z', $booking->internal_notes);
    }

    public function test_import_records_unmatched_addon_in_internal_notes(): void
    {
        $service = new BookingCsvImportService();

        $result = $service->processRows(
            [$this->makeRow([
                'ID' => '7777',
                'Service' => 'Unlimited Activities + Arcade Party (Nonexistent Fancy Addon)',
                'Customer email' => 'addon@test.com',
            ])],
            $this->location->id,
            $this->user->id
        );

        $this->assertEquals(1, $result['imported']);
        $booking = Booking::find($result['bookings'][0]->id);

        $this->assertStringContainsString('UNMATCHED ADD-ON', $booking->internal_notes);
        $this->assertStringContainsString('Nonexistent Fancy Addon', $booking->internal_notes);
    }

    public function test_import_skips_duplicate_bookly_id(): void
    {
        $service = new BookingCsvImportService();

        $rows = [$this->makeRow(['ID' => '5555', 'Customer email' => 'dup@test.com'])];

        // First import
        $result1 = $service->processRows($rows, $this->location->id, $this->user->id);
        $this->assertEquals(1, $result1['imported']);

        // Second import with same ID
        $result2 = $service->processRows($rows, $this->location->id, $this->user->id);
        $this->assertEquals(0, $result2['imported']);
        $this->assertEquals(1, $result2['skipped']);
    }

    public function test_import_reuses_existing_customer(): void
    {
        Customer::create([
            'email' => 'existing@example.com',
            'first_name' => 'Existing',
            'last_name' => 'Customer',
            'phone' => '5559876543',
            'password' => Hash::make('password'),
        ]);

        $service = new BookingCsvImportService();

        $result = $service->processRows(
            [$this->makeRow([
                'ID' => '6666',
                'Customer name' => 'Different Name',
                'Customer email' => 'existing@example.com',
            ])],
            $this->location->id,
            $this->user->id
        );

        $booking = $result['bookings'][0];
        $this->assertEquals(
            Customer::where('email', 'existing@example.com')->first()->id,
            $booking->customer_id
        );
        $this->assertEquals(1, Customer::where('email', 'existing@example.com')->count());
    }

    public function test_import_handles_missing_date(): void
    {
        $service = new BookingCsvImportService();

        $result = $service->processRows(
            [$this->makeRow([
                'ID' => '4444',
                'Appointment date' => '',
                'Customer email' => 'nodate@test.com',
            ])],
            $this->location->id,
            $this->user->id
        );

        $this->assertEquals(0, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Invalid or missing appointment date', $result['errors'][0]['error']);
    }

    public function test_import_room_matching_strips_any_suffix(): void
    {
        $service = new BookingCsvImportService();

        $result = $service->processRows(
            [$this->makeRow([
                'ID' => '3333',
                'Party Area' => 'Party Room A (Any)',
                'Customer email' => 'any@test.com',
            ])],
            $this->location->id,
            $this->user->id
        );

        $this->assertEquals(1, $result['imported']);
        $this->assertEquals($this->room->id, $result['bookings'][0]->room_id);
    }

    // ============================================================
    // API Endpoint Tests
    // ============================================================

    public function test_endpoint_requires_authentication(): void
    {
        $file = $this->createCsvFile($this->getBooklyHeader(), []);

        $response = $this->postJson('/api/bookings/bulk-import-csv', [
            'file' => $file,
            'location_id' => $this->location->id,
        ]);

        $response->assertStatus(401);
    }

    public function test_endpoint_requires_file(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/bookings/bulk-import-csv', [
                'location_id' => $this->location->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_endpoint_requires_location_id(): void
    {
        $file = $this->createCsvFile($this->getBooklyHeader(), [
            ['1', '4/5/2026 13:00', 'Room', 'Test', '123', 'a@b.com', 'Pkg', '150', 'Done', '', '', '', '', '', ''],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/bookings/bulk-import-csv', [
                'file' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['location_id']);
    }

    public function test_endpoint_success(): void
    {
        $file = $this->createCsvFile($this->getBooklyHeader(), [
            ['1628', '4/5/2026 13:00', 'Party Room A', 'Hao Wang', '12063698863', 'hao@test.com', 'Unlimited Activities + Arcade Party', '150', 'Done', '$50.00 of $567.93 Authorize.Net Completed', 'Test', '1/9/2026 20:53', '47083 Lyndon Ave MI 48187 Canton', 'Sean Wang', '10'],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/bookings/bulk-import-csv', [
                'file' => $file,
                'location_id' => $this->location->id,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'imported' => 1,
                    'skipped' => 0,
                    'total_rows' => 1,
                ],
            ]);

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_endpoint_empty_csv_returns_422(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tempPath, implode(',', $this->getBooklyHeader()) . "\n");
        $file = new UploadedFile($tempPath, 'empty.csv', 'text/csv', null, true);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/bookings/bulk-import-csv', [
                'file' => $file,
                'location_id' => $this->location->id,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'CSV file is empty or contains no data rows.',
            ]);
    }
}
