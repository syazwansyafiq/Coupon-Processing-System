<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey = 'test-api-key';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.api_key' => $this->apiKey]);
        User::factory()->create(['email' => 'test@example.com']);
    }

    public function test_get_token_returns_bearer_token_for_valid_api_key(): void
    {
        $response = $this->postJson('/api/token', ['api_key' => $this->apiKey]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'type', 'name', 'email'])
            ->assertJsonFragment(['type' => 'Bearer']);
    }

    public function test_get_token_accepts_api_key_in_header(): void
    {
        $response = $this->postJson('/api/token', [], ['X-Api-Key' => $this->apiKey]);

        $response->assertOk()->assertJsonStructure(['token']);
    }

    public function test_get_token_rejects_wrong_api_key(): void
    {
        $response = $this->postJson('/api/token', ['api_key' => 'wrong-key']);

        $response->assertUnauthorized()
            ->assertJsonFragment(['message' => 'Invalid API key.']);
    }

    public function test_get_token_rejects_missing_api_key(): void
    {
        $response = $this->postJson('/api/token', []);

        $response->assertUnauthorized();
    }

    public function test_get_token_creates_sanctum_token_for_api_user(): void
    {
        $this->postJson('/api/token', ['api_key' => $this->apiKey]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => User::class,
            'name'           => 'api',
        ]);
    }

    public function test_coupon_endpoints_require_bearer_token(): void
    {
        $this->postJson('/api/coupons/apply')->assertUnauthorized();
        $this->getJson('/api/coupons/status/any-id')->assertUnauthorized();
        $this->postJson('/api/coupons/consume')->assertUnauthorized();
        $this->postJson('/api/coupons/release')->assertUnauthorized();
    }
}
