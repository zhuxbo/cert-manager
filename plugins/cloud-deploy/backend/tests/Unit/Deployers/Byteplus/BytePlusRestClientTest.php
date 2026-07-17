<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusApiException;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * BytePlus REST 客户端 + volc/TOS 签名测试。
 *
 * 关键：BytePlusRestClient 构造接受注入的 GuzzleHttp\Client（$http），故用 MockHandler 短路真实 HTTP +
 * history 中间件捕获**实际签名后**发出的请求，再用「独立重算」校验签名（拿请求头里的 X-Date 复算，
 * 不依赖 frozen clock）—— 测「我们生成的签名 == 算法规范」，而非测 Guzzle 自身行为。
 */

/**
 * 造一个带 MockHandler（返回 $response）+ history（写入 $container）的 Guzzle client。
 *
 * @param  array<int,mixed>  $container
 */
function byteplusMockHttp(Response $response, array &$container): Client
{
    $stack = HandlerStack::create(new MockHandler([$response]));
    $stack->push(Middleware::history($container));

    return new Client(['handler' => $stack]);
}

/** volc OpenAPI 成功响应体（Result 为业务字段）。 */
function byteplusOpenApiResponse(array $result): Response
{
    return new Response(200, [], (string) json_encode([
        'ResponseMetadata' => ['RequestId' => 'req-test-1'],
        'Result' => $result,
    ]));
}

/**
 * 独立重算 volc OpenAPI HMAC-SHA256 v4 签名（规范实现，作金标准对照 client 输出）。
 *
 * @param  array<string,scalar>  $query
 */
