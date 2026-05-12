<?php

namespace Database\Factories;

use App\Models\Admin;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 通知工厂
 *
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'notifiable_type' => User::class,
            'notifiable_id' => User::factory(),
            'template_id' => NotificationTemplate::factory(),
            'data' => [
                'title' => fake()->sentence(4),
                'content' => fake()->paragraph(),
            ],
            'status' => Notification::STATUS_PENDING,
        ];
    }

    /**
     * 已发送
     */
    public function sent(): static
    {
        return $this->state([
            'status' => Notification::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    /**
     * 发送失败
     */
    public function failed(): static
    {
        return $this->state([
            'status' => Notification::STATUS_FAILED,
        ]);
    }

    /**
     * 发送中
     */
    public function sending(): static
    {
        return $this->state([
            'status' => Notification::STATUS_SENDING,
        ]);
    }

    /**
     * 已读
     */
    public function read(): static
    {
        return $this->state([
            'read_at' => now(),
        ]);
    }

    /**
     * 未读
     */
    public function unread(): static
    {
        return $this->state([
            'read_at' => null,
        ]);
    }

    /**
     * 关联到管理员
     */
    public function forAdmin(): static
    {
        return $this->state([
            'notifiable_type' => Admin::class,
            'notifiable_id' => Admin::factory(),
        ]);
    }

    /**
     * 关联到订单
     */
    public function forOrder(): static
    {
        return $this->state([
            'notifiable_type' => Order::class,
            'notifiable_id' => Order::factory(),
        ]);
    }
}
