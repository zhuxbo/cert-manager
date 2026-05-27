<?php

use App\Models\Notification;
use App\Services\Notification\ChannelManager;
use App\Services\Notification\Channels\ChannelInterface;
use App\Services\Notification\Channels\MailChannel;
use Illuminate\Database\Eloquent\Model;

afterEach(function () {
    Mockery::close();
});

test('构造时内置 mail 通道', function () {
    $mailChannel = Mockery::mock(MailChannel::class);

    $manager = new ChannelManager($mailChannel);

    expect($manager->channels())->toHaveKey('mail');
    expect($manager->channel('mail'))->toBe($mailChannel);
});

test('register 注入新通道', function () {
    $mailChannel = Mockery::mock(MailChannel::class);
    $manager = new ChannelManager($mailChannel);

    $pluginChannel = new class implements ChannelInterface
    {
        public function send(Notification $notification): array
        {
            return ['code' => 1];
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function shouldSend(Model $notifiable, string $code): bool
        {
            return true;
        }
    };

    $manager->register('feishu', $pluginChannel);

    expect($manager->channels())->toHaveKeys(['mail', 'feishu']);
    expect($manager->channel('feishu'))->toBe($pluginChannel);
});

test('获取不存在的通道时抛出异常', function () {
    $mailChannel = Mockery::mock(MailChannel::class);
    $manager = new ChannelManager($mailChannel);

    $manager->channel('feishu');
})->throws(InvalidArgumentException::class, '未知的通知通道: feishu');
