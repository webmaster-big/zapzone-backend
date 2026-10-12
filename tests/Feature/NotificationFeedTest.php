<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NotificationFeedTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $brighton;

    private Location $canton;

    private Location $rival;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->company = $this->makeCompany('Zap Zone');
        $this->brighton = $this->makeLocation($this->company, 'Brighton');
        $this->canton = $this->makeLocation($this->company, 'Canton');
        $this->rival = $this->makeLocation($this->makeCompany('Other Arcade'), 'Toledo');
    }

    public function test_the_feed_needs_a_staff_login(): void
    {
        $this->getJson('/api/notifications/feed')->assertUnauthorized();

        $customer = Customer::create([
            'first_name' => 'Pat',
            'last_name' => 'Guest',
            'email' => 'pat@example.com',
            'phone' => '7345550000',
            'password' => Hash::make('secret-password'),
            'status' => 'active',
        ]);

        $this->as($customer->createToken('customer')->plainTextToken)->getJson('/api/notifications/feed')->assertForbidden();
    }

    public function test_a_fresh_feed_sends_nothing_old_but_reports_the_unread_count(): void
    {
        $this->notify($this->brighton, 'New Booking Received');
        $this->notify($this->brighton, 'Gift card sold', 'gift_card');
        $this->notify($this->brighton, 'Payment Received', 'payment', ['status' => 'read']);

        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $feed = $this->feed($token, ['count' => 1]);

        $this->assertSame([], $feed['items']);
        $this->assertSame('n' . Notification::max('id'), $feed['cursor']);
        $this->assertSame(2, $feed['unread']);

        $quiet = $this->feed($token, ['after' => $feed['cursor']]);
        $this->assertSame([], $quiet['items']);
        $this->assertArrayNotHasKey('unread', $quiet, 'a quiet poll that did not ask for the count skips counting');
    }

    public function test_the_feed_delivers_every_kind_of_new_notification_once_with_its_own_wording(): void
    {
        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $cursor = $this->feed($token)['cursor'];

        $booking = $this->notify($this->brighton, 'New Booking Received', 'booking', ['message' => 'Taylor — Oct 7 at 1:00 PM • $30.00']);
        $gift = $this->notify($this->brighton, 'Gift card sold', 'gift_card', ['message' => 'A $50.00 gift card (GC-1) was sold at Brighton.', 'priority' => 'low']);
        $refund = $this->notify($this->brighton, 'Payment Refunded', 'payment', ['priority' => 'high']);
        $alert = $this->notify($this->brighton, 'Card payment needs checking in Authorize.Net', 'payment', ['priority' => 'high', 'user_id' => null]);
        $this->notify($this->canton, 'New Booking Received');
        $this->notify($this->rival, 'New Booking Received');

        $feed = $this->feed($token, ['after' => $cursor]);

        $this->assertSame([$booking->id, $gift->id, $refund->id, $alert->id], array_column($feed['items'], 'id'));
        $this->assertSame(
            ['id', 'type', 'priority', 'title', 'message', 'user_id', 'location_id', 'created_at'],
            array_keys($feed['items'][0])
        );
        $this->assertSame('Gift card sold', $feed['items'][1]['title']);
        $this->assertSame('A $50.00 gift card (GC-1) was sold at Brighton.', $feed['items'][1]['message']);
        $this->assertSame('gift_card', $feed['items'][1]['type']);
        $this->assertSame('n' . Notification::max('id'), $feed['cursor']);
        $this->assertSame(4, $feed['unread'], 'new items always come with the bell count');
        $this->assertSame([], $this->feed($token, ['after' => $feed['cursor']])['items']);
    }

    public function test_a_manager_only_ever_sees_their_own_location(): void
    {
        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $cursor = $this->feed($token)['cursor'];

        $here = $this->notify($this->brighton, 'New Booking Received');
        $this->notify($this->canton, 'New Booking Received');

        $this->assertSame([$here->id], array_column($this->feed($token, ['after' => $cursor])['items'], 'id'));
        $this->assertSame([], $this->feed($token, ['after' => $cursor, 'location_id' => $this->canton->id])['items']);
    }

    public function test_a_company_admin_sees_the_whole_company_but_never_another_company(): void
    {
        $token = $this->tokenFor($this->staff('company_admin', $this->brighton));
        $cursor = $this->feed($token)['cursor'];

        $here = $this->notify($this->brighton, 'New Booking Received');
        $there = $this->notify($this->canton, 'Payment Refunded', 'payment');
        $this->notify($this->rival, 'New Booking Received');

        $this->assertSame([$here->id, $there->id], array_column($this->feed($token, ['after' => $cursor])['items'], 'id'));
        $this->assertSame([$there->id], array_column($this->feed($token, ['after' => $cursor, 'location_id' => $this->canton->id])['items'], 'id'));
        $this->assertSame([], $this->feed($token, ['after' => $cursor, 'location_id' => $this->rival->id])['items']);
    }

    public function test_the_unread_count_matches_the_bell_for_every_role(): void
    {
        $this->notify($this->brighton, 'New Booking Received');
        $this->notify($this->brighton, 'Gift card sold', 'gift_card');
        $this->notify($this->canton, 'Payment Refunded', 'payment');
        $this->notify($this->canton, 'Photo storage is almost full', 'system');
        $this->notify($this->rival, 'New Booking Received');
        $this->notify($this->brighton, 'Payment Received', 'payment', ['status' => 'read']);

        foreach ([
            ['location_manager', [], []],
            ['location_manager', ['location_id' => $this->brighton->id], ['location_id' => $this->brighton->id]],
            ['attendant', ['location_id' => $this->brighton->id], ['location_id' => $this->brighton->id]],
            ['company_admin', [], []],
            ['company_admin', ['location_id' => $this->canton->id], []],
        ] as [$role, $feedQuery, $bellQuery]) {
            $token = $this->tokenFor($this->staff($role, $this->brighton));
            $bell = $this->as($token)->getJson('/api/notifications?' . http_build_query(['per_page' => 1, 'unread' => 'true'] + $bellQuery))
                ->assertOk()
                ->json('data.pagination.total');

            $this->assertSame($bell, $this->feed($token, $feedQuery + ['count' => 1])['unread'], $role . ' ' . json_encode($feedQuery));
        }

        $this->assertSame(4, $this->feed($this->tokenFor($this->staff('company_admin', $this->brighton)), ['count' => 1, 'location_id' => $this->canton->id])['unread'], 'an admin watching one location still sees the company-wide count on the bell');
    }

    public function test_a_far_behind_cursor_only_catches_up_on_the_newest_twenty(): void
    {
        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $ids = [];
        foreach (range(1, 23) as $n) {
            $ids[] = $this->notify($this->brighton, "Notice {$n}")->id;
        }

        $this->assertSame(array_slice($ids, -20), array_column($this->feed($token, ['after' => 'n0'])['items'], 'id'));
    }

    public function test_a_burst_at_other_locations_does_not_hide_a_managers_own_notification(): void
    {
        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $cursor = $this->feed($token)['cursor'];

        $mine = $this->notify($this->brighton, 'Payment Refunded', 'payment');
        foreach (range(1, 15) as $n) {
            $this->notify($this->canton, "Canton notice {$n}");
            $this->notify($this->rival, "Rival notice {$n}");
        }

        $feed = $this->feed($token, ['after' => $cursor]);

        $this->assertSame([$mine->id], array_column($feed['items'], 'id'));
        $this->assertSame('n' . Notification::max('id'), $feed['cursor']);
    }

    public function test_an_unrecognised_cursor_starts_fresh(): void
    {
        $token = $this->tokenFor($this->staff('location_manager', $this->brighton));
        $this->notify($this->brighton, 'New Booking Received');

        foreach (['b1.p0', 'garbage', 'n', 'n99999999999999999999999', ['n0']] as $after) {
            $feed = $this->feed($token, ['after' => $after]);

            $this->assertSame([], $feed['items'], json_encode($after));
            $this->assertSame('n' . Notification::max('id'), $feed['cursor'], json_encode($after));
        }
    }

    public function test_a_manager_covering_two_locations_follows_the_one_they_are_working_in(): void
    {
        $manager = $this->staff('location_manager', $this->brighton);
        $manager->locations()->sync([$this->canton->id]);
        $token = $this->tokenFor($manager);
        $cursor = $this->feed($token)['cursor'];

        $here = $this->notify($this->brighton, 'New Booking Received');
        $there = $this->notify($this->canton, 'Gift card sold', 'gift_card');

        $this->assertSame([$here->id], array_column($this->feed($token, ['after' => $cursor])['items'], 'id'));

        $this->as($token)->putJson('/api/staff-locations/active', ['location_id' => $this->canton->id])->assertOk();

        $this->assertSame([$there->id], array_column($this->feed($token, ['after' => $cursor])['items'], 'id'));
    }

    private function makeCompany(string $name): Company
    {
        return Company::create([
            'company_name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)) . '@example.test',
            'phone' => '5551230000',
            'address' => '1 Test St',
        ]);
    }

    private function makeLocation(Company $company, string $city): Location
    {
        return Location::create([
            'company_id' => $company->id,
            'name' => "{$city} | Zap Zone",
            'address' => '1 Test Way',
            'city' => $city,
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => strtolower($city) . '@example.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
    }

    private function staff(string $role, Location $location): User
    {
        return User::create([
            'first_name' => ucfirst($role),
            'last_name' => 'Staff',
            'email' => $role . '.' . uniqid() . '@zapzone.test',
            'password' => 'secret-password',
            'role' => $role,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'status' => 'active',
        ]);
    }

    private function notify(Location $location, string $title, string $type = 'booking', array $extra = []): Notification
    {
        return Notification::create(array_merge([
            'location_id' => $location->id,
            'type' => $type,
            'priority' => 'medium',
            'title' => $title,
            'message' => "{$title} message",
            'status' => 'unread',
            'user_id' => null,
        ], $extra));
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    private function feed(string $token, array $query = []): array
    {
        return $this->as($token)
            ->getJson('/api/notifications/feed' . ($query ? '?' . http_build_query($query) : ''))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data');
    }
}
