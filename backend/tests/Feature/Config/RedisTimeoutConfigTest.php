<?php

use Illuminate\Support\Facades\Redis;

/**
 * H5：CACHE/REDIS 网络黑洞防护 —— 显式 timeout + read_timeout。
 *
 * 连接级断言（禁 config-only 假绿）：断的是 PhpRedisConnector 实际应用到连接上的参数。
 * 键名写错（如 Predis 专属键 read_write_timeout，phpredis 会静默忽略）时 OPT_READ_TIMEOUT
 * 保持默认 0.0、getTimeout 保持 0.0 → 测试红。容器自带 phpredis + redis 服务，禁 markTestSkipped。
 */
test('redis 配置键 timeout/read_timeout 存在且为 float（default + cache 两块对称）', function () {
    foreach (['default', 'cache'] as $conn) {
        expect(config("database.redis.$conn.timeout"))->toBeFloat()
            ->and(config("database.redis.$conn.read_timeout"))->toBeFloat();
    }
});

test('default 连接把 read_timeout=5 与 timeout=5 实际应用到 phpredis 连接', function () {
    $client = Redis::connection('default')->client();

    expect($client->getOption(\Redis::OPT_READ_TIMEOUT))->toBe(5.0)
        ->and($client->getTimeout())->toBe(5.0);
});

test('cache 连接把 read_timeout=5 与 timeout=5 实际应用到 phpredis 连接', function () {
    $client = Redis::connection('cache')->client();

    expect($client->getOption(\Redis::OPT_READ_TIMEOUT))->toBe(5.0)
        ->and($client->getTimeout())->toBe(5.0);
});
