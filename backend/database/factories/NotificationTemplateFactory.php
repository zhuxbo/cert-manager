<?php

namespace Database\Factories;

use App\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 通知模板工厂
 *
 * @extends Factory<NotificationTemplate>
 */
class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'code' => fake()->unique()->slug(2),
            'content' => '<p>{{ $title }}</p><p>{{ $content }}</p>',
            'variables' => ['title', 'content'],
            'example' => '<p>测试标题</p><p>测试内容</p>',
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
     * 证书签发通知模板
     */
    public function certIssued(): static
    {
        return $this->state([
            'name' => '证书签发通知',
            'code' => 'cert_issued',
            'content' => '您的证书 {{ $domain }} 已签发成功',
            'variables' => ['domain', 'order_id', 'expires_at'],
        ]);
    }

    /**
     * 证书到期通知模板
     */
    public function certExpiring(): static
    {
        return $this->state([
            'name' => '证书到期提醒',
            'code' => 'cert_expiring',
            'content' => '您的证书 {{ $domain }} 将于 {{ $expires_at }} 到期',
            'variables' => ['domain', 'order_id', 'expires_at', 'days'],
        ]);
    }
}
