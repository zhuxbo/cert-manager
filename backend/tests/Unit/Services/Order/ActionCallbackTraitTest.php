<?php

use App\Exceptions\ApiResponseException;
use App\Services\Order\Traits\ActionCallbackTrait;
use App\Traits\ApiResponse;
use App\Utils\IpUtil;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 回调地址 SSRF 防护（isPrivateUrl）单元测试 — 白名单制（反模式 18）
 *
 * 仅公网 IP 放行；私网 / loopback / link-local（含云元数据）/ CGNAT /
 * 多播 / 保留段与解析失败一律拒绝（fail-closed）。
 */
function invokeIsPrivateUrl(string $url): bool
{
    $harness = new class
    {
        use ActionCallbackTrait;
    };

    return (new ReflectionMethod($harness, 'isPrivateUrl'))->invoke($harness, $url);
}

test('isPrivateUrl 拒绝私网/保留段回调地址', function (string $url) {
    expect(invokeIsPrivateUrl($url))->toBeTrue();
})->with([
    'loopback 127.0.0.1' => ['http://127.0.0.1/notify'],
    'RFC1918 10/8' => ['http://10.0.0.8/notify'],
    'RFC1918 172.16/12' => ['http://172.16.5.5/notify'],
    'RFC1918 192.168/16' => ['http://192.168.1.100/notify'],
    'link-local 云元数据 169.254.169.254' => ['http://169.254.169.254/latest/meta-data'],
    'CGNAT 下界 100.64.0.1' => ['http://100.64.0.1/notify'],
    'CGNAT 上界 100.127.255.254' => ['http://100.127.255.254/notify'],
    '0.0.0.0' => ['http://0.0.0.0/notify'],
    '多播 224.0.0.1' => ['http://224.0.0.1/notify'],
    '广播 255.255.255.255' => ['http://255.255.255.255/notify'],
    'IPv6 loopback ::1' => ['http://[::1]/notify'],
    'IPv6 link-local fe80::/10' => ['http://[fe80::1]/notify'],
    'IPv6 ULA fc00::/7' => ['http://[fd12:3456::1]/notify'],
    'IPv4-mapped 私网' => ['http://[::ffff:192.168.0.1]/notify'],
]);

test('isPrivateUrl 放行公网回调地址', function (string $url) {
    expect(invokeIsPrivateUrl($url))->toBeFalse();
})->with([
    '公网 8.8.8.8' => ['https://8.8.8.8/notify'],
    '公网 1.1.1.1' => ['http://1.1.1.1/notify'],
    'CGNAT 下边界外 100.63.255.255' => ['https://100.63.255.255/notify'],
    'CGNAT 上边界外 100.128.0.1' => ['https://100.128.0.1/notify'],
    '多播边界外 223.255.255.254' => ['https://223.255.255.254/notify'],
]);

test('isPrivateUrl 解析失败与畸形 URL 一律 fail-closed 拒绝', function (string $url) {
    // .invalid 在正常 DNS 下 NXDOMAIN（解析失败 fail-closed）；
    // fake-ip 代理 DNS 下会解析到 198.18.0.0/15 benchmark 保留段（同样被拒），两种环境断言一致
    expect(invokeIsPrivateUrl($url))->toBeTrue();
})->with([
    'DNS 解析失败' => ['http://nonexistent-callback-host.invalid/notify'],
    '无 host 的畸形 URL' => ['not-a-valid-url'],
    '空 host' => ['http:///notify'],
]);

test('isPrivateUrl 域名解析到内网 IP 时拒绝', function () {
    // localhost 解析为 127.0.0.1，覆盖"域名解析成功 → 判 IP"分支
    expect(invokeIsPrivateUrl('http://localhost/notify'))->toBeTrue();
});

test('callback 禁止跟随公网 URL 发出的内网重定向', function () {
    $mock = new MockHandler([
        new GuzzleResponse(302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
        new GuzzleResponse(200, [], 'metadata'),
    ]);
    $factory = new class($mock) extends Factory
    {
        public function __construct(private readonly MockHandler $testHandler)
        {
            parent::__construct();
        }

        protected function newPendingRequest()
        {
            return parent::newPendingRequest()->setHandler($this->testHandler);
        }
    };
    $originalFactory = Http::getFacadeRoot();
    Http::swap($factory);

    $harness = new class
    {
        use ActionCallbackTrait;
    };

    try {
        $response = (new ReflectionMethod($harness, 'postCallback'))
            ->invoke($harness, 'https://8.8.8.8/callback', ['id' => 1]);

        expect($response->status())->toBe(302)
            ->and($mock->count())->toBe(1);
    } finally {
        Http::swap($originalFactory);
    }
});

test('IpUtil::isPrivateOrReserved 拒绝私网/保留段 IP', function (string $ip) {
    expect(IpUtil::isPrivateOrReserved($ip))->toBeTrue();
})->with([
    'loopback' => ['127.0.0.1'],
    'RFC1918 10/8 上界' => ['10.255.255.255'],
    'CGNAT 起点' => ['100.64.0.0'],
    'link-local' => ['169.254.0.1'],
    '0.0.0.0' => ['0.0.0.0'],
    '保留段 240/4' => ['240.0.0.1'],
    'benchmark 198.18/15（fake-ip 代理常用）' => ['198.18.1.36'],
    'TEST-NET-1' => ['192.0.2.1'],
    'IPv6 loopback' => ['::1'],
    'IPv6 unspecified' => ['::'],
    'IPv6 link-local' => ['fe80::abcd'],
    'IPv6 ULA' => ['fc00::1'],
    '非法 IP fail-closed' => ['999.9.9.9'],
    '非 IP 字符串 fail-closed' => ['not-an-ip'],
]);

test('IpUtil::isPrivateOrReserved 放行公网 IP', function (string $ip) {
    expect(IpUtil::isPrivateOrReserved($ip))->toBeFalse();
})->with([
    '公网 IPv4' => ['8.8.8.8'],
    'CGNAT 边界外' => ['100.63.255.255'],
    '公网 IPv6' => ['2001:4860:4860::8888'],
    'IPv4-mapped 公网' => ['::ffff:8.8.8.8'],
]);

test('callback 临时传输失败转换为固定简洁业务错误', function () {
    Sleep::fake();
    Http::fake(fn () => throw new ConnectionException('cURL error 35: vendor/path/PendingRequest.php:1822'));

    $harness = new class
    {
        use ActionCallbackTrait, ApiResponse;
    };

    try {
        (new ReflectionMethod($harness, 'postCallback'))->invoke($harness, 'https://8.8.8.8/callback', ['id' => 1]);
        test()->fail('Expected ApiResponseException');
    } catch (ReflectionException $e) {
        throw $e;
    } catch (Throwable $e) {
        $exception = $e instanceof ReflectionException ? $e : ($e->getPrevious() ?? $e);
        expect($exception)->toBeInstanceOf(ApiResponseException::class);
        $response = $exception->getApiResponse();
        expect($response['code'])->toBe(0)
            ->and($response['msg'])->toBe('回调地址暂时无法连接')
            ->and($response['errors']['request_attempts'])->toBe(2)
            ->and(json_encode($response))->not->toContain('cURL')
            ->not->toContain('PendingRequest.php')
            ->not->toContain('trace');
    } finally {
        Sleep::fake(false);
    }
});
