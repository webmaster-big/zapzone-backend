<?php

namespace Tests\Feature;

use App\Models\Attraction;
use App\Models\Booking;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\GiftCard;
use App\Models\GoogleCalendarSetting;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Package;
use App\Models\PackageTimeSlot;
use App\Models\Payment;
use App\Models\Promo;
use App\Models\Room;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccessHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $location;

    private Location $otherLocation;

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
        $this->location = $this->makeLocation('Brighton', 'brighton@zapzone.test');
        $this->otherLocation = $this->makeLocation('Canton', 'canton@zapzone.test');
    }

    public function test_a_customer_cannot_freeze_cancel_or_replan_someone_elses_membership(): void
    {
        $owner = $this->customer('owner@example.com');
        $stranger = $this->customer('stranger@example.com');
        $membership = $this->membership($owner);
        $premium = $this->plan('Premium', 99.00);
        $admin = $this->staff('company_admin', 'admin');

        $this->as($stranger);
        $this->patchJson("/api/memberships/{$membership->id}/freeze", ['until' => now()->addMonth()->toDateString()])->assertForbidden();
        $this->patchJson("/api/memberships/{$membership->id}/cancel", ['effective' => 'immediate'])->assertForbidden();
        $this->patchJson("/api/memberships/{$membership->id}/change-plan", ['membership_plan_id' => $premium->id])->assertForbidden();
        $this->getJson("/api/memberships/{$membership->id}/payments?user_id={$admin->id}")->assertForbidden();
        $fresh = $membership->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->canceled_at);
        $this->assertSame($membership->membership_plan_id, $fresh->membership_plan_id);

        $this->as($owner);
        $this->patchJson("/api/memberships/{$membership->id}/change-plan", ['membership_plan_id' => $premium->id])->assertForbidden();
        $this->getJson("/api/memberships/{$membership->id}/payments")->assertOk();
        $this->patchJson("/api/memberships/{$membership->id}/freeze", ['until' => now()->addMonth()->toDateString()])->assertOk();
        $this->assertSame('frozen', $membership->fresh()->status);

        $this->as($admin);
        $this->patchJson("/api/memberships/{$membership->id}/change-plan", ['membership_plan_id' => $premium->id])->assertOk();
        $this->assertSame($premium->id, (int) $membership->fresh()->membership_plan_id);
        $this->patchJson("/api/memberships/{$membership->id}/cancel", ['effective' => 'immediate'])->assertOk();
        $this->assertNotNull($membership->fresh()->canceled_at);
    }

    public function test_staff_only_manage_memberships_within_their_own_locations(): void
    {
        $membership = $this->membership($this->customer('member@example.com'));
        $premium = $this->plan('Premium', 99.00);

        $this->as($this->staff('attendant', 'cantondesk', $this->otherLocation));
        $this->patchJson("/api/memberships/{$membership->id}/freeze", ['until' => now()->addMonth()->toDateString()])->assertForbidden();
        $this->patchJson("/api/memberships/{$membership->id}/cancel", ['effective' => 'immediate'])->assertForbidden();
        $this->getJson("/api/memberships/{$membership->id}/payments")->assertForbidden();
        $this->as($this->staff('location_manager', 'cantonlead', $this->otherLocation));
        $this->patchJson("/api/memberships/{$membership->id}/change-plan", ['membership_plan_id' => $premium->id])->assertForbidden();
        $this->assertSame('active', $membership->fresh()->status);
        $this->assertNull($membership->fresh()->canceled_at);

        $this->as($this->staff('attendant', 'brightondesk'));
        $this->patchJson("/api/memberships/{$membership->id}/freeze", ['until' => now()->addMonth()->toDateString()])->assertOk();
        $this->assertSame('frozen', $membership->fresh()->status);
    }

    public function test_customer_logins_cannot_reach_staff_reports_or_email_tools(): void
    {
        $package = $this->package($this->location);
        $attraction = Attraction::create([
            'location_id' => $this->location->id,
            'name' => 'Axe Throwing',
            'description' => 'Throw axes',
            'category' => 'Activities',
            'price' => 20,
            'duration' => 30,
            'max_capacity' => 20,
            'status' => 'active',
        ]);

        $this->as($this->customer('guest@example.com'));
        foreach ([
            '/api/page-analytics/overview',
            '/api/page-analytics/events',
            '/api/page-analytics/sessions/abc',
            '/api/email-campaigns',
            '/api/email-templates',
            '/api/sms-notifications',
            '/api/authorize-net/accounts/all',
            "/api/global-notes/package/{$package->id}",
            "/api/attractions/{$attraction->id}/statistics",
        ] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        $this->postJson('/api/email-campaigns/preview-recipients', ['recipient_types' => ['customers']])->assertForbidden();
        $this->postJson('/api/email-campaigns/send-test', ['email' => 'me@example.com'])->assertForbidden();
        $this->postJson('/api/authorize-net/test-connection', [])->assertForbidden();

        $this->as($this->staff('company_admin', 'admin'));
        $this->getJson('/api/page-analytics/overview')->assertOk();
        $this->getJson('/api/email-templates')->assertOk();
    }

    public function test_the_public_time_slot_list_hides_booking_details_and_staff_only_see_their_own_location(): void
    {
        $here = $this->slot($this->location, 'Allergy: peanuts');
        $there = $this->slot($this->otherLocation, 'VIP guest');

        $this->as(null);
        $listed = $this->getJson('/api/package-time-slots?per_page=100')->assertOk()->json('data.time_slots');
        $this->assertCount(2, $listed);
        foreach ($listed as $slot) {
            foreach (['notes', 'customer_id', 'booking_id', 'user_id'] as $private) {
                $this->assertArrayNotHasKey($private, $slot);
            }
        }
        $this->assertArrayNotHasKey('notes', $this->getJson("/api/package-time-slots/{$here->id}")->assertOk()->json('data'));
        $this->postJson('/api/package-time-slots', [
            'package_id' => $here->package_id,
            'room_id' => $here->room_id,
            'booking_id' => $here->booking_id,
            'customer_id' => $this->customer('slot@example.com')->id,
            'booked_date' => now()->addDays(9)->toDateString(),
            'time_slot_start' => '10:00',
            'duration' => 1,
            'duration_unit' => 'hours',
        ])->assertForbidden();
        $this->assertSame(2, PackageTimeSlot::count(), 'nobody can fill the calendar with fake booked slots');

        $this->as($this->staff('location_manager', 'brightonlead'));
        $mine = $this->getJson('/api/package-time-slots?per_page=100')->assertOk()->json('data.time_slots');
        $this->assertSame([$here->id], array_column($mine, 'id'));
        $this->assertSame('Allergy: peanuts', $mine[0]['notes']);
        $this->getJson("/api/package-time-slots/{$there->id}")->assertNotFound();
    }

    public function test_staff_search_and_payment_records_stay_within_the_callers_location(): void
    {
        $here = $this->booking($this->location, 'Taylor Here');
        $there = $this->booking($this->otherLocation, 'Taylor There');
        $herePayment = $this->payment($here);
        $therePayment = $this->payment($there);
        $herePayment->delete();
        $therePayment->delete();
        $liveThere = $this->payment($there);

        $this->as($this->staff('location_manager', 'brightonlead'));

        $found = $this->getJson('/api/bookings/search?query=Taylor')->assertOk()->json('data');
        $this->assertSame([$here->id], array_column($found, 'id'));
        $this->getJson("/api/payments/{$liveThere->id}")->assertForbidden();
        $this->assertSame([$herePayment->id], array_column($this->getJson('/api/payments/trashed?per_page=100')->assertOk()->json('data.payments') ?? $this->getJson('/api/payments/trashed?per_page=100')->json('data.data'), 'id'));
        $this->postJson('/api/payments/invoices/bulk', ['payment_ids' => [$liveThere->id]])->assertNotFound();
    }

    public function test_deleting_a_staff_account_keeps_the_gift_cards_they_sold_and_waits_while_they_own_promo_codes(): void
    {
        $admin = $this->staff('company_admin', 'admin');
        $seller = $this->staff('attendant', 'seller');
        $promoter = $this->staff('attendant', 'promoter');
        $leaver = $this->staff('attendant', 'leaver');
        $card = GiftCard::create([
            'code' => 'GC-KEEPME',
            'type' => 'fixed',
            'initial_value' => 50,
            'balance' => 50,
            'status' => 'active',
            'max_usage' => 1,
            'description' => 'Desk sale',
            'location_id' => $this->location->id,
            'created_by' => $seller->id,
            'deleted' => false,
        ]);
        $promo = Promo::create([
            'code' => 'SPRING',
            'name' => 'Spring',
            'type' => 'fixed',
            'value' => 10,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'usage_limit_per_user' => 1,
            'status' => 'active',
            'created_by' => $promoter->id,
            'location_ids' => null,
            'deleted' => false,
        ]);

        $this->as($admin);
        $this->deleteJson("/api/users/{$seller->id}")->assertOk();
        $this->assertNull(User::find($seller->id));
        $this->assertNotNull($card->fresh(), "a guest's gift card outlives the account that sold it");
        $this->assertNull($card->fresh()->created_by);

        $this->deleteJson("/api/users/{$promoter->id}")->assertStatus(409);
        $this->assertNotNull(User::find($promoter->id));
        $this->assertNotNull($promo->fresh(), 'live promo codes are never deleted along with an account');

        $this->postJson('/api/users/bulk-delete', ['ids' => [$promoter->id, $leaver->id]])
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 1)
            ->assertJsonPath('data.kept_count', 1);
        $this->assertNull(User::find($leaver->id));
        $this->assertNotNull(User::find($promoter->id));
    }

    public function test_sign_in_attempts_are_rate_limited_per_email(): void
    {
        $this->withMiddleware(ThrottleRequests::class);
        $this->staff('attendant', 'target');

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/login', ['email' => 'target@zapzone.test', 'password' => 'wrong-password-' . $attempt])->assertStatus(401);
        }

        $this->postJson('/api/login', ['email' => 'target@zapzone.test', 'password' => 'secret-password'])->assertStatus(429);
        $this->postJson('/api/customer-login', ['email' => 'target@zapzone.test', 'password' => 'whatever-password'])->assertStatus(429);
        $this->postJson('/api/login', ['email' => 'someone-else@zapzone.test', 'password' => 'wrong-password'])->assertStatus(401);
    }

    public function test_changing_a_password_signs_out_every_other_session(): void
    {
        $staff = $this->staff('location_manager', 'lead');
        $here = $staff->createToken('this-browser')->plainTextToken;
        $elsewhere = $staff->createToken('lost-phone')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$here}", 'Accept' => 'application/json'])
            ->patchJson("/api/users/{$staff->id}/update-password", [
                'current_password' => 'secret-password',
                'new_password' => 'a-brand-new-password',
                'new_password_confirmation' => 'a-brand-new-password',
            ])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeaders(['Authorization' => "Bearer {$elsewhere}", 'Accept' => 'application/json'])->getJson("/api/users/{$staff->id}")->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeaders(['Authorization' => "Bearer {$here}", 'Accept' => 'application/json'])->getJson("/api/users/{$staff->id}")->assertOk();
    }

    public function test_google_calendar_sign_in_needs_a_fresh_signed_link_for_a_location_the_caller_runs(): void
    {
        $forged = base64_encode(json_encode(['location_id' => $this->otherLocation->id]));
        $redirect = $this->get('/api/google-calendar/callback?code=attacker-code&state=' . urlencode($forged))->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('expired', urldecode((string) $redirect));
        $this->assertSame(0, GoogleCalendarSetting::where('location_id', $this->otherLocation->id)->where('is_connected', true)->count());

        $state = GoogleCalendarService::signedState($this->location->id);
        $this->assertSame($this->location->id, GoogleCalendarService::locationFromState($state));
        $this->assertNull(GoogleCalendarService::locationFromState($state), 'a sign-in link works once');

        $this->as($this->staff('location_manager', 'brightonlead'));
        $this->getJson("/api/google-calendar/auth-url?location_id={$this->otherLocation->id}")->assertForbidden();
        $this->postJson('/api/google-calendar/disconnect', ['location_id' => $this->otherLocation->id])->assertForbidden();
        $this->putJson('/api/google-calendar/calendar', ['location_id' => $this->otherLocation->id, 'calendar_id' => 'mine'])->assertForbidden();
    }

    public function test_a_pin_terminal_signed_in_as_an_attendant_cannot_manage_staff_or_send_invitations(): void
    {
        $admin = $this->staff('company_admin', 'admin');
        $attendant = $this->staff('attendant', 'desk');
        $terminal = $admin->createToken('front-desk-terminal', ['role:attendant'])->plainTextToken;
        $this->withHeaders(['Authorization' => "Bearer {$terminal}", 'Accept' => 'application/json']);

        $this->deleteJson("/api/users/{$attendant->id}")->assertForbidden();
        $this->patchJson("/api/users/{$attendant->id}/toggle-status")->assertForbidden();
        $this->postJson('/api/shareable-tokens', ['email' => 'new.hire@example.com', 'role' => 'attendant'])->assertForbidden();
        $this->assertNotNull(User::find($attendant->id));
        $this->assertSame('active', $attendant->fresh()->status);
    }

    public function test_staff_removing_a_booking_through_the_shared_route_still_give_a_reason_first(): void
    {
        $booking = $this->booking($this->location, 'Reason Guest');
        $this->as($this->staff('location_manager', 'brightonlead'));

        $this->deleteJson("/api/bookings/{$booking->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('change_reason');
        $this->assertNotNull(Booking::find($booking->id));

        $this->deleteJson("/api/bookings/{$booking->id}", ['change_reason' => 'Guest called to cancel'])->assertOk();
        $this->assertTrue(Booking::withTrashed()->findOrFail($booking->id)->trashed());
    }

    public function test_staff_cannot_read_or_change_another_locations_customer_notifications(): void
    {
        $customer = $this->customer('member@example.com');
        $theirs = CustomerNotification::create([
            'customer_id' => $customer->id,
            'location_id' => $this->otherLocation->id,
            'type' => 'general',
            'priority' => 'medium',
            'title' => 'Canton news',
            'message' => 'Only Canton staff should handle this.',
            'status' => 'unread',
        ]);

        $this->as($this->staff('location_manager', 'brightonlead'));
        $this->getJson("/api/customer-notifications/{$theirs->id}")->assertNotFound();
        $this->patchJson("/api/customer-notifications/{$theirs->id}/mark-as-read")->assertNotFound();
        $this->deleteJson("/api/customer-notifications/{$theirs->id}")->assertNotFound();
        $this->patchJson("/api/customer-notifications/mark-all-as-read/{$customer->id}")->assertOk();
        $this->assertSame('unread', $theirs->fresh()->status);
        $this->assertSame(0, $this->getJson("/api/customer-notifications/unread-count/{$customer->id}")->assertOk()->json('unread_count'));
    }

    public function test_desks_at_one_venue_each_get_their_own_checkout_rate_limit(): void
    {
        $this->withMiddleware(ThrottleRequests::class);
        $first = $this->staff('attendant', 'deskone');
        $second = $this->staff('attendant', 'desktwo');

        $this->as($first);
        for ($order = 1; $order <= 10; $order++) {
            $this->postJson('/api/ticket-orders', [])->assertStatus(422);
        }
        $this->postJson('/api/ticket-orders', [])->assertStatus(429);

        $this->as($second);
        $this->postJson('/api/ticket-orders', [])->assertStatus(422);
    }

    private function package(Location $location): Package
    {
        return Package::create([
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
    }

    private function booking(Location $location, string $guest): Booking
    {
        return Booking::create([
            'reference_number' => 'BK' . strtoupper(uniqid()),
            'location_id' => $location->id,
            'package_id' => $this->package($location)->id,
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
            'status' => 'confirmed',
            'payment_method' => 'in-store',
        ]);
    }

    private function slot(Location $location, string $notes): PackageTimeSlot
    {
        $booking = $this->booking($location, 'Slot Guest');
        $room = Room::create(['location_id' => $location->id, 'name' => 'Room ' . uniqid(), 'capacity' => 20, 'is_available' => true]);

        return PackageTimeSlot::create([
            'package_id' => $booking->package_id,
            'room_id' => $room->id,
            'booking_id' => $booking->id,
            'customer_id' => $this->customer('slot.' . uniqid() . '@example.com')->id,
            'booked_date' => now()->addDays(7)->toDateString(),
            'time_slot_start' => '14:00',
            'duration' => 2,
            'duration_unit' => 'hours',
            'status' => 'booked',
            'notes' => $notes,
        ]);
    }

    private function payment(Booking $booking): Payment
    {
        return Payment::create([
            'location_id' => $booking->location_id,
            'amount' => 50,
            'currency' => 'USD',
            'method' => 'in-store',
            'status' => 'completed',
            'transaction_id' => 'TXN' . uniqid(),
            'payable_type' => Payment::TYPE_BOOKING,
            'payable_id' => $booking->id,
            'paid_at' => now(),
        ]);
    }

    private function makeLocation(string $city, string $email): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'name' => "{$city} | Zap Zone",
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

    private function staff(string $role, string $handle, ?Location $location = null): User
    {
        return User::create([
            'first_name' => ucfirst($handle),
            'last_name' => 'Staff',
            'email' => "{$handle}@zapzone.test",
            'password' => 'secret-password',
            'role' => $role,
            'company_id' => $this->company->id,
            'location_id' => ($location ?? $this->location)->id,
            'status' => 'active',
        ]);
    }

    private function plan(string $name, float $price): MembershipPlan
    {
        return MembershipPlan::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'name' => $name,
            'slug' => strtolower($name) . '-' . uniqid(),
            'tier' => 'basic',
            'price' => $price,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);
    }

    private function membership(Customer $customer): Membership
    {
        $plan = $this->plan('Local Explorer', 29.99);

        return Membership::create([
            'customer_id' => $customer->id,
            'membership_plan_id' => $plan->id,
            'home_location_id' => $this->location->id,
            'status' => 'active',
            'billing_amount' => $plan->price,
        ]);
    }

    private function as(Customer|User|null $who): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        if ($who) {
            $this->withHeaders(['Authorization' => 'Bearer ' . $who->createToken('test')->plainTextToken, 'Accept' => 'application/json']);
        }
    }
}
