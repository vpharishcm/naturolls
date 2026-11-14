<?php

namespace Tests\Feature;

use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->actAsStaff();
    }

    public function test_merge_moves_every_item_and_closes_the_extra_order(): void
    {
        $mango = $this->product(price: 30, stock: 10);
        $kulfi = $this->product(price: 40, stock: 10);
        $first = $this->newOrder();
        $this->addItem($first, $mango, 2);
        $extra = $this->newOrder();
        $this->patchJson("/api/v1/orders/{$extra->id}", ['customer_name' => 'Asha'])->assertOk();
        $this->addItem($extra, $mango, 1);
        $this->addItem($extra, $kulfi, 3);

        $data = $this->postJson("/api/v1/orders/{$extra->id}/merge", ['target_order_id' => $first->id, 'expected_version' => $extra->fresh()->version])
            ->assertOk()->json('data');

        $this->assertSame('merged', $data['source']['status']);
        $this->assertSame($first->order_number, $data['source']['merged_into_number']);
        $this->assertSame([], $data['source']['items']);
        $this->assertSame('210.00', $data['target']['subtotal']);
        $this->assertCount(2, $data['target']['items'], 'Same item and price joins one line');
        $this->assertEquals(3, collect($data['target']['items'])->firstWhere('product_id', $mango->id)['quantity']);
        $this->assertSame('Asha', $data['target']['customer_name']);
        $this->assertSame([10.0, 3.0, 7.0], $this->stock($mango), 'Stock does not change');
        $this->assertSame([10.0, 3.0, 7.0], $this->stock($kulfi));
        $this->assertSame([], app(InventoryService::class)->reconcile());
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.merged']);
    }

    public function test_part_of_a_line_moves_to_a_new_order_with_its_reserved_stock(): void
    {
        $mango = $this->product(price: 30, stock: 10);
        $kulfi = $this->product(price: 40, stock: 10);
        $order = $this->newOrder();
        $this->addItem($order, $mango, 3);
        $this->addItem($order, $kulfi, 1);
        $line = OrderItem::where('order_id', $order->id)->where('product_id', $mango->id)->first();

        $data = $this->postJson("/api/v1/orders/{$order->id}/move", ['items' => [['id' => $line->id, 'quantity' => 1]]])
            ->assertOk()->json('data');

        $this->assertSame('unbilled', $data['source']['status']);
        $this->assertSame('100.00', $data['source']['subtotal']);
        $this->assertSame('30.00', $data['target']['subtotal']);
        $this->assertNotSame($order->order_number, $data['target']['order_number']);
        $newLine = OrderItem::where('order_id', $data['target']['id'])->first();
        $this->assertEquals(1, InventoryReservation::where('order_item_id', $newLine->id)->value('quantity'));
        $this->assertEquals(2, InventoryReservation::where('order_item_id', $line->id)->value('quantity'));
        $this->assertSame([10.0, 3.0, 7.0], $this->stock($mango), 'Still 3 reserved, now split across two orders');
        $this->assertSame([], app(InventoryService::class)->reconcile());
    }

    public function test_served_items_move_as_served_and_bill_correctly(): void
    {
        $mango = $this->product(price: 30, stock: 10);
        $from = $this->newOrder();
        $this->addItem($from, $mango, 3);
        $line = OrderItem::where('order_id', $from->id)->first();
        $this->postJson("/api/v1/orders/{$from->id}/items/{$line->id}/serve", ['quantity' => 2])->assertOk();
        $to = $this->newOrder();

        $this->postJson("/api/v1/orders/{$from->id}/move", ['target_order_id' => $to->id, 'items' => [['id' => $line->id, 'quantity' => 2]]])->assertOk();

        // One reserved unit moves first, then one served.
        $moved = OrderItem::where('order_id', $to->id)->first();
        $this->assertEquals(2, $moved->quantity);
        $this->assertEquals(1, $moved->served_qty);
        $this->assertEquals(1, $line->fresh()->served_qty);
        $this->assertSame([8.0, 1.0, 7.0], $this->stock($mango));

        $this->bill($to)->assertCreated();
        $this->bill($from)->assertCreated();
        $this->assertSame([7.0, 0.0, 7.0], $this->stock($mango), 'Billing both deducts exactly the 3 sold');
        $this->assertSame([], app(InventoryService::class)->reconcile());
    }

    public function test_moving_everything_to_another_order_closes_this_one_but_not_to_a_new_order(): void
    {
        $mango = $this->product(price: 30, stock: 10);
        $order = $this->newOrder();
        $this->addItem($order, $mango, 2);
        $line = OrderItem::where('order_id', $order->id)->first();
        $orders = Order::count();

        $this->postJson("/api/v1/orders/{$order->id}/move", ['items' => [['id' => $line->id, 'quantity' => 2]]])
            ->assertStatus(422)->assertJsonPath('code', 'nothing_left');
        $this->assertSame($orders, Order::count(), 'The new order is rolled back');

        $other = $this->newOrder();
        $data = $this->postJson("/api/v1/orders/{$order->id}/move", ['target_order_id' => $other->id, 'items' => [['id' => $line->id, 'quantity' => 2]]])
            ->assertOk()->json('data');
        $this->assertSame('merged', $data['source']['status']);
        $this->assertSame('60.00', $data['target']['subtotal']);
    }

    public function test_an_empty_order_opened_by_mistake_can_be_merged_away(): void
    {
        $order = $this->newOrder();
        $mistake = $this->newOrder();
        $this->postJson("/api/v1/orders/{$mistake->id}/merge", ['target_order_id' => $order->id])
            ->assertOk()->assertJsonPath('data.source.status', 'merged');
    }

    public function test_only_open_orders_and_valid_quantities_are_accepted(): void
    {
        $mango = $this->product(price: 30, stock: 10);
        $order = $this->newOrder();
        $this->addItem($order, $mango, 2);
        $line = OrderItem::where('order_id', $order->id)->first();
        $billed = $this->newOrder();
        $this->addItem($billed, $mango, 1);
        $this->bill($billed)->assertCreated();

        $this->postJson("/api/v1/orders/{$order->id}/merge", ['target_order_id' => $billed->id])->assertStatus(409)->assertJsonPath('code', 'order_closed');
        $this->postJson("/api/v1/orders/{$billed->id}/merge", ['target_order_id' => $order->id])->assertStatus(409)->assertJsonPath('code', 'order_closed');
        $this->postJson("/api/v1/orders/{$order->id}/merge", ['target_order_id' => $order->id])->assertStatus(422)->assertJsonPath('code', 'same_order');
        $this->postJson("/api/v1/orders/{$order->id}/move", ['items' => [['id' => $line->id, 'quantity' => 3]]])->assertStatus(422)->assertJsonPath('code', 'invalid_quantity');
        $other = $this->newOrder();
        $this->postJson("/api/v1/orders/{$order->id}/merge", ['target_order_id' => $other->id, 'expected_version' => 1])->assertStatus(409)->assertJsonPath('code', 'order_changed');
        $this->assertSame('unbilled', $order->fresh()->status);
        $this->assertSame('60.00', $this->getJson("/api/v1/orders/{$order->id}")->json('data.subtotal'), 'Nothing changed');
    }
}
