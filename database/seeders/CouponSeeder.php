<?php

namespace Database\Seeders;

use App\Models\Coupon;
use App\Models\CouponSetting;
use Illuminate\Database\Seeder;

class CouponSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Simple percentage coupon — no special rules
        $summer = Coupon::create([
            'code' => 'SUMMER20',
            'type' => 'percentage',
            'value' => 20.00,
            'is_active' => true,
        ]);
        CouponSetting::create([
            'coupon_id' => $summer->id,
            'version' => 1,
            'global_usage_limit' => 500,
            'per_user_limit' => 1,
            'min_cart_value' => 30.00,
            'rules' => null,
            'is_active' => true,
        ]);

        // 2. Fixed discount — first-time users only, electronics category
        $welcome = Coupon::create([
            'code' => 'WELCOME10',
            'type' => 'fixed',
            'value' => 10.00,
            'is_active' => true,
        ]);
        CouponSetting::create([
            'coupon_id' => $welcome->id,
            'version' => 1,
            'global_usage_limit' => 1000,
            'per_user_limit' => 1,
            'min_cart_value' => null,
            'rules' => [
                'first_time_user' => true,
            ],
            'is_active' => true,
        ]);

        // 3. Time-windowed flash sale, category-restricted
        $flash = Coupon::create([
            'code' => 'FLASH30',
            'type' => 'percentage',
            'value' => 30.00,
            'is_active' => true,
        ]);
        CouponSetting::create([
            'coupon_id' => $flash->id,
            'version' => 1,
            'global_usage_limit' => 100,
            'per_user_limit' => 1,
            'min_cart_value' => 50.00,
            'rules' => [
                'categories' => ['electronics', 'gadgets'],
                'time_window' => ['start' => '10:00', 'end' => '14:00'],
            ],
            'is_active' => true,
        ]);

        // 4. VIP-only coupon with product restriction
        $vip = Coupon::create([
            'code' => 'VIP50',
            'type' => 'percentage',
            'value' => 50.00,
            'is_active' => true,
        ]);
        CouponSetting::create([
            'coupon_id' => $vip->id,
            'version' => 1,
            'global_usage_limit' => 50,
            'per_user_limit' => 2,
            'min_cart_value' => 100.00,
            'rules' => [
                'user_segments' => ['vip', 'premium'],
            ],
            'is_active' => true,
        ]);
    }
}
