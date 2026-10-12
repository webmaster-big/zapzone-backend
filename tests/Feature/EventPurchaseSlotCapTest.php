<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\EventPurchase;
use App\Models\Location;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventPurchaseSlotCapTest extends TestCase
{
    use RefreshDatabase;

    public function test_raising_only_the_quantity_still_respects_the_tickets_left_in_the_slot(): void
    {
        $date = Carbon::today()->addDays(5)->toDateString();

        $company = Company::create([
            'company_name' => 'ZapZone Test',
            'email' => 'admin@zapzone.test',
            'phone' => '5551234567',
            'address' => '123 Main St',
        ]);

        $location = Location::create([
            'company_id' => $company->id,
            'name' => 'ZapZone Brighton',
            'address' => '1 Test Way',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => 'brighton@zapzone.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);

        $event = Event::create([
            'location_id' => $location->id,
            'name' => 'Glow Night',
            'date_type' => 'one_time',
            'start_date' => $date,
            'time_start' => '18:00',
            'time_end' => '22:00',
            'interval_minutes' => 60,
            'price' => 15,
            'max_tickets_per_slot' => 5,
            'is_active' => true,
        ]);

        $attendant = User::create([
            'company_id' => $company->id,
            'location_id' => $location->id,
            'first_name' => 'Attendant',
            'last_name' => 'Tester',
            'email' => 'attendant@zapzone.test',
            'password' => 'Password123!',
            'role' => 'attendant',
            'status' => 'active',
        ]);

        $purchase = fn (string $name, int $quantity) => EventPurchase::create([
            'reference_number' => 'EVT-TEST-' . strtoupper($name),
            'event_id' => $event->id,
            'location_id' => $location->id,
            'guest_name' => $name,
            'guest_email' => strtolower($name) . '@example.com',
            'purchase_date' => $date,
            'purchase_time' => '19:00',
            'quantity' => $quantity,
            'unit_price' => 15,
            'total_amount' => 15 * $quantity,
            'amount_paid' => 0,
            'payment_method' => 'paylater',
            'payment_status' => 'pending',
            'status' => 'confirmed',
        ]);

        $purchase('Avery', 3);
        $second = $purchase('Blake', 1);

        $token = $attendant->createToken('test')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/event-purchases/{$second->id}", ['quantity' => 4, 'change_reason' => 'More guests'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only 2 tickets fit in that time slot.');

        $this->assertSame(1, (int) $second->fresh()->quantity);

        $this->app['auth']->forgetGuards();

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->putJson("/api/event-purchases/{$second->id}", ['quantity' => 2, 'change_reason' => 'One more guest'])
            ->assertOk();

        $this->assertSame(2, (int) $second->fresh()->quantity);
    }
}
