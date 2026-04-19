<?php

namespace Tests\Unit;

use App\DTOs\CartContext;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\User;
use App\Services\CouponRuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponRuleEngineTest extends TestCase
{
    use RefreshDatabase;

    private CouponRuleEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new CouponRuleEngine();
    }

    private function makeCart(array $overrides = []): CartContext
    {
        return CartContext::fromArray(array_merge([
            'cart_id'         => 'cart-test',
            'user_id'         => 1,
            'cart_value'      => 100.0,
            'item_categories' => [],
            'product_ids'     => [],
            'is_first_order'  => false,
            'user_segments'   => [],
        ], $overrides));
    }

    private function couponWithSetting(array $couponAttrs = [], array $settingAttrs = []): Coupon
    {
        $coupon = Coupon::factory()->create($couponAttrs);
        $coupon->settings()->create(array_merge([
            'version'            => 1,
            'global_usage_limit' => 1000,
            'per_user_limit'     => 5,
            'min_cart_value'     => null,
            'rules'              => null,
            'is_active'          => true,
        ], $settingAttrs));

        return $coupon;
    }

    // ── Coupon existence and validity ─────────────────────────────────────────

    public function test_fails_when_coupon_not_found(): void
    {
        $result = $this->engine->validate('NONEXISTENT', $this->makeCart());

        $this->assertFalse($result->isValid);
        $this->assertSame('coupon_not_found', $result->failureReason);
    }

    public function test_fails_when_coupon_is_inactive(): void
    {
        $coupon = $this->couponWithSetting(['is_active' => false]);

        $result = $this->engine->validate($coupon->code, $this->makeCart());

        $this->assertFalse($result->isValid);
        $this->assertSame('coupon_inactive_or_expired', $result->failureReason);
    }

    public function test_fails_when_coupon_is_expired(): void
    {
        $coupon = $this->couponWithSetting(['valid_until' => now()->subDay()]);

        $result = $this->engine->validate($coupon->code, $this->makeCart());

        $this->assertFalse($result->isValid);
        $this->assertSame('coupon_inactive_or_expired', $result->failureReason);
    }

    public function test_fails_when_no_active_setting(): void
    {
        $coupon = Coupon::factory()->create();
        $coupon->settings()->create([
            'version' => 1, 'global_usage_limit' => 100, 'per_user_limit' => 1, 'is_active' => false,
        ]);

        $result = $this->engine->validate($coupon->code, $this->makeCart());

        $this->assertFalse($result->isValid);
        $this->assertSame('no_active_settings', $result->failureReason);
    }

    // ── Usage limits ──────────────────────────────────────────────────────────

    public function test_fails_when_global_limit_reached(): void
    {
        $coupon = $this->couponWithSetting([], ['global_usage_limit' => 1]);
        $otherUser = User::factory()->create();
        CouponUsage::factory()->create([
            'coupon_id'        => $coupon->id,
            'user_id'          => $otherUser->id,
            'order_id'         => 'order-other',
            'setting_version'  => 1,
            'discount_applied' => 10.00,
        ]);

        $result = $this->engine->validate($coupon->code, $this->makeCart());

        $this->assertFalse($result->isValid);
        $this->assertSame('global_limit_reached', $result->failureReason);
    }

    public function test_fails_when_per_user_limit_reached(): void
    {
        $coupon = $this->couponWithSetting([], ['per_user_limit' => 1]);
        $user = User::factory()->create();
        CouponUsage::factory()->create([
            'coupon_id'        => $coupon->id,
            'user_id'          => $user->id,
            'order_id'         => 'order-1',
            'setting_version'  => 1,
            'discount_applied' => 10.00,
        ]);

        $result = $this->engine->validate($coupon->code, $this->makeCart(['user_id' => $user->id]));

        $this->assertFalse($result->isValid);
        $this->assertSame('user_limit_reached', $result->failureReason);
    }

    // ── Cart rules ────────────────────────────────────────────────────────────

    public function test_fails_when_cart_value_below_minimum(): void
    {
        $coupon = $this->couponWithSetting([], ['min_cart_value' => 100.00]);

        $result = $this->engine->validate($coupon->code, $this->makeCart(['cart_value' => 50.0]));

        $this->assertFalse($result->isValid);
        $this->assertSame('cart_value_too_low', $result->failureReason);
    }

    public function test_passes_when_cart_value_meets_minimum(): void
    {
        $coupon = $this->couponWithSetting([], ['min_cart_value' => 100.00]);

        $result = $this->engine->validate($coupon->code, $this->makeCart(['cart_value' => 100.0]));

        $this->assertTrue($result->isValid);
    }

    public function test_fails_first_time_user_rule_for_returning_user(): void
    {
        $coupon = $this->couponWithSetting([], ['rules' => ['first_time_user' => true]]);

        $result = $this->engine->validate($coupon->code, $this->makeCart(['is_first_order' => false]));

        $this->assertFalse($result->isValid);
        $this->assertSame('not_first_time_user', $result->failureReason);
    }

    public function test_passes_first_time_user_rule_for_new_user(): void
    {
        $coupon = $this->couponWithSetting([], ['rules' => ['first_time_user' => true]]);

        $result = $this->engine->validate($coupon->code, $this->makeCart(['is_first_order' => true]));

        $this->assertTrue($result->isValid);
    }

    public function test_fails_when_required_category_missing(): void
    {
        $coupon = $this->couponWithSetting([], ['rules' => ['categories' => ['electronics']]]);

        $result = $this->engine->validate($coupon->code, $this->makeCart(['item_categories' => ['clothing']]));

        $this->assertFalse($result->isValid);
        $this->assertSame('required_categories_missing', $result->failureReason);
    }

    public function test_passes_when_required_category_present(): void
    {
        $coupon = $this->couponWithSetting([], ['rules' => ['categories' => ['electronics']]]);

        $result = $this->engine->validate($coupon->code, $this->makeCart(['item_categories' => ['electronics', 'clothing']]));

        $this->assertTrue($result->isValid);
    }

    public function test_fails_when_required_product_missing(): void
    {
        $coupon = $this->couponWithSetting([], ['rules' => ['product_ids' => [101]]]);

        $result = $this->engine->validate($coupon->code, $this->makeCart(['product_ids' => [202]]));

        $this->assertFalse($result->isValid);
        $this->assertSame('required_products_missing', $result->failureReason);
    }

    public function test_fails_when_user_segment_not_eligible(): void
    {
        $coupon = $this->couponWithSetting([], ['rules' => ['user_segments' => ['vip']]]);

        $result = $this->engine->validate($coupon->code, $this->makeCart(['user_segments' => ['regular']]));

        $this->assertFalse($result->isValid);
        $this->assertSame('user_segment_not_eligible', $result->failureReason);
    }

    public function test_passes_when_user_segment_matches(): void
    {
        $coupon = $this->couponWithSetting([], ['rules' => ['user_segments' => ['vip', 'premium']]]);

        $result = $this->engine->validate($coupon->code, $this->makeCart(['user_segments' => ['premium']]));

        $this->assertTrue($result->isValid);
    }

    // ── Discount calculation ──────────────────────────────────────────────────

    public function test_percentage_discount_calculated_correctly(): void
    {
        $coupon = $this->couponWithSetting(['type' => 'percentage', 'value' => 20.00]);

        $result = $this->engine->validate($coupon->code, $this->makeCart(['cart_value' => 100.0]));

        $this->assertTrue($result->isValid);
        $this->assertSame(20.0, $result->discountAmount);
    }

    public function test_fixed_discount_calculated_correctly(): void
    {
        $coupon = $this->couponWithSetting(['type' => 'fixed', 'value' => 15.00]);

        $result = $this->engine->validate($coupon->code, $this->makeCart(['cart_value' => 100.0]));

        $this->assertTrue($result->isValid);
        $this->assertSame(15.0, $result->discountAmount);
    }

    public function test_fixed_discount_capped_at_cart_value(): void
    {
        $coupon = $this->couponWithSetting(['type' => 'fixed', 'value' => 50.00]);

        $result = $this->engine->validate($coupon->code, $this->makeCart(['cart_value' => 30.0]));

        $this->assertTrue($result->isValid);
        $this->assertSame(30.0, $result->discountAmount);
    }

    public function test_result_includes_setting_version(): void
    {
        $coupon = $this->couponWithSetting([], ['version' => 3]);

        $result = $this->engine->validate($coupon->code, $this->makeCart());

        $this->assertSame(3, $result->settingVersion);
    }
}
