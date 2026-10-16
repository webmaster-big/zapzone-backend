<?php

namespace Tests\Feature;

use App\Models\Attraction;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Event;
use App\Models\EventPurchase;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Package;
use App\Models\PackageTimeSlot;
use App\Models\Payment;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Location $location;
    private Package $package;
    private Room $room;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false, 'google_calendar.auto_sync' => false]);

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
        $this->room = Room::create(['location_id' => $this->location->id, 'name' => 'Party Room 1', 'capacity' => 20, 'is_available' => true]);
        $this->package = Package::create([
            'location_id' => $this->location->id,
            'name' => 'Arcade Party',
            'description' => 'Party',
            'category' => 'Party',
            'price' => 100,
            'pricing_type' => 'base',
            'min_participants' => 1,
            'max_participants' => 20,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'is_active' => true,
        ]);
        $this->admin = User::create([
            'first_name' => 'Owner',
            'last_name' => 'Admin',
            'email' => 'admin@zapzone.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
        ]);
    }

    private function customer(string $email): Customer
    {
        return Customer::create([
            'first_name' => 'Pat',
            'last_name' => 'Rivera',
            'email' => $email,
            'phone' => '7345550000',
            'password' => bcrypt('secret-password'),
            'status' => 'active',
        ]);
    }

    private function asAdmin(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->admin->createToken('desk')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }

    private function bookingFor(Customer $customer, array $overrides = []): Booking
    {
        return Booking::create($overrides + [
            'reference_number' => 'BK' . strtoupper(uniqid()),
            'customer_id' => $customer->id,
            'location_id' => $this->location->id,
            'package_id' => $this->package->id,
            'room_id' => $this->room->id,
            'booking_date' => now()->addDays(7)->toDateString(),
            'booking_time' => '14:00',
            'participants' => 8,
            'duration' => 60,
            'duration_unit' => 'minutes',
            'total_amount' => 100,
            'amount_paid' => 100,
            'payment_status' => 'paid',
            'status' => 'confirmed',
        ]);
    }

    public function test_deleting_a_customer_keeps_their_bookings_purchases_and_payments_as_guest_records(): void
    {
        $customer = $this->customer('pat@example.com');
        $booking = $this->bookingFor($customer);
        $trashed = $this->bookingFor($customer, ['booking_time' => '16:00', 'guest_name' => 'Pat at the desk']);
        $trashed->delete();
        $payment = Payment::create([
            'payable_type' => Payment::TYPE_BOOKING,
            'payable_id' => $booking->id,
            'customer_id' => $customer->id,
            'location_id' => $this->location->id,
            'amount' => 100,
            'currency' => 'USD',
            'method' => 'card',
            'status' => 'completed',
            'transaction_id' => 'TXN' . uniqid(),
            'paid_at' => now(),
        ]);
        $slot = PackageTimeSlot::create([
            'package_id' => $this->package->id,
            'booking_id' => $booking->id,
            'room_id' => $this->room->id,
            'customer_id' => $customer->id,
            'booked_date' => $booking->booking_date,
            'time_slot_start' => '14:00',
            'duration' => 60,
            'duration_unit' => 'minutes',
            'status' => 'booked',
        ]);
        $attraction = Attraction::create([
            'location_id' => $this->location->id,
            'name' => 'Laser Tag',
            'description' => 'Arena laser tag',
            'category' => 'Attractions',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);
        $tickets = AttractionPurchase::create([
            'attraction_id' => $attraction->id,
            'customer_id' => $customer->id,
            'quantity' => 2,
            'total_amount' => 40,
            'amount_paid' => 40,
            'purchase_date' => now()->toDateString(),
            'payment_status' => 'paid',
        ]);
        $event = Event::create([
            'location_id' => $this->location->id,
            'name' => 'Glow Night',
            'date_type' => 'one_time',
            'start_date' => now()->addDays(3)->toDateString(),
            'time_start' => '18:00',
            'time_end' => '21:00',
            'interval_minutes' => 60,
            'price' => 15,
            'is_active' => true,
        ]);
        $party = EventPurchase::create([
            'reference_number' => 'EV' . strtoupper(uniqid()),
            'event_id' => $event->id,
            'location_id' => $this->location->id,
            'customer_id' => $customer->id,
            'guest_name' => 'Riley Rivera',
            'purchase_date' => now()->addDays(3)->toDateString(),
            'purchase_time' => '18:00',
            'quantity' => 2,
            'total_amount' => 30,
            'status' => 'confirmed',
        ]);
        CustomerNotification::create([
            'customer_id' => $customer->id,
            'title' => 'Booking confirmed',
            'message' => 'See you soon',
            'type' => 'booking',
        ]);

        $this->asAdmin()->deleteJson("/api/customers/{$customer->id}")->assertOk();

        $this->assertNull(Customer::find($customer->id));
        $this->assertSame(0, CustomerNotification::where('customer_id', $customer->id)->count(), 'their own notifications go with the account');

        $booking = Booking::findOrFail($booking->id);
        $this->assertNull($booking->customer_id);
        $this->assertSame('Pat Rivera', $booking->guest_name);
        $this->assertSame('pat@example.com', $booking->guest_email);
        $this->assertSame('7345550000', $booking->guest_phone);

        $trashed = Booking::withTrashed()->findOrFail($trashed->id);
        $this->assertNull($trashed->customer_id);
        $this->assertSame('Pat at the desk', $trashed->guest_name, 'a name already on the booking is never overwritten');

        $this->assertNull(Payment::findOrFail($payment->id)->customer_id);
        $this->assertSame('100.00', (string) Payment::findOrFail($payment->id)->amount);
        $this->assertNull(PackageTimeSlot::findOrFail($slot->id)->customer_id);

        $tickets = AttractionPurchase::findOrFail($tickets->id);
        $this->assertNull($tickets->customer_id);
        $this->assertSame('Pat Rivera', $tickets->guest_name);

        $party = EventPurchase::findOrFail($party->id);
        $this->assertNull($party->customer_id);
        $this->assertSame('Riley Rivera', $party->guest_name);
        $this->assertSame('pat@example.com', $party->guest_email);
    }

    public function test_a_customer_with_a_membership_is_not_deleted(): void
    {
        $customer = $this->customer('member@example.com');
        $plan = MembershipPlan::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'name' => 'Local Explorer',
            'slug' => 'local-explorer',
            'tier' => 'basic',
            'price' => 29.99,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);
        $membership = Membership::create([
            'customer_id' => $customer->id,
            'membership_plan_id' => $plan->id,
            'home_location_id' => $this->location->id,
            'status' => 'canceled',
            'billing_amount' => $plan->price,
        ]);
        $booking = $this->bookingFor($customer);

        $this->asAdmin()->deleteJson("/api/customers/{$customer->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertNotNull(Customer::find($customer->id));
        $this->assertNotNull(Membership::find($membership->id));
        $this->assertSame($customer->id, Booking::findOrFail($booking->id)->customer_id);
        $this->assertFalse($customer->fresh()->delete(), 'deleting the model directly is refused too');
        $this->assertNotNull(Customer::find($customer->id));
    }

    public function test_the_database_keeps_bookings_and_payments_even_when_a_customer_row_is_removed_directly(): void
    {
        $customer = $this->customer('raw@example.com');
        $booking = $this->bookingFor($customer);
        $payment = Payment::create([
            'payable_type' => Payment::TYPE_BOOKING,
            'payable_id' => $booking->id,
            'customer_id' => $customer->id,
            'location_id' => $this->location->id,
            'amount' => 100,
            'currency' => 'USD',
            'method' => 'card',
            'status' => 'completed',
            'transaction_id' => 'TXN' . uniqid(),
            'paid_at' => now(),
        ]);

        DB::table('customers')->where('id', $customer->id)->delete();

        $this->assertNull(Booking::findOrFail($booking->id)->customer_id);
        $this->assertNull(Payment::findOrFail($payment->id)->customer_id);
    }
}
