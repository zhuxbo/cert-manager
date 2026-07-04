<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Zenlayer\ZenlayerApiException;
use Plugins\CloudDeploy\Deployers\Zenlayer\ZenlayerRestClient;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 用 Guzzle MockHandler + History 中间件捕获实际外发请求，校验 ZenlayerCloud ZC2-HMAC-SHA256 签名协议
 * （逐字节对齐 zenlayercloud-sdk-go common.Client.ApiCall + signRequest）：
 *   - POST /api/v2/{service}，body=JSON，action 进 x-zc-action 头。
 *   - canonicalRequest = "POST\n/\n\ncontent-type:{ct}\nhost:{host}\n\ncontent-type;host\n{sha256hex(body)}"。
 *   - string2sign = "ZC2-HMAC-SHA256\n{ts}\n{sha256hex(canonicalRequest)}"；signature=bin2hex(HMAC-SHA256(string2sign, pwd))。
 *   - Authorization = "ZC2-HMAC-SHA256 Credential={keyId}, SignedHeaders=content-type;host, Signature={sig}"。
 *   - 用独立参考实现从捕获到的 body + x-zc-timestamp 重算签名并断言相等。
 *   - 错误归一：响应体 code 非空 / HTTP 非 2xx → ZenlayerApiException；成功返回 response 子对象。
 */
function zenlayerClientWithMock(array $responses, ArrayObject $history, string $service = 'cdn', string $version = '2022-11-20'): ZenlayerRestClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false]);

    return new ZenlayerRestClient($service, $version, 'AKID-TEST', 'PWD-SECRET-TEST', $http);
}

/** 参考实现：ZC2-HMAC-SHA256 签名（与被测代码独立的第二份实现）。 */
function zenlayerReferenceSig(string $bodyStr, string $timestamp, string $pwd): string
{
    $host = 'console.zenlayer.com';
    $hashedPayload = hash('sha256', $bodyStr);
    $canonicalHeaders = "content-type:application/json\nhost:$host\n";
    $canonicalRequest = "POST\n/\n\n$canonicalHeaders\ncontent-type;host\n$hashedPayload";
    $string2sign = "ZC2-HMAC-SHA256\n$timestamp\n".hash('sha256', $canonicalRequest);

    return bin2hex(hash_hmac('sha256', $string2sign, $pwd, true));
}

test('call：POST /api/v2/cdn，headers 齐全，Authorization 参考实现重算一致', function () {
    $history = new ArrayObject;
    $client = zenlayerClientWithMock([new Response(200, [], json_encode(['response' => ['certificateId' => 'c-1']]))], $history);

    $resp = $client->call('CreateCertificate', ['certificateLabel' => 'n', 'certificateContent' => 'C', 'certificateKey' => 'K']);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getUri()->getHost())->toBe('console.zenlayer.com');
    expect($req->getUri()->getPath())->toBe('/api/v2/cdn');
    expect($req->getHeaderLine('Content-Type'))->toContain('application/json');
    expect($req->getHeaderLine('x-zc-service'))->toBe('cdn');
    expect($req->getHeaderLine('x-zc-version'))->toBe('2022-11-20');
    expect($req->getHeaderLine('x-zc-action'))->toBe('CreateCertificate');
    expect($req->getHeaderLine('x-zc-signature-method'))->toBe('ZC2-HMAC-SHA256');
    expect($req->getHeaderLine('x-zc-timestamp'))->not->toBe('');

    // body 是合法 JSON 且含传入字段（action 不在 body）
    $bodyStr = (string) $req->getBody();
    $body = json_decode($bodyStr, true);
    expect($body['certificateLabel'])->toBe('n');
    expect($body)->not->toHaveKey('action');

    // 重算签名
    $ts = $req->getHeaderLine('x-zc-timestamp');
    $expectedSig = zenlayerReferenceSig($bodyStr, $ts, 'PWD-SECRET-TEST');
    expect($req->getHeaderLine('Authorization'))->toBe("ZC2-HMAC-SHA256 Credential=AKID-TEST, SignedHeaders=content-type;host, Signature=$expectedSig");

    // 成功响应剥到 response 子对象
    expect($resp['certificateId'])->toBe('c-1');
});

test('call：zga 服务走 /api/v2/zga + x-zc-version=2023-07-06', function () {
    $history = new ArrayObject;
    $client = zenlayerClientWithMock([new Response(200, [], json_encode(['response' => []]))], $history, 'zga', '2023-07-06');

    $client->call('DescribeAccelerators', ['acceleratorIds' => ['ga-1']]);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getUri()->getPath())->toBe('/api/v2/zga');
    expect($req->getHeaderLine('x-zc-service'))->toBe('zga');
    expect($req->getHeaderLine('x-zc-version'))->toBe('2023-07-06');
});

test('空 body 编为 {} 并参与签名', function () {
    $history = new ArrayObject;
    $client = zenlayerClientWithMock([new Response(200, [], json_encode(['response' => []]))], $history);

    $client->call('SomeAction');

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect((string) $req->getBody())->toBe('{}');
    $ts = $req->getHeaderLine('x-zc-timestamp');
    $expectedSig = zenlayerReferenceSig('{}', $ts, 'PWD-SECRET-TEST');
    expect($req->getHeaderLine('Authorization'))->toContain("Signature=$expectedSig");
});

test('响应体 code 非空 → 抛 ZenlayerApiException（code + message）', function () {
    $client = zenlayerClientWithMock([
        new Response(200, [], json_encode(['requestId' => 'r-1', 'code' => 'INVALID_PARAMETER', 'message' => 'bad domain'])),
    ], new ArrayObject);

    try {
        $client->call('ModifyDomainCertificate', ['domainId' => 'd-1']);
        expect(false)->toBeTrue('应抛异常');
    } catch (ZenlayerApiException $e) {
        expect($e->getErrorCode())->toBe('INVALID_PARAMETER');
        expect($e->getErrorMessage())->toBe('bad domain');
    }
});

test('HTTP 非 2xx 且无 code 体 → 抛 ZenlayerApiException（HTTP 状态码）', function () {
    $client = zenlayerClientWithMock([new Response(500, [], 'oops')], new ArrayObject);

    expect(fn () => $client->call('X'))
        ->toThrow(ZenlayerApiException::class, '500');
});
