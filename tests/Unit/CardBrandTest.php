<?php

namespace Tests\Unit;

use App\Support\CardBrand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CardBrandTest extends TestCase
{
    public static function gatewayBrands(): array
    {
        return [
            'visa' => ['Visa', 'Visa'],
            'mastercard' => ['MasterCard', 'Mastercard'],
            'american express' => ['AmericanExpress', 'American Express'],
            'discover' => ['Discover', 'Discover'],
            'jcb' => ['JCB', 'JCB'],
            'diners club' => ['DinersClub', 'Diners Club'],
            'frontend short code mc' => ['MC', 'Mastercard'],
            'frontend short code amex' => ['Amex', 'American Express'],
        ];
    }

    #[DataProvider('gatewayBrands')]
    public function test_it_normalizes_the_brands_authorize_net_returns(string $raw, string $expected): void
    {
        $this->assertSame($expected, CardBrand::normalize($raw));
    }

    public function test_it_treats_blank_brands_as_unknown(): void
    {
        $this->assertNull(CardBrand::normalize(null));
        $this->assertNull(CardBrand::normalize(''));
        $this->assertNull(CardBrand::normalize('   '));
    }

    public function test_an_unrecognised_brand_is_kept_but_bounded(): void
    {
        $this->assertSame('Fancy Pay', CardBrand::normalize('Fancy Pay'));
        $this->assertLessThanOrEqual(CardBrand::MAX_LENGTH, strlen((string) CardBrand::normalize(str_repeat('ab', 40))));
    }

    public function test_it_reads_the_last_four_from_a_masked_account_number(): void
    {
        $this->assertSame('3798', CardBrand::lastFour('XXXX3798'));
        $this->assertSame('0916', CardBrand::lastFour('XXXX0916'));
        $this->assertSame('3798', CardBrand::lastFour('3798'));
        $this->assertNull(CardBrand::lastFour('798'));
        $this->assertNull(CardBrand::lastFour(null));
    }

    public function test_it_builds_the_label_the_work_item_asked_for(): void
    {
        $this->assertSame('Visa ending in 3798', CardBrand::label('Visa', 'XXXX3798'));
        $this->assertSame('Card ending in 3798', CardBrand::label(null, '3798'));
    }

    public function test_there_is_no_label_without_a_last_four(): void
    {
        $this->assertNull(CardBrand::label('Visa', null));
        $this->assertNull(CardBrand::label(null, null));
    }

    public static function fullCardNumbers(): array
    {
        return [
            'bare 16 digit' => ['4111111111111111', 'ending in 1111'],
            'space separated' => ['4111 1111 1111 1111', 'ending in 1111'],
            'dash separated' => ['4111-1111-1111-1111', 'ending in 1111'],
            'amex 15 digit' => ['378282246310005', 'ending in 0005'],
            'inside a sentence' => ['card 4111111111111111 ok', 'card ending in 1111 ok'],
        ];
    }

    #[DataProvider('fullCardNumbers')]
    public function test_a_full_card_number_never_survives_redaction(string $input, string $expected): void
    {
        $redacted = CardBrand::redactPan($input);

        $this->assertSame($expected, $redacted);
        $this->assertDoesNotMatchRegularExpression('/\d(?:[ -]?\d){11,18}/', (string) $redacted);
    }

    public static function legitimateLabels(): array
    {
        return [
            'a normal card label' => ['Jane Doe · Visa ending in 3798'],
            'a phone number' => ['Call 248-555-1234'],
            'a date' => ['Saved 2026-09-14'],
            'a booking reference' => ['BK20260131E2UNS8'],
            'a money amount' => ['Card on file $1,250.00'],
        ];
    }

    #[DataProvider('legitimateLabels')]
    public function test_redaction_leaves_legitimate_text_alone(string $label): void
    {
        $this->assertSame($label, CardBrand::redactPan($label));
    }

    public function test_redaction_passes_null_through(): void
    {
        $this->assertNull(CardBrand::redactPan(null));
    }

    public function test_no_card_is_shown_when_nothing_was_paid_by_card(): void
    {
        $this->assertNull(CardBrand::fromPayments([]));
        $this->assertNull(CardBrand::fromPayments(null));
        $this->assertNull(CardBrand::fromPayments([
            ['status' => 'completed', 'card_type' => null, 'card_last_four' => null, 'paid_at' => '2026-08-18 07:13:48'],
        ]));
    }

    public function test_it_shows_the_most_recent_completed_card(): void
    {
        $label = CardBrand::fromPayments([
            ['status' => 'completed', 'card_type' => 'Visa', 'card_last_four' => '1111', 'paid_at' => '2026-08-01 10:00:00'],
            ['status' => 'completed', 'card_type' => 'MasterCard', 'card_last_four' => '2222', 'paid_at' => '2026-08-18 10:00:00'],
        ]);

        $this->assertSame('Mastercard ending in 2222', $label);
    }

    public function test_a_completed_charge_wins_over_a_newer_reversal(): void
    {
        $label = CardBrand::fromPayments([
            ['status' => 'refunded', 'card_type' => 'Visa', 'card_last_four' => '9999', 'paid_at' => '2026-09-01 10:00:00'],
            ['status' => 'completed', 'card_type' => 'Visa', 'card_last_four' => '3798', 'paid_at' => '2026-08-18 10:00:00'],
        ]);

        $this->assertSame('Visa ending in 3798', $label);
    }

    public function test_a_reversal_is_still_shown_when_nothing_completed(): void
    {
        $label = CardBrand::fromPayments([
            ['status' => 'refunded', 'card_type' => 'Discover', 'card_last_four' => '4444', 'paid_at' => '2026-09-01 10:00:00'],
        ]);

        $this->assertSame('Discover ending in 4444', $label);
    }

    public function test_it_falls_back_to_created_at_when_there_is_no_paid_at(): void
    {
        $label = CardBrand::fromPayments([
            ['status' => 'completed', 'card_type' => 'Visa', 'card_last_four' => '1111', 'paid_at' => null, 'created_at' => '2026-08-01 10:00:00'],
            ['status' => 'completed', 'card_type' => 'JCB', 'card_last_four' => '5555', 'paid_at' => null, 'created_at' => '2026-08-20 10:00:00'],
        ]);

        $this->assertSame('JCB ending in 5555', $label);
    }

    public function test_a_cash_row_never_hides_the_card_row(): void
    {
        $label = CardBrand::fromPayments([
            ['status' => 'completed', 'card_type' => null, 'card_last_four' => null, 'paid_at' => '2026-09-01 10:00:00'],
            ['status' => 'completed', 'card_type' => 'Visa', 'card_last_four' => '3798', 'paid_at' => '2026-08-18 10:00:00'],
        ]);

        $this->assertSame('Visa ending in 3798', $label);
    }
}
