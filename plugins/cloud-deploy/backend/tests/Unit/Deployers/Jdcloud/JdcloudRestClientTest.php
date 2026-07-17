<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudApiException;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 用 Guzzle MockHandler 捕获实际发出的 HTTP 请求，验证 JdcloudRestClient 的 URL 拼装 / path 转义 /
 * query 编码 / 签名头落位 与签名时一致（在线请求 == 被签名请求，否则上游验签失败）。
 *
 * @param  list<Response>  $responses
 * @param  array<int,Request>  $captured  out param，收集每次请求
 */
function jdRestClient(string $endpoint, string $service, array $responses, array &$captured): JdcloudRestClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(function (callable $handler) use (&$captured) {
        return function (Request $request, array $options) use ($handler, &$captured) {
            $captured[] = $request;

            return $handler($request, $options);
        };
    });
    $http = new Client(['handler' => $stack, 'http_errors' => false]);

    $client = new JdcloudRestClient($endpoint, $service, '1.0.0', [
        'access_key_id' => 'AKID', 'access_key_secret' => 'SECRET',
    ], $http);
    // 固定时间 + nonce，使断言稳定
    $client->withClockForTesting(
        fn () => new DateTimeImmutable('2026-06-28T10:20:30', new DateTimeZone('UTC')),
        fn () => '11111111-2222-4333-8444-555555555555',
    );

    return $client;
}

test('POST uploadCert：path 含冒号转义为 %3A、body 为签名同串 JSON、Authorization 头落位', function () {
    $captured = [];
    $client = jdRestClient('ssl.jdcloud-api.com', 'ssl', [
        new Response(200, [], json_encode(['result' => ['certId' => 'jdcert-001']])),
    ], $captured);

    $certId = $client->uploadCert('clouddeploy-1', 'CERT', 'KEY');
    expect($certId)->toBe('jdcert-001');

    /** @var Request $req */
    $req = $captured[0];
    expect($req->getMethod())->toBe('POST');
    // 在线 path 与签名 path 同串（冒号 → %3A）
    expect($req->getUri()->getPath())->toBe('/v1/sslCert%3Aupload');
    expect((string) $req->getUri())->toBe('https://ssl.jdcloud-api.com/v1/sslCert%3Aupload');
    // body 与签名 body 同串
    expect((string) $req->getBody())->toBe('{"certName":"clouddeploy-1","certFile":"CERT","keyFile":"KEY"}');
    // 签名头落位且 host 头为 endpoint
    expect($req->getHeaderLine('Authorization'))->toStartWith('JDCLOUD2-HMAC-SHA256 Credential=AKID/20260628/jdcloud-api/ssl/jdcloud2_request');
    expect($req->getHeaderLine('x-jdcloud-date'))->toBe('20260628T102030Z');
    expect($req->getHeaderLine('x-jdcloud-nonce'))->toBe('11111111-2222-4333-8444-555555555555');
    expect($req->getHeaderLine('Host'))->toBe('ssl.jdcloud-api.com');
    // Authorization 头不含 SK
    expect($req->getHeaderLine('Authorization'))->not->toContain('SECRET');
});

test('GET findVodDomainId：query 按 key 排序编码、单页命中转 int', function () {
    $captured = [];
    $client = jdRestClient('vod.jdcloud-api.com', 'vod', [
        new Response(200, [], json_encode(['result' => ['content' => [
            ['id' => '789', 'name' => 'vod.example.com', 'status' => 'online'],
        ]]])),
    ], $captured);

    $id = $client->findVodDomainId('vod.example.com');
    expect($id)->toBe(789); // string id → int

    /** @var Request $req */
    $req = $captured[0];
    expect($req->getMethod())->toBe('GET');
    expect($req->getUri()->getPath())->toBe('/v1/domains');
    // query 按 key 排序：pageNumber 在 pageSize 前
    expect($req->getUri()->getQuery())->toBe('pageNumber=1&pageSize=100');
    expect((string) $req->getBody())->toBe('');
});

test('GET listAlbHttpsListenerIds：filters 数组索引编码 + 过滤 https/tls', function () {
    $captured = [];
    $client = jdRestClient('lb.jdcloud-api.com', 'lb', [
        new Response(200, [], json_encode(['result' => ['listeners' => [
            ['listenerId' => 'lsr-1', 'protocol' => 'Https'],
            ['listenerId' => 'lsr-2', 'protocol' => 'TCP'],
            ['listenerId' => 'lsr-3', 'protocol' => 'tls'],
        ]]])),
    ], $captured);

    $ids = $client->listAlbHttpsListenerIds('cn-north-1', 'lb-abc');
    expect($ids)->toBe(['lsr-1', 'lsr-3']); // https + tls，大小写不敏感；TCP 排除

    /** @var Request $req */
    $req = $captured[0];
    expect($req->getUri()->getPath())->toBe('/v1/regions/cn-north-1/listeners/');
    // filters.1.name / filters.1.values.1 进 query（点号不转义），按 key 排序
    expect($req->getUri()->getQuery())->toBe(
        'filters.1.name=loadBalancerId&filters.1.values.1=lb-abc&pageNumber=1&pageSize=100'
    );
});

test('HTTP 4xx 带响应体 error → JdcloudApiException（取 error.code/message）', function () {
    $captured = [];
    $client = jdRestClient('ssl.jdcloud-api.com', 'ssl', [
        new Response(400, [], json_encode(['error' => ['code' => 'BadRequest', 'message' => 'invalid cert']])),
    ], $captured);

    try {
        $client->uploadCert('n', 'C', 'K');
        expect(false)->toBeTrue('应抛 JdcloudApiException');
    } catch (JdcloudApiException $e) {
        expect($e->getErrorCode())->toBe('BadRequest');
        expect($e->getErrorMessage())->toBe('invalid cert');
    }
});

test('HTTP 200 但响应体 error.code 非空 → JdcloudApiException（业务错误）', function () {
    $captured = [];
    $client = jdRestClient('cdn.jdcloud-api.com', 'cdn', [
        new Response(200, [], json_encode(['error' => ['code' => 'Forbidden', 'message' => 'no permission']])),
    ], $captured);

    expect(fn () => $client->setCdnHttpType('d.example.com', 'cert-1', ''))
        ->toThrow(JdcloudApiException::class, 'no permission');
});

test('setVodHttpSsl：domainId 进 path（冒号 %3A）+ source/enabled 固定值在 body', function () {
    $captured = [];
    $client = jdRestClient('vod.jdcloud-api.com', 'vod', [
        new Response(200, [], json_encode(['result' => []])),
    ], $captured);

    $client->setVodHttpSsl(123, 'title-x', 'CERT', 'KEY', 'redirect');

    /** @var Request $req */
    $req = $captured[0];
    expect($req->getUri()->getPath())->toBe('/v1/domains/123%3AsetHttpSsl');
    $body = json_decode((string) $req->getBody(), true);
    expect($body)->toMatchArray([
        'source' => 'default',
        'title' => 'title-x',
        'sslCert' => 'CERT',
        'sslKey' => 'KEY',
        'jumpType' => 'redirect',
        'enabled' => true,
    ]);
});
