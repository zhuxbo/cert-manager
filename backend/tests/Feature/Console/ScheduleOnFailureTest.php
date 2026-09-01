<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

uses()->group('database');

function findScheduledEvent(string $command): ?Event
{
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    return collect($schedule->events())
        ->first(fn (Event $e) => str_contains((string) ($e->command ?? ''), $command));
}

test('M6：关键命令非零退出触发 onFailure 落 Log::error', function (string $command) {
    $event = findScheduledEvent($command);
    expect($event)->not->toBeNull("未找到 schedule 事件: $command");

    Log::spy();
    $event->exitCode = 1; // 模拟命令非零退出
    $event->callAfterCallbacks(app());

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($msg) => str_contains((string) $msg, 'schedule.failed') && str_contains((string) $msg, $command))
        ->once();
})->with([
    'schedule:validate',
    'schedule:auto-renew',
    'schedule:reconcile-pending',
    'logs:purge',
    'schedule:purge',
]);

test('日志与运行时清理错峰且备份仍为 02:00', function () {
    expect(findScheduledEvent('logs:purge')?->expression)->toBe('0 1 * * *')
        ->and(findScheduledEvent('schedule:purge')?->expression)->toBe('30 1 * * *')
        ->and(findScheduledEvent('schedule:backup')?->expression)->toBe('0 2 * * *');
});

test('M6：命令成功（exitCode=0）不触发 onFailure 日志', function () {
    $event = findScheduledEvent('schedule:validate');
    expect($event)->not->toBeNull();

    Log::spy();
    $event->exitCode = 0;
    $event->callAfterCallbacks(app());

    Log::shouldNotHaveReceived('error');
});
