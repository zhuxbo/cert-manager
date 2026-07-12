<?php

use App\Services\Delegation\DnsResolver;
use App\Services\Order\Utils\VerifyUtil;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
});

/** 反射直调纯决策函数 decideCnameOutcome（渠道观测 → {matched, authoritative}）。 */
function invokeDecideCname(array $observations, string $expected): array
{
    $method = new ReflectionMethod(VerifyUtil::class, 'decideCnameOutcome');
    $method->setAccessible(true);

    return $method->invoke(null, $observations, $expected);
}

/**
 * 清空 dnsTools（走本地兜底路径）+ 注入三态 DnsResolver 桩。
 * 真实 unreachable/权威形态经 verifyCnameDelegationDetailed 渠道适配层产出（非直喂决策树）。
 */
function stubLocalCname(?array $records): void
{
    // site 组缓存不含 dnsTools → getDnsToolsUrls() 返 [] → 仅走本地渠道
    Cache::put('setting:group_name:site', [], 3600);

    $stub = Mockery::mock(DnsResolver::class);
    $stub->shouldReceive('cnameRecords')->andReturn($records);
    app()->instance(DnsResolver::class, $stub);
}

// ── 纯决策函数四形态直测 ─────────────────────────────────────────────────────
test('decideCnameOutcome 权威匹配 → matched + authoritative', function () {
    expect(invokeDecideCname(
        [['authoritative' => true, 'targets' => ['label.proxy.example.com.']]],
        'label.proxy.example.com'
    ))->toBe(['matched' => true, 'authoritative' => true]);
});

test('decideCnameOutcome 权威不匹配 → authoritative 但不 matched', function () {
    expect(invokeDecideCname(
        [['authoritative' => true, 'targets' => ['other.example.com']]],
        'label.proxy.example.com'
    ))->toBe(['matched' => false, 'authoritative' => true]);
});

test('decideCnameOutcome 权威空记录 → authoritative 但不 matched', function () {
    expect(invokeDecideCname(
        [['authoritative' => true, 'targets' => []]],
        'label.proxy.example.com'
    ))->toBe(['matched' => false, 'authoritative' => true]);
});

test('decideCnameOutcome 全渠道失败 → 既不 matched 也不 authoritative', function () {
    expect(invokeDecideCname(
        [['authoritative' => false, 'targets' => []]],
        'label.proxy.example.com'
    ))->toBe(['matched' => false, 'authoritative' => false]);
});

// ── 适配层端到端（穿过渠道适配层，防「合成输入直喂决策树」假绿；对应 RECHECK 容器实证矩阵）──
test('适配层：本地 cnameRecords 返 null（死解析器/不可达）→ authoritative=false', function () {
    stubLocalCname(null);

    $out = VerifyUtil::verifyCnameDelegationDetailed('_dnsauth.example.com', 'label.proxy.example.com');

    expect($out['authoritative'])->toBeFalse()
        ->and($out['matched'])->toBeFalse();
});

test('适配层：本地 cnameRecords 返 [] (NXDOMAIN/权威无记录) → authoritative=true、matched=false', function () {
    stubLocalCname([]);

    $out = VerifyUtil::verifyCnameDelegationDetailed('_dnsauth.example.com', 'label.proxy.example.com');

    expect($out['authoritative'])->toBeTrue()
        ->and($out['matched'])->toBeFalse();
});

test('适配层：本地 cnameRecords 命中期望目标 → matched + authoritative', function () {
    stubLocalCname(['label.proxy.example.com.']);

    $out = VerifyUtil::verifyCnameDelegationDetailed('_dnsauth.example.com', 'label.proxy.example.com');

    expect($out['matched'])->toBeTrue()
        ->and($out['authoritative'])->toBeTrue();
});

// ── bool 薄包装等价（matched 即 verifyCnameDelegation）──────────────────────────
test('verifyCnameDelegation bool 包装 = detailed matched', function () {
    stubLocalCname(['label.proxy.example.com.']);
    expect(VerifyUtil::verifyCnameDelegation('_dnsauth.example.com', 'label.proxy.example.com'))->toBeTrue();

    stubLocalCname([]);
    expect(VerifyUtil::verifyCnameDelegation('_dnsauth.example.com', 'label.proxy.example.com'))->toBeFalse();
});
