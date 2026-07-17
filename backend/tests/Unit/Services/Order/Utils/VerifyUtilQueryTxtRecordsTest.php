<?php

use App\Services\Delegation\DnsResolver;
use App\Services\Order\Utils\VerifyUtil;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * queryTxtRecords 本地兜底段收编到 DnsResolver。
 *
 * 与 verifyValidationLocal 共用同一份可注入本地解析（DnsResolver），消除
 * 「委托巡检走裸 dns_get_record、DCV 预检走 DnsResolver」双实现并存。
 * 清空 dnsTools（site 组缓存置空）→ getDnsToolsUrls() 返 [] → 直接走本地兜底；
 * 注入 DnsResolver 桩断言本地段确经封装（收编前走裸 @dns_get_record、桩不被调 → 红）。
 */
uses(TestCase::class);

afterEach(function () {
    Mockery::close();
});

/** 清空 dnsTools（走本地兜底路径）+ 绑定裸 DnsResolver 桩（由各用例定义具体期望）。 */
function bindQueryTxtResolver(): MockInterface
{
    // site 组缓存不含 dnsTools → getDnsToolsUrls() 返 [] → queryTxtRecords 跳远端、走本地兜底
    Cache::put('setting:group_name:site', [], 3600);

    $stub = Mockery::mock(DnsResolver::class);
    app()->instance(DnsResolver::class, $stub);

    return $stub;
}

test('本地兜底经 DnsResolver::txt（非裸 dns_get_record）返回 TXT 值', function () {
    $stub = bindQueryTxtResolver();
    $stub->shouldReceive('txt')->once()->with('_certum.example.com')->andReturn(['token-a', 'token-b']);

    $result = VerifyUtil::queryTxtRecords('_certum.example.com');

    expect($result)->toBe(['token-a', 'token-b']);
});

test('direct 模式：本地 CNAME 存在 → 返回空（TXT 属 CNAME 目标，owner name 排除）', function () {
    $stub = bindQueryTxtResolver();
    $stub->shouldReceive('cname')->once()->with('_certum.example.com')->andReturn(['proxy.target.example.com']);
    $stub->shouldReceive('txt')->never(); // CNAME 存在 → 早返回，不再查 TXT

    $result = VerifyUtil::queryTxtRecords('_certum.example.com', direct: true);

    expect($result)->toBe([]);
});

test('direct 模式：本地无 CNAME → 继续查 TXT 并返回', function () {
    $stub = bindQueryTxtResolver();
    $stub->shouldReceive('cname')->once()->andReturn([]);
    $stub->shouldReceive('txt')->once()->andReturn(['direct-token']);

    $result = VerifyUtil::queryTxtRecords('_certum.example.com', direct: true);

    expect($result)->toBe(['direct-token']);
});

test('非 direct 模式：不查 CNAME，直取 TXT', function () {
    $stub = bindQueryTxtResolver();
    $stub->shouldReceive('cname')->never();      // 非 direct 不做 owner name 排除
    $stub->shouldReceive('txt')->once()->andReturn(['plain-token']);

    $result = VerifyUtil::queryTxtRecords('_certum.example.com', direct: false);

    expect($result)->toBe(['plain-token']);
});
