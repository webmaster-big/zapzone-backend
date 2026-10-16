<?php

namespace Tests\Feature;

use App\Models\Attraction;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventPurchase;
use App\Models\Location;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class StaffLocationScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Location $brighton;
    private Location $canton;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false, 'google_calendar.auto_sync' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'owner@zapzone.test',
            'phone' => '5551230000',
            'address' => '1 Main St',
        ]);
        $this->brighton = $this->location('Brighton', 'brighton@zapzone.test');
        $this->canton = $this->location('Canton', 'canton@zapzone.test');
    }

    private function location(string $city, string $email): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'name' => $city,
            'address' => '1 Test Way',
            'city' => $city,
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => $email,
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    private function staff(string $role, string $handle, Location $location): User
    {
        return User::create([
            'first_name' => ucfirst($handle),
            'last_name' => 'Staff',
            'email' => "{$handle}@zapzone.test",
            'password' => 'secret-password',
            'role' => $role,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'status' => 'active',
        ]);
    }

    private function as(User $who): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $who->createToken('test')->plainTextToken, 'Accept' => 'application/json']);
    }

    private function booking(Location $location, string $guest, string $status = 'pending'): Booking
    {
        $package = Package::create([
            'location_id' => $location->id,
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

        return Booking::create([
            'reference_number' => 'BK' . strtoupper(uniqid()),
            'location_id' => $location->id,
            'package_id' => $package->id,
            'guest_name' => $guest,
            'guest_email' => strtolower(str_replace(' ', '.', $guest)) . '@example.com',
            'booking_date' => now()->addDays(7)->toDateString(),
            'booking_time' => '14:00',
            'participants' => 8,
            'duration' => 120,
            'duration_unit' => 'minutes',
            'total_amount' => 199.00,
            'amount_paid' => 0,
            'payment_status' => 'pending',
            'status' => $status,
            'payment_method' => 'in-store',
        ]);
    }

    private function attractionPurchase(Location $location, string $guest): AttractionPurchase
    {
        $attraction = Attraction::create([
            'location_id' => $location->id,
            'name' => 'Laser Tag ' . $location->name,
            'description' => 'Arena laser tag',
            'category' => 'Attractions',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);

        return AttractionPurchase::create([
            'attraction_id' => $attraction->id,
            'guest_name' => $guest,
            'guest_email' => strtolower(str_replace(' ', '.', $guest)) . '@example.com',
            'quantity' => 2,
            'total_amount' => 40,
            'amount_paid' => 0,
            'purchase_date' => now()->toDateString(),
            'payment_status' => 'pending',
            'status' => AttractionPurchase::STATUS_PENDING,
        ]);
    }

    private function eventPurchase(Location $location, string $guest): EventPurchase
    {
        $event = Event::create([
            'location_id' => $location->id,
            'name' => 'Glow Night ' . $location->name,
            'date_type' => 'one_time',
            'start_date' => now()->addDays(3)->toDateString(),
            'time_start' => '18:00',
            'time_end' => '21:00',
            'interval_minutes' => 60,
            'price' => 15,
            'is_active' => true,
        ]);

        return EventPurchase::create([
            'reference_number' => 'EV' . strtoupper(uniqid()),
            'event_id' => $event->id,
            'location_id' => $location->id,
            'guest_name' => $guest,
            'guest_email' => strtolower(str_replace(' ', '.', $guest)) . '@example.com',
            'purchase_date' => now()->addDays(3)->toDateString(),
            'purchase_time' => '18:00',
            'quantity' => 2,
            'total_amount' => 30,
            'status' => 'pending',
        ]);
    }

    public function test_managers_and_attendants_cannot_delete_or_purge_another_locations_bookings(): void
    {
        $theirs = $this->booking($this->canton, 'Canton Guest');
        $mine = $this->booking($this->brighton, 'Brighton Guest');
        $trashed = $this->booking($this->canton, 'Canton Trashed');
        $trashed->delete();
        $reason = ['change_reason' => 'Guest asked to cancel'];

        foreach ([$this->staff('location_manager', 'brightonlead', $this->brighton), $this->staff('attendant', 'brightondesk', $this->brighton)] as $who) {
            $this->as($who)->deleteJson("/api/bookings/{$theirs->id}", $reason)->assertForbidden();
            $this->as($who)->postJson('/api/bookings/bulk-delete', ['ids' => [$mine->id, $theirs->id]] + $reason)->assertForbidden();
            $this->as($who)->deleteJson("/api/bookings/{$theirs->id}/force-delete", $reason)->assertForbidden();
            $this->as($who)->deleteJson("/api/bookings/{$trashed->id}/force-delete", $reason)->assertForbidden();
        }

        $this->assertNotNull(Booking::find($theirs->id));
        $this->assertNotNull(Booking::find($mine->id), 'a bulk delete that reaches another location deletes nothing');
        $this->assertNotNull(Booking::withTrashed()->find($trashed->id));

        $this->as($this->staff('attendant', 'brightonclerk', $this->brighton))->deleteJson("/api/bookings/{$mine->id}", $reason)->assertOk();
        $this->as($this->staff('company_admin', 'owner', $this->brighton))->deleteJson("/api/bookings/{$theirs->id}", $reason)->assertOk();
        $this->assertNull(Booking::find($theirs->id));
    }

    public function test_a_manager_who_also_runs_the_other_location_is_told_to_switch_to_it(): void
    {
        $theirs = $this->booking($this->canton, 'Canton Guest');
        $manager = $this->staff('location_manager', 'twosites', $this->brighton);
        $manager->locations()->attach([$this->brighton->id, $this->canton->id]);

        $this->as($manager)->deleteJson("/api/bookings/{$theirs->id}", ['change_reason' => 'Duplicate'])
            ->assertForbidden()
            ->assertJsonPath('switch_location_id', $this->canton->id);

        $this->assertNotNull(Booking::find($theirs->id));
    }

    public function test_managers_and_attendants_cannot_open_change_or_delete_another_locations_attraction_purchases(): void
    {
        $theirs = $this->attractionPurchase($this->canton, 'Canton Guest');
        $mine = $this->attractionPurchase($this->brighton, 'Brighton Guest');

        foreach ([$this->staff('location_manager', 'brightonlead', $this->brighton), $this->staff('attendant', 'brightondesk', $this->brighton)] as $who) {
            $this->as($who)->getJson("/api/attraction-purchases/{$theirs->id}")->assertForbidden();
            $this->as($who)->putJson("/api/attraction-purchases/{$theirs->id}", ['guest_name' => 'Changed Elsewhere'])->assertForbidden();
            $this->as($who)->deleteJson("/api/attraction-purchases/{$theirs->id}")->assertForbidden();
            $this->as($who)->postJson('/api/attraction-purchases/bulk-delete', ['ids' => [$mine->id, $theirs->id]])->assertForbidden();
            $this->as($who)->deleteJson("/api/attraction-purchases/{$theirs->id}/force-delete")->assertForbidden();
            $this->as($who)->getJson('/api/attraction-purchases/statistics')
                ->assertOk()
                ->assertJsonPath('data.total_purchases', 1);
        }

        $this->assertSame('Canton Guest', AttractionPurchase::findOrFail($theirs->id)->guest_name);
        $this->assertNotNull(AttractionPurchase::find($mine->id));

        $owner = $this->staff('company_admin', 'owner', $this->brighton);
        $this->as($owner)->getJson("/api/attraction-purchases/{$theirs->id}")->assertOk();
        $this->as($owner)->getJson('/api/attraction-purchases/statistics')->assertOk()->assertJsonPath('data.total_purchases', 2);
        $this->as($this->staff('attendant', 'brightonclerk', $this->brighton))->getJson("/api/attraction-purchases/{$mine->id}")->assertOk();
    }

    public function test_managers_and_attendants_cannot_open_or_delete_another_locations_event_purchases(): void
    {
        $theirs = $this->eventPurchase($this->canton, 'Canton Guest');
        $mine = $this->eventPurchase($this->brighton, 'Brighton Guest');

        foreach ([$this->staff('location_manager', 'brightonlead', $this->brighton), $this->staff('attendant', 'brightondesk', $this->brighton)] as $who) {
            $this->as($who)->getJson("/api/event-purchases/{$theirs->id}")->assertForbidden();
            $this->as($who)->deleteJson("/api/event-purchases/{$theirs->id}")->assertForbidden();
            $this->as($who)->deleteJson("/api/event-purchases/{$theirs->id}/force-delete")->assertForbidden();
        }

        $this->assertNotNull(EventPurchase::find($theirs->id));
        $this->as($this->staff('attendant', 'brightonclerk', $this->brighton))->getJson("/api/event-purchases/{$mine->id}")->assertOk();
        $this->as($this->staff('company_admin', 'owner', $this->brighton))->deleteJson("/api/event-purchases/{$theirs->id}")->assertOk();
        $this->assertNull(EventPurchase::find($theirs->id));
    }

    public function test_a_staff_member_can_still_roll_back_their_own_checkout_at_another_location(): void
    {
        $booking = $this->booking($this->canton, 'Booked From The Storefront');
        $tickets = $this->attractionPurchase($this->canton, 'Tickets From The Storefront');
        $party = $this->eventPurchase($this->canton, 'Party From The Storefront');
        $desk = $this->staff('attendant', 'brightondesk', $this->brighton);

        $this->as($desk)->deleteJson("/api/bookings/{$booking->id}/force-delete", ['checkout_token' => \App\Support\CheckoutToken::for('booking', $booking->id)])->assertOk();
        $this->as($desk)->deleteJson("/api/attraction-purchases/{$tickets->id}/force-delete", ['checkout_token' => \App\Support\CheckoutToken::for('attraction_purchase', $tickets->id)])->assertOk();
        $this->as($desk)->deleteJson("/api/event-purchases/{$party->id}/force-delete", ['checkout_token' => \App\Support\CheckoutToken::for('event_purchase', $party->id)])->assertOk();

        $this->assertNull(Booking::withTrashed()->find($booking->id));
        $this->assertNull(AttractionPurchase::withTrashed()->find($tickets->id));
        $this->assertNull(EventPurchase::withTrashed()->find($party->id));
    }

    public function test_a_purchase_cannot_be_moved_to_another_locations_attraction(): void
    {
        $mine = $this->attractionPurchase($this->brighton, 'Brighton Guest');
        $elsewhere = $this->attractionPurchase($this->canton, 'Canton Guest')->attraction_id;
        $manager = $this->staff('location_manager', 'brightonlead', $this->brighton);

        $this->as($manager)->putJson("/api/attraction-purchases/{$mine->id}", ['attraction_id' => $elsewhere])->assertForbidden();

        $this->assertNotSame($elsewhere, AttractionPurchase::findOrFail($mine->id)->attraction_id);
    }

    public function test_another_locations_attraction_tickets_cannot_be_confirmed_cancelled_checked_in_or_listed(): void
    {
        $customer = \App\Models\Customer::create([
            'first_name' => 'Two',
            'last_name' => 'Venues',
            'email' => 'two.venues@example.com',
            'phone' => '7345550000',
            'password' => bcrypt('secret-password'),
            'status' => 'active',
        ]);
        $theirs = $this->attractionPurchase($this->canton, 'Canton Guest');
        $theirs->update(['customer_id' => $customer->id]);
        $mine = $this->attractionPurchase($this->brighton, 'Brighton Guest');
        $mine->update(['customer_id' => $customer->id]);

        foreach ([$this->staff('location_manager', 'brightonlead', $this->brighton), $this->staff('attendant', 'brightondesk', $this->brighton)] as $who) {
            $this->as($who)->patchJson("/api/attraction-purchases/{$theirs->id}/update-status", ['status' => AttractionPurchase::STATUS_CANCELLED])->assertForbidden();
            $this->as($who)->patchJson("/api/attraction-purchases/{$theirs->id}/confirm")->assertForbidden();
            $this->as($who)->patchJson("/api/attraction-purchases/{$theirs->id}/cancel")->assertForbidden();
            $this->as($who)->patchJson("/api/attraction-purchases/{$theirs->id}/check-in")->assertForbidden();
            $this->assertSame([$mine->id], array_column($this->as($who)->getJson("/api/attraction-purchases/customer/{$customer->id}")->assertOk()->json('data'), 'id'));
            $this->assertSame([], $this->as($who)->getJson("/api/attraction-purchases/attraction/{$theirs->attraction_id}")->assertOk()->json('data'));
        }

        $this->assertSame(AttractionPurchase::STATUS_PENDING, AttractionPurchase::findOrFail($theirs->id)->status);
        $this->assertCount(2, $this->as($this->staff('company_admin', 'owner', $this->brighton))->getJson("/api/attraction-purchases/customer/{$customer->id}")->assertOk()->json('data'));
    }


    public function test_a_checkout_token_only_lets_staff_remove_an_unfinished_checkout_elsewhere(): void
    {
        $unfinished = $this->booking($this->canton, 'Unfinished Checkout');
        $confirmed = $this->booking($this->canton, 'Confirmed Party', 'confirmed');
        $desk = $this->staff('attendant', 'brightondesk', $this->brighton);
        $reason = ['change_reason' => 'Rolling back'];

        $this->as($desk)->deleteJson("/api/bookings/{$confirmed->id}", $reason + ['checkout_token' => \App\Support\CheckoutToken::for('booking', $confirmed->id)])->assertForbidden();
        $this->assertNotNull(Booking::find($confirmed->id));

        $this->as($desk)->deleteJson("/api/bookings/{$unfinished->id}", $reason + ['checkout_token' => \App\Support\CheckoutToken::for('booking', $unfinished->id)])->assertOk();
        $this->assertNull(Booking::find($unfinished->id));
    }


    public function test_another_locations_booking_cannot_be_cancelled_marked_paid_or_listed_by_day(): void
    {
        $theirs = $this->booking($this->canton, 'Canton Guest', 'confirmed');
        $mine = $this->booking($this->brighton, 'Brighton Guest', 'confirmed');
        $day = $theirs->booking_date instanceof \DateTimeInterface ? $theirs->booking_date->format('Y-m-d') : (string) $theirs->booking_date;

        foreach ([$this->staff('location_manager', 'brightonlead', $this->brighton), $this->staff('attendant', 'brightondesk', $this->brighton)] as $who) {
            $this->as($who)->patchJson("/api/bookings/{$theirs->id}/cancel", ['change_reason' => 'Wrong venue'])->assertForbidden();
            $this->as($who)->patchJson("/api/bookings/{$theirs->id}/payment-status", ['payment_status' => 'paid', 'change_reason' => 'Paid at the desk'])->assertForbidden();
            $this->as($who)->getJson("/api/bookings/location-date?location_id={$this->canton->id}&date={$day}")->assertForbidden();
            $this->as($who)->getJson("/api/bookings/location-date?location_id={$this->brighton->id}&date={$day}")->assertOk();
        }

        $this->assertSame('confirmed', Booking::findOrFail($theirs->id)->status);
        $this->assertSame('pending', Booking::findOrFail($theirs->id)->payment_status);
        $this->as($this->staff('attendant', 'brightonclerk', $this->brighton))->patchJson("/api/bookings/{$mine->id}/cancel", ['change_reason' => 'Guest called'])->assertOk();
    }

    public function test_an_owner_signed_in_by_pin_at_a_managers_terminal_works_at_that_terminals_location(): void
    {
        $owner = User::create([
            'first_name' => 'Owner',
            'last_name' => 'Staff',
            'email' => 'pinowner@zapzone.test',
            'password' => 'secret-password',
            'role' => 'company_admin',
            'company_id' => $this->company->id,
            'location_id' => null,
            'status' => 'active',
        ]);
        $terminal = new \App\Models\StaffTerminal();
        $terminal->forceFill([
            'company_id' => $this->company->id,
            'location_id' => $this->brighton->id,
            'device_id' => 'brighton-front-desk',
            'label' => 'Brighton front desk',
            'token_hash' => hash('sha256', 'terminal-secret'),
            'ceiling_role' => 'location_manager',
        ])->save();
        $theirs = $this->booking($this->canton, 'Canton Guest', 'confirmed');
        $mine = $this->booking($this->brighton, 'Brighton Guest', 'confirmed');
        $ticket = $this->attractionPurchase($this->brighton, 'Brighton Ticket');

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeaders(['Authorization' => 'Bearer ' . $owner->createToken('pin:brighton-front-desk', ['pin', 'role:location_manager', 'terminal:' . $terminal->id])->plainTextToken, 'Accept' => 'application/json']);

        $this->patchJson("/api/bookings/{$theirs->id}/cancel", ['change_reason' => 'Wrong venue'])->assertForbidden();
        $this->getJson("/api/attraction-purchases/{$ticket->id}")->assertOk();
        $this->patchJson("/api/attraction-purchases/{$ticket->id}/confirm")->assertOk();
        $this->patchJson("/api/attraction-purchases/{$ticket->id}/check-in")->assertOk();
        $this->patchJson("/api/bookings/{$mine->id}/cancel", ['change_reason' => 'Guest called'])->assertOk();
        $this->assertSame('confirmed', Booking::findOrFail($theirs->id)->status);

        $terminal->forceFill(['revoked_at' => now()])->save();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/attraction-purchases/{$ticket->id}")->assertForbidden();
    }
}
