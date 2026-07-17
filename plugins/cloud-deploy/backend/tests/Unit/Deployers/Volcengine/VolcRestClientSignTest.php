<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcApiException;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcRestClient;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 构造一个带「请求历史捕获 + 预置响应」的 VolcRestClient。
 *
 * @param  array<int,Response>  $responses  依次返回的响应
 * @param  array<int,array{0:RequestInterface}>  &$history  捕获的请求历史（引用）
 */
function volcClientWithHistory(string $host, string $service, string $region, string $ak, string $sk, array $responses, array &$history): VolcRestClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false]);

    return new VolcRestClient($host, $service, $region, $ak, $sk, $http);
}

/**
 * 独立复刻火山 OpenAPI V4 签名（与被测实现解耦的「第二实现」），用于交叉验证 Authorization。
 * 逐字节对齐官方算法：algorithm=HMAC-SHA256、scope 末段 request、初始密钥为原始 secret。
 *
 * @param  array<string,string>  $signedHeaderValues  小写 header 名 => 值（已按需取自请求）
 * @param  list<string>  $signedNames  已排序的小写 signed header 名
 */
function volcExpectedAuthorization(
    string $method,
    string $canonicalQuery,
    array $signedHeaderValues,
    array $signedNames,
    string $hashedPayload,
    string $service,
    string $region,
    string $ak,
    string $sk,
    string $xdate,
): string {
    $canonicalHeaders = '';
    foreach ($signedNames as $name) {
        $canonicalHeaders .= $name.':'.trim($signedHeaderValues[$name] ?? '')."\n";
    }
    $signedHeaders = implode(';', $signedNames);

    $canonicalRequest = implode("\n", [
        $method, '/', $canonicalQuery, $canonicalHeaders, $signedHeaders, $hashedPayload,
    ]);

    $date = substr($xdate, 0, 8);
    $scope = "$date/$region/$service/request";
    $stringToSign = implode("\n", ['HMAC-SHA256', $xdate, $scope, hash('sha256', $canonicalRequest)]);

    $kDate = hash_hmac('sha256', $date, $sk, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    return "HMAC-SHA256 Credential=$ak/$scope, SignedHeaders=$signedHeaders, Signature=$signature";
}

test('callJson 签名：POST JSON body 的 Authorization 与独立复刻算法逐字节一致', function () {
    $history = [];
    $client = volcClientWithHistory(
        VolcRestClient::OPEN_HOST, 'cdn', 'cn-north-1', 'AKID', 'SECRET',
        [new Response(200, [], json_encode(['Result' => ['CertId' => 'c1']]))],
        $history,
    );

    $client->callJson('BatchDeployCert', '2021-03-01', ['Domain' => 'd.example.com', 'CertId' => 'cert-1']);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];

    // body 与签名所用 body 必须同串
    $payload = (string) $req->getBody();
    expect($payload)->toBe(json_encode(['Domain' => 'd.example.com', 'CertId' => 'cert-1'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $hashedPayload = hash('sha256', $payload);
    expect($req->getHeaderLine('X-Content-Sha256'))->toBe($hashedPayload);

    // 方法 + URL（query 已排序、RFC3986）
    expect($req->getMethod())->toBe('POST');
    expect((string) $req->getUri())->toBe('https://open.volcengineapi.com/?Action=BatchDeployCert&Version=2021-03-01');

    $xdate = $req->getHeaderLine('X-Date');
    $signedNames = ['content-type', 'host', 'x-content-sha256', 'x-date'];
    $signedHeaderValues = [
        'content-type' => 'application/json',
        'host' => 'open.volcengineapi.com',
        'x-content-sha256' => $hashedPayload,
        'x-date' => $xdate,
    ];
    // canonical query 含 Action+Version（排序后 Action < Version）
    $canonicalQuery = 'Action=BatchDeployCert&Version=2021-03-01';

    $expected = volcExpectedAuthorization(
        'POST', $canonicalQuery, $signedHeaderValues, $signedNames, $hashedPayload,
        'cdn', 'cn-north-1', 'AKID', 'SECRET', $xdate,
    );

    expect($req->getHeaderLine('Authorization'))->toBe($expected);
    // Authorization 结构 sanity（算法名、Credential/scope、SignedHeaders、Signature 段）
    expect($expected)->toStartWith('HMAC-SHA256 Credential=AKID/');
    expect($expected)->toContain('/cn-north-1/cdn/request,');
    expect($expected)->toContain('SignedHeaders=content-type;host;x-content-sha256;x-date,');
    expect($expected)->toMatch('/Signature=[0-9a-f]{64}$/');
});

test('callQuery 签名：GET 无 body，签名头不含 content-type，query 平铺进 canonical 串', function () {
    $history = [];
    $client = volcClientWithHistory(
        VolcRestClient::OPEN_HOST, 'clb', 'cn-beijing', 'AKID', 'SECRET',
        [new Response(200, [], json_encode(['Result' => []]))],
        $history,
    );

    $client->callQuery('ModifyListenerAttributes', '2020-04-01', [
        'ListenerId' => 'lsn-1',
        'CertificateSource' => 'cert_center',
        'CertCenterCertificateId' => 'cert-9',
    ]);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];

    expect($req->getMethod())->toBe('GET');
    expect((string) $req->getBody())->toBe('');
    // GET 空 body 的 hash = sha256('')
    $emptyHash = hash('sha256', '');
    expect($req->getHeaderLine('X-Content-Sha256'))->toBe($emptyHash);
    // 无 content-type 头
    expect($req->hasHeader('Content-Type'))->toBeFalse();

    // canonical query 键排序：Action,CertCenterCertificateId,CertificateSource,ListenerId,Version
    $canonicalQuery = 'Action=ModifyListenerAttributes&CertCenterCertificateId=cert-9&CertificateSource=cert_center&ListenerId=lsn-1&Version=2020-04-01';
    expect((string) $req->getUri())->toBe('https://open.volcengineapi.com/?'.$canonicalQuery);

    $xdate = $req->getHeaderLine('X-Date');
    $signedNames = ['host', 'x-content-sha256', 'x-date'];
    $signedHeaderValues = [
        'host' => 'open.volcengineapi.com',
        'x-content-sha256' => $emptyHash,
        'x-date' => $xdate,
    ];

    $expected = volcExpectedAuthorization(
        'GET', $canonicalQuery, $signedHeaderValues, $signedNames, $emptyHash,
        'clb', 'cn-beijing', 'AKID', 'SECRET', $xdate,
    );
    expect($req->getHeaderLine('Authorization'))->toBe($expected);
    expect($expected)->toContain('SignedHeaders=host;x-content-sha256;x-date,');
});

test('callQuery 嵌套 list 平铺为 Key.1.Field（1-based、点分、PascalCase）', function () {
    $history = [];
    $client = volcClientWithHistory(
        VolcRestClient::OPEN_HOST, 'alb', 'cn-beijing', 'AKID', 'SECRET',
        [new Response(200, [], json_encode(['Result' => []]))],
        $history,
    );

    $client->callQuery('ModifyListenerAttributes', '2020-04-01', [
        'ListenerId' => 'lsn-1',
        'DomainExtensions' => [
            ['DomainExtensionId' => 'de-1', 'Domain' => 'a.example.com', 'Action' => 'modify'],
            ['DomainExtensionId' => 'de-2', 'Domain' => 'b.example.com', 'Action' => 'modify'],
        ],
    ]);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    $uri = (string) $req->getUri();

    // 1-based 索引、点分、字段名 PascalCase 原样
    expect($uri)->toContain('DomainExtensions.1.DomainExtensionId=de-1');
    expect($uri)->toContain('DomainExtensions.1.Domain=a.example.com');
    expect($uri)->toContain('DomainExtensions.1.Action=modify');
    expect($uri)->toContain('DomainExtensions.2.DomainExtensionId=de-2');
    expect($uri)->toContain('DomainExtensions.2.Domain=b.example.com');
});

test('putTos 签名：TOS4-HMAC-SHA256、service=tos、host {bucket}.tos-{region}.volces.com、path /?customdomain', function () {
    $history = [];
    // TOS host 在 putTos 内按 bucket+region 构造，构造器 host/service 占位即可
    $client = volcClientWithHistory(
        'tos-cn-beijing.volces.com', 'tos', 'cn-beijing', 'AKID', 'SECRET',
        [new Response(200, [], '')],
        $history,
    );

    $client->putTos('my-bucket', 'cn-beijing', ['CustomDomainRule' => ['Domain' => 'cdn.example.com', 'CertId' => 'cert-7']]);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('PUT');
    expect((string) $req->getUri())->toBe('https://my-bucket.tos-cn-beijing.volces.com/?customdomain');

    $auth = $req->getHeaderLine('Authorization');
    expect($auth)->toStartWith('TOS4-HMAC-SHA256 Credential=AKID/');
    expect($auth)->toContain('/cn-beijing/tos/request,');
    // TOS 签名头集（小写、排序）
    expect($auth)->toContain('SignedHeaders=content-type;host;x-tos-content-sha256;x-tos-date,');
    expect($req->getHeaderLine('X-Tos-Content-Sha256'))->toBe(hash('sha256', (string) $req->getBody()));
});

test('HTTP 非 2xx 抛 VolcApiException（响应体 Error 优先），含 code/message 无凭证', function () {
    $history = [];
    $client = volcClientWithHistory(
        VolcRestClient::OPEN_HOST, 'cdn', 'cn-north-1', 'AKID', 'SECRET',
        [new Response(400, [], json_encode([
            'ResponseMetadata' => ['Error' => ['Code' => 'InvalidCert', 'Message' => 'cert not found']],
        ]))],
        $history,
    );

    try {
        $client->callJson('BatchDeployCert', '2021-03-01', ['Domain' => 'd', 'CertId' => 'c']);
        expect(false)->toBeTrue('应抛异常');
    } catch (VolcApiException $e) {
        expect($e->getErrorCode())->toBe('InvalidCert');
        expect($e->getErrorMessage())->toBe('cert not found');
        expect($e->getMessage())->not->toContain('SECRET')->not->toContain('AKID');
    }
});

test('HTTP 2xx 但响应体 ResponseMetadata.Error 非空也视为业务失败', function () {
    $history = [];
    $client = volcClientWithHistory(
        VolcRestClient::OPEN_HOST, 'apig', 'cn-beijing', 'AKID', 'SECRET',
        [new Response(200, [], json_encode([
            'ResponseMetadata' => ['Error' => ['Code' => 'QuotaExceeded', 'Message' => 'too many certs']],
        ]))],
        $history,
    );

    expect(fn () => $client->callJson('UpdateCustomDomain', '2021-03-03', ['Id' => 'x']))
        ->toThrow(VolcApiException::class, 'too many certs');
});
