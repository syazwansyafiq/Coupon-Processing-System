<?php

namespace Database\Factories;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    public function definition(): array
    {
        return [
            'code'       => strtoupper($this->faker->unique()->lexify('????##')),
            'type'       => 'percentage',
            'value'      => 20.00,
            'is_active'  => true,
            'valid_from' => null,
            'valid_until' => null,
        ];
    }

    public function fixed(float $value = 10.00): static
    {
        return $this->state(['type' => 'fixed', 'value' => $value]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function expired(): static
    {
        return $this->state([
            'valid_from'  => now()->subDays(10),
            'valid_until' => now()->subDay(),
        ]);
    }

    public function notYetValid(): static
    {
        return $this->state([
            'valid_from' => now()->addDay(),
        ]);
    }
}
