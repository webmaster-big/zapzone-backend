<?php

namespace Tests\Feature;

use App\Models\Attraction;
use App\Models\AttractionPurchase;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Payment;
use App\Models\TicketOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CartOrderCustomerBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Location $location;
    private Attraction $attraction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'owner@zapzone.test',
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
            'email' => 'brighton@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $this->attraction = Attraction::create([
            'location_id' => $this->location->id,
            'name' => 'Laser Tag',
            'description' => 'Arena laser tag',
            'category' => 'Attractions',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);
    }

    private function customer(string $email): Customer
    {
        return Customer::create([
            'first_name' => 'Cart',
            'last_name' => 'Shopper',
            'email' => $email,
            'phone' => '7345550000',
            'password' => bcrypt('secret-password'),
            'status' => 'active',
        ]);
    }

    private function order(string $placedAt, ?Customer $owner = null): TicketOrder
    {
        $order = TicketOrder::create([
            'reference_number' => 'TO' . strtoupper(uniqid()),
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'customer_id' => $owner?->id,
            'guest_name' => 'Cart Shopper',
            'guest_email' => 'cart@example.com',
            'purchase_date' => '2026-10-08',
            'item_count' => 1,
            'ticket_count' => 2,
            'subtotal' => 40,
            'total_amount' => 40,
            'amount_paid' => 40,
            'payment_method' => 'authorize.net',
            'status' => 'confirmed',
        ]);
        DB::table('ticket_orders')->where('id', $order->id)->update(['created_at' => $placedAt, 'updated_at' => $placedAt]);

        AttractionPurchase::create([
            'attraction_id' => $this->attraction->id,
            'ticket_order_id' => $order->id,
            'customer_id' => $owner?->id,
            'guest_name' => 'Cart Shopper',
            'guest_email' => 'cart@example.com',
            'quantity' => 2,
            'total_amount' => 40,
            'amount_paid' => 40,
            'purchase_date' => '2026-10-08',
            'payment_status' => 'paid',
        ]);

        return $order;
    }

    private function paid(TicketOrder $order, Customer $payer, string $status = 'completed'): void
    {
        Payment::create([
            'payable_type' => Payment::TYPE_TICKET_ORDER,
            'payable_id' => $order->id,
            'customer_id' => $payer->id,
            'location_id' => $this->location->id,
            'amount' => 40,
            'currency' => 'USD',
            'method' => 'card',
            'status' => $status,
            'transaction_id' => 'TXN' . uniqid(),
            'paid_at' => now(),
        ]);
    }

    private function backfill(): void
    {
        (require database_path('migrations/2026_10_16_000003_link_cart_orders_to_their_paying_customer.php'))->up();
    }

    public function test_an_order_paid_by_a_signed_in_customer_gets_that_customer_and_so_do_its_tickets(): void
    {
        $shopper = $this->customer('shopper@example.com');
        $order = $this->order('2026-10-07 18:30:00');
        $this->paid($order, $shopper);
        $this->paid($order, $this->customer('mistake@example.com'));
        Payment::where('payable_id', $order->id)->latest('id')->first()->delete();

        $this->backfill();

        $this->assertSame($shopper->id, (int) $order->fresh()->customer_id);
        $this->assertSame([$shopper->id], AttractionPurchase::where('ticket_order_id', $order->id)->pluck('customer_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_orders_it_cannot_be_sure_about_are_left_alone(): void
    {
        $first = $this->customer('first@example.com');
        $second = $this->customer('second@example.com');

        $older = $this->order('2026-10-06 12:00:00');
        $this->paid($older, $first);

        $shared = $this->order('2026-10-08 12:00:00');
        $this->paid($shared, $first);
        $this->paid($shared, $second);

        $declined = $this->order('2026-10-08 13:00:00');
        $this->paid($declined, $first, 'failed');

        $owned = $this->order('2026-10-08 14:00:00', $second);
        $this->paid($owned, $first);

        $this->backfill();

        $this->assertNull($older->fresh()->customer_id, 'orders from before the gap are not touched');
        $this->assertNull($shared->fresh()->customer_id, 'two different payers means the buyer is not known');
        $this->assertNull($declined->fresh()->customer_id, 'only a completed payment names the buyer');
        $this->assertSame($second->id, (int) $owned->fresh()->customer_id, 'an order that already has its customer keeps it');
        $this->assertSame(0, AttractionPurchase::whereIn('ticket_order_id', [$older->id, $shared->id, $declined->id])->whereNotNull('customer_id')->count());
    }
}
