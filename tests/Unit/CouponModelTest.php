<?php

namespace Tests\Unit;

use App\Models\Coupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_currently_valid_returns_true_for_active_coupon(): void
    {
        $coupon = Coupon::factory()->create();

        $this->assertTrue($coupon->isCurrentlyValid());
    }

    public function test_is_currently_valid_returns_false_for_inactive_coupon(): void
    {
        $coupon = Coupon::factory()->inactive()->create();

        $this->assertFalse($coupon->isCurrentlyValid());
    }

    public function test_is_currently_valid_returns_false_when_expired(): void
    {
        $coupon = Coupon::factory()->expired()->create();

        $this->assertFalse($coupon->isCurrentlyValid());
    }

    public function test_is_currently_valid_returns_false_when_not_yet_valid(): void
    {
        $coupon = Coupon::factory()->notYetValid()->create();

        $this->assertFalse($coupon->isCurrentlyValid());
    }

    public function test_is_currently_valid_with_valid_date_range(): void
    {
        $coupon = Coupon::factory()->create([
            'valid_from'  => now()->subDay(),
            'valid_until' => now()->addDay(),
        ]);

        $this->assertTrue($coupon->isCurrentlyValid());
    }

    public function test_latest_active_setting_returns_highest_version(): void
    {
        $coupon = Coupon::factory()->create();
        $coupon->settings()->createMany([
            ['version' => 1, 'is_active' => true, 'global_usage_limit' => 10, 'per_user_limit' => 1],
            ['version' => 2, 'is_active' => true, 'global_usage_limit' => 20, 'per_user_limit' => 1],
        ]);

        $this->assertSame(2, $coupon->latestActiveSetting()->version);
    }

    public function test_latest_active_setting_ignores_inactive_versions(): void
    {
        $coupon = Coupon::factory()->create();
        $coupon->settings()->createMany([
            ['version' => 1, 'is_active' => true, 'global_usage_limit' => 10, 'per_user_limit' => 1],
            ['version' => 2, 'is_active' => false, 'global_usage_limit' => 20, 'per_user_limit' => 1],
        ]);

        $this->assertSame(1, $coupon->latestActiveSetting()->version);
    }

    public function test_latest_active_setting_returns_null_when_none(): void
    {
        $coupon = Coupon::factory()->create();

        $this->assertNull($coupon->latestActiveSetting());
    }
}
