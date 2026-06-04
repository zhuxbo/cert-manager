<?php

namespace App\Services\Notification;

use App\Services\Notification\Channels\ChannelInterface;
use App\Services\Notification\Channels\MailChannel;
use InvalidArgumentException;

class ChannelManager
{
    /**
     * @var array<string, ChannelInterface>
     */
    protected array $channels = [];

    public function __construct(MailChannel $mailChannel)
    {
        $this->register('mail', $mailChannel);
    }

    /**
     * 注册一个通道（插件通过此方法注入新通道）
     */
    public function register(string $name, ChannelInterface $channel): void
    {
        $this->channels[$name] = $channel;
    }

    /**
     * 获取所有已注册通道
     *
     * @return array<string, ChannelInterface>
     */
    public function channels(): array
    {
        return $this->channels;
    }

    public function channel(string $name): ChannelInterface
    {
        if (! isset($this->channels[$name])) {
            throw new InvalidArgumentException("未知的通知通道: $name");
        }

        return $this->channels[$name];
    }
}
