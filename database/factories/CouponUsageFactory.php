<?php

namespace Database\Factories;

use App\Models\CouponUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

class CouponUsageFactory extends Factory
{
    protected $model = CouponUsage::class;

    public function definition(): array
    {
        return [
            'coupon_id'        => null,
            'user_id'          => null,
            'order_id'         => $this->faker->unique()->uuid(),
            'setting_version'  => 1,
            'discount_applied' => 10.00,
            'consumed_at'      => now(),
        ];
    }
}
