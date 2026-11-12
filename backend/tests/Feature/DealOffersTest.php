<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Invoice;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealOffersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->actAsAdmin();
    }

    private function save(array $body)
    {
        return $this->postJson('/api/v1/promotions', $body + ['name' => 'Deal', 'is_active' => true]);
    }

    public function test_owner_creates_each_deal_and_can_turn_it_off_again(): void
    {
        $product = $this->product(price: 20);
        $id = $this->save(['discount_type' => 'buy_get', 'buy_qty' => 1, 'get_qty' => 2, 'scope' => 'product', 'product_ids' => [$product->id]])->assertCreated()->json('data.id');
        $promo = Promotion::find($id);
        $this->assertSame(1, $promo->buy_qty);
        $this->assertSame(2, $promo->get_qty);
        $this->assertSame('0.00', (string) $promo->discount_value);

        $body = $this->getJson('/api/v1/promotions')->json('data.0');
        $this->putJson("/api/v1/promotions/{$id}", ['is_active' => false, 'product_ids' => [$product->id], 'category_ids' => []] + $body)->assertOk();
        $this->assertFalse($promo->fresh()->is_active);

        $this->save(['discount_type' => 'bundle', 'buy_qty' => 4, 'discount_value' => 300, 'scope' => 'order'])->assertCreated()->assertJsonPath('data.buy_qty', 4);
    }

    public function test_bad_deals_are_refused(): void
    {
        $this->save(['discount_type' => 'buy_get', 'buy_qty' => 2, 'scope' => 'order'])->assertStatus(422);
        $this->save(['discount_type' => 'buy_get', 'get_qty' => 1, 'scope' => 'order'])->assertStatus(422);
        $this->save(['discount_type' => 'bundle', 'buy_qty' => 4, 'scope' => 'order'])->assertStatus(422);
        $this->save(['discount_type' => 'bundle', 'buy_qty' => 1, 'discount_value' => 10, 'scope' => 'order'])->assertStatus(422);
        $this->save(['discount_type' => 'buy_get', 'buy_qty' => 2, 'get_qty' => 1, 'scope' => 'product', 'product_ids' => []])->assertStatus(422);
    }

    public function test_changing_a_deal_to_a_percent_clears_its_quantities(): void
    {
        $id = $this->save(['discount_type' => 'buy_get', 'buy_qty' => 2, 'get_qty' => 1, 'scope' => 'order'])->json('data.id');
        $this->putJson("/api/v1/promotions/{$id}", ['name' => 'Deal', 'discount_type' => 'percent', 'discount_value' => 10, 'scope' => 'order'])->assertOk();
        $this->assertNull(Promotion::find($id)->buy_qty);
        $this->assertNull(Promotion::find($id)->get_qty);
    }

    public function test_buy_2_get_1_applies_by_itself_on_the_bill_and_stock_counts_the_free_item(): void
    {
        $mango = $this->product(price: 20, stock: 10);
        $other = $this->product(price: 50, stock: 10);
        $this->save(['discount_type' => 'buy_get', 'buy_qty' => 2, 'get_qty' => 1, 'scope' => 'product', 'product_ids' => [$mango->id]])->assertCreated();

        $order = $this->newOrder();
        $this->addItem($order, $mango, 2);
        $this->addItem($order, $other, 1);
        $this->assertSame('0.00', $this->postJson("/api/v1/orders/{$order->id}/bill/preview", [])->json('data.promotion_discount'));

        $this->addItem($order, $mango, 1);
        $this->assertSame('20.00', $this->postJson("/api/v1/orders/{$order->id}/bill/preview", [])->json('data.promotion_discount'));

        $invoice = $this->bill($order)->assertCreated()->json('data');
        $this->assertSame('90.00', $invoice['total']);
        $this->assertSame([7.0, 0.0, 7.0], $this->stock($mango));
    }

    public function test_four_scoops_for_a_price_across_a_category_and_flavours(): void
    {
        $scoops = Category::create(['name' => 'Scoops', 'display_order' => 9]);
        $vanilla = $this->product(price: 100, extra: ['category_id' => $scoops->id]);
        $choco = $this->product(price: 90, extra: ['category_id' => $scoops->id]);
        $mango = $this->product(price: 20);
        $this->save(['discount_type' => 'bundle', 'buy_qty' => 4, 'discount_value' => 300, 'scope' => 'category', 'category_ids' => [$scoops->id]])->assertCreated();

        $order = $this->newOrder();
        $this->addItem($order, $vanilla, 2);
        $this->addItem($order, $choco, 2);
        $this->addItem($order, $mango, 1);

        $invoice = $this->bill($order)->assertCreated()->json('data');
        $this->assertSame('320.00', $invoice['total']);
        $this->assertSame('80.00', $invoice['promotion_discount']);
    }

    public function test_buy_one_item_get_a_different_item_free_on_the_bill(): void
    {
        $mango = $this->product(price: 20, stock: 10);
        $scoop = $this->product(price: 90, stock: 10);
        $id = $this->save(['discount_type' => 'buy_get', 'buy_qty' => 2, 'get_qty' => 1, 'scope' => 'product', 'product_ids' => [$mango->id], 'get_scope' => 'product', 'get_product_ids' => [$scoop->id]])
            ->assertCreated()->assertJsonPath('data.get_products.0.id', $scoop->id)->json('data.id');

        $body = $this->getJson('/api/v1/promotions')->json('data.0');
        $this->assertSame('product', $body['get_scope']);
        $this->putJson("/api/v1/promotions/{$id}", ['product_ids' => [$mango->id], 'category_ids' => [], 'get_product_ids' => [$scoop->id], 'get_category_ids' => []] + $body)->assertOk();

        $order = $this->newOrder();
        $this->addItem($order, $mango, 3);
        $this->assertSame('0.00', $this->postJson("/api/v1/orders/{$order->id}/bill/preview", [])->json('data.promotion_discount'));
        $this->addItem($order, $scoop, 1);

        $invoice = $this->bill($order)->assertCreated()->json('data');
        $this->assertSame('90.00', $invoice['promotion_discount']);
        $this->assertSame('60.00', $invoice['total']);
        $this->assertSame([9.0, 0.0, 9.0], $this->stock($scoop));
    }

    public function test_get_lists_are_required_and_cleared_when_not_used(): void
    {
        $mango = $this->product(price: 20);
        $base = ['discount_type' => 'buy_get', 'buy_qty' => 2, 'get_qty' => 1, 'scope' => 'order'];
        $this->save($base + ['get_scope' => 'product', 'get_product_ids' => []])->assertStatus(422);
        $this->save($base + ['get_scope' => 'category'])->assertStatus(422);

        $id = $this->save($base + ['get_scope' => 'product', 'get_product_ids' => [$mango->id]])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/promotions/{$id}", $base + ['name' => 'Deal', 'get_scope' => 'same'])->assertOk();
        $this->assertSame(0, Promotion::find($id)->getProducts()->count());

        $this->putJson("/api/v1/promotions/{$id}", ['name' => 'Deal', 'discount_type' => 'percent', 'discount_value' => 5, 'scope' => 'order'])->assertOk();
        $this->assertNull(Promotion::find($id)->get_scope);
    }

    public function test_every_active_deal_applies_on_the_same_bill(): void
    {
        $milk = $this->product(price: 50, stock: 10);
        $water = $this->product(price: 25, stock: 10);
        $milkDeal = $this->save(['name' => 'Milk pair', 'discount_type' => 'bundle', 'buy_qty' => 2, 'discount_value' => 90, 'scope' => 'product', 'product_ids' => [$milk->id]])->json('data.id');
        $waterDeal = $this->save(['name' => 'Water pair', 'discount_type' => 'bundle', 'buy_qty' => 2, 'discount_value' => 40, 'scope' => 'product', 'product_ids' => [$water->id]])->json('data.id');

        $order = $this->newOrder();
        $this->addItem($order, $milk, 2);
        $this->addItem($order, $water, 2);

        $preview = $this->postJson("/api/v1/orders/{$order->id}/bill/preview", [])->assertOk()->json('data');
        $this->assertSame('20.00', $preview['promotion_discount']);
        $this->assertEqualsCanonicalizing([$milkDeal, $waterDeal], array_column($preview['promotions'], 'id'));

        // The cashier can keep just one of them.
        $this->assertSame('10.00', $this->postJson("/api/v1/orders/{$order->id}/bill/preview", ['promotion_ids' => [$waterDeal]])->json('data.promotion_discount'));

        $invoice = $this->bill($order)->assertCreated()->json('data');
        $this->assertSame('130.00', $invoice['total']);
        $this->assertEqualsCanonicalizing([$milkDeal, $waterDeal], Invoice::find($invoice['id'])->promotions->pluck('id')->all());

        $rows = collect($this->getJson('/api/v1/reports/promotion-summary')->assertOk()->json('data.rows'))->keyBy('promotion');
        $this->assertEquals(10, $rows['Milk pair']['discount']);
        $this->assertEquals(10, $rows['Water pair']['discount']);
    }

    public function test_editing_a_bill_below_the_deal_drops_it(): void
    {
        $mango = $this->product(price: 20, stock: 10);
        $this->save(['discount_type' => 'buy_get', 'buy_qty' => 2, 'get_qty' => 1, 'scope' => 'order'])->assertCreated();
        $order = $this->newOrder();
        $this->addItem($order, $mango, 3);
        $invoice = $this->bill($order)->assertCreated()->json('data');
        $this->assertSame('40.00', $invoice['total']);

        $this->postJson("/api/v1/invoices/{$invoice['id']}/edit", [
            'expected_edit_count' => 0, 'items' => [['id' => $order->items()->first()->id, 'quantity' => 2]], 'reason' => 'One less', 'settlement_method' => 'cash',
        ])->assertOk();
        $saved = Invoice::find($invoice['id']);
        $this->assertSame('40.00', (string) $saved->total);
        $this->assertSame('0.00', (string) $saved->promotion_discount);
    }
}
