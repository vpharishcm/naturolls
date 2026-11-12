<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A repeated mobile or SKU is a mistake to correct on screen, not a server error. */
class DuplicateValuesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->actAsAdmin();
    }

    public function test_duplicate_customer_mobile_is_a_clear_message(): void
    {
        $first = $this->postJson('/api/v1/customers', ['name' => 'A', 'mobile' => '9000011111'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/customers', ['name' => 'B', 'mobile' => '9000011111'])
            ->assertStatus(422)->assertJsonPath('errors.mobile.0', 'Another customer already has this mobile number.');
        $this->putJson("/api/v1/customers/{$first}", ['name' => 'A2', 'mobile' => '9000011111'])->assertOk();
    }

    public function test_a_deleted_customers_mobile_can_be_used_again_at_the_counter(): void
    {
        $id = $this->postJson('/api/v1/customers', ['name' => 'A', 'mobile' => '9000022222'])->json('data.id');
        $this->deleteJson("/api/v1/customers/{$id}")->assertOk();

        $this->postJson('/api/v1/orders', ['customer_name' => 'New', 'customer_mobile' => '9000022222'])->assertCreated();
        $this->assertSame('New', Customer::where('mobile', '9000022222')->first()->name);
    }

    public function test_duplicate_sku_and_code_are_clear_messages(): void
    {
        $category = Category::create(['name' => 'C', 'display_order' => 1]);
        $base = ['category_id' => $category->id, 'name' => 'Item', 'price' => 10];
        $id = $this->postJson('/api/v1/products', $base + ['sku' => 'S1', 'code' => 'C1'])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/products', $base + ['sku' => 'S1'])->assertStatus(422)->assertJsonPath('errors.sku.0', 'Another item already has this SKU.');
        $this->postJson('/api/v1/products', $base + ['code' => 'C1'])->assertStatus(422)->assertJsonPath('errors.code.0', 'Another item already has this product code.');
        $this->putJson("/api/v1/products/{$id}", $base + ['sku' => 'S1', 'code' => 'C1'])->assertOk();

        $this->deleteJson("/api/v1/products/{$id}")->assertOk();
        $this->postJson('/api/v1/products', $base + ['sku' => 'S1', 'code' => 'C1'])->assertCreated();
    }

    public function test_duplicate_variant_sku_is_a_clear_message(): void
    {
        $category = Category::create(['name' => 'C', 'display_order' => 1]);
        $base = ['category_id' => $category->id, 'name' => 'Item', 'price' => 10];
        $this->postJson('/api/v1/products', $base + ['variants' => [['name' => 'A', 'price' => 1, 'sku' => 'V1'], ['name' => 'B', 'price' => 1, 'sku' => 'V1']]])->assertStatus(422);

        $saved = $this->postJson('/api/v1/products', $base + ['variants' => [['name' => 'A', 'price' => 1, 'sku' => 'V1']]])->assertCreated()->json('data');
        $this->postJson('/api/v1/products', $base + ['variants' => [['name' => 'C', 'price' => 1, 'sku' => 'V1']]])->assertStatus(422);
        $this->putJson("/api/v1/products/{$saved['id']}", $base + ['variants' => [['id' => $saved['variants'][0]['id'], 'name' => 'A', 'price' => 2, 'sku' => 'V1']]])->assertOk();
    }

    public function test_product_photos_are_served_from_storage_without_a_symlink(): void
    {
        \Illuminate\Support\Facades\Storage::disk('public')->put('products/e2e-test.txt', 'photo');
        $this->get('/storage/products/e2e-test.txt')->assertOk();
        $this->get('/storage/products/missing.png')->assertNotFound();
        $this->get('/storage/..%2f..%2f.env')->assertNotFound();
        \Illuminate\Support\Facades\Storage::disk('public')->delete('products/e2e-test.txt');
    }
}
