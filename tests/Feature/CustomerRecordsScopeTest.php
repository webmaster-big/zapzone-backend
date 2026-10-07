<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Location;
use App\Models\Package;
use App\Models\TicketOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerRecordsScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = Company::create([
            'company_name' => 'Zap Zone',
            'email' => 'owner@zapzone.test',
            'phone' => '5551230000',
            'address' => '1 Arcade St',
        ]);
        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Brighton | Zap Zone',
            'address' => '1 Test Way',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => 'brighton@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    public function test_a_customer_sees_and_opens_only_their_own_ticket_orders(): void
    {
        $pat = $this->customer('pat@example.com');
        $sam = $this->customer('sam@example.com');
        $mine = $this->order(['customer_id' => $pat->id, 'guest_email' => 'pat@example.com']);
        $theirs = $this->order(['customer_id' => $sam->id, 'guest_email' => 'sam@example.com']);
        $guestUnderMyEmail = $this->order(['customer_id' => null, 'guest_email' => 'pat@example.com']);

        $this->as($pat);
        $this->assertSame([$mine->id], array_column($this->getJson('/api/ticket-orders')->assertOk()->json('data'), 'id'));
        $this->getJson("/api/ticket-orders/{$mine->id}")->assertOk();
        $this->getJson("/api/ticket-orders/{$theirs->id}")->assertNotFound();
        $this->getJson("/api/ticket-orders/{$guestUnderMyEmail->id}")->assertNotFound();
        $this->postJson("/api/ticket-orders/{$theirs->id}/cancel")->assertForbidden();
        $this->postJson("/api/ticket-orders/{$mine->id}/cancel")->assertForbidden();
        $this->assertSame('confirmed', $theirs->fresh()->status);

        $pat->forceFill(['email_verified_at' => now()])->save();
        $this->as($pat);
        $this->assertEqualsCanonicalizing([$mine->id, $guestUnderMyEmail->id], array_column($this->getJson('/api/ticket-orders')->assertOk()->json('data'), 'id'));
        $this->getJson("/api/ticket-orders/{$guestUnderMyEmail->id}")->assertOk();

        $this->as($this->staff());
        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id, $guestUnderMyEmail->id], array_column($this->getJson('/api/ticket-orders')->assertOk()->json('data'), 'id'));
        $this->getJson("/api/ticket-orders/{$theirs->id}")->assertOk();
    }

    public function test_a_customer_reaches_only_their_own_notifications(): void
    {
        $pat = $this->customer('pat@example.com');
        $sam = $this->customer('sam@example.com');
        $mine = $this->notice($pat);
        $this->notice($pat);
        $theirs = $this->notice($sam);

        $this->as($pat);
        $listed = array_column($this->getJson("/api/customer-notifications?customer_id={$sam->id}")->assertOk()->json('data.notifications'), 'customer_id');
        $this->assertSame([$pat->id, $pat->id], $listed);
        $this->getJson("/api/customer-notifications/{$theirs->id}")->assertNotFound();
        $this->patchJson("/api/customer-notifications/{$theirs->id}/mark-as-read")->assertNotFound();
        $this->putJson("/api/customer-notifications/{$theirs->id}", ['status' => 'archived'])->assertNotFound();
        $this->deleteJson("/api/customer-notifications/{$theirs->id}")->assertNotFound();
        $this->postJson('/api/customer-notifications', ['customer_id' => $sam->id, 'type' => 'general', 'title' => 'Hi', 'message' => 'Spam'])->assertForbidden();
        $this->assertSame(2, $this->getJson("/api/customer-notifications/unread-count/{$sam->id}")->assertOk()->json('unread_count'));
        $this->assertSame(2, $this->getJson('/api/customer-notifications/unread-count/0')->assertOk()->json('data.unread_count'), 'the portal reads the count from data');

        $this->patchJson("/api/customer-notifications/mark-all-as-read/{$sam->id}")->assertOk();
        $this->assertSame(0, CustomerNotification::where('customer_id', $pat->id)->where('status', 'unread')->count());
        $this->assertSame('unread', $theirs->fresh()->status);
        $this->getJson("/api/customer-notifications/{$mine->id}")->assertOk();
        $this->assertNotNull(CustomerNotification::find($theirs->id));

        $this->as($this->staff());
        $this->assertSame([$sam->id], array_column($this->getJson("/api/customer-notifications?customer_id={$sam->id}")->assertOk()->json('data.notifications'), 'customer_id'));
    }

    public function test_waiver_links_and_party_invitations_need_the_booking_or_a_confirmed_email(): void
    {
        $package = Package::create([
            'location_id' => $this->location->id,
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
        $booking = Booking::create([
            'reference_number' => 'BK' . strtoupper(uniqid()),
            'location_id' => $this->location->id,
            'package_id' => $package->id,
            'guest_name' => 'Pat Guest',
            'guest_email' => 'pat@example.com',
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
        $pat = $this->customer('pat@example.com');
        config(['gmail.credentials.client_email' => 'robot@example.iam.gserviceaccount.com', 'gmail.credentials.private_key' => 'unused']);

        $this->as($pat);
        $this->assertSame([], $this->getJson("/api/customer-bookings/waivers?ids[]={$booking->id}")->assertOk()->json('data.bookings'));
        $this->getJson("/api/bookings/{$booking->id}/invitations")->assertForbidden();

        $pat->forceFill(['email_verified_at' => now()])->save();
        $this->as($pat);
        $this->assertSame([$booking->id], array_column($this->getJson("/api/customer-bookings/waivers?ids[]={$booking->id}")->assertOk()->json('data.bookings'), 'booking_id'));
        $this->getJson("/api/bookings/{$booking->id}/invitations")->assertOk();
    }

    private function customer(string $email): Customer
    {
        return Customer::create([
            'first_name' => 'Guest',
            'last_name' => ucfirst(strstr($email, '@', true)),
            'email' => $email,
            'phone' => '7345550000',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);
    }

    private function staff(): User
    {
        return User::create([
            'first_name' => 'Desk',
            'last_name' => 'Manager',
            'email' => 'manager.' . uniqid() . '@zapzone.test',
            'password' => 'secret-password',
            'role' => 'location_manager',
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'status' => 'active',
        ]);
    }

    private function order(array $attributes): TicketOrder
    {
        return TicketOrder::create(array_merge([
            'reference_number' => 'TO' . strtoupper(uniqid()),
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'guest_name' => 'Guest',
            'purchase_date' => now()->toDateString(),
            'item_count' => 1,
            'ticket_count' => 1,
            'subtotal' => 10,
            'total_amount' => 10,
            'amount_paid' => 10,
            'payment_method' => 'in-store',
            'status' => 'confirmed',
        ], $attributes));
    }

    private function notice(Customer $customer): CustomerNotification
    {
        return CustomerNotification::create([
            'customer_id' => $customer->id,
            'location_id' => $this->location->id,
            'type' => 'general',
            'priority' => 'medium',
            'title' => 'Your booking',
            'message' => 'Details inside',
            'status' => 'unread',
        ]);
    }

    private function as(Customer|User $who): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeaders(['Authorization' => 'Bearer ' . $who->createToken('test')->plainTextToken, 'Accept' => 'application/json']);
    }
}
