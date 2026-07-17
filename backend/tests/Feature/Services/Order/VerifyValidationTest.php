<?php

use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Delegation\DnsResolver;
use App\Services\Order\Utils\VerifyUtil;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** 配置 site.dnsTools URL 列表 */
function setDnsToolsUrls(array $urls): void
{
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => '站点', 'weight' => 1]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'dnsTools'],
        ['type' => 'array', 'value' => $urls, 'weight' => 0]
    );
    Setting::clearGroupCache($group->id);
    Cache::flush();
}

/** 注入 DnsResolver 桩：txt/cname 返回指定值 */
function stubDnsResolver(array $txt = [], array $cname = []): void
{
    $mock = Mockery::mock(DnsResolver::class);
    $mock->shouldReceive('txt')->andReturn($txt);
    $mock->shouldReceive('cname')->andReturn($cname);
    app()->instance(DnsResolver::class, $mock);
}

beforeEach(function () {
    setDnsToolsUrls(['http://dnstool1.test', 'http://dnstool2.test']);
});

afterEach(function () {
    Mockery::close();
});

// 1. dnsTools 全连接异常 + 本地 DNS 命中期望 TXT → code=1（走 revalidate 自愈）+ dns_tools_down
test('dnsTools 全挂 + 本地 DNS 命中 → code=1 且带 dns_tools_down（infra 挂但本地兜底）', function () {
    Http::preventStrayRequests();
    Http::fake([
        'dnstool1.test/*' => fn () => throw new ConnectionException('conn fail 1'),
        'dnstool2.test/*' => fn () => throw new ConnectionException('conn fail 2'),
    ]);
    stubDnsResolver(txt: ['expected-token']);

    $validation = [
        ['domain' => 'example.com', 'method' => 'txt', 'host' => '_dnsauth.example.com', 'value' => 'expected-token'],
    ];

    $result = VerifyUtil::verifyValidation($validation);

    expect($result['code'])->toBe(1)
        ->and($result['dns_tools_down'] ?? false)->toBeTrue();
});

// 2. dnsTools 全挂 + 本地不可判定（stub 空）→ code=0 + dns_tools_down=true
test('dnsTools 全挂 + 本地不可判定 → code=0 且 dns_tools_down=true', function () {
    Http::preventStrayRequests();
    Http::fake([
        'dnstool1.test/*' => fn () => throw new ConnectionException('conn fail 1'),
        'dnstool2.test/*' => fn () => throw new ConnectionException('conn fail 2'),
    ]);
    stubDnsResolver(txt: []); // 本地未命中

    $validation = [
        ['domain' => 'example.com', 'method' => 'txt', 'host' => '_dnsauth.example.com', 'value' => 'expected-token'],
    ];

    $result = VerifyUtil::verifyValidation($validation);

    expect($result['code'])->toBe(0)
        ->and($result['dns_tools_down'] ?? false)->toBeTrue();
});

// 3. 5xx 故障转移：首节点 500 + 合法 JSON 体、次节点 200+code=1 → 结果取自次节点（不采信 5xx 响应体）
test('5xx 故障转移：首节点 500 被跳过，取次节点 200 结果', function () {
    Http::preventStrayRequests();
    Http::fake([
        'dnstool1.test/*' => Http::response(['code' => 1, 'msg' => 'from-5xx-node'], 500),
        'dnstool2.test/*' => Http::response(['code' => 1, 'msg' => 'from-200-node'], 200),
    ]);

    $validation = [
        ['domain' => 'example.com', 'method' => 'txt', 'host' => '_dnsauth.example.com', 'value' => 'tok'],
    ];

    $result = VerifyUtil::verifyValidation($validation);

    // 取自次节点（200），5xx 节点响应体不被采信；无 infra-down（有节点应答）
    expect($result['code'])->toBe(1)
        ->and($result['msg'])->toBe('from-200-node')
        ->and($result['dns_tools_down'] ?? false)->toBeFalse();
});

// 4. dnsTools 有应答但校验失败（code=0）→ 无 dns_tools_down 标记（DNS 未就绪，正常）
test('dnsTools 应答但校验失败 → code=0 无 dns_tools_down 标记', function () {
    Http::preventStrayRequests();
    Http::fake([
        'dnstool1.test/*' => Http::response(['code' => 0, 'msg' => 'DNS 未就绪', 'errors' => []], 200),
    ]);

    $validation = [
        ['domain' => 'example.com', 'method' => 'txt', 'host' => '_dnsauth.example.com', 'value' => 'tok'],
    ];

    $result = VerifyUtil::verifyValidation($validation);

    expect($result['code'])->toBe(0)
        ->and($result['dns_tools_down'] ?? false)->toBeFalse();
});

