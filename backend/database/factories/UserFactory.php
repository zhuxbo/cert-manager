<?php

namespace Database\Factories;

use App\Models\Fund;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * 用户工厂
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'username' => 'u_'.fake()->unique()->numerify('######'),
            'email' => fake()->unique()->safeEmail(),
            // users.mobile 有唯一索引，使用唯一数字串避免批量建数时碰撞
            'mobile' => fake()->unique()->numerify('1##########'),
            'password' => 'password',
            'balance' => '0.00',
            'level_code' => 'standard',
            'credit_limit' => '0.00',
            'join_ip' => fake()->ipv4(),
            'join_at' => now(),
            'source' => 'web',
            'token_version' => 0,
            'email_verified_at' => now(),
            'notification_settings' => [],
            'auto_settings' => ['auto_renew' => false, 'auto_reissue' => false],
            'status' => 1,
        ];
    }

    /**
     * 禁用状态
     */
    public function disabled(): static
    {
        return $this->state(['status' => 0]);
    }

    /**
     * 未验证邮箱
     */
    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }

    /**
     * 设置余额（走 Fund::create 钩子链产生 transaction 流水，满足资金审计）
     */
    public function withBalance(string $amount): static
    {
        return $this->afterCreating(function (User $user) use ($amount) {
            if (bccomp((string) $amount, '0.00', 2) === 0) {
                return;
            }

            DB::transaction(fn () => Fund::create([
                'user_id' => $user->id,
                'amount' => (string) $amount,
                'type' => 'addfunds',
                'pay_method' => 'admin',
                'pay_sn' => null,
                'remark' => 'factory withBalance',
                'status' => 1,
            ]));

            $user->refresh();
        });
    }

    /**
     * 设置信用额度
     */
    public function withCreditLimit(string $amount): static
    {
        return $this->state(['credit_limit' => $amount]);
    }

    /**
     * 设置自动续费
     */
    public function withAutoRenew(): static
    {
        return $this->state([
            'auto_settings' => ['auto_renew' => true, 'auto_reissue' => false],
        ]);
    }

    /**
     * 已登录过
     */
    public function loggedIn(): static
    {
        return $this->state([
            'last_login_at' => now(),
            'last_login_ip' => fake()->ipv4(),
        ]);
    }
}
