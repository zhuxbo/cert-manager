<?php

use App\Services\Order\Api\default\Sdk;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

// 锁定 Order default Sdk 在「ca.url 未配置」时优雅降级、绝不抛 TypeError 的契约。
//
// 背景：call() 第一行 `rtrim(get_system_setting('ca','url'), '/')` 在 ca.url 未配置(null)时，
// 文件顶部 strict_types=1 下 rtrim(null,...) 抛 TypeError；该行位于 try 块之外，直接冒泡成
// 500/400，绕过其下一行既有的 `['code'=>0,'msg'=>'Api url or token is not set']` 优雅兜底。
// 修复：取值后 (string) 强转再 rtrim，让 null → '' → 命中 `! $apiUrl` 兜底分支。
// （Acme default Sdk 第 24/27/29 行早已 (string) 强转，本就安全 —— 此为 Order 侧对齐。）
//
// 未配置态在 rtrim 后即被 line 114 拦截 return，根本到不了 makeClient()/真实 HTTP，
// 故直接用真实 new Sdk 调 getProducts()，无需 mock client。配置走 setting 缓存注入（array driver），不碰 DB。

beforeEach(fn () => Cache::forget('setting:group_name:ca'));

// url 缺失、token 存在 —— 精确命中 rtrim(get_system_setting('ca','url')) 的 null 路径，
// 证明即便 token 已配，url 为 null 也走优雅兜底而非 TypeError。
test('ca.url 未配置（token 已配）时返回优雅错误而非抛 TypeError', function () {
    Cache::put('setting:group_name:ca', ['token' => 'tok'], 60);

    $result = (new Sdk)->getProducts();

    expect($result)->toBe(['code' => 0, 'msg' => 'Api url or token is not set']);
});

// ca 组完全为空（url + token 均未配）—— 同样必须优雅兜底而非 TypeError
test('ca 组完全未配置时返回优雅错误而非抛 TypeError', function () {
    Cache::put('setting:group_name:ca', [], 60);

    $result = (new Sdk)->getProducts();

    expect($result)->toBe(['code' => 0, 'msg' => 'Api url or token is not set']);
});
