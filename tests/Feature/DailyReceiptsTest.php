<?php

namespace Tests\Feature;

use App\Models\Attraction;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\Company;
use App\Models\EmailNotification;
use App\Models\FeeSupport;
use App\Models\Location;
use App\Models\Package;
use App\Models\Payment;
use App\Models\TicketOrder;
use App\Models\User;
use App\Services\AccountingReportService;
use App\Services\DailyReceiptsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DailyReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $brighton;

    private User $admin;

    private Package $package;

    private int $references = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false, 'accounting.staff_card_channel' => 'unclassified']);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = Company::create([
            'company_name' => 'Receipts Co',
            'email' => 'owner@receipts.test',
            'phone' => '5551230000',
            'address' => '1 Main St',
        ]);
        $this->brighton = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Brighton',
            'address' => '1 Test Way',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => 'brighton@receipts.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $this->admin = User::create([
            'first_name' => 'Receipts',
            'last_name' => 'Admin',
            'email' => 'admin@receipts.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $this->company->id,
            'location_id' => $this->brighton->id,
        ]);
        $this->package = Package::create([
            'location_id' => $this->brighton->id,
            'name' => 'Party Package',
            'description' => 'Party',
            'category' => 'Birthday',
            'price' => 200,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 20,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
    }

    private function booking(array $extra = []): Booking
    {
        $this->references++;

        return Booking::create(array_merge([
            'reference_number' => 'RCPT-' . $this->references,
            'booking_date' => '2026-12-20',
            'booking_time' => '14:00',
            'duration' => 120,
            'duration_unit' => 'minutes',
            'location_id' => $this->brighton->id,
            'package_id' => $this->package->id,
            'participants' => 4,
            'total_amount' => 200,
            'status' => 'confirmed',
        ], $extra));
    }

    private function pay(string $type, int $id, float $amount, string $method, string $paidAt, array $extra = []): Payment
    {
        return Payment::create(array_merge([
            'payable_type' => $type,
            'payable_id' => $id,
            'amount' => $amount,
            'currency' => 'USD',
            'method' => $method,
            'status' => 'completed',
            'transaction_id' => 'T' . uniqid(),
            'paid_at' => $paidAt,
            'location_id' => $this->brighton->id,
        ], $extra));
    }

    private function online(Booking $booking, float $amount, string $paidAt): Payment
    {
        return $this->pay(Payment::TYPE_BOOKING, $booking->id, $amount, 'authorize.net', $paidAt);
    }

    private function pos(Booking $booking, float $amount, string $paidAt, array $extra = []): Payment
    {
        return $this->pay(Payment::TYPE_BOOKING, $booking->id, $amount, 'card', $paidAt, ['recorded_by' => $this->admin->id] + $extra);
    }

    private function refund(Payment $original, float $amount, string $at): Payment
    {
        return Payment::create([
            'payable_type' => $original->payable_type,
            'payable_id' => $original->payable_id,
            'reverses_payment_id' => $original->id,
            'recorded_by' => $this->admin->id,
            'amount' => $amount,
            'currency' => 'USD',
            'method' => $original->method,
            'status' => 'refunded',
            'transaction_id' => 'R' . uniqid(),
            'refunded_at' => $at,
            'notes' => "Refund from Payment #{$original->id}",
            'location_id' => $original->location_id,
        ]);
    }

    private function receipts(string $start, ?string $end = null, string $groupBy = 'location'): array
    {
        return app(DailyReceiptsService::class)->daily([$this->brighton->id], $start, $end ?? $start, $groupBy);
    }

    private function day(array $receipts, string $date): array
    {
        foreach ($receipts['days'] as $day) {
            if ($day['date'] === $date) {
                return $day['totals'];
            }
        }

        return ['online' => 0.0, 'in_store' => 0.0, 'unclassified' => 0.0, 'total' => 0.0];
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->admin->createToken('receipts')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }

    public function test_online_and_pos_payments_on_the_same_day_land_in_their_own_columns(): void
    {
        $booking = $this->booking();
        $this->online($booking, 50, '2026-12-01 14:00:00');
        $this->pos($booking, 150, '2026-12-01 15:00:00');

        $totals = $this->receipts('2026-12-01')['totals'];

        $this->assertSame(50.0, $totals['online']);
        $this->assertSame(150.0, $totals['in_store']);
        $this->assertSame(0.0, $totals['unclassified']);
        $this->assertSame(200.0, $totals['total']);
    }

    public function test_a_pay_in_store_deposit_counts_once_even_if_its_receipt_is_entered_twice(): void
    {
        $booking = $this->booking();
        $payload = [
            'payable_type' => Payment::TYPE_BOOKING,
            'payable_id' => $booking->id,
            'location_id' => $this->brighton->id,
            'amount' => 50,
            'method' => 'in-store',
            'status' => 'completed',
            'external_reference' => 'POS-1001',
        ];

        $this->asAdmin()->postJson('/api/payments', $payload)->assertSuccessful();
        $this->travel(5)->minutes();
        $this->asAdmin()->postJson('/api/payments', $payload)->assertStatus(409)->assertJsonPath('error_code', 'DUPLICATE_RECEIPT');

        $first = Payment::where('external_reference', 'POS-1001')->firstOrFail();
        $this->assertSame(Payment::CHANNEL_IN_STORE, $first->channel);
        $this->assertSame($this->admin->id, (int) $first->recorded_by);

        Payment::create($first->only(['payable_type', 'payable_id', 'amount', 'currency', 'method', 'status', 'location_id', 'external_reference', 'recorded_by']) + [
            'transaction_id' => 'DUP-' . uniqid(),
            'paid_at' => $first->paid_at,
        ]);

        $totals = $this->receipts($first->paid_at->copy()->setTimezone('America/Detroit')->toDateString())['totals'];
        $this->assertSame(0.0, $totals['online']);
        $this->assertSame(50.0, $totals['in_store']);
    }

    public function test_each_payment_posts_on_its_own_business_day(): void
    {
        $booking = $this->booking();
        $this->online($booking, 50, '2026-12-01 14:00:00');
        $this->pos($booking, 150, '2026-12-02 15:00:00');

        $receipts = $this->receipts('2026-12-01', '2026-12-02');

        $this->assertSame(['online' => 50.0, 'in_store' => 0.0], array_intersect_key($this->day($receipts, '2026-12-01'), ['online' => 0, 'in_store' => 0]));
        $this->assertSame(['online' => 0.0, 'in_store' => 150.0], array_intersect_key($this->day($receipts, '2026-12-02'), ['online' => 0, 'in_store' => 0]));
    }

    public function test_a_refund_posts_on_the_day_it_is_made(): void
    {
        $booking = $this->booking();
        $charge = $this->online($booking, 200, '2026-12-01 14:00:00');
        $refund = $this->refund($charge, 50, '2026-12-02 10:00:00');

        $receipts = $this->receipts('2026-12-01', '2026-12-02');

        $this->assertSame(Payment::CHANNEL_ONLINE, $refund->channel, 'the refund takes the channel of the payment it reverses');
        $this->assertSame(200.0, $this->day($receipts, '2026-12-01')['online']);
        $this->assertSame(-50.0, $this->day($receipts, '2026-12-02')['online']);
        $this->assertSame(0.0, $this->day($receipts, '2026-12-01')['in_store']);
        $this->assertSame(0.0, $this->day($receipts, '2026-12-02')['in_store']);
    }

    public function test_a_single_cart_with_online_and_pos_tender_splits_by_channel_and_by_item(): void
    {
        $laserTag = Attraction::create(['location_id' => $this->brighton->id, 'name' => 'Laser Tag', 'description' => 'Laser tag', 'category' => 'Attractions', 'price' => 30, 'duration' => 30, 'max_capacity' => 20, 'status' => 'active']);
        $arcade = Attraction::create(['location_id' => $this->brighton->id, 'name' => 'Arcade Card', 'description' => 'Arcade', 'category' => 'Attractions', 'price' => 20, 'duration' => 30, 'max_capacity' => 20, 'status' => 'active']);
        $order = TicketOrder::create([
            'reference_number' => 'TO-CART-1',
            'company_id' => $this->company->id,
            'location_id' => $this->brighton->id,
            'guest_name' => 'Cart Shopper',
            'guest_email' => 'cart@example.com',
            'purchase_date' => '2026-12-01',
            'item_count' => 2,
            'ticket_count' => 2,
            'subtotal' => 200,
            'total_amount' => 200,
            'amount_paid' => 200,
            'payment_method' => 'authorize.net',
            'status' => 'confirmed',
        ]);
        foreach ([[$laserTag, 120], [$arcade, 80]] as [$attraction, $total]) {
            AttractionPurchase::create([
                'attraction_id' => $attraction->id,
                'ticket_order_id' => $order->id,
                'guest_name' => 'Cart Shopper',
                'guest_email' => 'cart@example.com',
                'quantity' => 1,
                'total_amount' => $total,
                'amount_paid' => $total,
                'purchase_date' => '2026-12-01',
                'payment_status' => 'paid',
            ]);
        }
        $this->pay(Payment::TYPE_TICKET_ORDER, $order->id, 50, 'authorize.net', '2026-12-01 12:00:00');
        $this->pay(Payment::TYPE_TICKET_ORDER, $order->id, 150, 'cash', '2026-12-01 12:05:00', ['recorded_by' => $this->admin->id]);

        $this->assertSame(['online' => 50.0, 'in_store' => 150.0], array_intersect_key($this->receipts('2026-12-01')['totals'], ['online' => 0, 'in_store' => 0]));

        $rows = collect($this->receipts('2026-12-01', null, 'item')['days'][0]['rows'])->keyBy('label');
        $this->assertSame([20.0, 60.0], [$rows['Arcade Card']['online'], $rows['Arcade Card']['in_store']]);
        $this->assertSame([30.0, 90.0], [$rows['Laser Tag']['online'], $rows['Laser Tag']['in_store']]);
    }

    public function test_a_void_reverses_the_capture_on_the_day_it_is_made(): void
    {
        $booking = $this->booking();
        $sameDay = $this->online($booking, 100, '2026-12-01 10:00:00');
        $sameDay->update(['status' => 'voided', 'refunded_at' => '2026-12-01 11:00:00']);
        Payment::create(['payable_type' => Payment::TYPE_BOOKING, 'payable_id' => $booking->id, 'reverses_payment_id' => $sameDay->id, 'amount' => 100, 'currency' => 'USD', 'method' => 'authorize.net', 'status' => 'voided', 'transaction_id' => 'VOID-' . $sameDay->transaction_id, 'refunded_at' => '2026-12-01 11:00:00', 'location_id' => $this->brighton->id]);

        $nextDay = $this->online($booking, 40, '2026-12-01 20:00:00');
        $nextDay->update(['status' => 'voided', 'refunded_at' => '2026-12-02 09:00:00']);
        Payment::create(['payable_type' => Payment::TYPE_BOOKING, 'payable_id' => $booking->id, 'reverses_payment_id' => $nextDay->id, 'amount' => 40, 'currency' => 'USD', 'method' => 'authorize.net', 'status' => 'voided', 'transaction_id' => 'VOID-' . $nextDay->transaction_id, 'refunded_at' => '2026-12-02 09:00:00', 'location_id' => $this->brighton->id]);

        $receipts = $this->receipts('2026-12-01', '2026-12-02');

        $this->assertSame(40.0, $this->day($receipts, '2026-12-01')['online']);
        $this->assertSame(-40.0, $this->day($receipts, '2026-12-02')['online']);
        $this->assertSame(0.0, $receipts['totals']['online']);
    }

    public function test_cards_taken_by_staff_wait_in_unclassified_until_their_channel_is_confirmed(): void
    {
        $booking = $this->booking();
        $this->pay(Payment::TYPE_BOOKING, $booking->id, 75, 'authorize.net', '2026-12-01 13:00:00', ['recorded_by' => $this->admin->id]);

        $unconfirmed = $this->receipts('2026-12-01');
        $this->assertSame(75.0, $unconfirmed['totals']['unclassified']);
        $this->assertTrue($unconfirmed['has_unclassified']);

        config(['accounting.staff_card_channel' => 'online']);
        $confirmed = $this->receipts('2026-12-01');
        $this->assertSame(75.0, $confirmed['totals']['online']);
        $this->assertFalse($confirmed['has_unclassified']);
    }

    public function test_every_authorize_net_payment_counts_as_online_by_default(): void
    {
        $this->assertSame('online', (require base_path('config/accounting.php'))['staff_card_channel']);
        config(['accounting.staff_card_channel' => 'online']);
        $now = Carbon::now('America/Detroit');
        $at = $now->copy()->setTimezone(config('app.timezone'))->toDateTimeString();
        $booking = $this->booking();
        $this->pay(Payment::TYPE_BOOKING, $booking->id, 25, 'authorize.net', $at, ['recorded_by' => $this->admin->id]);
        $older = $this->pay(Payment::TYPE_BOOKING, $booking->id, 40, 'authorize.net', $at);
        DB::table('payments')->where('id', $older->id)->update(['channel' => Payment::CHANNEL_UNVERIFIED]);
        $savedMidDeploy = $this->pay(Payment::TYPE_BOOKING, $booking->id, 12, 'cash', $at);
        DB::table('payments')->where('id', $savedMidDeploy->id)->update(['channel' => null]);

        $day = $this->day($this->receipts($now->toDateString()), $now->toDateString());

        $this->assertEquals(65.0, $day['online'], 'staff-typed cards and older card payments go through Authorize.Net like website payments');
        $this->assertEquals(12.0, $day['in_store']);
        $this->assertEquals(0.0, $day['unclassified']);

        config(['accounting.staff_card_channel' => 'unclassified']);
        $held = $this->day($this->receipts($now->toDateString()), $now->toDateString());
        $this->assertEquals(0.0, $held['online']);
        $this->assertEquals(65.0, $held['unclassified'], 'switched back, both wait for confirmation again');
    }

    public function test_an_evening_payment_counts_on_the_michigan_business_day(): void
    {
        config(['app.timezone' => 'UTC']);
        $booking = $this->booking();
        $payment = $this->online($booking, 30, '2026-12-02 04:30:00');
        DB::table('payments')->where('id', $payment->id)->update(['paid_at' => '2026-12-02 04:30:00', 'created_at' => '2026-12-02 04:30:00']);

        $this->assertSame(30.0, $this->receipts('2026-12-01')['totals']['online'], '11:30 PM in Michigan on Dec 1');
        $this->assertSame(0.0, $this->receipts('2026-12-02')['totals']['online']);
    }

    public function test_older_payments_are_classified_once_by_the_backfill(): void
    {
        $staffBooking = $this->booking(['created_by' => $this->admin->id]);
        $guestBooking = $this->booking();
        $lateBooking = $this->booking();
        DB::table('bookings')->where('id', $guestBooking->id)->update(['created_at' => '2026-11-01 10:00:00']);
        DB::table('bookings')->where('id', $lateBooking->id)->update(['created_at' => '2026-11-01 10:00:00']);
        $insert = fn (array $row) => DB::table('payments')->insertGetId($row + ['currency' => 'USD', 'status' => 'completed', 'location_id' => $this->brighton->id, 'created_at' => '2026-11-01 10:05:00', 'updated_at' => '2026-11-01 10:05:00']);

        $cash = $insert(['payable_type' => 'booking', 'payable_id' => $guestBooking->id, 'amount' => 10, 'method' => 'cash', 'transaction_id' => 'L1']);
        $staffCard = $insert(['payable_type' => 'booking', 'payable_id' => $staffBooking->id, 'amount' => 20, 'method' => 'authorize.net', 'transaction_id' => 'L2']);
        $checkout = $insert(['payable_type' => 'booking', 'payable_id' => $guestBooking->id, 'amount' => 30, 'method' => 'authorize.net', 'transaction_id' => 'L3']);
        $later = $insert(['payable_type' => 'booking', 'payable_id' => $lateBooking->id, 'amount' => 40, 'method' => 'authorize.net', 'transaction_id' => 'L4', 'created_at' => '2026-11-04 12:00:00']);
        $refund = $insert(['payable_type' => 'booking', 'payable_id' => $guestBooking->id, 'amount' => 5, 'method' => 'authorize.net', 'transaction_id' => 'L5', 'status' => 'refunded', 'notes' => "Refund from Payment #{$checkout} "]);
        DB::table('payments')->whereIn('id', [$cash, $staffCard, $checkout, $later, $refund])->update(['channel' => null]);

        $migration = require database_path('migrations/2026_11_27_000001_add_receipt_channel_to_payments_table.php');
        $migration->up();
        $migration->up();

        $channels = DB::table('payments')->whereIn('id', [$cash, $staffCard, $checkout, $later, $refund])->pluck('channel', 'id');
        $this->assertSame('in_store', $channels[$cash]);
        $this->assertSame('staff_card', $channels[$staffCard]);
        $this->assertSame('online', $channels[$checkout], 'paid within minutes of a guest booking');
        $this->assertSame('unverified', $channels[$later], 'paid days later, by an unknown hand');
        $this->assertSame('online', $channels[$refund]);
        $this->assertSame($checkout, (int) DB::table('payments')->where('id', $refund)->value('reverses_payment_id'));
    }

    public function test_a_payment_saved_without_its_channel_during_the_deploy_is_sorted_by_the_hourly_check(): void
    {
        $guestBooking = $this->booking();
        $at = now()->toDateTimeString();
        $row = fn (array $extra) => $extra + ['payable_type' => 'booking', 'payable_id' => $guestBooking->id, 'currency' => 'USD', 'status' => 'completed', 'location_id' => $this->brighton->id, 'created_at' => $at, 'updated_at' => $at];
        $online = DB::table('payments')->insertGetId($row(['amount' => 30, 'method' => 'authorize.net', 'transaction_id' => 'OLD-RELEASE-1']));
        $cash = DB::table('payments')->insertGetId($row(['amount' => 12, 'method' => 'cash', 'transaction_id' => 'OLD-RELEASE-2']));
        $this->assertNull(DB::table('payments')->where('id', $online)->value('channel'));

        $this->artisan('payments:classify-channels')->assertSuccessful();

        $this->assertSame('online', DB::table('payments')->where('id', $online)->value('channel'));
        $this->assertSame('in_store', DB::table('payments')->where('id', $cash)->value('channel'));
        $this->artisan('schedule:list')->expectsOutputToContain('payments:classify-channels');
    }

    public function test_older_attraction_card_sales_are_not_guessed_online_before_staff_were_recorded(): void
    {
        $attraction = Attraction::create(['location_id' => $this->brighton->id, 'name' => 'Laser Tag', 'description' => 'Laser tag', 'category' => 'Attractions', 'price' => 30, 'duration' => 30, 'max_capacity' => 20, 'status' => 'active']);
        $purchase = fn (string $at) => tap(AttractionPurchase::create(['attraction_id' => $attraction->id, 'guest_name' => 'Counter', 'guest_email' => 'counter@example.com', 'quantity' => 1, 'total_amount' => 30, 'amount_paid' => 30, 'purchase_date' => substr($at, 0, 10), 'payment_status' => 'paid']), fn ($row) => DB::table('attraction_purchases')->where('id', $row->id)->update(['created_at' => $at, 'created_by' => null]));
        $september = $purchase('2026-09-01 15:00:00');
        $november = $purchase('2026-11-01 15:00:00');
        $insert = fn (int $payableId, string $at) => DB::table('payments')->insertGetId(['payable_type' => 'attraction_purchase', 'payable_id' => $payableId, 'amount' => 30, 'method' => 'authorize.net', 'transaction_id' => 'AT' . $payableId, 'currency' => 'USD', 'status' => 'completed', 'location_id' => $this->brighton->id, 'created_at' => $at, 'updated_at' => $at]);
        $old = $insert($september->id, '2026-09-01 15:01:00');
        $new = $insert($november->id, '2026-11-01 15:01:00');
        DB::table('payments')->whereIn('id', [$old, $new])->update(['channel' => null]);

        (require database_path('migrations/2026_11_27_000001_add_receipt_channel_to_payments_table.php'))->up();

        $this->assertSame('unverified', DB::table('payments')->where('id', $old)->value('channel'), 'staff were not recorded on attraction sales yet');
        $this->assertSame('online', DB::table('payments')->where('id', $new)->value('channel'));
    }

    public function test_a_fee_saved_without_its_setup_link_still_follows_its_charge_type_by_name(): void
    {
        FeeSupport::create(['company_id' => $this->company->id, 'location_id' => $this->brighton->id, 'fee_name' => 'Michigan 6%', 'charge_type' => 'tax', 'fee_amount' => 6, 'fee_calculation_type' => 'percentage', 'fee_application_type' => 'additive', 'entity_type' => 'package', 'entity_ids' => [], 'applies_to_all' => true, 'is_active' => true]);
        FeeSupport::create(['company_id' => $this->company->id, 'location_id' => $this->brighton->id, 'fee_name' => 'Tax Recovery Fee', 'charge_type' => 'fee', 'fee_amount' => 3, 'fee_calculation_type' => 'fixed', 'fee_application_type' => 'additive', 'entity_type' => 'package', 'entity_ids' => [], 'applies_to_all' => true, 'is_active' => true]);

        $this->booking(['total_amount' => 215, 'applied_fees' => [
            ['fee_name' => 'Michigan 6%', 'fee_amount' => 12, 'fee_application_type' => 'additive'],
            ['fee_name' => 'Tax Recovery Fee', 'fee_amount' => 3, 'fee_application_type' => 'additive'],
        ]]);

        $today = Carbon::now('America/Detroit')->startOfDay();
        $summary = app(AccountingReportService::class)->buildReportData([$this->brighton->id], $today, $today, 'booked_on')['summary'];

        $this->assertEquals(12.0, $summary['tax_amount']);
        $this->assertEquals(3.0, $summary['fee_amount']);
    }

    public function test_two_different_receipts_of_the_same_amount_are_both_kept(): void
    {
        $booking = $this->booking(['total_amount' => 200]);
        $body = fn (string $receipt) => ['payable_type' => 'booking', 'payable_id' => $booking->id, 'location_id' => $this->brighton->id, 'amount' => 100, 'currency' => 'USD', 'method' => 'card', 'status' => 'completed', 'external_reference' => $receipt];

        $this->asAdmin()->postJson('/api/payments', $body('R-1001'))->assertSuccessful();
        $this->asAdmin()->postJson('/api/payments', $body('R-1002'))->assertSuccessful();
        $this->asAdmin()->postJson('/api/payments', $body('R-1002'))->assertSuccessful();
        $this->assertSame(['R-1001', 'R-1002'], Payment::where('payable_id', $booking->id)->orderBy('id')->pluck('external_reference')->all(), 'a double click saves nothing new');

        Payment::where('payable_id', $booking->id)->update(['created_at' => now()->subMinutes(10)]);
        $this->asAdmin()->postJson('/api/payments', $body('R-1002'))->assertStatus(409)->assertJsonPath('error_code', 'DUPLICATE_RECEIPT');
        $this->assertSame(2, Payment::where('payable_id', $booking->id)->count());
    }

    public function test_refunding_a_duplicate_receipt_does_not_cancel_the_real_one(): void
    {
        $now = Carbon::now('America/Detroit');
        $at = $now->copy()->setTimezone(config('app.timezone'))->toDateTimeString();
        $booking = $this->booking();
        $this->pos($booking, 150, $at, ['external_reference' => 'POS-77']);
        $duplicate = $this->pos($booking, 150, $at, ['external_reference' => 'POS-77']);
        $this->refund($duplicate, 150, $at);

        $day = $this->day($this->receipts($now->toDateString()), $now->toDateString());

        $this->assertEquals(150.0, $day['in_store']);
    }

    public function test_a_payment_with_nothing_attached_still_counts_in_both_views(): void
    {
        $now = Carbon::now('America/Detroit');
        $at = $now->copy()->setTimezone(config('app.timezone'))->toDateTimeString();
        DB::table('payments')->insert(['payable_type' => null, 'payable_id' => null, 'amount' => 50, 'currency' => 'USD', 'method' => 'card', 'status' => 'completed', 'channel' => 'in_store', 'location_id' => $this->brighton->id, 'transaction_id' => 'LOOSE-1', 'paid_at' => $at, 'created_at' => $at, 'updated_at' => $at]);

        $byLocation = $this->day($this->receipts($now->toDateString()), $now->toDateString());
        $byItem = app(DailyReceiptsService::class)->daily([$this->brighton->id], $now->toDateString(), $now->toDateString(), 'item');

        $this->assertEquals(50.0, $byLocation['in_store']);
        $this->assertEquals(50.0, collect($byItem['days'])->firstWhere('date', $now->toDateString())['totals']['in_store'] ?? null);
    }

    public function test_a_cancelled_booking_keeps_its_payment_and_its_refund_on_their_own_days(): void
    {
        $booking = $this->booking();
        $paid = $this->online($booking, 120, '2026-12-01 15:00:00');
        $booking->update(['status' => 'cancelled']);
        $this->refund($paid, 120, '2026-12-02 15:00:00');

        $receipts = $this->receipts('2026-12-01', '2026-12-02');

        $this->assertEquals(120.0, $this->day($receipts, '2026-12-01')['online']);
        $this->assertEquals(-120.0, $this->day($receipts, '2026-12-02')['online']);
        $this->assertEquals(0.0, $receipts['totals']['total']);
    }

    public function test_a_discounted_booking_counts_only_the_money_actually_taken(): void
    {
        $booking = $this->booking(['total_amount' => 160, 'discount_amount' => 40]);
        $this->pos($booking, 160, '2026-12-03 13:00:00');

        $day = $this->day($this->receipts('2026-12-03'), '2026-12-03');

        $this->assertEquals(160.0, $day['in_store']);
        $this->assertEquals(160.0, $day['total']);
    }

    public function test_gift_card_money_counts_when_the_card_is_sold_and_not_again_when_it_is_used(): void
    {
        $this->pay(Payment::TYPE_GIFT_CARD, 991, 50, 'authorize.net', '2026-12-04 12:00:00');
        $booking = $this->booking(['total_amount' => 150, 'discount_amount' => 50]);
        $this->pos($booking, 150, '2026-12-05 12:00:00');

        $receipts = $this->receipts('2026-12-04', '2026-12-05');

        $this->assertEquals(50.0, $this->day($receipts, '2026-12-04')['online']);
        $this->assertEquals(0.0, $this->day($receipts, '2026-12-05')['online']);
        $this->assertEquals(150.0, $this->day($receipts, '2026-12-05')['in_store']);
        $this->assertEquals(200.0, $receipts['totals']['total']);
    }

    public function test_the_csv_export_and_the_end_of_day_email_show_the_same_day(): void
    {
        $today = Carbon::now('America/Detroit')->startOfDay();
        $at = $today->copy()->setTime(12, 0)->setTimezone(config('app.timezone'))->toDateTimeString();
        $booking = $this->booking();
        $online = $this->online($booking, 80, $at);
        $this->pos($booking, 45.5, $at);
        $this->pay(Payment::TYPE_BOOKING, $booking->id, 20, 'authorize.net', $at, ['recorded_by' => $this->admin->id]);
        $this->refund($online, 10, $at);

        $csv = $this->asAdmin()
            ->get('/api/accounting-analytics/export?format=csv&start_date=' . $today->toDateString() . '&location_id=' . $this->brighton->id)
            ->assertOk()
            ->streamedContent();
        $dayTotal = collect(explode("\n", trim($csv)))->map(fn ($line) => str_getcsv($line))->first(fn ($row) => ($row[1] ?? null) === 'DAY TOTAL');
        $email = app(AccountingReportService::class)->buildDailyReportVariables($today, 'Receipts Co');

        $this->assertSame('$70.00', $email['online_revenue']);
        $this->assertSame(
            [$today->toDateString(), 'DAY TOTAL', $email['online_revenue'], $email['in_store_collected'], $email['unclassified_receipts'], $email['total_collected']],
            $dayTotal
        );
    }

    public function test_a_cash_payment_right_after_a_card_payment_of_the_same_amount_is_kept(): void
    {
        $body = fn (Booking $booking, string $method, array $extra = []) => ['payable_type' => 'booking', 'payable_id' => $booking->id, 'location_id' => $this->brighton->id, 'amount' => 50, 'currency' => 'USD', 'method' => $method, 'status' => 'completed'] + $extra;

        $withReceipt = $this->booking(['total_amount' => 100]);
        $this->asAdmin()->postJson('/api/payments', $body($withReceipt, 'card', ['external_reference' => '4411']))->assertSuccessful();
        $this->asAdmin()->postJson('/api/payments', $body($withReceipt, 'card'))->assertSuccessful();
        $this->assertSame(2, Payment::where('payable_id', $withReceipt->id)->count(), 'a second card payment without a receipt is not taken for the first one');

        $noReceipts = $this->booking(['total_amount' => 100]);
        $this->asAdmin()->postJson('/api/payments', $body($noReceipts, 'card'))->assertSuccessful();
        $this->asAdmin()->postJson('/api/payments', $body($noReceipts, 'cash'))->assertSuccessful();
        $this->asAdmin()->postJson('/api/payments', $body($noReceipts, 'cash'))->assertSuccessful();

        $this->assertSame(['card', 'cash'], Payment::where('payable_id', $noReceipts->id)->orderBy('id')->pluck('method')->all(), 'the split is kept and a double click on cash still saves once');
    }

    public function test_the_end_of_day_email_uses_the_same_receipts(): void
    {
        $today = Carbon::now('America/Detroit')->startOfDay();
        $booking = $this->booking();
        $at = $today->copy()->setTime(12, 0)->setTimezone(config('app.timezone'))->toDateTimeString();
        $this->online($booking, 50, $at);
        $this->pos($booking, 150, $at);

        $variables = app(AccountingReportService::class)->buildDailyReportVariables($today, 'Receipts Co');

        $this->assertSame('$50.00', $variables['online_revenue']);
        $this->assertSame('$150.00', $variables['in_store_collected']);
        $this->assertSame('$200.00', $variables['total_collected']);
        $this->assertSame('$50.00', $variables['collected_card']);
        $this->assertSame('$150.00', $variables['collected_cash']);
        $this->assertSame('', $variables['unclassified_receipts_row']);
        $this->assertStringContainsString('Brighton', $variables['receipts_location_table']);
        $this->assertStringNotContainsString('Unclassified', $variables['receipts_location_table']);

        $this->pay(Payment::TYPE_BOOKING, $booking->id, 25, 'authorize.net', $at, ['recorded_by' => $this->admin->id]);
        $withStaffCard = app(AccountingReportService::class)->buildDailyReportVariables($today, 'Receipts Co');
        $this->assertSame('$75.00', $withStaffCard['collected_card'], 'an older edited template still adds up: card + cash = total');
        $this->assertSame('$225.00', $withStaffCard['total_collected']);
        $this->assertStringContainsString('Unclassified', $withStaffCard['receipts_location_table']);
        $this->assertStringContainsString('$25.00', $withStaffCard['unclassified_receipts_row']);
    }

    public function test_the_dashboard_shows_the_same_receipts(): void
    {
        $now = Carbon::now('America/Detroit');
        $booking = $this->booking();
        $this->online($booking, 50, $now->copy()->setTimezone(config('app.timezone'))->toDateTimeString());
        $this->pos($booking, 150, $now->copy()->setTimezone(config('app.timezone'))->toDateTimeString());

        $metrics = $this->asAdmin()->getJson('/api/metrics/dashboard/' . $this->admin->id . '?timeframe=today&location_id=' . $this->brighton->id)
            ->assertOk()->json('metrics');

        $this->assertEquals(50, $metrics['onlineRevenue']);
        $this->assertEquals(150, $metrics['inStoreCollected']);
        $this->assertEquals(0, $metrics['unclassifiedReceipts']);
        $this->assertEquals(200, $metrics['receiptsTotal']);
    }

    public function test_tax_is_classified_by_its_charge_type_not_by_its_name(): void
    {
        $roomFee = FeeSupport::create(['company_id' => $this->company->id, 'location_id' => $this->brighton->id, 'fee_name' => 'Private Room Fee', 'fee_amount' => 4, 'fee_calculation_type' => 'fixed', 'fee_application_type' => 'additive', 'entity_type' => 'package', 'entity_ids' => [], 'applies_to_all' => true, 'is_active' => true]);
        $salesTax = FeeSupport::create(['company_id' => $this->company->id, 'location_id' => $this->brighton->id, 'fee_name' => 'Sales Tax', 'fee_amount' => 8, 'fee_calculation_type' => 'fixed', 'fee_application_type' => 'additive', 'entity_type' => 'package', 'entity_ids' => [], 'applies_to_all' => true, 'is_active' => true]);
        $migration = require database_path('migrations/2026_11_27_000002_add_charge_type_to_fee_supports_table.php');
        $migration->up();
        $this->assertSame('fee', $roomFee->fresh()->charge_type);
        $this->assertSame('tax', $salesTax->fresh()->charge_type);

        $this->booking(['total_amount' => 212, 'applied_fees' => [
            ['fee_support_id' => $roomFee->id, 'fee_name' => 'Private Room Fee', 'fee_amount' => 4],
            ['fee_support_id' => $salesTax->id, 'fee_name' => 'Sales Tax', 'fee_amount' => 8],
            ['fee_name' => 'Private Party Fee', 'fee_amount' => 3],
            ['fee_name' => 'State Tax', 'fee_amount' => 2],
        ]]);

        $today = Carbon::now('America/Detroit')->startOfDay();
        $summary = app(AccountingReportService::class)->buildReportData([$this->brighton->id], $today, $today, 'booked_on')['summary'];

        $this->assertEquals(7.0, $summary['fee_amount']);
        $this->assertEquals(10.0, $summary['tax_amount']);
    }

    public function test_processor_totals_net_gateway_refunds_and_split_ticket_orders_to_the_cent(): void
    {
        $today = Carbon::now('America/Detroit');
        $at = $today->copy()->setTimezone(config('app.timezone'))->toDateTimeString();
        $booking = $this->booking(['amount_paid' => 150]);
        $charge = $this->online($booking, 200, $at);
        $this->refund($charge, 50, $at);

        $attraction = Attraction::create(['location_id' => $this->brighton->id, 'name' => 'Laser Tag', 'description' => 'Laser tag', 'category' => 'Attractions', 'price' => 30, 'duration' => 30, 'max_capacity' => 20, 'status' => 'active']);
        $order = TicketOrder::create(['reference_number' => 'TO-SPLIT', 'company_id' => $this->company->id, 'location_id' => $this->brighton->id, 'guest_name' => 'Split', 'guest_email' => 'split@example.com', 'purchase_date' => $today->toDateString(), 'item_count' => 2, 'ticket_count' => 2, 'subtotal' => 200, 'total_amount' => 200, 'amount_paid' => 200, 'payment_method' => 'authorize.net', 'status' => 'confirmed']);
        foreach ([100.01, 99.99] as $total) {
            AttractionPurchase::create(['attraction_id' => $attraction->id, 'ticket_order_id' => $order->id, 'guest_name' => 'Split', 'guest_email' => 'split@example.com', 'quantity' => 1, 'total_amount' => $total, 'amount_paid' => $total, 'purchase_date' => $today->toDateString(), 'payment_status' => 'paid']);
        }
        $this->pay(Payment::TYPE_TICKET_ORDER, $order->id, 50, 'authorize.net', $at);
        $this->pay(Payment::TYPE_TICKET_ORDER, $order->id, 150, 'cash', $at, ['recorded_by' => $this->admin->id]);

        $report = app(AccountingReportService::class)->buildReportData([$this->brighton->id], $today->copy()->startOfDay(), $today->copy()->startOfDay(), 'booked_on');
        $categories = collect($report['categories'])->keyBy('name');

        $this->assertEquals(150.0, $categories['Parties']['summary']['collected_via_gateway']);
        $this->assertEquals(50.0, $categories['Attractions']['summary']['collected_via_gateway']);
    }

    public function test_the_end_of_day_template_is_swapped_only_where_it_still_matches_the_default(): void
    {
        $this->seed(\Database\Seeders\DefaultEmailNotificationSeeder::class);
        $migration = require database_path('migrations/2026_11_27_000003_update_end_of_day_report_template.php');
        $old = (new \ReflectionMethod($migration, 'oldBody'))->invoke(null);
        $new = (new \ReflectionMethod($migration, 'newBody'))->invoke(null);
        $seeded = EmailNotification::where('default_key', EmailNotification::DEFAULT_END_OF_DAY_SALES_REPORT)->firstOrFail();
        $this->assertSame($new, $seeded->body, 'the seeder ships the new template');

        $untouched = $seeded;
        $untouched->forceFill(['body' => $old, 'default_body' => $old])->saveQuietly();
        $otherCompany = Company::create(['company_name' => 'Other Co', 'email' => 'other@receipts.test', 'phone' => '5551239999', 'address' => '2 Main St']);
        $edited = EmailNotification::where('company_id', $otherCompany->id)->where('default_key', EmailNotification::DEFAULT_END_OF_DAY_SALES_REPORT)->first()
            ?? $untouched->replicate()->forceFill(['company_id' => $otherCompany->id]);
        $edited->forceFill(['body' => $old . '<p>Our own note</p>', 'default_body' => $old])->saveQuietly();

        $migration->up();

        $this->assertSame($new, $untouched->fresh()->body);
        $this->assertSame($new, $untouched->fresh()->default_body);
        $this->assertSame($old . '<p>Our own note</p>', $edited->fresh()->body, 'an edited template keeps its words');
        $this->assertSame($new, $edited->fresh()->default_body);
        $this->assertStringNotContainsString('{{net_sales}}', $new);
        $this->assertStringContainsString('{{online_revenue}}', $new);
        $this->assertStringContainsString('{{in_store_collected}}', $new);
    }
}
