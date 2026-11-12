<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\ProductionBatchItem;
use App\Models\Terminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->actAsStaff();
    }

    public function test_production_session_with_several_flavours_adds_to_stock(): void
    {
        $mango = $this->product(stock: 5);
        $grape = $this->product(stock: 0);
        $data = $this->postJson('/api/v1/production', [
            'production_date' => now()->toDateString(),
            'items' => [['product_id' => $mango->id, 'quantity' => 20], ['product_id' => $grape->id, 'quantity' => 30]],
        ])->assertCreated()->json('data');

        $this->assertCount(2, $data['items']);
        $this->assertSame($this->staff->id, $data['user_id']);
        $this->assertNotEmpty($data['production_time']);
        $this->assertSame([25.0, 0.0, 25.0], $this->stock($mango));
        $this->assertSame([30.0, 0.0, 30.0], $this->stock($grape));
    }

    public function test_reversal_needs_authorisation_and_cannot_take_sold_stock(): void
    {
        $product = $this->product(stock: 0);
        $this->postJson('/api/v1/production', ['production_date' => now()->toDateString(), 'items' => [['product_id' => $product->id, 'quantity' => 10]]])->assertCreated();
        $item = ProductionBatchItem::first();
        $url = "/api/v1/production/items/{$item->id}/reverse";

        $this->postJson($url, ['reason' => 'Typo'])->assertStatus(403);

        $this->addItem($this->newOrder(), $product, 5)->assertCreated();
        $this->postJson($url, ['reason' => 'Typo', 'manager_pin' => '1234'])->assertStatus(409);
        $this->assertSame([10.0, 5.0, 5.0], $this->stock($product));
    }

    public function test_reverse_and_edit_are_audited_and_correct_stock(): void
    {
        $product = $this->product(stock: 0);
        $this->postJson('/api/v1/production', ['production_date' => now()->toDateString(), 'items' => [['product_id' => $product->id, 'quantity' => 10]]])->assertCreated();
        $item = ProductionBatchItem::first();

        $this->postJson("/api/v1/production/items/{$item->id}/edit", ['quantity' => 7, 'reason' => 'Miscounted', 'manager_pin' => '1234'])->assertOk();
        $this->assertSame([7.0, 0.0, 7.0], $this->stock($product));
        $this->assertDatabaseHas('audit_logs', ['action' => 'production.reversed', 'reason' => 'Miscounted', 'authorized_by' => $this->admin->id]);

        $this->postJson("/api/v1/production/items/{$item->id}/reverse", ['reason' => 'again', 'manager_pin' => '1234'])->assertStatus(409)->assertJsonPath('code', 'already_reversed');
    }

    private function offlineOrder(array $items, ?string $uuid = null): array
    {
        return [
            'client_uuid' => $uuid ?? (string) Str::uuid(),
            'order_number' => '261101-TABC-'.random_int(1000, 9999),
            'created_at' => now()->subHour()->toIso8601String(),
            'customer_name' => 'Offline Oli',
            'items' => $items,
        ];
    }

    public function test_offline_order_syncs_once_even_when_resent(): void
    {
        $product = $this->product(price: 20, stock: 10);
        $body = $this->offlineOrder([['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 20]]);

        $first = $this->postJson('/api/v1/orders/sync', $body)->assertOk()->json('data');
        $second = $this->postJson('/api/v1/orders/sync', $body)->assertOk()->json('data');

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['order']['id'], $second['order']['id']);
        $this->assertSame($body['order_number'], $first['order']['order_number'], 'The number given to the customer is kept');
        $this->assertTrue($first['order']['created_offline']);
        $this->assertSame(1, Order::count());
        $this->assertSame([10.0, 2.0, 8.0], $this->stock($product));
    }

    public function test_offline_time_is_converted_to_shop_time(): void
    {
        $product = $this->product();
        $body = $this->offlineOrder([['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]]);
        $body['created_at'] = '2026-11-01T06:42:21Z';
        $res = $this->postJson('/api/v1/orders/sync', $body)->assertOk()->json('data.order');
        $this->assertSame('2026-11-01 12:12:21', Order::find($res['id'])->created_at->format('Y-m-d H:i:s'));
    }

    public function test_offline_stock_conflict_is_flagged_blocks_billing_and_can_be_resolved(): void
    {
        $product = $this->product(price: 20, stock: 1);
        $res = $this->postJson('/api/v1/orders/sync', $this->offlineOrder([['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 20]]))->assertOk()->json('data');

        $this->assertSame('stock_shortfall', $res['conflicts'][0]['type']);
        $this->assertSame('2.000', $res['order']['items'][0]['stock_shortfall']);
        $this->assertStringContainsString('short', $res['order']['items'][0]['conflict_note']);
        $this->assertSame([1.0, 1.0, 0.0], $this->stock($product), 'Stock is never oversold');

        $order = Order::find($res['order']['id']);
        $this->postJson("/api/v1/orders/{$order->id}/bill", ['expected_version' => $order->version, 'payments' => [['method' => 'cash', 'amount' => 60]]])
            ->assertStatus(409)->assertJsonPath('code', 'stock_shortfall');

        $item = $res['order']['items'][0];
        $this->postJson("/api/v1/orders/{$order->id}/items/{$item['id']}/resolve")->assertStatus(409);

        $this->postJson('/api/v1/production', ['production_date' => now()->toDateString(), 'items' => [['product_id' => $product->id, 'quantity' => 5]]])->assertCreated();
        $this->postJson("/api/v1/orders/{$order->id}/items/{$item['id']}/resolve")->assertOk()->assertJsonPath('data.items.0.stock_shortfall', '0.000');
        $this->bill($order)->assertCreated()->assertJsonPath('data.total', '60.00');
        $this->assertSame([3.0, 0.0, 3.0], $this->stock($product));
    }

    public function test_offline_price_is_honoured_and_change_flagged(): void
    {
        $product = $this->product(price: 25, stock: 5);
        $res = $this->postJson('/api/v1/orders/sync', $this->offlineOrder([['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 20]]))->json('data');
        $this->assertSame('20.00', $res['order']['subtotal']);
        $this->assertSame('price_changed', $res['conflicts'][0]['type']);
    }

    public function test_terminals_register_with_unique_codes_and_stay_stable(): void
    {
        $this->app['auth']->forgetGuards();
        $a = $this->postJson('/api/v1/auth/login', ['username' => 'staff', 'password' => 'staff123', 'device_id' => 'device-a'])->assertOk()->json('data');
        $b = $this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => 'admin123', 'device_id' => 'device-b'])->assertOk()->json('data');
        $a2 = $this->postJson('/api/v1/auth/login', ['username' => 'staff', 'password' => 'staff123', 'device_id' => 'device-a'])->assertOk()->json('data');
        $this->assertNotSame($a['terminal']['code'], $b['terminal']['code']);
        $this->assertSame($a['terminal']['code'], $a2['terminal']['code']);
        $this->assertSame(2, Terminal::count());
        $this->assertNotContains('reports.view', $a['user']['permissions']);
        $this->postJson('/api/v1/auth/login', ['username' => 'staff', 'password' => 'wrong'])->assertStatus(401);
    }

    public function test_reports_reflect_sales_inventory_and_production(): void
    {
        app(\App\Services\SettingsService::class)->update(['tax.enabled' => true]);
        $product = $this->product(price: 100, stock: 0, extra: ['cost' => 40]);
        $this->postJson('/api/v1/production', ['production_date' => now()->toDateString(), 'items' => [['product_id' => $product->id, 'quantity' => 10]]]);
        $order = $this->newOrder();
        $this->addItem($order, $product, 3);
        $this->bill($order)->assertCreated();

        $this->getJson('/api/v1/reports/sales-product')->assertStatus(403);
        $this->actAsAdmin();

        $this->getJson('/api/v1/reports/sales-period')->assertJsonPath('data.summary.0.value', 1)->assertJsonPath('data.rows.0.revenue', '300.00');
        $sales = $this->getJson('/api/v1/reports/sales-product')->assertOk()->json('data.rows.0');
        $this->assertSame(3.0, (float) $sales['quantity']);
        $vs = $this->getJson('/api/v1/reports/production-vs-sales')->assertOk()->json('data.rows.0');
        $this->assertSame(7.0, (float) $vs['difference']);
        $this->getJson('/api/v1/reports/stock-consumption')->assertOk()->assertJsonPath('data.rows.0.consumed', '3.000');

        $fin = collect($this->getJson('/api/v1/reports/financial-summary')->json('data.rows'))->keyBy('measure');
        $this->assertEquals(300, $fin['Gross sales']['amount']);
        $this->assertEqualsWithDelta(14.29, $fin['Tax collected']['amount'], 0.01);
        $this->assertEquals(120, $fin['Cost of goods sold (where cost is set)']['amount']);

        foreach (\App\Services\ReportService::CATALOG as $group) {
            foreach (array_keys($group) as $type) {
                $this->getJson("/api/v1/reports/{$type}?from=".now()->subDay()->toDateString())->assertOk();
            }
        }
        $this->get('/api/v1/reports/sales-product/export/csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->get('/api/v1/reports/sales-product/export/xlsx')->assertOk();
        $this->get('/api/v1/reports/sales-product/export/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
