<?php

namespace Tests\Unit;

use App\DTOs\CouponValidationResult;
use PHPUnit\Framework\TestCase;

class CouponValidationResultTest extends TestCase
{
    public function test_pass_sets_valid_and_discount(): void
    {
        $result = CouponValidationResult::pass(
            discountAmount: 24.00,
            couponId: 1,
            settingVersion: 2,
            ruleSnapshot: ['version' => 2],
        );

        $this->assertTrue($result->isValid);
        $this->assertSame(24.00, $result->discountAmount);
        $this->assertSame(1, $result->couponId);
        $this->assertSame(2, $result->settingVersion);
        $this->assertSame(['version' => 2], $result->ruleSnapshot);
        $this->assertNull($result->failureReason);
    }

    public function test_fail_sets_invalid_and_reason(): void
    {
        $result = CouponValidationResult::fail('cart_value_too_low');

        $this->assertFalse($result->isValid);
        $this->assertSame('cart_value_too_low', $result->failureReason);
        $this->assertNull($result->discountAmount);
        $this->assertNull($result->couponId);
        $this->assertNull($result->settingVersion);
        $this->assertSame([], $result->ruleSnapshot);
    }
}
