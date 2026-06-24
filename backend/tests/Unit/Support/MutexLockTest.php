<?php

use App\Exceptions\ApiResponseException;
use App\Exceptions\MutationBusyException;
use App\Support\MutexLock;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

// 锁定 withMutex 并发原语契约：抢到→执行+释放、抢不到→抛 MutationBusyException 不执行、
// finally 释放、不同 key 不互斥、Cache 故障 fail-open。用 array driver（实现 LockProvider）
// 测进程内互斥语义，不碰 DB、无需 RefreshDatabase。
beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::store('array')->flush();
});

/** 测试宿主：暴露 protected withMutex 供断言 */
class MutexLockHost
{
    use MutexLock;

    public function run(string $key, Closure $fn, int $ttl = 60): mixed
    {
        return $this->withMutex($key, $fn, $ttl);
    }
}

test('抢到锁时执行闭包并返回其结果', function () {
    $result = (new MutexLockHost)->run('order_mutate_1', fn () => 'done');

    expect($result)->toBe('done');
});

test('同一 key 已被占用时抛 MutationBusyException 且不执行闭包', function () {
    // 用独立 lock 占住同 key（不释放，模拟另一个请求正持锁执行）
    $held = Cache::lock('order_mutate_1', 60);
    expect($held->get())->toBeTrue();

    $executed = false;
    expect(fn () => (new MutexLockHost)->run('order_mutate_1', function () use (&$executed) {
        $executed = true;
    }))->toThrow(MutationBusyException::class);

    expect($executed)->toBeFalse();
});

test('闭包执行完释放锁，可再次抢到同一 key', function () {
    $host = new MutexLockHost;
    $host->run('order_mutate_1', fn () => null);

    expect($host->run('order_mutate_1', fn () => 'second'))->toBe('second');
});

test('闭包抛异常时也释放锁（finally）', function () {
    $host = new MutexLockHost;

    try {
        $host->run('order_mutate_1', fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
        // 吞掉业务异常，只验证锁已释放
    }

    expect($host->run('order_mutate_1', fn () => 'ok'))->toBe('ok');
});

test('不同 key 互不阻塞', function () {
    $held = Cache::lock('order_mutate_1', 60);
    $held->get();

    expect((new MutexLockHost)->run('order_mutate_2', fn () => 'ok'))->toBe('ok');
});

test('Cache 故障时 fail-open 执行闭包（退回 DB 锁串行）', function () {
    // mock Cache::lock 抛异常模拟 Redis 故障，断言闭包仍执行（不阻塞业务）
    Cache::shouldReceive('lock')->andThrow(new RuntimeException('redis down'));

    $result = (new MutexLockHost)->run('order_mutate_1', fn () => 'fail-open');

    expect($result)->toBe('fail-open');
});

test('MutationBusyException 携带用户友好文案', function () {
    $e = new MutationBusyException('order_mutate_9');

    expect($e->getMessage())->toBe('该订单正在处理中，请稍后重试')
        ->and($e)->not->toBeInstanceOf(ApiResponseException::class);
});

test('file driver（生产默认 CACHE_DRIVER=file）下 withMutex 同样互斥', function () {
    // 生产 config/cache.php 默认 file，测试环境默认 array；若只测 array 绿、生产 file 实际不锁
    // 即假绿。Laravel 13 FileStore::add 用 flock(LOCK_EX) 跨进程原子，此处验证 file 也真互斥。
    config(['cache.default' => 'file']);
    $key = uniqid('mutex_file_', true); // 唯一 key 避免 paratest 跨 worker 共享 file cache 残留串扰

    $held = Cache::store('file')->lock($key, 60);
    expect($held->get())->toBeTrue();

    try {
        expect(fn () => (new MutexLockHost)->run($key, fn () => 'x'))
            ->toThrow(MutationBusyException::class);
    } finally {
        $held->release();
    }
});
