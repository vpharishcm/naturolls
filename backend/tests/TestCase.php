<?php

namespace Tests;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected User $admin;

    protected User $staff;

    protected function seedAccess(): void
    {
        (new DatabaseSeeder)->seedAccess();
        $this->admin = User::where('username', 'admin')->first();
        $this->staff = User::where('username', 'staff')->first();
    }

    protected function actAsStaff(): void
    {
        Sanctum::actingAs($this->staff->fresh());
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->staff->fresh());
    }

    protected function actAsAdmin(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->admin->fresh());
    }

    protected function product(float $price = 100, float $stock = 10, float $gst = 5, array $extra = []): Product
    {
        $category = Category::firstOrCreate(['name' => 'Test'], ['display_order' => 1]);
        $product = Product::create($extra + ['category_id' => $category->id, 'name' => 'Item '.uniqid(), 'price' => $price, 'gst_rate' => $gst]);
        if ($product->track_inventory ?? true) {
            $inventory = app(InventoryService::class);
            $inventory->opening($inventory->ensureRow($product)->id, number_format($stock, 3, '.', ''), null);
        }

        return $product->fresh();
    }

    protected function stock(Product $product): array
    {
        $row = $product->inventoryRows()->first()->fresh();

        return [(float) $row->physical_qty, (float) $row->reserved_qty, (float) $row->available_qty];
    }

    protected function newOrder(): Order
    {
        return Order::find($this->postJson('/api/v1/orders', [])->assertCreated()->json('data.id'));
    }

    protected function addItem(Order $order, Product $product, float $qty = 1)
    {
        return $this->postJson("/api/v1/orders/{$order->id}/items", ['product_id' => $product->id, 'quantity' => $qty]);
    }

    protected function bill(Order $order, array $overrides = [])
    {
        $order = $order->fresh();
        $preview = $this->postJson("/api/v1/orders/{$order->id}/bill/preview", array_intersect_key($overrides, array_flip(['manual_discount', 'promotion_id', 'redeem_points'])))->json('data');

        return $this->postJson("/api/v1/orders/{$order->id}/bill", $overrides + [
            'expected_version' => $order->version,
            'payments' => [['method' => 'cash', 'amount' => $preview['total']]],
        ]);
    }
}
