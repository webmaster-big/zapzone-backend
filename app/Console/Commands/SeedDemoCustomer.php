<?php

namespace App\Console\Commands;

use App\Models\Attraction;
use App\Models\AttractionPurchase;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Event;
use App\Models\EventPurchase;
use App\Models\GiftCard;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Package;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeedDemoCustomer extends Command
{
    protected $signature = 'demo:customer
        {--email=demo.customer@zapzone.test : Login email}
        {--password=DemoPass123! : Login password}
        {--location= : Location id to attach the data to}
        {--wipe : Remove this demo customer and everything seeded for them, then stop}
        {--force : Required outside the local environment}';

    protected $description = 'Create a signed-in-customer test account with bookings, attractions, events, a membership, notifications and a gift card, so every customer page has data';

    public function handle(): int
    {
        if (!app()->environment('local') && !$this->option('force')) {
            $this->error('Refusing to seed demo data outside local. Re-run with --force if you really mean it.');

            return self::FAILURE;
        }

        $email = (string) $this->option('email');

        if ($this->option('wipe')) {
            return $this->wipe($email);
        }

        $location = $this->option('location')
            ? Location::find((int) $this->option('location'))
            : Location::orderBy('id')->first();

        if (!$location) {
            $this->error('No location found to attach demo data to.');

            return self::FAILURE;
        }

        $package = Package::where('location_id', $location->id)->where('is_active', true)->orderBy('id')->first();
        $attractions = Attraction::where('location_id', $location->id)->where('is_active', true)->orderBy('id')->take(2)->get();
        $event = Event::where('location_id', $location->id)->orderBy('id')->first();
        $plan = MembershipPlan::orderByDesc('id')->first();

        if (!$package || $attractions->isEmpty()) {
            $this->error("Location {$location->id} has no active package or attractions to book.");

            return self::FAILURE;
        }

        $customer = DB::transaction(function () use ($email, $location, $package, $attractions, $event, $plan) {
            $customer = Customer::firstWhere('email', $email) ?? new Customer(['email' => $email]);
            $customer->fill([
                'first_name' => 'Demo',
                'last_name' => 'Customer',
                'phone' => '(248) 555-0142',
                'password' => (string) $this->option('password'),
                'date_of_birth' => '1990-05-14',
                'address' => '1 Demo Street',
                'city' => 'Brighton',
                'state' => 'MI',
                'zip' => '48116',
                'country' => 'USA',
                'status' => 'active',
                'email_verified_at' => now(),
            ]);
            $customer->save();

            $this->seedBookings($customer, $location, $package);
            $this->seedAttractionPurchases($customer, $location, $attractions);
            $this->seedEventPurchase($customer, $location, $event);
            $this->seedMembership($customer, $location, $plan);
            $this->seedNotifications($customer);
            $this->seedGiftCard($customer, $location);

            return $customer;
        });

        $this->newLine();
        $this->info('Demo customer ready.');
        $this->table(['Field', 'Value'], [
            ['Login URL', rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/') . '/customer/login'],
            ['Email', $customer->email],
            ['Password', (string) $this->option('password')],
            ['Location', $location->name . ' (#' . $location->id . ')'],
        ]);
        $this->table(['Customer page', 'Seeded'], [
            ['/customer/reservations', Booking::where('customer_id', $customer->id)->count() . ' bookings'],
            ['/customer/attractions', AttractionPurchase::where('customer_id', $customer->id)->count() . ' attraction purchases'],
            ['/customer/events', EventPurchase::where('customer_id', $customer->id)->count() . ' event purchases'],
            ['/customer/membership', Membership::where('customer_id', $customer->id)->count() . ' membership'],
            ['/customer/notifications', CustomerNotification::where('customer_id', $customer->id)->count() . ' notifications'],
            ['/customer/gift-cards', $customer->giftCards()->count() . ' gift card'],
        ]);
        $this->line('Re-run any time to refresh, or clear it with: php artisan demo:customer --wipe');

        return self::SUCCESS;
    }

    protected function seedBookings(Customer $customer, Location $location, Package $package): void
    {
        $rows = [
            ['days' => 6, 'status' => 'confirmed', 'payment_status' => 'partial', 'paid' => 100.00],
            ['days' => 1, 'status' => 'confirmed', 'payment_status' => 'paid', 'paid' => (float) $package->price],
            ['days' => -21, 'status' => 'completed', 'payment_status' => 'paid', 'paid' => (float) $package->price],
        ];

        foreach ($rows as $i => $row) {
            $date = now()->addDays($row['days']);
            Booking::updateOrCreate(
                ['reference_number' => 'DEMO-BK-' . ($i + 1)],
                [
                    'customer_id' => $customer->id,
                    'package_id' => $package->id,
                    'location_id' => $location->id,
                    'guest_name' => $customer->first_name . ' ' . $customer->last_name,
                    'guest_email' => $customer->email,
                    'guest_phone' => $customer->phone,
                    'booking_date' => $date->toDateString(),
                    'booking_time' => '14:00:00',
                    'participants' => 8,
                    'duration' => 2,
                    'duration_unit' => 'hours',
                    'total_amount' => (float) $package->price,
                    'amount_paid' => $row['paid'],
                    'payment_method' => 'card',
                    'payment_status' => $row['payment_status'],
                    'status' => $row['status'],
                    'guest_of_honor_name' => $i === 0 ? 'Demo Kid' : null,
                    'completed_at' => $row['status'] === 'completed' ? $date : null,
                ]
            );
        }
    }

    protected function seedAttractionPurchases(Customer $customer, Location $location, $attractions): void
    {
        foreach ($attractions->values() as $i => $attraction) {
            $date = now()->addDays($i === 0 ? 3 : -10);
            $qty = $i === 0 ? 4 : 2;
            AttractionPurchase::updateOrCreate(
                ['customer_id' => $customer->id, 'attraction_id' => $attraction->id],
                [
                    'guest_name' => $customer->first_name . ' ' . $customer->last_name,
                    'guest_email' => $customer->email,
                    'guest_phone' => $customer->phone,
                    'purchase_date' => $date->toDateString(),
                    'scheduled_date' => $date->toDateString(),
                    'scheduled_time' => '16:30:00',
                    'quantity' => $qty,
                    'unit_price' => (float) $attraction->price,
                    'total_amount' => (float) $attraction->price * $qty,
                    'amount_paid' => (float) $attraction->price * $qty,
                    'payment_method' => 'card',
                    'status' => $i === 0 ? 'confirmed' : 'checked-in',
                    'checked_in_at' => $i === 0 ? null : $date,
                ]
            );
        }
    }

    protected function seedEventPurchase(Customer $customer, Location $location, ?Event $event): void
    {
        if (!$event) {
            return;
        }

        EventPurchase::updateOrCreate(
            ['reference_number' => 'DEMO-EV-1'],
            [
                'event_id' => $event->id,
                'customer_id' => $customer->id,
                'location_id' => $location->id,
                'guest_name' => $customer->first_name . ' ' . $customer->last_name,
                'guest_email' => $customer->email,
                'guest_phone' => $customer->phone,
                'purchase_date' => now()->addDays(9)->toDateString(),
                'purchase_time' => '18:00:00',
                'quantity' => 2,
                'total_amount' => 70.00,
                'amount_paid' => 70.00,
                'payment_method' => 'card',
                'payment_status' => 'paid',
                'status' => 'confirmed',
            ]
        );
    }

    protected function seedMembership(Customer $customer, Location $location, ?MembershipPlan $plan): void
    {
        if (!$plan) {
            return;
        }

        Membership::updateOrCreate(
            ['customer_id' => $customer->id, 'membership_plan_id' => $plan->id],
            [
                'home_location_id' => $location->id,
                'qr_token' => 'demo-' . Str::random(24),
                'status' => 'active',
                'started_at' => now()->subMonths(2),
                'current_term_end' => now()->addMonths(10),
            ]
        );
    }

    protected function seedNotifications(Customer $customer): void
    {
        $rows = [
            ['booking', 'Your party is confirmed', 'Demo booking DEMO-BK-1 is confirmed. We will see you soon!'],
            ['payment', 'Payment received', 'We received your payment for DEMO-BK-2. Thank you!'],
            ['reminder', 'Visit coming up', 'Your visit is tomorrow at 2:00 PM.'],
            ['general', 'Welcome to Zap Zone', 'Thanks for creating an account. Your tickets live under My Attractions.'],
        ];

        foreach ($rows as $i => [$type, $title, $message]) {
            CustomerNotification::updateOrCreate(
                ['customer_id' => $customer->id, 'title' => $title],
                [
                    'type' => $type,
                    'message' => $message,
                    'status' => $i === 0 ? 'read' : 'unread',
                    'read_at' => $i === 0 ? now()->subHour() : null,
                ]
            );
        }
    }

    protected function seedGiftCard(Customer $customer, Location $location): void
    {
        $card = GiftCard::updateOrCreate(
            ['code' => 'DEMO-GIFT-50'],
            [
                'type' => 'fixed',
                'initial_value' => 50.00,
                'balance' => 35.00,
                'status' => 'active',
                'description' => 'Demo gift card',
                'location_id' => $location->id,
                'created_by' => \App\Models\User::whereIn('role', ['company_admin', 'admin'])->value('id'),
                'expiry_date' => now()->addYear()->toDateString(),
                'deleted' => false,
            ]
        );

        if (!$card->customers()->where('customers.id', $customer->id)->exists()) {
            $card->customers()->attach($customer->id, ['amount' => 15.00, 'redeemed' => false]);
        }
    }

    protected function wipe(string $email): int
    {
        $customer = Customer::withTrashed()->firstWhere('email', $email);

        if (!$customer) {
            $this->warn("No demo customer found for {$email}.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($customer) {
            Booking::withTrashed()->where('customer_id', $customer->id)->forceDelete();
            AttractionPurchase::withTrashed()->where('customer_id', $customer->id)->forceDelete();
            EventPurchase::withTrashed()->where('customer_id', $customer->id)->forceDelete();
            Membership::withTrashed()->where('customer_id', $customer->id)->forceDelete();
            CustomerNotification::where('customer_id', $customer->id)->delete();
            DB::table('customer_gift_cards')->where('customer_id', $customer->id)->delete();
            GiftCard::where('code', 'DEMO-GIFT-50')->delete();
            $customer->delete();
        });

        $this->info("Removed the demo customer {$email} and everything seeded for them.");

        return self::SUCCESS;
    }
}
