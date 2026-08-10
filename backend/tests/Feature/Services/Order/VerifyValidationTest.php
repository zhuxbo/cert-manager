<?php

use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Delegation\DnsResolver;
use App\Services\Order\Utils\VerifyUtil;
use GuzzleHttp\Psr7\PumpStream;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
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

test('dnsTools 首节点未通过时继续轮询，采用下一节点成功结果', function () {
    Http::preventStrayRequests();
    Http::fake([
        'dnstool1.test/*' => Http::response(['code' => 0, 'msg' => 'DNS 未就绪', 'errors' => []], 200),
        'dnstool2.test/*' => Http::response(['code' => 1, 'msg' => '第二节点通过', 'errors' => []], 200),
    ]);

    $validation = [
        ['domain' => 'example.com', 'method' => 'txt', 'host' => '_dnsauth.example.com', 'value' => 'tok'],
    ];

    $result = VerifyUtil::verifyValidation($validation);

    expect($result['code'])->toBe(1)
        ->and($result['msg'])->toBe('第二节点通过')
        ->and($result['dns_tools_down'] ?? false)->toBeFalse();
});

test('dnsTools 均返回未通过但本地实时命中时采用本地结果', function () {
    Http::preventStrayRequests();
    Http::fake([
        'dnstool1.test/*' => Http::response(['code' => 0, 'msg' => '节点一缓存未更新', 'errors' => []], 200),
        'dnstool2.test/*' => Http::response(['code' => 0, 'msg' => '节点二缓存未更新', 'errors' => []], 200),
    ]);
    stubDnsResolver(txt: ['tok']);

    $result = VerifyUtil::verifyValidation([[
        'domain' => 'example.com',
        'method' => 'txt',
        'host' => '_dnsauth.example.com',
        'value' => 'tok',
    ]]);

    expect($result['code'])->toBe(1)
        ->and($result['msg'])->toBe('本地 DCV 验证通过')
        ->and($result['dns_tools_down'] ?? false)->toBeFalse();
});

// dnsTools 有应答且本地也未命中 → 保留远端未通过结果，不打 infra-down 标记。
test('dnsTools 均未通过且本地未命中 → code=0 无 dns_tools_down 标记', function () {
    Http::preventStrayRequests();
    Http::fake([
        'dnstool1.test/*' => Http::response(['code' => 0, 'msg' => '节点一未就绪', 'errors' => []], 200),
        'dnstool2.test/*' => Http::response(['code' => 0, 'msg' => '节点二未就绪', 'errors' => []], 200),
    ]);
    stubDnsResolver(txt: []);

    $result = VerifyUtil::verifyValidation([[
        'domain' => 'example.com',
        'method' => 'txt',
        'host' => '_dnsauth.example.com',
        'value' => 'tok',
    ]]);

    expect($result['code'])->toBe(0)
        ->and($result['msg'])->toBe('节点二未就绪')
        ->and($result['dns_tools_down'] ?? false)->toBeFalse();
});

test('dnsTools 未配置时直接本地检测且不标记基础设施故障', function () {
    setDnsToolsUrls([]);
    Http::preventStrayRequests();
    Http::fake();

    $mock = Mockery::mock(DnsResolver::class);
    $mock->shouldReceive('txt')
        ->twice()
        ->with('_dnsauth.example.com')
        ->andReturn(['expected-token']);
    app()->instance(DnsResolver::class, $mock);

    $validation = [
        ['domain' => 'example.com', 'method' => 'txt', 'host' => '_dnsauth.example.com', 'value' => 'expected-token'],
    ];

    $first = VerifyUtil::verifyValidation($validation);
    $second = VerifyUtil::verifyValidation($validation);

    expect($first['code'])->toBe(1)
        ->and($first['msg'])->toBe('本地 DCV 验证通过')
        ->and($first['dns_tools_down'] ?? false)->toBeFalse()
        ->and($second['code'])->toBe(1)
        ->and($second['dns_tools_down'] ?? false)->toBeFalse();
    Http::assertNothingSent();
});

test('dnsTools 未配置且本地未命中时正常返回待验证而非节点故障', function () {
    setDnsToolsUrls([]);
    Http::preventStrayRequests();
    Http::fake();
    stubDnsResolver(txt: []);

    $result = VerifyUtil::verifyValidation([[
        'domain' => 'example.com',
        'method' => 'txt',
        'host' => '_dnsauth.example.com',
        'value' => 'expected-token',
    ]]);

    expect($result['code'])->toBe(0)
        ->and($result['msg'])->toBe('本地 DCV 验证未通过')
        ->and($result['dns_tools_down'] ?? false)->toBeFalse();
    Http::assertNothingSent();
});

