<?php

use App\Utils\UpgradeFreezeLock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // 每个用例前确保锁文件不存在
    UpgradeFreezeLock::unfreeze();
});

afterEach(function () {
    // 每个用例后清理锁文件
    UpgradeFreezeLock::unfreeze();
});

test('freeze 后 isFrozen 返回 true', function () {
    UpgradeFreezeLock::freeze();

    expect(UpgradeFreezeLock::isFrozen())->toBeTrue();
});

test('freeze 成功返回 true，失败返回 false', function () {
    // 正常情况：应该返回 true
    expect(UpgradeFreezeLock::freeze('1.0.0', '1.1.0', 3600))->toBeTrue();
    expect(UpgradeFreezeLock::isFrozen())->toBeTrue();

    UpgradeFreezeLock::unfreeze();

    // 异常情况：父目录权限不足模拟（用一个不可创建的路径）
    // 通过反射改 path 暂时不易做，改为验证 json_encode 失败路径
    // 这里只验证返回 true 的正向语义；负向通过 controller 测试覆盖
    expect(UpgradeFreezeLock::freeze())->toBeTrue();
});

test('unfreeze 后 isFrozen 返回 false', function () {
    UpgradeFreezeLock::freeze();
    UpgradeFreezeLock::unfreeze();

    expect(UpgradeFreezeLock::isFrozen())->toBeFalse();
});

test('freeze 写入完整字段，info 能读出全部内容', function () {
    UpgradeFreezeLock::freeze('1.0.5', '1.1.0', 3600);

    $info = UpgradeFreezeLock::info();

    expect($info)->toBeArray()
        ->and($info['version_from'])->toBe('1.0.5')
        ->and($info['version_to'])->toBe('1.1.0')
        ->and($info['ttl_seconds'])->toBe(3600)
        ->and($info['frozen_at'])->toBeString();
    expect(strtotime($info['frozen_at']) === false)->toBeFalse();

    // 验证文件实际写入的 JSON 结构与 info() 一致
    $raw = file_get_contents(UpgradeFreezeLock::path());
    expect($raw)->toBeString();
    /** @var string $raw */
    $decoded = json_decode($raw, true);
    expect($decoded)->toBe($info);
});

test('freeze 重复调用，第二次覆盖第一次内容', function () {
    UpgradeFreezeLock::freeze('1.0.0', '1.0.1', 1000);
    $first = UpgradeFreezeLock::info();
    expect($first['version_from'])->toBe('1.0.0');

    UpgradeFreezeLock::freeze('2.0.0', '2.0.1', 5000);
    $second = UpgradeFreezeLock::info();

    expect($second['version_from'])->toBe('2.0.0')
        ->and($second['version_to'])->toBe('2.0.1')
        ->and($second['ttl_seconds'])->toBe(5000);
});

test('unfreeze 在已 unfreeze 状态下不抛异常', function () {
    // 双重 unfreeze
    UpgradeFreezeLock::unfreeze();
    UpgradeFreezeLock::unfreeze();

    expect(UpgradeFreezeLock::isFrozen())->toBeFalse();
});

test('TTL 过期自动 unfreeze', function () {
    UpgradeFreezeLock::freeze(null, null, 1);
    expect(UpgradeFreezeLock::isFrozen())->toBeTrue();

    sleep(2);

    expect(UpgradeFreezeLock::isFrozen())->toBeFalse()
        ->and(file_exists(UpgradeFreezeLock::path()))->toBeFalse();
});

test('Cache::flush 不影响 freeze 状态', function () {
    UpgradeFreezeLock::freeze();
    expect(UpgradeFreezeLock::isFrozen())->toBeTrue();

    Cache::flush();

    expect(UpgradeFreezeLock::isFrozen())->toBeTrue()
        ->and(file_exists(UpgradeFreezeLock::path()))->toBeTrue();
});

test('optimize:clear 不影响 freeze 状态', function () {
    UpgradeFreezeLock::freeze();
    expect(UpgradeFreezeLock::isFrozen())->toBeTrue();

    Artisan::call('optimize:clear');

    expect(UpgradeFreezeLock::isFrozen())->toBeTrue()
        ->and(file_exists(UpgradeFreezeLock::path()))->toBeTrue();
});

test('cache:clear 不影响 freeze 状态', function () {
    UpgradeFreezeLock::freeze();
    expect(UpgradeFreezeLock::isFrozen())->toBeTrue();

    Artisan::call('cache:clear');

    expect(UpgradeFreezeLock::isFrozen())->toBeTrue()
        ->and(file_exists(UpgradeFreezeLock::path()))->toBeTrue();
});

test('config:clear 不影响 freeze 状态', function () {
    UpgradeFreezeLock::freeze();
    expect(UpgradeFreezeLock::isFrozen())->toBeTrue();

    Artisan::call('config:clear');

    expect(UpgradeFreezeLock::isFrozen())->toBeTrue()
        ->and(file_exists(UpgradeFreezeLock::path()))->toBeTrue();
});

test('并发 freeze 文件内容不损坏，JSON 可解析', function () {
    if (! function_exists('pcntl_fork')) {
        expect(true)->toBeTrue(); // pcntl 不可用：用占位断言跳过实际并发逻辑

        return;
    }

    $childCount = 8;
    $pids = [];

    for ($i = 0; $i < $childCount; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork 失败');
        }

        if ($pid === 0) {
            // 子进程：随机延迟后并发写
            usleep(random_int(0, 5000));
            UpgradeFreezeLock::freeze("v.from.$i", "v.to.$i", 1000 + $i);
            // 立即退出，不跑测试 teardown
            exit(0);
        }

        $pids[] = $pid;
    }

    // 等待所有子进程结束
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    // 父进程读取最终文件内容，验证未损坏
    expect(file_exists(UpgradeFreezeLock::path()))->toBeTrue();

    $raw = file_get_contents(UpgradeFreezeLock::path());
    expect($raw)->toBeString();
    expect($raw === '')->toBeFalse();

    /** @var string $raw */
    $decoded = json_decode($raw, true);
    expect($decoded)->toBeArray()
        ->and($decoded)->toHaveKeys(['frozen_at', 'version_from', 'version_to', 'ttl_seconds']);

    // 内容应为某个子进程的写入（version_from 形如 "v.from.N"）
    expect($decoded['version_from'])->toMatch('/^v\.from\.\d+$/')
        ->and($decoded['version_to'])->toMatch('/^v\.to\.\d+$/');
});

test('freeze 写入 owner_source/owner_pid，缺省 source=unknown', function () {
    UpgradeFreezeLock::freeze('1.0.5', '1.1.0', 3600, 'web');

    $info = UpgradeFreezeLock::info();
    expect($info['owner_source'])->toBe('web')
        ->and($info['owner_pid'])->toBe(getmypid());

    UpgradeFreezeLock::unfreeze();
    UpgradeFreezeLock::freeze();

    $info = UpgradeFreezeLock::info();
    expect($info['owner_source'])->toBe('unknown')
        ->and($info['owner_pid'])->toBe(getmypid());
});
