<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class OrderInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->actAsStaff();
    }

    public function test_order_gets_unique_number_and_optional_customer(): void
    {
        $a = $this->postJson('/api/v1/orders', [])->assertCreated()->json('data');
        $b = $this->postJson('/api/v1/orders', ['customer_name' => 'Asha'])->assertCreated()->json('data');
        $this->assertNotSame($a['order_number'], $b['order_number']);
        $this->assertMatchesRegularExpression('/^\d{6}-\d{4}$/', $a['order_number']);
        $this->assertNull($a['customer_name']);
        $this->assertSame('Asha', $b['customer_name']);
        $this->assertSame('unbilled', $a['status']);
    }

    public function test_adding_an_item_reserves_stock_and_totals_recalculate(): void
    {
        $product = $this->product(price: 20, stock: 10);
        $order = $this->newOrder();
        $data = $this->addItem($order, $product, 3)->assertCreated()->json('data');
        $this->assertSame('60.00', $data['subtotal']);
        $this->assertSame([10.0, 3.0, 7.0], $this->stock($product));
    }

    public function test_same_product_merges_into_one_line_with_a_price_snapshot(): void
    {
        $product = $this->product(price: 20);
        $order = $this->newOrder();
        $this->addItem($order, $product);
        $product->update(['price' => 99]);
        $data = $this->addItem($order, $product)->json('data');
        $this->assertCount(2, $data['items'], 'A new price starts a new line');
        $this->assertSame('20.00', $data['items'][0]['unit_price']);
        $this->assertSame('119.00', $data['subtotal']);
    }

    public function test_overselling_is_refused(): void
    {
        $product = $this->product(stock: 2);
        $order = $this->newOrder();
        $this->addItem($order, $product, 3)->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame([2.0, 0.0, 2.0], $this->stock($product));
    }

    public function test_two_orders_cannot_reserve_the_same_last_unit(): void
    {
        $product = $this->product(stock: 1);
        $this->addItem($this->newOrder(), $product)->assertCreated();
        $this->addItem($this->newOrder(), $product)->assertStatus(409);
        $this->assertSame([1.0, 1.0, 0.0], $this->stock($product));
    }

    public function test_reducing_and_removing_an_unserved_item_releases_stock(): void
    {
        $product = $this->product(stock: 10);
        $order = $this->newOrder();
        $item = $this->addItem($order, $product, 5)->json('data.items.0');
        $this->patchJson("/api/v1/orders/{$order->id}/items/{$item['id']}", ['quantity' => 2])->assertOk();
        $this->assertSame([10.0, 2.0, 8.0], $this->stock($product));
        $data = $this->deleteJson("/api/v1/orders/{$order->id}/items/{$item['id']}")->assertOk()->json('data');
        $this->assertSame([], $data['items']);
        $this->assertSame('0.00', $data['subtotal']);
        $this->assertSame([10.0, 0.0, 10.0], $this->stock($product));
    }

    public function test_serving_consumes_stock(): void
    {
        $product = $this->product(stock: 10);
        $order = $this->newOrder();
        $item = $this->addItem($order, $product, 4)->json('data.items.0');
        $this->postJson("/api/v1/orders/{$order->id}/items/{$item['id']}/serve", ['quantity' => 3])->assertOk();
        $this->assertSame([7.0, 1.0, 6.0], $this->stock($product));
    }

    public function test_removing_a_served_item_does_not_return_stock_automatically(): void
    {
        $product = $this->product(stock: 10);
        $order = $this->newOrder();
        $item = $this->addItem($order, $product, 2)->json('data.items.0');
        $this->postJson("/api/v1/orders/{$order->id}/items/{$item['id']}/serve")->assertOk();
        $this->deleteJson("/api/v1/orders/{$order->id}/items/{$item['id']}")->assertOk();
        $this->assertSame([8.0, 0.0, 8.0], $this->stock($product));
    }

    public function test_returning_served_stock_needs_authorisation(): void
    {
        $product = $this->product(stock: 10);
        $order = $this->newOrder();
        $item = $this->addItem($order, $product, 2)->json('data.items.0');
        $this->postJson("/api/v1/orders/{$order->id}/items/{$item['id']}/serve")->assertOk();
        $url = "/api/v1/orders/{$order->id}/items/{$item['id']}";
        $this->patchJson($url, ['quantity' => 1, 'return_to_stock' => true])->assertStatus(403);
        $this->patchJson($url, ['quantity' => 1, 'return_to_stock' => true, 'manager_pin' => '1234'])->assertOk();
        $this->assertSame([9.0, 0.0, 9.0], $this->stock($product));
    }

    public function test_cancelling_after_partial_serving_returns_only_the_unserved_part(): void
    {
        $product = $this->product(stock: 10);
        $order = $this->newOrder();
        $item = $this->addItem($order, $product, 5)->json('data.items.0');
        $this->postJson("/api/v1/orders/{$order->id}/items/{$item['id']}/serve", ['quantity' => 2])->assertOk();

        $this->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'Customer left'])->assertStatus(403);
        $this->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'Customer left', 'manager_pin' => '1234'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame([8.0, 0.0, 8.0], $this->stock($product));
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.cancelled', 'entity_id' => $order->id, 'authorized_by' => $this->admin->id]);
    }

    public function test_cancelled_orders_stay_on_record_and_cannot_be_edited(): void
    {
        $product = $this->product();
        $order = $this->newOrder();
        $this->addItem($order, $product);
        $this->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'x', 'manager_pin' => '1234'])->assertOk();
        $this->addItem($order, $product)->assertStatus(409)->assertJsonPath('code', 'order_closed');
        $this->assertNotNull(Order::find($order->id));
    }

    public function test_double_tap_with_one_idempotency_key_adds_once(): void
    {
        $product = $this->product(stock: 10);
        $order = $this->newOrder();
        $headers = ['Idempotency-Key' => 'tap-1'];
        $url = "/api/v1/orders/{$order->id}/items";
        $first = $this->postJson($url, ['product_id' => $product->id], $headers)->assertCreated();
        $second = $this->postJson($url, ['product_id' => $product->id], $headers)->assertCreated();
        $second->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json('data.items.0.quantity'), $second->json('data.items.0.quantity'));
        $this->assertSame(1.0, (float) OrderItem::where('order_id', $order->id)->sum('quantity'));
        $this->assertSame([10.0, 1.0, 9.0], $this->stock($product));
    }

    public function test_retry_after_a_lost_response_does_not_create_a_second_order(): void
    {
        $headers = ['Idempotency-Key' => 'create-1'];
        $a = $this->postJson('/api/v1/orders', [], $headers)->json('data.id');
        $b = $this->postJson('/api/v1/orders', [], $headers)->json('data.id');
        $this->assertSame($a, $b);
        $this->assertSame(1, Order::count());
    }

    public function test_movement_ledger_is_immutable_and_reconciliation_finds_tampering(): void
    {
        $product = $this->product(stock: 5);
        $this->addItem($this->newOrder(), $product, 2);
        $service = app(InventoryService::class);
        $this->assertSame([], $service->reconcile());

        $movement = InventoryMovement::first();
        try {
            $movement->update(['type' => 'x']);
            $this->fail('Movement was changed');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $product->inventoryRows()->first()->update(['reserved_qty' => 4]);
        $this->assertCount(1, $service->reconcile(true));
        $this->assertSame([5.0, 2.0, 3.0], $this->stock($product));
        $this->assertSame([], $service->reconcile());
    }

    public function test_manual_correction_requires_permission_and_is_audited(): void
    {
        $product = $this->product(stock: 5);
        $id = $product->inventoryRows()->first()->id;
        $this->postJson("/api/v1/inventory/{$id}/correct", ['counted' => 8, 'reason' => 'count'])->assertStatus(403);
        $this->actAsAdmin();
        $this->postJson("/api/v1/inventory/{$id}/correct", ['counted' => 8, 'reason' => 'count'])->assertOk();
        $this->assertSame([8.0, 0.0, 8.0], $this->stock($product));
        $this->assertDatabaseHas('audit_logs', ['action' => 'stock.corrected', 'reason' => 'count']);
    }

    public function test_correction_cannot_go_below_reserved(): void
    {
        $product = $this->product(stock: 5);
        $this->addItem($this->newOrder(), $product, 3);
        $id = $product->inventoryRows()->first()->id;
        $this->actAsAdmin();
        $this->postJson("/api/v1/inventory/{$id}/correct", ['counted' => 2, 'reason' => 'x'])->assertStatus(422)->assertJsonPath('code', 'below_reserved');
    }

    public function test_untracked_products_can_be_sold_freely(): void
    {
        $product = $this->product(extra: ['track_inventory' => false], stock: 0);
        $this->addItem($this->newOrder(), $product, 50)->assertCreated();
    }

    public function test_search_and_filter_orders(): void
    {
        $a = $this->postJson('/api/v1/orders', ['customer_name' => 'Meena', 'customer_mobile' => '9876500000'])->json('data');
        $this->postJson('/api/v1/orders', [])->assertCreated();
        $this->getJson('/api/v1/orders?search=Meena')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/orders?search=98765')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/orders?search='.$a['order_number'])->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/orders?status=billed')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/orders?status=unbilled')->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
    }

}
