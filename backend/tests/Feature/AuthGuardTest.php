<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_requires_login_and_uses_the_standard_error_shape(): void
    {
        $this->getJson('/api/v1/orders')->assertStatus(401)->assertJson(['success' => false, 'code' => 'unauthenticated']);
    }

    public function test_validation_errors_use_the_standard_shape(): void
    {
        $this->postJson('/api/v1/auth/login', [])->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['username', 'password']]);
    }
}
