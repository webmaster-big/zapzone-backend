<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\GiftCard;
use App\Models\Location;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class GiftCardSaleRefundTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;

    private User $admin;

    private int $cards = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gmail.enabled' => false]);
        $this->withoutMiddleware(ThrottleRequests::class);

        $company = Company::create([
            'company_name' => 'Gift Co',
            'email' => 'owner@giftco.test',
            'phone' => '5551230000',
            'address' => '1 Main St',
        ]);
        $this->location = Location::create([
            'company_id' => $company->id,
            'name' => 'Brighton',
            'address' => '1 Test Way',
            'city' => 'Brighton',
            'state' => 'MI',
            'zip_code' => '48116',
            'phone' => '8105551234',
            'email' => 'brighton@giftco.test',
            'timezone' => 'America/Detroit',
            'is_active' => true,
        ]);
        $this->admin = User::create([
            'first_name' => 'Gift',
            'last_name' => 'Admin',
            'email' => 'admin@giftco.test',
            'password' => bcrypt('secret-password'),
            'role' => 'company_admin',
            'company_id' => $company->id,
            'location_id' => $this->location->id,
        ]);
    }

    private function card(float $initial, float $balance): GiftCard
    {
        $this->cards++;

        return GiftCard::create([
            'code' => 'GIFTREFUND' . $this->cards,
            'type' => 'fixed',
            'initial_value' => $initial,
            'balance' => $balance,
            'max_usage' => 5,
            'status' => 'active',
            'location_id' => $this->location->id,
            'created_by' => $this->admin->id,
        ]);
    }

    private function sale(GiftCard $card, float $amount, string $method): Payment
    {
        return Payment::create([
            'payable_type' => Payment::TYPE_GIFT_CARD,
            'payable_id' => $card->id,
            'amount' => $amount,
            'currency' => 'USD',
            'method' => $method,
            'status' => 'completed',
            'transaction_id' => 'GC' . uniqid(),
            'paid_at' => now(),
            'location_id' => $this->location->id,
        ]);
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->admin->createToken('gift')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }

    public function test_refunding_an_unused_gift_card_sale_empties_the_card(): void
    {
        $card = $this->card(100, 100);
        $sale = $this->sale($card, 100, 'cash');

        $this->asAdmin()->patchJson("/api/payments/{$sale->id}/manual-refund", ['amount' => 100, 'notes' => 'Bought by mistake'])->assertOk();

        $card->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $card->balance, 0.001);
        $this->assertSame('redeemed', $card->status);
        $this->assertFalse($card->isValid(), 'a refunded card can no longer be spent');
    }

    public function test_a_partial_refund_lowers_the_balance_and_keeps_the_card(): void
    {
        $card = $this->card(100, 100);
        $sale = $this->sale($card, 100, 'cash');

        $this->asAdmin()->patchJson("/api/payments/{$sale->id}/manual-refund", ['amount' => 30, 'notes' => 'Partial refund'])->assertOk();

        $card->refresh();
        $this->assertEqualsWithDelta(70.0, (float) $card->balance, 0.001);
        $this->assertSame('active', $card->status);
    }

    public function test_money_already_spent_from_the_card_cannot_be_refunded(): void
    {
        $card = $this->card(100, 20);
        $sale = $this->sale($card, 100, 'cash');

        $this->asAdmin()->patchJson("/api/payments/{$sale->id}/manual-refund", ['amount' => 50, 'notes' => 'Wants money back'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This gift card has $20.00 left on it, so only up to that amount can be refunded. The rest has already been spent.');
        $this->assertSame(1, Payment::count(), 'nothing was refunded');
        $this->assertEqualsWithDelta(20.0, (float) $card->fresh()->balance, 0.001);

        $this->asAdmin()->patchJson("/api/payments/{$sale->id}/manual-refund", ['amount' => 20, 'notes' => 'Refund what is left'])->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $card->fresh()->balance, 0.001);
        $this->assertSame('redeemed', $card->fresh()->status);
    }

    public function test_card_refunds_and_voids_check_the_balance_before_reaching_the_processor(): void
    {
        $card = $this->card(100, 20);
        $sale = $this->sale($card, 100, 'authorize.net');

        $this->asAdmin()->patchJson("/api/payments/{$sale->id}/refund", ['amount' => 100])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->asAdmin()->patchJson("/api/payments/{$sale->id}/void")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This gift card has $20.00 left on it, so the whole charge cannot be voided. Once the charge settles (usually within a day), refund the $20.00 that is left.');

        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertEqualsWithDelta(20.0, (float) $card->fresh()->balance, 0.001);
    }

    public function test_a_refund_or_void_that_fails_at_the_processor_puts_the_value_back(): void
    {
        $card = $this->card(100, 100);
        $sale = $this->sale($card, 100, 'authorize.net');

        $this->asAdmin()->patchJson("/api/payments/{$sale->id}/refund", ['amount' => 50])->assertStatus(503);
        $this->assertEqualsWithDelta(100.0, (float) $card->fresh()->balance, 0.001);
        $this->assertSame('active', $card->fresh()->status);

        $this->asAdmin()->patchJson("/api/payments/{$sale->id}/void")->assertStatus(503);
        $this->assertEqualsWithDelta(100.0, (float) $card->fresh()->balance, 0.001);
        $this->assertSame('active', $card->fresh()->status);
    }

    public function test_value_put_back_after_the_card_was_refunded_to_zero_can_be_spent_again(): void
    {
        $card = $this->card(100, 40);
        $sale = $this->sale($card, 100, 'cash');
        $this->asAdmin()->patchJson("/api/payments/{$sale->id}/manual-refund", ['amount' => 40, 'notes' => 'Refund the unspent part'])->assertOk();
        $this->assertSame('redeemed', $card->fresh()->status);

        app(\App\Services\DiscountService::class)->refundGiftCard($card->fresh(), 60, 'booking_cancelled');

        $card->refresh();
        $this->assertEqualsWithDelta(60.0, (float) $card->balance, 0.001);
        $this->assertTrue($card->isValid(), 'the cancelled party\'s $60 is spendable again');
    }

    public function test_the_report_lists_older_refunded_cards_that_can_still_be_spent(): void
    {
        $untouched = $this->card(100, 100);
        $oldSale = $this->sale($untouched, 100, 'cash');
        Payment::create(['payable_type' => Payment::TYPE_GIFT_CARD, 'payable_id' => $untouched->id, 'reverses_payment_id' => $oldSale->id, 'amount' => 100, 'currency' => 'USD', 'method' => 'cash', 'status' => 'refunded', 'transaction_id' => 'REFUND-OLD-1', 'notes' => "Refund from Payment #{$oldSale->id} (old)", 'refunded_at' => now(), 'location_id' => $this->location->id]);

        $handled = $this->card(100, 100);
        $newSale = $this->sale($handled, 100, 'cash');
        $this->asAdmin()->patchJson("/api/payments/{$newSale->id}/manual-refund", ['amount' => 30, 'notes' => 'Partial refund'])->assertOk();

        $this->artisan('giftcards:refunded-but-spendable')
            ->expectsOutputToContain('...' . substr($untouched->code, -4))
            ->doesntExpectOutputToContain('...' . substr($handled->code, -4))
            ->assertSuccessful();
        $this->assertEqualsWithDelta(100.0, (float) $untouched->fresh()->balance, 0.001, 'the report changes nothing');
    }
}