// 5. file 方法由本机直接读取验证文件，dnsTools 全挂时仍可通过
test('dnsTools 全挂 + 本地文件内容命中 → code=1 + dns_tools_down', function () {
    Http::preventStrayRequests();
    Http::fake([
        'dnstool1.test/*' => fn () => throw new ConnectionException('conn fail 1'),
        'dnstool2.test/*' => fn () => throw new ConnectionException('conn fail 2'),
        'http://93.184.216.34/*' => Http::response("line-1\r\nline-2\r\n"),
    ]);

    $validation = [
        [
            'domain' => '93.184.216.34',
            'method' => 'file',
            'name' => 'x.txt',
            'content' => "line-1\nline-2\n",
            'link' => '//93.184.216.34/.well-known/pki-validation/x.txt',
        ],
    ];

    $result = VerifyUtil::verifyValidation($validation);

    expect($result['code'])->toBe(1)
        ->and($result['dns_tools_down'] ?? false)->toBeTrue();
});

test('未配置 dnsTools 时文件检测每次重新请求且发送禁用缓存请求头', function () {
    setDnsToolsUrls([]);
    Http::preventStrayRequests();
    $requestCount = 0;
    Http::fake([
        'http://93.184.216.34/*' => function () use (&$requestCount) {
            $requestCount++;

            return Http::response("line-1\nline-2\n");
        },
    ]);

    $validation = [[
        'domain' => '93.184.216.34',
        'method' => 'http',
        'content' => "line-1\nline-2\n",
        'link' => 'http://93.184.216.34/.well-known/pki-validation/x.txt',
    ]];

    $first = VerifyUtil::verifyValidation($validation);
    $second = VerifyUtil::verifyValidation($validation);

    expect($first['code'])->toBe(1)
        ->and($second['code'])->toBe(1)
        ->and($requestCount)->toBe(2);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Cache-Control', 'no-cache, no-store, max-age=0')
        && $request->hasHeader('Pragma', 'no-cache'));
});

test('本地文件检测有界读取会拼接完整分块响应', function () {
    $chunks = ['line-', "1\nline", "-2\n"];
    $stream = new PumpStream(function () use (&$chunks) {
        return array_shift($chunks) ?? false;
    });
    $method = new ReflectionMethod(VerifyUtil::class, 'readLimitedBody');

    expect($method->invoke(null, $stream, 8192))->toBe("line-1\nline-2\n");
});

test('本地文件检测拒绝超过 8 KiB 的响应', function () {
    setDnsToolsUrls([]);
    Http::preventStrayRequests();
    $oversized = str_repeat('a', 8193);
    Http::fake([
        'https://93.184.216.34/*' => Http::response($oversized),
    ]);

    $result = VerifyUtil::verifyValidation([[
        'domain' => '93.184.216.34',
        'method' => 'https',
        'content' => $oversized,
        'link' => 'https://93.184.216.34/.well-known/pki-validation/x.txt',
    ]]);

    expect($result['code'])->toBe(0)
        ->and($result['dns_tools_down'] ?? false)->toBeFalse();
});

test('dnsTools 全挂 + 本地文件内容不匹配 → code=0 + dns_tools_down', function () {
    Http::preventStrayRequests();
    Http::fake([
        'dnstool1.test/*' => fn () => throw new ConnectionException('conn fail 1'),
        'dnstool2.test/*' => fn () => throw new ConnectionException('conn fail 2'),
        'https://93.184.216.34/*' => Http::response('wrong-content'),
    ]);

    $result = VerifyUtil::verifyValidation([[
        'domain' => '93.184.216.34',
        'method' => 'https',
        'content' => 'expected-content',
        'link' => 'https://93.184.216.34/.well-known/pki-validation/x.txt',
    ]]);

    expect($result['code'])->toBe(0)
        ->and($result['dns_tools_down'] ?? false)->toBeTrue();
});

test('文件本地兜底拒绝私网地址且不发起请求', function () {
    Http::preventStrayRequests();
    Http::fake([
        'dnstool1.test/*' => fn () => throw new ConnectionException('conn fail 1'),
        'dnstool2.test/*' => fn () => throw new ConnectionException('conn fail 2'),
    ]);

    $result = VerifyUtil::verifyValidation([[
        'domain' => '127.0.0.1',
        'method' => 'http',
        'content' => 'expected-content',
        'link' => 'http://127.0.0.1/.well-known/pki-validation/x.txt',
    ]]);

    expect($result['code'])->toBe(0)
        ->and($result['dns_tools_down'] ?? false)->toBeTrue();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
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
