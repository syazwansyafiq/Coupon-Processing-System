<?php

namespace Database\Seeders;

use App\Models\Coupon;
use App\Models\CouponSetting;
use Illuminate\Database\Seeder;

class CouponSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCoupon(
            coupon: ['code' => 'SUMMER20', 'type' => 'percentage', 'value' => 20.00],
            setting: ['global_usage_limit' => 500, 'per_user_limit' => 1, 'min_cart_value' => 30.00, 'rules' => null],
        );

        $this->seedCoupon(
            coupon: ['code' => 'WELCOME10', 'type' => 'fixed', 'value' => 10.00],
            setting: ['global_usage_limit' => 1000, 'per_user_limit' => 1, 'min_cart_value' => null, 'rules' => ['first_time_user' => true]],
        );

        $this->seedCoupon(
            coupon: ['code' => 'FLASH30', 'type' => 'percentage', 'value' => 30.00],
            setting: ['global_usage_limit' => 100, 'per_user_limit' => 1, 'min_cart_value' => 50.00, 'rules' => ['categories' => ['electronics', 'gadgets'], 'time_window' => ['start' => '10:00', 'end' => '14:00']]],
        );

        $this->seedCoupon(
            coupon: ['code' => 'VIP50', 'type' => 'percentage', 'value' => 50.00],
            setting: ['global_usage_limit' => 50, 'per_user_limit' => 2, 'min_cart_value' => 100.00, 'rules' => ['user_segments' => ['vip', 'premium']]],
        );
    }

    private function seedCoupon(array $coupon, array $setting): void
    {
        $record = Coupon::firstOrCreate(
            ['code' => $coupon['code']],
            array_merge($coupon, ['is_active' => true]),
        );

        CouponSetting::firstOrCreate(
            ['coupon_id' => $record->id, 'version' => 1],
            array_merge($setting, ['is_active' => true]),
        );
    }
}
