<?php

namespace Tests\Unit;

use App\Models\Promotion;
use App\Services\PricingService;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class PricingServiceTest extends TestCase
{
    private function line(float $total, float $gst = 5, int $product = 1, array $cats = [1]): array
    {
        return ['id' => $product, 'line_total' => $total, 'gst_rate' => $gst, 'product_id' => $product, 'category_ids' => $cats];
    }

    private function units(int $qty, float $price, int $product = 1): array
    {
        return ['quantity' => $qty, 'unit_price' => $price] + $this->line($qty * $price, 0, $product);
    }

    private function deal(string $type, int $buy, ?int $get = null, float $value = 0): Promotion
    {
        return new Promotion(['discount_type' => $type, 'buy_qty' => $buy, 'get_qty' => $get, 'discount_value' => $value, 'scope' => 'order', 'min_purchase' => 0, 'is_active' => true]);
    }

    public function test_buy_get_makes_only_full_sets_free(): void
    {
        $pricing = new PricingService;
        $b2g1 = $this->deal('buy_get', 2, 1);
        $this->assertSame(0, $pricing->promotionDiscount($b2g1, [$this->units(2, 20)]));
        $this->assertSame(2000, $pricing->promotionDiscount($b2g1, [$this->units(3, 20)]));
        $this->assertSame(2000, $pricing->promotionDiscount($b2g1, [$this->units(5, 20)]));
        $this->assertSame(4000, $pricing->promotionDiscount($b2g1, [$this->units(6, 20)]));

        $b1g2 = $this->deal('buy_get', 1, 2);
        $this->assertSame(0, $pricing->promotionDiscount($b1g2, [$this->units(2, 20)]));
        $this->assertSame(4000, $pricing->promotionDiscount($b1g2, [$this->units(3, 20)]));
    }

    public function test_buy_get_mixes_flavours_and_frees_the_cheapest(): void
    {
        $r = (new PricingService)->calculate([$this->units(2, 60, 1), $this->units(1, 50, 2)], $this->deal('buy_get', 2, 1), null, 0, true);
        $this->assertSame('50.00', $r['promotion_discount']);
        $this->assertSame('120.00', $r['total']);
    }

    public function test_bundle_prices_full_sets_and_leftovers_pay_full_price(): void
    {
        $pricing = new PricingService;
        $deal = $this->deal('bundle', 4, null, 300);
        $this->assertSame(0, $pricing->promotionDiscount($deal, [$this->units(3, 100)]));

        $r = $pricing->calculate([$this->units(5, 100)], $deal, null, 0, true);
        $this->assertSame('100.00', $r['promotion_discount']);
        $this->assertSame('400.00', $r['total']);

        $mixed = $pricing->calculate([$this->units(3, 100, 1), $this->units(2, 80, 2)], $deal, null, 0, true);
        $this->assertSame('80.00', $mixed['promotion_discount']);
        $this->assertSame('380.00', $mixed['total']);
    }

    public function test_a_deal_applies_only_when_it_saves_money(): void
    {
        $pricing = new PricingService;
        $dear = $this->deal('bundle', 4, null, 300);
        $this->assertFalse($pricing->isEligible($dear, [$this->units(4, 50)]));
        $this->assertSame(0, $pricing->promotionDiscount($dear, [$this->units(4, 50)]));
        $this->assertTrue($pricing->isEligible($dear, [$this->units(4, 100)]));
        $this->assertFalse($pricing->isEligible($this->deal('buy_get', 2, 1), [$this->units(2, 20)]));
    }

    private function buyMangoGetScoop(int $buy = 2, int $get = 1): Promotion
    {
        $promo = new Promotion(['discount_type' => 'buy_get', 'buy_qty' => $buy, 'get_qty' => $get, 'discount_value' => 0, 'scope' => 'product', 'get_scope' => 'product', 'min_purchase' => 0, 'is_active' => true]);
        $promo->setRelation('products', collect([new Promotion(['id' => 1])]));
        $promo->products->first()->id = 1;
        $free = new Promotion;
        $free->id = 2;
        $promo->setRelation('getProducts', collect([$free]));

        return $promo;
    }

    public function test_buy_list_earns_and_get_list_is_free(): void
    {
        $pricing = new PricingService;
        $deal = $this->buyMangoGetScoop();
        // Two mangoes alone, or scoops alone, earn nothing.
        $this->assertSame(0, $pricing->promotionDiscount($deal, [$this->units(2, 20, 1)]));
        $this->assertSame(0, $pricing->promotionDiscount($deal, [$this->units(3, 90, 2)]));
        $this->assertSame(0, $pricing->promotionDiscount($deal, [$this->units(1, 20, 1), $this->units(1, 90, 2)]));

        // A free item dearer than what was bought comes off its own line in full.
        $r = $pricing->calculate([$this->units(2, 20, 1), $this->units(1, 90, 2)], $deal, null, 0, true);
        $this->assertSame('90.00', $r['promotion_discount']);
        $this->assertSame('40.00', $r['total']);

        // Sets are limited by whichever side runs out.
        $this->assertSame(9000, $pricing->promotionDiscount($deal, [$this->units(3, 20, 1), $this->units(2, 90, 2)]));
        $this->assertSame(18000, $pricing->promotionDiscount($deal, [$this->units(4, 20, 1), $this->units(3, 90, 2)]));
    }

    public function test_an_item_on_both_lists_is_not_counted_twice(): void
    {
        $pricing = new PricingService;
        $deal = $this->buyMangoGetScoop();
        $deal->setRelation('getProducts', collect([$deal->products->first(), $deal->getProducts->first()]));
        // Mango is on both lists: 2 bought leaves nothing free.
        $this->assertSame(0, $pricing->promotionDiscount($deal, [$this->units(2, 20, 1)]));
        $this->assertSame(2000, $pricing->promotionDiscount($deal, [$this->units(3, 20, 1)]));
        // Mangoes must be kept back to be bought, so the scoop is the free one.
        $this->assertSame(9000, $pricing->promotionDiscount($deal, [$this->units(2, 20, 1), $this->units(1, 90, 2)]));
    }

    private function forProduct(Promotion $promo, int $product): Promotion
    {
        $promo->scope = 'product';
        $item = new Promotion;
        $item->id = $product;
        $promo->setRelation('products', collect([$item]));

        return $promo;
    }

    public function test_offers_on_different_items_all_apply_on_one_bill(): void
    {
        $pricing = new PricingService;
        // 2 milk popsicles (₹50 each) for ₹90, and 2 water popsicles (₹25 each) for ₹40.
        $milk = $this->forProduct($this->deal('bundle', 2, null, 90), 1);
        $water = $this->forProduct($this->deal('bundle', 2, null, 40), 2);
        $lines = [$this->units(2, 50, 1), $this->units(2, 25, 2)];

        $offers = $pricing->bestPromotions([$milk, $water], $lines);
        $this->assertCount(2, $offers);
        $r = $pricing->calculate($lines, $offers, null, 0, true);
        $this->assertSame('20.00', $r['promotion_discount']);
        $this->assertSame('130.00', $r['total']);
        $this->assertCount(2, $r['promotions']);

        // Each offer repeats for every full pair.
        $more = [$this->units(4, 50, 1), $this->units(5, 25, 2)];
        $r = $pricing->calculate($more, $pricing->bestPromotions([$milk, $water], $more), null, 0, true);
        $this->assertSame('40.00', $r['promotion_discount']);
    }

    public function test_a_unit_is_never_used_by_two_offers(): void
    {
        $pricing = new PricingService;
        $pair = $this->deal('bundle', 2, null, 90);
        $b2g1 = $this->deal('buy_get', 2, 1);
        $lines = [$this->units(3, 50)];
        // 3 units: either the pair (saves 10) or buy 2 get 1 (saves 50), never both.
        $offers = $pricing->bestPromotions([$pair, $b2g1], $lines);
        $this->assertSame([$b2g1], $offers);
        $this->assertSame('50.00', $pricing->calculate($lines, $offers, null, 0, true)['promotion_discount']);

        // With 5 units both fit: 3 for buy 2 get 1, and the other 2 as a pair.
        $five = [$this->units(5, 50)];
        $this->assertSame('60.00', $pricing->calculate($five, $pricing->bestPromotions([$pair, $b2g1], $five), null, 0, true)['promotion_discount']);
    }

    public function test_a_percent_offer_covers_what_the_deals_leave(): void
    {
        $pricing = new PricingService;
        $milk = $this->forProduct($this->deal('bundle', 2, null, 85), 1);
        $tenOff = new Promotion(['discount_type' => 'percent', 'discount_value' => 10, 'scope' => 'order', 'min_purchase' => 0, 'is_active' => true]);
        $lines = [$this->units(2, 50, 1), $this->units(1, 100, 2)];
        // 10% of everything alone saves 20; the pair (15) then 10% of the ₹100 item (10) saves 25.
        $r = $pricing->calculate($lines, $pricing->bestPromotions([$tenOff, $milk], $lines), null, 0, true);
        $this->assertSame('25.00', $r['promotion_discount']);
        $this->assertSame('175.00', $r['total']);
    }

    public function test_line_total_rounds_to_the_paisa(): void
    {
        $this->assertSame('60.00', Money::lineTotal('20.00', '3.000'));
        $this->assertSame('33.33', Money::lineTotal('33.33', '1.000'));
        $this->assertSame('8.33', Money::lineTotal('33.33', '0.250'));
    }

    public function test_inclusive_gst_keeps_the_shelf_price_as_the_total(): void
    {
        $r = (new PricingService)->calculate([$this->line(105)], null, null, 0, true);
        $this->assertSame('105.00', $r['total']);
        $this->assertSame('100.00', $r['taxable_amount']);
        $this->assertSame('5.00', $r['tax_amount']);
        $this->assertSame('2.50', $r['tax_breakup'][0]['cgst']);
        $this->assertSame('2.50', $r['tax_breakup'][0]['sgst']);
    }

    public function test_exclusive_gst_is_added_on_top(): void
    {
        $r = (new PricingService)->calculate([$this->line(100)], null, null, 0, false);
        $this->assertSame('105.00', $r['total']);
        $this->assertSame('5.00', $r['tax_amount']);
    }

    public function test_mixed_rates_are_taxed_separately(): void
    {
        $r = (new PricingService)->calculate([$this->line(105, 5), $this->line(118, 18, 2)], null, null, 0, true);
        $this->assertCount(2, $r['tax_breakup']);
        $this->assertSame('23.00', $r['tax_amount']);
        $this->assertSame('223.00', $r['total']);
    }

    public function test_percent_manual_discount(): void
    {
        $r = (new PricingService)->calculate([$this->line(200, 0)], null, ['type' => 'percent', 'value' => 10], 0, true);
        $this->assertSame('20.00', $r['manual_discount']);
        $this->assertSame('180.00', $r['total']);
    }

    public function test_fixed_discount_cannot_exceed_the_bill(): void
    {
        $r = (new PricingService)->calculate([$this->line(50, 0)], null, ['type' => 'fixed', 'value' => 80], 0, true);
        $this->assertSame('50.00', $r['manual_discount']);
        $this->assertSame('0.00', $r['total']);
    }

    public function test_discount_split_across_lines_never_loses_a_paisa(): void
    {
        $lines = [$this->line(33.33, 5), $this->line(33.33, 18, 2), $this->line(33.34, 0, 3)];
        $r = (new PricingService)->calculate($lines, null, ['type' => 'fixed', 'value' => 10], 0, true);
        $this->assertSame('90.00', $r['total']);
        $this->assertSame($r['total'], bcadd($r['taxable_amount'], $r['tax_amount'], 2));
    }

    public function test_order_promotion_respects_minimum_purchase_shape(): void
    {
        $promo = new Promotion(['discount_type' => 'fixed', 'discount_value' => 30, 'scope' => 'order', 'min_purchase' => 100, 'is_active' => true]);
        $service = new PricingService;
        $this->assertTrue($service->isEligible($promo, [$this->line(100)]));
        $this->assertFalse($service->isEligible($promo, [$this->line(99)]));
        $this->assertSame(3000, $service->promotionDiscount($promo, [$this->line(100)]));
    }

    public function test_loyalty_is_applied_after_other_discounts(): void
    {
        $r = (new PricingService)->calculate([$this->line(100, 0)], null, ['type' => 'percent', 'value' => 50], 2000, true);
        $this->assertSame('50.00', $r['manual_discount']);
        $this->assertSame('20.00', $r['loyalty_discount']);
        $this->assertSame('30.00', $r['total']);
    }
}
