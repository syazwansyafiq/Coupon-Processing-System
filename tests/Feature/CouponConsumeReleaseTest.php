<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CouponConsumeReleaseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function actingWithToken(): static
    {
        return $this->withToken($this->user->createToken('test')->plainTextToken);
    }

    public function test_consume_requires_authentication(): void
    {
        $this->postJson('/api/coupons/consume')->assertUnauthorized();
    }

    public function test_consume_validates_required_fields(): void
    {
        $response = $this->actingWithToken()->postJson('/api/coupons/consume', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['coupon_code', 'order_id', 'request_id', 'discount_amount', 'setting_version']);
    }

    public function test_consume_dispatches_job_and_returns_202(): void
    {
        Queue::fake();
        $coupon = Coupon::factory()->create();

        $response = $this->actingWithToken()->postJson('/api/coupons/consume', [
            'coupon_code'      => $coupon->code,
            'cart_id'          => 'cart-1',
            'cart_value'       => 100.0,
            'order_id'         => 'order-1',
            'request_id'       => 'req-abc',
            'discount_amount'  => 20.0,
            'setting_version'  => 1,
        ]);

        $response->assertAccepted()
            ->assertJsonFragment(['message' => \App\Messages\CouponMessage::CONSUMPTION_QUEUED]);

        Queue::assertPushed(\App\Jobs\ConsumeCouponJob::class);
    }

    public function test_release_requires_authentication(): void
    {
        $this->postJson('/api/coupons/release')->assertUnauthorized();
    }

    public function test_release_validates_required_fields(): void
    {
        $response = $this->actingWithToken()->postJson('/api/coupons/release', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['coupon_code', 'request_id']);
    }

    public function test_release_dispatches_job_and_returns_202(): void
    {
        Queue::fake();
        $coupon = Coupon::factory()->create();

        $response = $this->actingWithToken()->postJson('/api/coupons/release', [
            'coupon_code' => $coupon->code,
            'cart_id'     => 'cart-1',
            'cart_value'  => 100.0,
            'request_id'  => 'req-abc',
        ]);

        $response->assertAccepted()
            ->assertJsonFragment(['message' => \App\Messages\CouponMessage::RELEASE_QUEUED]);

        Queue::assertPushed(\App\Jobs\ReleaseCouponJob::class);
    }
}
