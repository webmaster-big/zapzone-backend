<?php

namespace Tests\Feature;

use App\Models\AddOn;
use App\Models\Booking;
use App\Models\BookingInvitation;
use App\Models\Category;
use App\Models\Company;
use App\Models\Location;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Room;
use App\Models\User;
use App\Models\Waiver;
use App\Models\WaiverTemplate;
use App\Services\AccountingReportService;
use App\Services\EmailNotificationService;
use App\Services\GoogleCalendarService;
use App\Services\InvitationService;
use App\Services\WaiverService;
use App\Support\CompletedVisit;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class CustomPackageConsumersTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $location;

    private Room $room;

    private Room $hall;

    private Package $arcade;

    private AddOn $pizza;

    private User $admin;

    private User $attendant;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gmail.enabled' => false,
            'google_calendar.auto_sync' => false,
            'booking_rules.slot_conflict' => 'enforce',
            'booking_rules.package_required' => 'log',
            'checkout.enforce_charge_within_due' => false,
            'app.frontend_url' => 'https://book.test',
        ]);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(now()->setTime(12, 0));

        $this->company = Company::create([
            'company_name' => 'Consumers Co',
            'email' => 'owner@consumers.test',
            'phone' => '5551230000',
            'address' => '1 Main St',
        ]);
        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Brighton',
            'address' => '1 Test Way',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => 'brighton@consumers.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $this->room = Room::create(['location_id' => $this->location->id, 'name' => 'Party Room 1', 'capacity' => 30, 'is_available' => true, 'booking_interval' => 15]);
        $this->hall = Room::create(['location_id' => $this->location->id, 'name' => 'Event Hall', 'capacity' => 60, 'is_available' => true, 'booking_interval' => 15]);

        Category::create(['name' => 'Corporate Event']);

        $this->pizza = AddOn::create(['location_id' => $this->location->id, 'name' => 'Pizza', 'price' => 7.50, 'is_active' => true]);
        $this->arcade = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Arcade Party',
            'description' => 'Arcade fun',
            'category' => 'Party',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 20,
            'duration' => 2,
            'duration_unit' => 'hours',
            'is_active' => true,
        ]);

        $this->admin = $this->staff('company_admin');
        $this->attendant = $this->staff('attendant');

        $this->date = now()->addDays(5)->toDateString();
    }

    public function test_email_variables_describe_the_custom_package_and_list_its_one_time_add_ons(): void
    {
        $booking = $this->createCustom([
            'total_amount' => 303.80,
            'additional_addons' => [['addon_id' => $this->pizza->id, 'quantity' => 2]],
        ], [
            'one_time_add_ons' => [
                ['name' => 'Glow Bracelets', 'quantity' => 3, 'unit_price' => 5],
                ['name' => 'Cake & Candles', 'quantity' => 1, 'unit_price' => 12.50],
            ],
        ]);
        $this->pizza->update(['price' => 9.00]);

        $variables = $this->bookingVariables($booking);

        $this->assertSame('Team Outing', $variables['package_name']);
        $this->assertSame('Custom: Work', $variables['package_description']);
        $this->assertSame('1 hr 30 min', $variables['package_duration']);
        $this->assertSame('$20.00 per guest', $variables['package_price']);
        $this->assertSame('8', $variables['package_min_participants']);
        $this->assertSame('16', $variables['package_max_participants']);
        $this->assertSame('Pizza x2 - $15<br>Glow Bracelets x3 - $15<br>Cake &amp; Candles x1 - $12.5', $variables['addons_list']);
        $this->assertSame('$42.50', $variables['addons_total']);
        $this->assertSame('$303.80', $variables['booking_total']);

        $perBooking = $this->createCustom([
            'total_amount' => 300,
            'booking_time' => '17:00',
            'room_id' => $this->hall->id,
            'guest_email' => 'office@example.com',
        ], [
            'name' => 'Office Party',
            'pricing_type' => 'per_booking',
            'price' => 300,
            'duration_minutes' => 120,
            'min_guests' => null,
            'fees' => [],
            'one_time_add_ons' => [],
        ]);

        $variables = $this->bookingVariables($perBooking);

        $this->assertSame('Office Party', $variables['package_name']);
        $this->assertSame('$300.00', $variables['package_price']);
        $this->assertSame('2 hours', $variables['package_duration']);
        $this->assertSame('1', $variables['package_min_participants']);
        $this->assertSame('16', $variables['package_max_participants']);
        $this->assertSame('', $variables['addons_list']);
        $this->assertSame('$0.00', $variables['addons_total']);
    }

    public function test_regular_booking_email_variables_keep_the_package_and_read_the_booked_add_on_price(): void
    {
        $booking = $this->regularBooking();
        $booking->addOns()->attach($this->pizza->id, ['quantity' => 2, 'price_at_booking' => 7.50]);
        $this->pizza->update(['price' => 9.00]);

        $variables = $this->bookingVariables($booking);

        $this->assertSame('Arcade Party', $variables['package_name']);
        $this->assertSame('Arcade fun', $variables['package_description']);
        $this->assertSame('2 hours', $variables['package_duration']);
        $this->assertSame('$100.00', $variables['package_price']);
        $this->assertSame('1', $variables['package_min_participants']);
        $this->assertSame('20', $variables['package_max_participants']);
        $this->assertSame('Pizza x2 - $15', $variables['addons_list']);
        $this->assertSame('$15.00', $variables['addons_total']);

        $orphanVariables = $this->bookingVariables($this->orphanBooking());

        $this->assertSame('', $orphanVariables['package_name']);
        $this->assertSame('$0.00', $orphanVariables['package_price']);
        $this->assertSame('10', $orphanVariables['package_max_participants']);
        $this->assertSame('', $orphanVariables['addons_list']);
    }

    public function test_accounting_reports_custom_bookings_under_their_category_and_still_skips_orphans(): void
    {
        $this->regularBooking();
        $this->createCustom(['payment_method' => 'in-store', 'amount_paid' => 275.20]);
        $this->createCustom(['booking_time' => '17:00', 'room_id' => $this->hall->id, 'guest_email' => 'second@example.com']);
        $this->createCustom(['booking_time' => '19:30', 'guest_email' => 'third@example.com'])->update(['status' => 'cancelled']);
        $this->orphanBooking(['total_amount' => 50, 'amount_paid' => 50, 'payment_status' => 'paid']);

        $report = $this->accountingReport();
        $parties = $this->reportCategory($report, 'Parties');

        $this->assertSame(
            [['Custom: Work', 'Custom Package'], ['Party', 'Arcade Party']],
            array_map(fn (array $item) => [$item['sub_category'], $item['name']], $parties['items'])
        );

        $custom = $parties['items'][0];
        $this->assertSame(2, $custom['quantity_sold']);
        $this->assertEquals(550.40, $custom['gross_sales']);
        $this->assertEquals(550.40, $custom['net_sales']);
        $this->assertEquals(40.40, $custom['fee_amount']);
        $this->assertEquals(0, $custom['tax_amount']);
        $this->assertEquals(0, $custom['discount_amount']);
        $this->assertEquals(550.40, $custom['total_billed']);
        $this->assertEquals(275.20, $custom['grand_total']);
        $this->assertEquals(275.20, $custom['balance_due']);

        $regular = $parties['items'][1];
        $this->assertSame(1, $regular['quantity_sold']);
        $this->assertEquals(100, $regular['net_sales']);
        $this->assertEquals(100, $regular['grand_total']);

        $this->assertEquals(650.40, $parties['summary']['net_sales']);
        $this->assertEquals(650.40, $report['summary']['total_billed']);
        $this->assertEquals(375.20, $report['summary']['grand_total']);
        $this->assertEquals(40.40, $report['summary']['fee_amount']);

        $this->assertSame(['Custom Package'], array_column($this->reportCategory($this->accountingReport(['category_filter' => 'Custom: Work']), 'Parties')['items'], 'name'));
        $this->assertSame(['Arcade Party'], array_column($this->reportCategory($this->accountingReport(['category_filter' => 'Party']), 'Parties')['items'], 'name'));

        $endOfDay = app(AccountingReportService::class)->buildDailyReportVariables(Carbon::now(), 'Consumers Co', $this->company->id);
        $this->assertSame('$650.40', $endOfDay['total_billed']);
        $this->assertSame($endOfDay['receipts_total'], $endOfDay['total_collected']);
        $this->assertSame('$0.00', $endOfDay['total_collected'], 'receipts come from the payment ledger, and this fixture records no payments');
        $this->assertSame('$40.40', $endOfDay['fee_total']);
    }

    public function test_discount_by_source_counts_custom_rows_and_still_skips_orphans(): void
    {
        $this->regularBooking(['discount_amount' => 3]);
        $custom = $this->createCustom();
        DB::table('bookings')->where('id', $custom->id)->update(['discount_amount' => 2]);
        $this->orphanBooking(['discount_amount' => 5]);

        $this->assertEquals(5, $this->accountingReport()['summary']['discount_by_source']['other']);
        $this->assertEquals(2, $this->accountingReport(['category_filter' => 'Custom: Work'])['summary']['discount_by_source']['other']);
        $this->assertEquals(3, $this->accountingReport(['category_filter' => 'Party'])['summary']['discount_by_source']['other']);
    }

    public function test_accounting_add_ons_section_gains_one_one_time_add_ons_row(): void
    {
        $regular = $this->regularBooking();
        $regular->addOns()->attach($this->pizza->id, ['quantity' => 2, 'price_at_booking' => 7.50]);

        $this->assertSame(['Pizza'], array_column($this->reportCategory($this->accountingReport(), 'Add-ons')['items'], 'name'));

        $this->createCustom(['payment_method' => 'in-store', 'amount_paid' => 275.20]);
        $this->createCustom(['booking_time' => '17:00', 'room_id' => $this->hall->id, 'guest_email' => 'second@example.com']);
        $this->createCustom(['booking_time' => '19:30', 'guest_email' => 'third@example.com'])->update(['status' => 'cancelled']);

        $addOns = $this->reportCategory($this->accountingReport(), 'Add-ons');

        $this->assertSame(['One-time add-ons', 'Pizza'], array_column($addOns['items'], 'name'));
        $oneTime = $addOns['items'][0];
        $this->assertSame('Add-ons', $oneTime['sub_category']);
        $this->assertSame(6, $oneTime['quantity_sold']);
        $this->assertEquals(30.00, $oneTime['gross_sales']);
        $this->assertEquals(30.00, $oneTime['total_billed']);
        $this->assertEquals(15.00, $oneTime['grand_total']);
        $this->assertEquals(15.00, $oneTime['balance_due']);
        $this->assertSame(8, $addOns['summary']['quantity_sold']);
        $this->assertEquals(45.00, $addOns['summary']['gross_sales']);

        $bookedOn = $this->reportCategory(app(AccountingReportService::class)->buildReportData([$this->location->id], Carbon::now(), Carbon::now(), 'booked_on'), 'Add-ons');
        $this->assertSame(6, collect($bookedOn['items'])->firstWhere('name', 'One-time add-ons')['quantity_sold']);
    }

    public function test_analytics_package_performance_appends_one_custom_package_row_and_distribution_uses_the_custom_category(): void
    {
        $this->regularBooking();
        $this->orphanBooking();
        $this->createCustom(['payment_method' => 'in-store', 'amount_paid' => 275.20]);
        $this->createCustom([
            'booking_time' => '17:00',
            'room_id' => $this->hall->id,
            'guest_email' => 'second@example.com',
            'participants' => 10,
            'total_amount' => 233.60,
        ], ['name' => 'Board Games Night']);

        $query = ['location_id' => $this->location->id, 'date_range' => 'custom', 'start_date' => $this->date, 'end_date' => $this->date];
        $performance = $this->as($this->admin)->getJson('/api/analytics/location?' . http_build_query($query))->assertOk()->json('package_performance');

        $this->assertSame(['Arcade Party', 'Custom Package'], array_column($performance, 'name'));
        $customRow = $performance[1];
        $this->assertNull($customRow['id']);
        $this->assertSame('Custom: Work', $customRow['category']);
        $this->assertSame(2, $customRow['bookings']);
        $this->assertEquals(275.20, $customRow['revenue']);
        $this->assertSame(22, $customRow['participants']);
        $this->assertEquals(11.0, $customRow['avg_party_size']);
        $this->assertEquals(20.0, $customRow['price']);
        $this->assertSame(['id', 'name', 'category', 'bookings', 'revenue', 'participants', 'avg_party_size', 'price'], array_keys($customRow));
        $this->assertSame(array_keys($performance[0]), array_keys($customRow));

        $csv = $this->as($this->admin)
            ->postJson('/api/analytics/location/export', $query + ['format' => 'csv', 'sections' => ['packages']])
            ->assertOk()
            ->getContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", $csv)));
        $this->assertContains(['Custom Package', 'Custom: Work', '2', '275.2', '22', '11'], $rows);

        $distribution = $this->as($this->admin)
            ->getJson('/api/analytics/company?' . http_build_query(['company_id' => $this->company->id, 'date_range' => '30d']))
            ->assertOk()
            ->json('package_distribution');

        $this->assertEquals(
            ['Custom: Work Package' => 2, 'Party Package' => 1, 'Other Package' => 1],
            collect($distribution)->pluck('count', 'name')->all()
        );
    }

    public function test_package_performance_shows_the_custom_row_when_the_location_has_no_active_packages(): void
    {
        $this->arcade->update(['is_active' => false]);
        $this->createCustom();

        $performance = $this->as($this->admin)
            ->getJson('/api/analytics/location?' . http_build_query(['location_id' => $this->location->id, 'date_range' => 'custom', 'start_date' => $this->date, 'end_date' => $this->date]))
            ->assertOk()
            ->json('package_performance');

        $this->assertSame(['Custom Package'], array_column($performance, 'name'));
    }

    public function test_dashboard_metrics_label_custom_bookings_custom_package_and_recent_bookings_use_the_custom_name(): void
    {
        $regular = $this->regularBooking();
        $orphan = $this->orphanBooking();
        $custom = $this->createCustom();

        $breakdowns = $this->as($this->admin)
            ->getJson("/api/metrics/dashboard/{$this->admin->id}?timeframe=all_time")
            ->assertOk()
            ->json('breakdowns');

        $this->assertEquals(
            ['Arcade Party' => 1, 'Other' => 1, 'Custom Package' => 1],
            collect($breakdowns['packageBreakdown'])->pluck('count', 'label')->all()
        );
        $this->assertCount(3, $breakdowns['packageBreakdown']);
        $this->assertEquals(
            ['Arcade Party' => 10, 'Other' => 10, 'Custom Package' => 12],
            collect($breakdowns['participantBreakdown'])->pluck('count', 'label')->all()
        );

        $recent = $this->as($this->attendant)
            ->getJson('/api/metrics/attendant?timeframe=all_time')
            ->assertOk()
            ->json('recentBookings');
        $names = collect($recent)->pluck('package_name', 'id')->all();

        $this->assertSame('Team Outing', $names[$custom->id]);
        $this->assertSame('Arcade Party', $names[$regular->id]);
        $this->assertNull($names[$orphan->id]);
    }

    public function test_payment_invoice_and_booking_summary_render_the_custom_name_one_time_lines_and_fees(): void
    {
        $booking = $this->createCustom(['payment_method' => 'in-store', 'amount_paid' => 275.20]);
        $payment = $this->paymentFor($booking, 275.20);

        $invoice = $this->renderInvoice($payment, $booking);

        $this->assertStringContainsString('<div class="event-title">Team Outing</div>', $invoice);
        $this->assertMatchesRegularExpression('#<td>\s*Team Outing\s*<div class="item-desc">#', $invoice);
        $this->assertStringNotContainsString('Party Package', $invoice);
        $this->assertMatchesRegularExpression('#<td class="right">12</td>\s*<td class="right">\$20\.00</td>\s*<td class="right">\$240\.00</td>#', $invoice);
        $this->assertMatchesRegularExpression('#<td>Glow Bracelets</td>\s*<td class="right">3</td>\s*<td class="right">\$5\.00</td>\s*<td class="right">\$15\.00</td>#', $invoice);
        $this->assertMatchesRegularExpression('#<td>Venue Fee \(4%\)</td>\s*<td class="right">1</td>\s*<td class="right">\$10\.20</td>\s*<td class="right">\$10\.20</td>#', $invoice);
        $this->assertMatchesRegularExpression('#<td>Setup Fee</td>\s*<td class="right">1</td>\s*<td class="right">\$10\.00</td>\s*<td class="right">\$10\.00</td>#', $invoice);
        $this->assertMatchesRegularExpression('#Total</span>\s*<span class="total-value">\$275\.20</span>#', $invoice);

        $summary = view('exports.booking-summary', [
            'booking' => $booking->load(['customer', 'package', 'location', 'room', 'attractions', 'addOns', 'payments']),
            'customer' => null,
            'location' => $this->location,
            'companyName' => 'Consumers Co',
        ])->render();

        $this->assertMatchesRegularExpression('#<div class="party-package">\s*Team Outing\s*</div>#', $summary);
        $this->assertStringNotContainsString('Package Details', $summary);
        $this->assertStringContainsString('<div class="section-title">Add-Ons</div>', $summary);
        $this->assertMatchesRegularExpression('#<td>Glow Bracelets</td>\s*<td class="text-right">3</td>\s*<td class="text-right">\$5\.00</td>#', $summary);
    }

    public function test_regular_invoices_never_itemize_fees(): void
    {
        $booking = $this->regularBooking([
            'applied_fees' => [['fee_name' => 'Service Fee', 'fee_amount' => 5, 'fee_application_type' => 'additive']],
        ]);

        $invoice = $this->renderInvoice($this->paymentFor($booking, 100), $booking);

        $this->assertStringContainsString('<div class="event-title">Arcade Party</div>', $invoice);
        $this->assertStringNotContainsString('Service Fee', $invoice);
    }

    public function test_report_pdfs_print_the_custom_name_and_one_time_add_ons(): void
    {
        $booking = $this->createCustom(['payment_method' => 'in-store', 'amount_paid' => 275.20]);
        $bookings = Booking::with(['customer', 'package', 'location', 'room', 'attractions', 'addOns', 'payments'])->whereKey($booking->id)->get();
        $range = ['start' => $this->date, 'end' => $this->date];

        $individual = view('exports.booking-details-individual', [
            'bookings' => $bookings,
            'dateRange' => $range,
            'periodType' => 'custom',
            'packageNames' => 'All Packages',
            'viewMode' => 'individual',
            'companyName' => 'Consumers Co',
        ])->render();
        $this->assertStringContainsString('<span class="detail-value">Team Outing</span>', $individual);
        $this->assertMatchesRegularExpression('#<td>Glow Bracelets</td>\s*<td>3</td>\s*<td>\$5\.00</td>\s*<td>\$15\.00</td>#', $individual);

        $list = view('exports.booking-details-list', [
            'bookings' => $bookings,
            'dateRange' => $range,
            'periodType' => 'custom',
            'packageNames' => 'All Packages',
            'viewMode' => 'list',
            'companyName' => 'Consumers Co',
        ])->render();
        $this->assertStringContainsString('<span class="info-value">Team Outing</span>', $list);
        $this->assertStringContainsString('<span class="extras-badge">1 One-time add-ons</span>', $list);

        foreach (['compact', 'full'] as $viewMode) {
            $report = view('exports.booking-summaries-report', [
                'bookings' => $bookings,
                'dateRange' => $range,
                'location' => $this->location,
                'viewMode' => $viewMode,
                'companyName' => 'Consumers Co',
            ])->render();
            $this->assertStringContainsString('Team Outing', $report, $viewMode);
            $this->assertStringNotContainsString('Package Details', $report, $viewMode);
        }

        $payment = $this->paymentFor($booking, 275.20);
        $payment->payable = Booking::withTrashed()->with('package')->find($booking->id);
        $invoices = view('exports.payment-invoices-report', [
            'payments' => collect([$payment]),
            'summary' => ['total_count' => 1, 'total_amount' => 275.20, 'completed_count' => 1, 'refunded_amount' => 0],
            'company' => $this->company,
            'companyName' => 'Consumers Co',
            'locationName' => 'Brighton',
            'filters' => null,
            'reportTitle' => 'Payment Invoices Report',
            'timezone' => 'America/Detroit',
        ])->render();
        $this->assertMatchesRegularExpression('#<td>\s*Team Outing\s*</td>#', $invoices);
    }

    public function test_customer_analytics_top_packages_and_its_export_include_a_custom_package_row(): void
    {
        $this->createCustom(['payment_method' => 'in-store', 'amount_paid' => 275.20]);
        $this->createCustom(['booking_time' => '17:00']);
        $this->regularBooking();
        $this->orphanBooking();

        $top = collect($this->as($this->admin)
            ->getJson('/api/customers/analytics?' . http_build_query(['location_id' => $this->location->id, 'date_range' => '30d']))
            ->assertOk()
            ->json('data.topPackages'))
            ->keyBy(fn (array $row) => $row['customer'] . '|' . $row['package']);

        $this->assertEquals(2, $top['Jordan Planner|Custom Package']['bookings'] ?? null);
        $this->assertEquals(1, $top['Riley Regular|Arcade Party']['bookings'] ?? null);
        $this->assertFalse($top->has('Olive Orphan|N/A'));

        $csv = $this->as($this->admin)
            ->postJson('/api/customers/analytics/export', [
                'location_id' => $this->location->id,
                'date_range' => '30d',
                'format' => 'csv',
                'include_sections' => ['packages'],
            ])
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('"Custom Package",2,$275.20', $csv);
        $this->assertStringContainsString('"Arcade Party",1,$100.00', $csv);
        $this->assertStringNotContainsString('N/A', $csv);
    }

    public function test_the_booking_details_report_can_pick_custom_bookings_alone_or_with_packages(): void
    {
        $this->createCustom();
        $captured = [];
        View::composer('exports.booking-details-individual', function ($view) use (&$captured) {
            $captured[] = [
                'names' => $view->getData()['packageNames'],
                'ids' => $view->getData()['bookings']->pluck('id')->sort()->values()->all(),
            ];
        });
        $report = fn (string $packageIds) => $this->as($this->admin)->get('/api/bookings/details-report?' . http_build_query([
            'package_ids' => $packageIds,
            'period_type' => 'custom',
            'start_date' => $this->date,
            'end_date' => $this->date,
            'location_id' => $this->location->id,
        ]));

        $report((string) $this->arcade->id)->assertNotFound();

        $regular = $this->regularBooking();
        $custom = Booking::where('is_custom_package', true)->firstOrFail();

        $report((string) $this->arcade->id)->assertOk();
        $report('custom')->assertOk();
        $report($this->arcade->id . ',custom')->assertOk();
        $report('all')->assertOk();

        $this->assertSame([
            ['names' => 'Arcade Party', 'ids' => [$regular->id]],
            ['names' => 'Custom Package', 'ids' => [$custom->id]],
            ['names' => 'Arcade Party, Custom Package', 'ids' => collect([$regular->id, $custom->id])->sort()->values()->all()],
            ['names' => 'All Packages', 'ids' => collect([$regular->id, $custom->id])->sort()->values()->all()],
        ], $captured);
    }

    public function test_a_custom_booking_waiver_names_the_custom_package_and_never_an_unrelated_one(): void
    {
        $template = WaiverTemplate::create([
            'company_id' => $this->company->id,
            'title' => 'General waiver',
            'status' => WaiverTemplate::STATUS_ACTIVE,
            'is_default' => true,
            'body_text' => '<p>I take part in {{activity_name}} at {{company_name}}.</p>',
            'kind' => WaiverTemplate::KIND_STANDARD,
            'electronic_consent_enabled' => true,
            'assigned_package_ids' => [$this->arcade->id],
        ]);
        $waivers = app(WaiverService::class);
        $waivers->syncVersion($template);

        $custom = $this->createCustom();
        $waiver = $waivers->ensureForBooking($custom);
        $this->assertNotNull($waiver);

        $this->assertSame('Team Outing', $waivers->buildContentVariables(Waiver::findOrFail($waiver->id))['activity_name']);
        $this->assertSame('Team Outing', $waivers->buildContentVariables(Waiver::with('booking:id,package_id')->findOrFail($waiver->id))['activity_name']);
        $this->assertSame('Team Outing', app(EmailNotificationService::class)->buildVariables(Waiver::findOrFail($waiver->id), 'waiver')['activity_name']);

        $shown = $this->as($this->admin)->getJson("/api/waivers/{$waiver->id}")->assertOk()->json('data');
        $this->assertTrue($shown['waiver']['booking']['is_custom_package']);
        $this->assertSame('Team Outing', $shown['waiver']['booking']['custom_package_name']);
        $this->assertSame('Custom: Work', $shown['waiver']['booking']['custom_package_category']);
        $this->assertSame(16, $shown['waiver']['booking']['custom_package_terms']['max_guests']);
        $this->assertSame('20.00', $shown['waiver']['booking']['package_price_at_booking']);
        $this->assertSame('0.00', $shown['waiver']['booking']['price_per_additional_at_booking']);
        $this->assertSame('per_person', $shown['waiver']['booking']['package_pricing_type_at_booking']);
        $this->assertArrayNotHasKey('guest_email', $shown['waiver']['booking']);
        $this->assertStringContainsString('I take part in Team Outing at Consumers Co.', $shown['rendered_body']);
        $this->assertStringNotContainsString('Arcade Party', $shown['rendered_body']);

        $regularWaiver = $waivers->ensureForBooking($this->regularBooking());
        $this->assertSame('Arcade Party', $waivers->buildContentVariables(Waiver::findOrFail($regularWaiver->id))['activity_name']);
    }

    public function test_follow_up_emails_name_the_custom_package(): void
    {
        $this->assertSame('Team Outing', CompletedVisit::fromBooking($this->createCustom())->activityName);
        $this->assertSame('Arcade Party', CompletedVisit::fromBooking($this->regularBooking())->activityName);
        $this->assertSame('your visit', CompletedVisit::fromBooking($this->orphanBooking())->activityName);
    }

    public function test_party_invitations_and_the_rsvp_page_name_the_custom_package(): void
    {
        $custom = $this->createCustom();
        $invitation = BookingInvitation::create([
            'booking_id' => $custom->id,
            'guest_name' => 'Sam Guest',
            'guest_email' => 'sam@example.com',
            'send_via' => 'email',
        ]);

        $build = new \ReflectionMethod(InvitationService::class, 'buildInvitationVariables');
        $this->assertSame('Team Outing', $build->invoke(app(InvitationService::class), $invitation, $custom->fresh())['package_name']);

        $this->assertSame('sent', app(InvitationService::class)->sendInvitation($invitation)['email']);
        $sent = collect(Mail::mailer()->getSymfonyTransport()->messages())->last()->getOriginalMessage();
        $this->assertStringContainsString('Team Outing', (string) $sent->getHtmlBody());

        $this->assertSame('Team Outing', $this->asGuest()->getJson("/api/rsvp/{$invitation->rsvp_token}")->assertOk()->json('party.package_name'));

        $regularInvitation = BookingInvitation::create([
            'booking_id' => $this->regularBooking()->id,
            'guest_name' => 'Ana Guest',
            'guest_email' => 'ana@example.com',
            'send_via' => 'email',
        ]);
        $this->assertSame('Arcade Party', $this->asGuest()->getJson("/api/rsvp/{$regularInvitation->rsvp_token}")->assertOk()->json('party.package_name'));
    }

    public function test_the_live_feed_names_the_custom_package(): void
    {
        $regular = $this->regularBooking();
        $orphan = $this->orphanBooking();
        $custom = $this->createCustom();

        $items = $this->as($this->admin)
            ->getJson('/api/notifications/live?' . http_build_query(['location_id' => $this->location->id, 'after' => 'b0.p0']))
            ->assertOk()
            ->json('data.items');
        $names = collect($items)->where('type', 'booking')->pluck('package_name', 'id')->all();

        $this->assertSame('Team Outing', $names[$custom->id]);
        $this->assertSame('Arcade Party', $names[$regular->id]);
        $this->assertNull($names[$orphan->id]);
    }

    public function test_google_calendar_events_name_the_custom_package_its_category_and_one_time_add_ons(): void
    {
        $custom = $this->createCustom();
        $regular = $this->regularBooking();
        $regular->addOns()->attach($this->pizza->id, ['quantity' => 2, 'price_at_booking' => 7.50]);
        $build = new \ReflectionMethod(GoogleCalendarService::class, 'buildEventFromBooking');
        $relations = ['package', 'location', 'customer', 'room', 'attractions', 'addOns'];

        $event = $build->invoke(new GoogleCalendarService($this->location->id), $custom->fresh($relations));

        $this->assertSame("Team Outing - Jordan Planner ({$custom->reference_number})", $event->getSummary());
        $this->assertStringContainsString("Package: Team Outing\nCategory: Custom: Work\nLocation: Brighton\nRoom: Party Room 1", $event->getDescription());
        $this->assertStringContainsString("One-time add-ons:\n  - Glow Bracelets (x3)", $event->getDescription());

        $regularEvent = $build->invoke(new GoogleCalendarService($this->location->id), $regular->fresh($relations));

        $this->assertSame("Arcade Party - Riley Regular ({$regular->reference_number})", $regularEvent->getSummary());
        $this->assertStringContainsString("Package: Arcade Party\nLocation: Brighton", $regularEvent->getDescription());
        $this->assertStringContainsString("Add-ons:\n  - Pizza (x2)", $regularEvent->getDescription());
        $this->assertStringNotContainsString('Category:', $regularEvent->getDescription());
        $this->assertStringNotContainsString('One-time add-ons', $regularEvent->getDescription());
    }

    private function staff(string $role): User
    {
        return User::create([
            'first_name' => 'Staff',
            'last_name' => ucfirst($role),
            'email' => $role . '.' . uniqid() . '@consumers.test',
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'status' => 'active',
        ]);
    }

    private function freshRequest(): void
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
    }

    private function as(User $user): static
    {
        $this->freshRequest();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken, 'Accept' => 'application/json']);
    }

    private function asGuest(): static
    {
        $this->freshRequest();

        return $this->withHeaders(['Accept' => 'application/json']);
    }

    private function terms(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Team Outing',
            'category' => 'Custom: Work',
            'duration_minutes' => 90,
            'min_guests' => 8,
            'max_guests' => 16,
            'pricing_type' => 'per_guest',
            'price' => 20,
            'fees' => [
                ['name' => 'Venue Fee', 'type' => 'percent', 'amount' => 4],
                ['name' => 'Setup Fee', 'type' => 'flat', 'amount' => 10],
            ],
            'one_time_add_ons' => [
                ['name' => 'Glow Bracelets', 'quantity' => 3, 'unit_price' => 5],
            ],
        ], $overrides);
    }

    private function createCustom(array $overrides = [], array $terms = []): Booking
    {
        $payload = array_merge([
            'guest_name' => 'Jordan Planner',
            'guest_email' => 'jordan@example.com',
            'guest_phone' => '6165550000',
            'package_id' => null,
            'location_id' => $this->location->id,
            'room_id' => $this->room->id,
            'type' => 'package',
            'booking_date' => $this->date,
            'booking_time' => '14:00',
            'participants' => 12,
            'duration' => 90,
            'duration_unit' => 'minutes',
            'total_amount' => 275.20,
            'amount_paid' => 0,
            'payment_method' => 'paylater',
            'send_email' => false,
            'is_manual_entry' => true,
            'custom_package' => $this->terms($terms),
        ], $overrides);

        $id = (int) $this->as($this->admin)->postJson('/api/bookings', $payload)->assertCreated()->json('data.id');

        return Booking::findOrFail($id);
    }

    private function regularBooking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'reference_number' => 'BK-' . strtoupper(uniqid()),
            'location_id' => $this->location->id,
            'package_id' => $this->arcade->id,
            'booking_date' => $this->date,
            'booking_time' => '10:00',
            'participants' => 10,
            'duration' => 2,
            'duration_unit' => 'hours',
            'total_amount' => 100,
            'amount_paid' => 100,
            'payment_method' => 'in-store',
            'payment_status' => 'paid',
            'status' => 'confirmed',
            'guest_name' => 'Riley Regular',
            'guest_email' => 'riley@example.com',
            'package_price_at_booking' => 100,
            'package_pricing_type_at_booking' => 'base',
            'price_per_additional_at_booking' => 0,
        ], $overrides));
    }

    private function orphanBooking(array $overrides = []): Booking
    {
        return $this->regularBooking(array_merge([
            'package_id' => null,
            'booking_time' => '11:00',
            'total_amount' => 80,
            'amount_paid' => 0,
            'payment_status' => 'pending',
            'guest_name' => 'Olive Orphan',
            'guest_email' => 'olive@example.com',
        ], $overrides));
    }

    private function bookingVariables(Booking $booking): array
    {
        $booking = Booking::with(['customer', 'package', 'location.company', 'room', 'addOns'])->findOrFail($booking->id);

        return app(EmailNotificationService::class)->buildVariables($booking, 'booking', false);
    }

    private function accountingReport(array $filters = []): array
    {
        return app(AccountingReportService::class)->buildReportData(
            [$this->location->id],
            Carbon::parse($this->date),
            Carbon::parse($this->date),
            'booked_for',
            $filters
        );
    }

    private function reportCategory(array $report, string $name): array
    {
        return collect($report['categories'])->firstWhere('name', $name);
    }

    private function paymentFor(Booking $booking, float $amount): Payment
    {
        return Payment::create([
            'payable_id' => $booking->id,
            'payable_type' => Payment::TYPE_BOOKING,
            'amount' => $amount,
            'currency' => 'USD',
            'method' => 'in-store',
            'status' => 'completed',
            'transaction_id' => 'T' . uniqid(),
            'location_id' => $this->location->id,
            'paid_at' => now(),
        ])->fresh();
    }

    private function renderInvoice(Payment $payment, Booking $booking): string
    {
        return view('exports.payment-invoice', [
            'payment' => $payment,
            'payable' => Booking::withTrashed()->with(['package', 'customer', 'room', 'location', 'addOns', 'attractions'])->findOrFail($booking->id),
            'customer' => null,
            'location' => $this->location,
            'company' => $this->company,
            'companyName' => 'Consumers Co',
            'timezone' => 'America/Detroit',
        ])->render();
    }
}