// 5. 含 file 方法项（非 DNS）→ 本地不可判定 → dnsTools 全挂时 code=0 + dns_tools_down
test('含 file 非 DNS 项 + dnsTools 全挂 → 本地不可判定 → code=0 + dns_tools_down', function () {
    Http::preventStrayRequests();
    Http::fake([
        'dnstool1.test/*' => fn () => throw new ConnectionException('conn fail 1'),
        'dnstool2.test/*' => fn () => throw new ConnectionException('conn fail 2'),
    ]);
    stubDnsResolver(txt: ['expected-token']); // 即便 txt 命中，含 file 项仍不可判定

    $validation = [
        ['domain' => 'example.com', 'method' => 'txt', 'host' => '_dnsauth.example.com', 'value' => 'expected-token'],
        ['domain' => 'example.com', 'method' => 'file', 'name' => 'x.txt', 'content' => 'c', 'link' => 'http://x'],
    ];

    $result = VerifyUtil::verifyValidation($validation);

    expect($result['code'])->toBe(0)
        ->and($result['dns_tools_down'] ?? false)->toBeTrue();
});

// 6. 裸前缀 host（_certum 无点，主力 CA 真实形态）→ 本地兜底用 domain 补全成 FQDN 再核对 → code=1
//    修复前：resolver->txt('_certum') 查单标签名恒空 → 恒不命中 → code=0（本地兜底对裸前缀结构性失效）。
test('裸前缀 host txt 用 domain 补全成 FQDN 后命中 → code=1（堵裸前缀结构性失效）', function () {
    Http::preventStrayRequests();
    Http::fake([
        'dnstool1.test/*' => fn () => throw new ConnectionException('down 1'),
        'dnstool2.test/*' => fn () => throw new ConnectionException('down 2'),
    ]);

    // 桩校验 host 实参：仅补全后的 _certum.example.com 命中，裸前缀 _certum 返回空
    $mock = Mockery::mock(DnsResolver::class);
    $mock->shouldReceive('txt')->andReturnUsing(
        fn (string $host) => $host === '_certum.example.com' ? ['expected-token'] : []
    );
    app()->instance(DnsResolver::class, $mock);

    $validation = [
        ['domain' => 'example.com', 'method' => 'txt', 'host' => '_certum', 'value' => 'expected-token'],
    ];

    $result = VerifyUtil::verifyValidation($validation);

    expect($result['code'])->toBe(1)
        ->and($result['dns_tools_down'] ?? false)->toBeTrue();
});

// 7. 裸前缀 host cname 分支同样补全（对称覆盖 cname 路径）
test('裸前缀 host cname 用 domain 补全成 FQDN 后命中 → code=1', function () {
    Http::preventStrayRequests();
    Http::fake([
        'dnstool1.test/*' => fn () => throw new ConnectionException('down 1'),
        'dnstool2.test/*' => fn () => throw new ConnectionException('down 2'),
    ]);

    $mock = Mockery::mock(DnsResolver::class);
    $mock->shouldReceive('cname')->andReturnUsing(
        fn (string $host) => $host === '_dnsauth.example.com' ? ['target.proxy.example.com'] : []
    );
    app()->instance(DnsResolver::class, $mock);

    $validation = [
        ['domain' => 'example.com', 'method' => 'cname', 'host' => '_dnsauth', 'value' => 'target.proxy.example.com'],
    ];

    $result = VerifyUtil::verifyValidation($validation);

    expect($result['code'])->toBe(1)
        ->and($result['dns_tools_down'] ?? false)->toBeTrue();
});

// 8. 通配符 domain（*.example.com）补全去掉 *. 前缀 → 命中根域 FQDN
test('裸前缀 host + 通配符 domain 补全去 *. 前缀后命中 → code=1', function () {
    Http::preventStrayRequests();
    Http::fake([
        'dnstool1.test/*' => fn () => throw new ConnectionException('down 1'),
        'dnstool2.test/*' => fn () => throw new ConnectionException('down 2'),
    ]);

    $mock = Mockery::mock(DnsResolver::class);
    $mock->shouldReceive('txt')->andReturnUsing(
        fn (string $host) => $host === '_certum.example.com' ? ['wild-token'] : []
    );
    app()->instance(DnsResolver::class, $mock);

    $validation = [
        ['domain' => '*.example.com', 'method' => 'txt', 'host' => '_certum', 'value' => 'wild-token'],
    ];

    $result = VerifyUtil::verifyValidation($validation);

    expect($result['code'])->toBe(1);
});

// 9. 边界：裸前缀 host 但 domain 缺失 → 无法补全 → 保持不可判定 code=0（不对无意义单标签做查询）
test('裸前缀 host 且 domain 缺失 → 无法补全 → 不可判定 code=0', function () {
    Http::preventStrayRequests();
    Http::fake([
        'dnstool1.test/*' => fn () => throw new ConnectionException('down 1'),
        'dnstool2.test/*' => fn () => throw new ConnectionException('down 2'),
    ]);
    stubDnsResolver(txt: ['expected-token']); // 即便桩对裸前缀返回命中，缺 domain 仍应判不可判定

    $validation = [
        ['method' => 'txt', 'host' => '_certum', 'value' => 'expected-token'], // 无 domain
    ];

    $result = VerifyUtil::verifyValidation($validation);

    expect($result['code'])->toBe(0)
        ->and($result['dns_tools_down'] ?? false)->toBeTrue();
});
