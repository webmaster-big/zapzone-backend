<?php

namespace Tests\Unit;

use App\Models\GiftCard;
use App\Models\Promo;
use Tests\TestCase;

class DiscountTargetingTest extends TestCase
{
    public function test_all_null_targeting_applies_everywhere(): void
    {
        $promo = new Promo();

        $this->assertTrue($promo->isItemWide());
        $this->assertTrue($promo->isAllLocations());
        $this->assertTrue($promo->appliesToLocation(7));
        $this->assertTrue($promo->appliesToLocation(null));
        $this->assertTrue($promo->appliesToPackage(3));
        $this->assertTrue($promo->appliesToAttraction(3));
        $this->assertTrue($promo->appliesToEvent(3));
    }

    public function test_specific_packages_only_apply_to_those_packages(): void
    {
        $promo = new Promo();
        $promo->package_ids = [1, 2];

        $this->assertFalse($promo->isItemWide());
        $this->assertTrue($promo->appliesToPackage(1));
        $this->assertTrue($promo->appliesToPackage(2));
        $this->assertFalse($promo->appliesToPackage(9));

        // Untargeted product types are excluded once any item type is targeted.
        $this->assertFalse($promo->appliesToAttraction(1));
        $this->assertFalse($promo->appliesToEvent(1));
    }

    public function test_targeting_can_span_multiple_item_types(): void
    {
        $promo = new Promo();
        $promo->package_ids = [1];
        $promo->attraction_ids = [5];

        $this->assertTrue($promo->appliesToPackage(1));
        $this->assertTrue($promo->appliesToAttraction(5));
        $this->assertFalse($promo->appliesToAttraction(6));
        $this->assertFalse($promo->appliesToEvent(1));
    }

    public function test_location_targeting_is_independent(): void
    {
        $promo = new Promo();
        $promo->location_ids = [10];

        $this->assertFalse($promo->isAllLocations());
        $this->assertTrue($promo->appliesToLocation(10));
        $this->assertFalse($promo->appliesToLocation(11));
        $this->assertFalse($promo->appliesToLocation(null));
    }

    public function test_appliesToItem_dispatches_by_type(): void
    {
        $promo = new Promo();
        $promo->event_ids = [4];

        $this->assertTrue($promo->appliesToItem('event', 4));
        $this->assertFalse($promo->appliesToItem('event', 5));
        $this->assertFalse($promo->appliesToItem('package', 4));
        $this->assertFalse($promo->appliesToItem('unknown', 4));
    }

    public function test_string_id_matching_guards_json_cast_ambiguity(): void
    {
        $promo = new Promo();
        $promo->package_ids = ['1', '2'];

        $this->assertTrue($promo->appliesToPackage(1));
        $this->assertTrue($promo->appliesToPackage(2));
        $this->assertFalse($promo->appliesToPackage(3));
    }

    public function test_normalize_ids(): void
    {
        $this->assertNull(Promo::normalizeIds(null));
        $this->assertNull(Promo::normalizeIds([]));
        $this->assertSame([1, 2, 3], Promo::normalizeIds([1, '2', 3]));
        $this->assertSame([5], Promo::normalizeIds(['5']));
    }

    public function test_gift_card_uses_same_targeting(): void
    {
        $giftCard = new GiftCard();
        $giftCard->location_ids = [2];
        $giftCard->package_ids = [8];

        $this->assertTrue($giftCard->appliesToLocation(2));
        $this->assertFalse($giftCard->appliesToLocation(3));
        $this->assertTrue($giftCard->appliesToPackage(8));
        $this->assertFalse($giftCard->appliesToPackage(9));
        $this->assertFalse($giftCard->appliesToAttraction(8));
    }
}
