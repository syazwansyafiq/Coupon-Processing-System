<?php

namespace Database\Factories;

use App\Models\CouponSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

class CouponSettingFactory extends Factory
{
    protected $model = CouponSetting::class;

    public function definition(): array
    {
        return [
            'coupon_id'          => null,
            'version'            => 1,
            'global_usage_limit' => 100,
            'per_user_limit'     => 1,
            'min_cart_value'     => null,
            'rules'              => null,
            'is_active'          => true,
            'activated_at'       => now(),
        ];
    }

    public function withMinCart(float $min): static
    {
        return $this->state(['min_cart_value' => $min]);
    }

    public function withRules(array $rules): static
    {
        return $this->state(['rules' => $rules]);
    }

    public function unlimited(): static
    {
        return $this->state(['global_usage_limit' => null, 'per_user_limit' => null]);
    }
}