function byteplusExpectedOpenApiAuthorization(
    string $method,
    string $host,
    string $service,
    string $region,
    string $ak,
    string $sk,
    array $query,
    string $body,
    string $contentType,
    string $xDate,
): string {
    $shortDate = substr($xDate, 0, 8);
    $payloadHash = strtolower(hash('sha256', $body));

    ksort($query);
    $canonicalQuery = implode('&', array_map(
        fn ($k) => rawurlencode((string) $k).'='.rawurlencode((string) $query[$k]),
        array_keys($query),
    ));

    $canonicalHeaders = "content-type:$contentType\nhost:$host\nx-content-sha256:$payloadHash\nx-date:$xDate\n";
    $signedHeaders = 'content-type;host;x-content-sha256;x-date';

    $canonicalRequest = implode("\n", [
        strtoupper($method), '/', $canonicalQuery, $canonicalHeaders, $signedHeaders, $payloadHash,
    ]);

    $scope = "$shortDate/$region/$service/request";
    $stringToSign = implode("\n", ['HMAC-SHA256', $xDate, $scope, strtolower(hash('sha256', $canonicalRequest))]);

    $kDate = hash_hmac('sha256', $shortDate, $sk, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    return "HMAC-SHA256 Credential=$ak/$scope, SignedHeaders=$signedHeaders, Signature=$signature";
}

test('golden vector：HMAC 派生签名固定输入→固定输出（secret 无前缀、链 kDate→kRegion→kService→request）', function () {
    // 固定全部输入，断言确定性输出。secret 直接进 kDate（无 AWS4 前缀）。
    $ak = 'AKLTtest';
    $sk = 'c2VjcmV0LWtleQ==';
    $region = 'ap-singapore-1';
    $service = 'certificate_service';
    $shortDate = '20240115';
    $stringToSign = "HMAC-SHA256\n20240115T120000Z\n20240115/ap-singapore-1/certificate_service/request\nabc123";

    // 独立重算金标准
    $kDate = hash_hmac('sha256', $shortDate, $sk, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'request', $kService, true);
    $expected = hash_hmac('sha256', $stringToSign, $kSigning);

    // 经反射调 client 的私有 deriveSignature，确认与金标准逐字节一致
    $client = new BytePlusRestClient($service, $region, $ak, $sk);
    $ref = new ReflectionMethod($client, 'deriveSignature');
    $actual = $ref->invoke($client, $stringToSign, $shortDate);

    expect($actual)->toBe($expected);
    // 固定值快照（防算法被无意改动）：secret 无前缀派生，链 kDate→kRegion→kService→"request"
    expect($actual)->toBe('cab814e033329b16d01a12e572744e159647d766cf4f1855b70163bd9cb0f5e0');
});

test('openApi POST：Action/Version 进 query，JSON body，签名头与独立重算一致（无 AK/SK 进 URL/body）', function () {
    $container = [];
    $http = byteplusMockHttp(byteplusOpenApiResponse(['CertId' => 'cert-xyz']), $container);

    $client = new BytePlusRestClient('certificate_service', 'ap-singapore-1', 'AKID', 'SKSECRET', BytePlusRestClient::OPENAPI_HOST, $http);
    $result = $client->openApi('POST', 'UploadCertificate', '2021-06-01', [], [
        'CertificateInfo' => ['CertificateChain' => 'PEM', 'PrivateKey' => 'KEY'],
        'Repeatable' => false,
    ]);

    expect($result->CertId)->toBe('cert-xyz');

    /** @var Request $req */
    $req = $container[0]['request'];
    expect($req->getMethod())->toBe('POST');

    // Action/Version 在 query string
    parse_str($req->getUri()->getQuery(), $q);
    expect($q['Action'])->toBe('UploadCertificate');
    expect($q['Version'])->toBe('2021-06-01');

    // body 是 JSON（与签名 payload 同串）
    $body = (string) $req->getBody();
    expect($body)->toContain('CertificateChain')->toContain('"Repeatable":false');

    // 头：Content-Type application/json，X-Content-Sha256 = sha256(body)
    expect($req->getHeaderLine('Content-Type'))->toBe('application/json; charset=utf-8');
    expect($req->getHeaderLine('X-Content-Sha256'))->toBe(strtolower(hash('sha256', $body)));

    // URL / body 不含凭证
    expect((string) $req->getUri())->not->toContain('SKSECRET');
    expect($body)->not->toContain('SKSECRET');

    // 独立重算 Authorization 逐字节一致（用请求头里的 X-Date 复算）
    $xDate = $req->getHeaderLine('X-Date');
    $expectedAuth = byteplusExpectedOpenApiAuthorization(
        'POST', BytePlusRestClient::OPENAPI_HOST, 'certificate_service', 'ap-singapore-1', 'AKID', 'SKSECRET',
        ['Action' => 'UploadCertificate', 'Version' => '2021-06-01'], $body, 'application/json; charset=utf-8', $xDate,
    );
    expect($req->getHeaderLine('Authorization'))->toBe($expectedAuth);
    // 签名包含 Credential=AKID/.../certificate_service/request 结构
    expect($req->getHeaderLine('Authorization'))->toStartWith('HMAC-SHA256 Credential=AKID/')
        ->toContain('/certificate_service/request')
        ->toContain('SignedHeaders=content-type;host;x-content-sha256;x-date');
});

test('openApi GET：入参进 query（含摊平的 DomainExtensions.N.*），body 空，签名一致', function () {
    $container = [];
    $http = byteplusMockHttp(byteplusOpenApiResponse([]), $container);

    $client = new BytePlusRestClient('alb', 'ap-singapore-1', 'AKID', 'SKSECRET', BytePlusRestClient::OPENAPI_HOST, $http);
    $query = [
        'ListenerId' => 'lsn-1',
        'DomainExtensions.1.DomainExtensionId' => 'de-1',
        'DomainExtensions.1.Domain' => 'a.example.com',
        'DomainExtensions.1.Action' => 'modify',
    ];
    $client->openApi('GET', 'ModifyListenerAttributes', '2020-04-01', $query);

    /** @var Request $req */
    $req = $container[0]['request'];
    expect($req->getMethod())->toBe('GET');
    expect((string) $req->getBody())->toBe('');

    // 注意：PHP parse_str 会把 key 里的 "." 替换成 "_"，故摊平键直接断言原始 query 串（RFC3986 编码后 "." 不转义）。
    $rawQuery = $req->getUri()->getQuery();
    expect($rawQuery)->toContain('Action=ModifyListenerAttributes');
    expect($rawQuery)->toContain('ListenerId=lsn-1');
    expect($rawQuery)->toContain('DomainExtensions.1.DomainExtensionId=de-1');
    expect($rawQuery)->toContain('DomainExtensions.1.Domain=a.example.com');
    expect($rawQuery)->toContain('DomainExtensions.1.Action=modify');

    // GET 空 body 的 payload hash 是空串 sha256
    $emptyHash = strtolower(hash('sha256', ''));
    expect($req->getHeaderLine('X-Content-Sha256'))->toBe($emptyHash);
    expect($req->getHeaderLine('Content-Type'))->toBe('application/x-www-form-urlencoded');

    $xDate = $req->getHeaderLine('X-Date');
    $expectedAuth = byteplusExpectedOpenApiAuthorization(
        'GET', BytePlusRestClient::OPENAPI_HOST, 'alb', 'ap-singapore-1', 'AKID', 'SKSECRET',
        array_merge($query, ['Action' => 'ModifyListenerAttributes', 'Version' => '2020-04-01']),
        '', 'application/x-www-form-urlencoded', $xDate,
    );
    expect($req->getHeaderLine('Authorization'))->toBe($expectedAuth);
});

test('openApi 响应 ResponseMetadata.Error 时抛 BytePlusApiException（携 Code+Message）', function () {
    $container = [];
    $resp = new Response(200, [], (string) json_encode([
        'ResponseMetadata' => ['RequestId' => 'r1', 'Error' => ['Code' => 'InvalidCertificate', 'Message' => 'bad cert']],
    ]));
    $http = byteplusMockHttp($resp, $container);

    $client = new BytePlusRestClient('cdn', 'cn-north-1', 'AK', 'SK', BytePlusRestClient::OPENAPI_HOST, $http);

    try {
        $client->openApi('POST', 'BatchDeployCert', '2021-03-01', [], ['CertId' => 'c', 'Domain' => 'd']);
        expect(false)->toBeTrue('应抛异常');
    } catch (BytePlusApiException $e) {
        expect($e->getErrorCode())->toBe('InvalidCertificate');
        expect($e->getErrorMessage())->toBe('bad cert');
    }
});

test('openApi HTTP 非 2xx（无结构化 Error）抛 BytePlusApiException（状态码作 code）', function () {
    $container = [];
    $http = byteplusMockHttp(new Response(500, [], 'gateway boom'), $container);

    $client = new BytePlusRestClient('cdn', 'cn-north-1', 'AK', 'SK', BytePlusRestClient::OPENAPI_HOST, $http);

    expect(fn () => $client->openApi('POST', 'BatchDeployCert', '2021-03-01', [], ['CertId' => 'c', 'Domain' => 'd']))
        ->toThrow(BytePlusApiException::class, '500');
});

test('tosPut：TOS4 签名头（X-Tos-Date/X-Tos-Content-Sha256），host 含 bucket，body=JSON，PUT /?customdomain', function () {
    $container = [];
    $http = byteplusMockHttp(new Response(200, [], ''), $container);

    $host = 'mybucket.tos-ap-singapore-1.bytepluses.com';
    $client = new BytePlusRestClient('tos', 'ap-singapore-1', 'AKID', 'SKSECRET', $host, $http);
    $client->tosPut('/?customdomain', [
        'CustomDomainRule' => ['Domain' => 'cdn.example.com', 'CertId' => 'cert-1'],
    ]);

    /** @var Request $req */
    $req = $container[0]['request'];
    expect($req->getMethod())->toBe('PUT');
    expect($req->getUri()->getHost())->toBe($host);
    expect($req->getUri()->getPath())->toBe('/');
    expect($req->getUri()->getQuery())->toBe('customdomain');

    $body = (string) $req->getBody();
    expect($body)->toContain('CustomDomainRule')->toContain('cert-1');

    // TOS 专属签名头
    expect($req->getHeaderLine('X-Tos-Date'))->not->toBe('');
    expect($req->getHeaderLine('X-Tos-Content-Sha256'))->toBe(strtolower(hash('sha256', $body)));
    expect($req->getHeaderLine('Authorization'))->toStartWith('TOS4-HMAC-SHA256 Credential=AKID/')
        ->toContain('/tos/request')
        ->toContain('SignedHeaders=host;x-tos-content-sha256;x-tos-date');

    // 无凭证泄露
    expect((string) $req->getUri())->not->toContain('SKSECRET');
    expect($body)->not->toContain('SKSECRET');
});

test('tosPut HTTP 非 2xx 抛 BytePlusApiException（解析 XML 错误体 Code）', function () {
    $container = [];
    $xml = '<?xml version="1.0"?><Error><Code>NoSuchBucket</Code><Message>bucket gone</Message></Error>';
    $http = byteplusMockHttp(new Response(404, [], $xml), $container);

    $client = new BytePlusRestClient('tos', 'ap-singapore-1', 'AK', 'SK', 'b.tos-ap-singapore-1.bytepluses.com', $http);

    try {
        $client->tosPut('/?customdomain', ['CustomDomainRule' => ['Domain' => 'd', 'CertId' => 'c']]);
        expect(false)->toBeTrue('应抛异常');
    } catch (BytePlusApiException $e) {
        expect($e->getErrorCode())->toBe('NoSuchBucket');
        expect($e->getErrorMessage())->toBe('bucket gone');
    }
});
