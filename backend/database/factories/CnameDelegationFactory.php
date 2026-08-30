<?php

namespace Database\Factories;

use App\Models\CnameDelegation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * CNAME 委托工厂
 *
 * @extends Factory<CnameDelegation>
 */
class CnameDelegationFactory extends Factory
{
    protected $model = CnameDelegation::class;

    public function definition(): array
    {
        $zone = fake()->unique()->domainName();
        $prefix = '_dnsauth';
        $userId = fake()->randomNumber(8);

        return [
            'user_id' => User::factory(),
            'zone' => $zone,
            'prefix' => $prefix,
            'label' => substr(hash('sha256', "$userId:$prefix.$zone"), 0, 32),
            'proxy_domain' => 'proxy.example.com',
            'valid' => true,
            'fail_count' => 0,
            'last_error' => '',
        ];
    }

    /**
     * 无效状态
     */
    public function invalid(): static
    {
        return $this->state([
            'valid' => false,
            'fail_count' => fake()->numberBetween(1, 10),
            'last_error' => 'CNAME record not found',
            'last_checked_at' => now(),
        ]);
    }

    /**
     * 已验证
     */
    public function verified(): static
    {
        return $this->state([
            'valid' => true,
            'fail_count' => 0,
            'last_error' => '',
            'last_checked_at' => now(),
        ]);
    }
}
