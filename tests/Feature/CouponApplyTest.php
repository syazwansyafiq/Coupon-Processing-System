<?php

namespace Tests\Feature;

use App\Enums\CouponStatus;
use App\Messages\CouponMessage;
use App\Models\Coupon;
use App\Models\User;
use App\Services\CouponReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CouponApplyTest extends TestCase
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

    private function couponWithSetting(array $coupon = [], array $setting = []): Coupon
    {
        $c = Coupon::factory()->create($coupon);
        $c->settings()->create(array_merge([
            'version' => 1, 'global_usage_limit' => 100, 'per_user_limit' => 5,
            'min_cart_value' => null, 'rules' => null, 'is_active' => true,
        ], $setting));

        return $c;
    }

    private function mockReservation(): \Mockery\MockInterface
    {
        return $this->mock(CouponReservationService::class);
    }

    public function test_apply_requires_authentication(): void
    {
        $this->postJson('/api/coupons/apply')->assertUnauthorized();
    }

    public function test_apply_validates_required_fields(): void
    {
        $mock = $this->mockReservation();
        $mock->shouldReceive('getStatus')->andReturn(null);

        $response = $this->actingWithToken()->postJson('/api/coupons/apply', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['coupon_code', 'cart_id', 'cart_value']);
    }

    public function test_apply_dispatches_job_and_returns_202(): void
    {
        Queue::fake();
        $coupon = $this->couponWithSetting();

        $mock = $this->mockReservation();
        $mock->shouldReceive('getStatus')->andReturn(null);
        $mock->shouldReceive('setStatus');

        $response = $this->actingWithToken()->postJson('/api/coupons/apply', [
            'coupon_code' => $coupon->code,
            'cart_id'     => 'cart-abc',
            'cart_value'  => 100.0,
        ]);

        $response->assertAccepted()
            ->assertJsonFragment(['status' => CouponStatus::Processing->value]);

        Queue::assertPushed(\App\Jobs\ValidateCouponJob::class);
    }

    public function test_apply_returns_existing_status_when_already_reserved(): void
    {
        Queue::fake();
        $coupon = $this->couponWithSetting();

        $mock = $this->mockReservation();
        $mock->shouldReceive('getStatus')->andReturn([
            'status'  => CouponStatus::Reserved->value,
            'message' => CouponMessage::RESERVED_SUCCESSFULLY,
        ]);

        $response = $this->actingWithToken()->postJson('/api/coupons/apply', [
            'coupon_code' => $coupon->code,
            'cart_id'     => 'cart-abc',
            'cart_value'  => 100.0,
        ]);

        $response->assertOk()
            ->assertJsonFragment(['status' => CouponStatus::Reserved->value]);

        Queue::assertNothingPushed();
    }

    public function test_status_returns_not_found_for_unknown_request_id(): void
    {
        $mock = $this->mockReservation();
        $mock->shouldReceive('getStatus')->andReturn(null);

        $response = $this->actingWithToken()->getJson('/api/coupons/status/unknown-key');

        $response->assertNotFound()
            ->assertJsonFragment(['status' => CouponStatus::NotFound->value]);
    }

    public function test_status_returns_stored_status(): void
    {
        $mock = $this->mockReservation();
        $mock->shouldReceive('getStatus')->andReturn([
            'status'  => CouponStatus::Reserved->value,
            'message' => CouponMessage::RESERVED_SUCCESSFULLY,
        ]);

        $response = $this->actingWithToken()->getJson('/api/coupons/status/some-key');

        $response->assertOk()
            ->assertJsonFragment(['status' => CouponStatus::Reserved->value]);
    }
}
