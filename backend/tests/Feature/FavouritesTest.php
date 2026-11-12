<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavouritesTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_add_and_remove_a_favourite_and_all_devices_see_it(): void
    {
        $this->seedAccess();
        $this->actAsStaff();
        $product = $this->product();

        $this->postJson("/api/v1/products/{$product->id}/favourite", ['is_favourite' => true])->assertOk()->assertJsonPath('data.is_favourite', true);
        $this->assertContains($product->id, $this->getJson('/api/v1/products/quick-lists')->json('data.favourites'));
        $listed = collect($this->getJson('/api/v1/products')->json('data'))->firstWhere('id', $product->id);
        $this->assertTrue($listed['is_favourite']);

        $this->postJson("/api/v1/products/{$product->id}/favourite", ['is_favourite' => false])->assertOk()->assertJsonPath('data.is_favourite', false);
        $this->assertNotContains($product->id, $this->getJson('/api/v1/products/quick-lists')->json('data.favourites'));
    }

    public function test_api_answers_401_even_when_the_caller_does_not_ask_for_json(): void
    {
        $this->get('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_a_value_is_required_and_login_is_needed(): void
    {
        $this->seedAccess();
        $this->actAsStaff();
        $product = $this->product();
        $this->postJson("/api/v1/products/{$product->id}/favourite", [])->assertStatus(422);
        $this->postJson('/api/v1/products/999999/favourite', ['is_favourite' => true])->assertNotFound();
    }
}
