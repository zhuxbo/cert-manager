<?php

namespace Database\Factories;

use App\Models\Acme;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Acme>
 */
class AcmeFactory extends Factory
{
    protected $model = Acme::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'brand' => 'certum',
            'period' => 12,
            'amount' => '100.00',
            'purchased_standard_count' => 1,
            'purchased_wildcard_count' => 0,
            'eab_kid' => fake()->uuid(),
            'eab_hmac' => fake()->sha256(),
            'contact_email' => fake()->safeEmail(),
            'status' => Acme::STATUS_PENDING,
        ];
    }

    /**
     * 已取消
     */
    public function cancelled(): static
    {
        return $this->state([
            'status' => Acme::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * 未支付
     */
    public function unpaid(): static
    {
        return $this->state([
            'status' => Acme::STATUS_UNPAID,
        ]);
    }

    /**
     * 活跃
     */
    public function active(): static
    {
        return $this->state([
            'status' => Acme::STATUS_ACTIVE,
            'period_from' => now(),
            'period_till' => now()->addYear(),
        ]);
    }
}
