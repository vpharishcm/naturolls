<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DiceOffer;
use App\Models\Invoice;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiceOfferTest extends TestCase
{
    use RefreshDatabase;

    private Product $mini;

    private Product $scoop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->actAsStaff();
        $this->mini = $this->product(price: 15, stock: 10, extra: ['name' => 'Mini Popsicle']);
        $this->scoop = $this->product(price: 25, stock: 10, extra: ['name' => 'Mini Scoop']);
    }

    /** The poster's six faces. */
    private function offer(array $overrides = []): DiceOffer
    {
        return DiceOffer::create($overrides + [
            'name' => 'Roll the dice',
            'min_bill' => 250,
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addDay()->toDateString(),
            'is_active' => true,
            'faces' => [
                ['type' => 'free_item', 'label' => 'Free mini popsicle', 'product_ids' => [$this->mini->id]],
                ['type' => 'reroll', 'label' => 'Try again'],
                ['type' => 'free_item', 'label' => 'Free mini scoop', 'product_ids' => [$this->scoop->id]],
                ['type' => 'discount', 'label' => '₹20 off your bill', 'amount' => '20.00'],
                ['type' => 'none', 'label' => 'Better luck next time'],
                ['type' => 'free_item', 'label' => 'Free regular popsicle', 'product_ids' => [$this->mini->id]],
            ],
        ]);
    }

    /** An order worth the given amount in one line. */
    private function orderOf(float $amount)
    {
        $item = $this->product(price: $amount, stock: 5);
        $order = $this->newOrder();
        $this->addItem($order, $item)->assertCreated();

        return $order;
    }

    public function test_bill_screen_shows_the_offer_and_eligibility_uses_the_value_before_discounts(): void
    {
        $this->offer();
        Promotion::create(['name' => '10% off', 'discount_type' => 'percent', 'discount_value' => 10, 'scope' => 'order', 'is_active' => true]);
        $order = $this->orderOf(250);

        $data = $this->postJson("/api/v1/orders/{$order->id}/bill/preview")->assertOk()->json('data');
        $this->assertSame('225.00', $data['total'], 'The 10% offer applies');
        $this->assertTrue($data['dice']['eligible'], '₹250 before the discount still qualifies');
        $this->assertCount(6, $data['dice']['faces']);
        $this->assertSame('Mini Popsicle', $data['dice']['faces'][0]['choices'][0]['name']);

        $small = $this->orderOf(249);
        $this->assertFalse($this->postJson("/api/v1/orders/{$small->id}/bill/preview")->json('data.dice.eligible'));
    }

    public function test_rupees_off_comes_off_the_bill_and_is_recorded(): void
    {
        $this->offer();
        $order = $this->orderOf(300);

        $preview = $this->postJson("/api/v1/orders/{$order->id}/bill/preview", ['dice' => ['rolls' => [4]]])->json('data');
        $this->assertSame('20.00', $preview['dice_discount']);
        $this->assertSame('280.00', $preview['total']);

        $id = $this->bill($order, ['dice' => ['rolls' => [4]]])->assertCreated()->json('data.invoice.id') ?? Invoice::latest('id')->value('id');
        $invoice = Invoice::find($id);
        $this->assertSame('280.00', (string) $invoice->total);
        $this->assertSame('20.00', (string) $invoice->dice_discount);
        $this->assertSame([4], $invoice->dice_rolls);
        $this->assertSame('Rolled 4: ₹20 off your bill', $invoice->dice_prize);
        $receipt = $this->getJson("/api/v1/invoices/{$invoice->id}/receipt")->assertOk()->json('data');
        $this->assertStringContainsString('Dice: Rolled 4: Rs 20 off your bill', base64_decode($receipt['escpos_base64']));
    }

    public function test_a_free_item_joins_the_bill_at_no_charge_and_its_stock_is_deducted(): void
    {
        $this->offer();
        $order = $this->orderOf(300);

        $this->bill($order, ['dice' => ['rolls' => [3], 'product_id' => $this->scoop->id]])->assertCreated();

        $prize = OrderItem::where('order_id', $order->id)->where('product_id', $this->scoop->id)->first();
        $this->assertSame('Mini Scoop (dice prize)', $prize->product_name);
        $this->assertSame('0.00', (string) $prize->line_total);
        $this->assertSame([9.0, 0.0, 9.0], $this->stock($this->scoop));
        $invoice = Invoice::where('order_id', $order->id)->first();
        $this->assertSame('300.00', (string) $invoice->total);
        $this->assertSame('Rolled 3: free Mini Scoop', $invoice->dice_prize);
        $this->assertSame([], app(InventoryService::class)->reconcile());
    }

    public function test_try_again_needs_another_roll_and_only_the_last_roll_wins(): void
    {
        $this->offer();
        $order = $this->orderOf(300);

        $this->postJson("/api/v1/orders/{$order->id}/bill/preview", ['dice' => ['rolls' => [2]]])->assertStatus(422)->assertJsonPath('code', 'dice_roll_again');
        $this->postJson("/api/v1/orders/{$order->id}/bill/preview", ['dice' => ['rolls' => [4, 5]]])->assertStatus(422)->assertJsonPath('code', 'dice_invalid_roll');
        $this->assertSame('0.00', $this->postJson("/api/v1/orders/{$order->id}/bill/preview", ['dice' => ['rolls' => [2, 2, 5]]])->assertOk()->json('data.dice_discount'));

        $this->bill($order, ['dice' => ['rolls' => [2, 4]]])->assertCreated();
        $invoice = Invoice::where('order_id', $order->id)->first();
        $this->assertSame([2, 4], $invoice->dice_rolls);
        $this->assertSame('280.00', (string) $invoice->total);
    }

    public function test_rolls_are_refused_below_the_minimum_outside_the_dates_or_for_an_item_not_on_the_list(): void
    {
        $offer = $this->offer();
        $small = $this->orderOf(200);
        $this->bill($small, ['dice' => ['rolls' => [4]]])->assertStatus(422)->assertJsonPath('code', 'dice_not_eligible');

        $order = $this->orderOf(300);
        $this->bill($order, ['dice' => ['rolls' => [1], 'product_id' => $this->scoop->id]])->assertStatus(422)->assertJsonPath('code', 'dice_choose_item');
        $this->bill($order, ['dice' => ['rolls' => [1]]])->assertStatus(422)->assertJsonPath('code', 'dice_choose_item');

        $offer->update(['ends_on' => now()->subDay()->toDateString()]);
        $this->bill($order, ['dice' => ['rolls' => [4]]])->assertStatus(422)->assertJsonPath('code', 'dice_not_running');
        $this->assertNull($this->postJson("/api/v1/orders/{$order->id}/bill/preview")->json('data.dice'));
        $this->bill($order)->assertCreated();
    }

    public function test_a_free_prize_does_not_count_towards_other_offers(): void
    {
        $this->offer();
        Promotion::create(['name' => 'Buy 1 get 1 mini', 'discount_type' => 'buy_get', 'discount_value' => 0, 'buy_qty' => 1, 'get_qty' => 1, 'get_scope' => 'same', 'scope' => 'product', 'is_active' => true])
            ->products()->sync([$this->mini->id]);
        $order = $this->orderOf(300);

        $this->bill($order, ['dice' => ['rolls' => [1], 'product_id' => $this->mini->id]])->assertCreated();
        $invoice = Invoice::where('order_id', $order->id)->first();
        $this->assertSame('0.00', (string) $invoice->promotion_discount);
        $this->assertSame('300.00', (string) $invoice->total);
    }

    public function test_owner_sets_up_the_offer_and_sees_the_report(): void
    {
        $category = Category::firstOrCreate(['name' => 'Popsicles'], ['display_order' => 2]);
        $faces = array_fill(0, 6, ['type' => 'none', 'label' => 'Better luck']);
        $faces[3] = ['type' => 'discount', 'label' => '₹20 off', 'amount' => 20];
        $faces[0] = ['type' => 'free_item', 'label' => 'Free popsicle', 'category_ids' => [$category->id]];
        $body = ['name' => 'Roll the dice', 'min_bill' => 250, 'starts_on' => now()->toDateString(), 'ends_on' => now()->addWeek()->toDateString(), 'faces' => $faces];

        $this->postJson('/api/v1/dice-offers', $body)->assertForbidden();
        $this->actAsAdmin();
        $this->postJson('/api/v1/dice-offers', ['faces' => array_slice($faces, 0, 5)] + $body)->assertStatus(422);
        $bad = $faces;
        $bad[0] = ['type' => 'free_item', 'label' => 'Free'];
        $this->postJson('/api/v1/dice-offers', ['faces' => $bad] + $body)->assertStatus(422)->assertJsonValidationErrors('faces.0.product_ids');
        $this->postJson('/api/v1/dice-offers', $body)->assertCreated()->assertJsonPath('data.faces.3.amount', '20.00');

        $order = $this->orderOf(400);
        $this->bill($order, ['dice' => ['rolls' => [4]]])->assertCreated();
        $report = $this->getJson('/api/v1/reports/dice-summary')->assertOk()->json('data');
        $this->assertSame('₹20 off', $report['rows'][0]['prize']);
        $this->assertEquals(1, $report['rows'][0]['wins']);
        $this->assertEquals(20, $report['summary'][2]['value']);
    }
}
