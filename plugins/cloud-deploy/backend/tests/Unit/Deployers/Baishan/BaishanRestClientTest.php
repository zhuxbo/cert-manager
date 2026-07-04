<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Baishan\BaishanApiException;
use Plugins\CloudDeploy\Deployers\Baishan\BaishanRestClient;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 用 Guzzle MockHandler + History 中间件捕获实际外发请求，校验白山云 REST 协议（对齐 certimate）：
 *   - 鉴权：token 进 URL 查询串（无 HMAC 签名）。
 *   - GET：业务参数 + 数组参数 config[] 进 query。
 *   - POST：JSON body。
 *   - 错误归一：响应体 code 非 0 / HTTP 非 2xx → BaishanApiException。
 */
function baishanClientWithMock(array $responses, ArrayObject $history): BaishanRestClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false]);

    return new BaishanRestClient('TOKEN-SECRET-TEST', $http);
}

test('GET：token + 业务参数 + 数组参数 config[] 进 query', function () {
    $history = new ArrayObject;
    $client = baishanClientWithMock([new Response(200, [], json_encode(['code' => 0, 'data' => []]))], $history);

    $client->get('/v2/domain/config', ['domains' => 'cdn.example.com'], ['config' => ['https']]);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('GET');
    expect($req->getUri()->getHost())->toBe('cdn.api.baishan.com');
    expect($req->getUri()->getPath())->toBe('/v2/domain/config');
    expect((string) $req->getBody())->toBe('');

    $rawQuery = $req->getUri()->getQuery();
    expect($rawQuery)->toContain('token=TOKEN-SECRET-TEST');
    expect($rawQuery)->toContain('domains=cdn.example.com');
    // 数组参数：config[]=https（[] rawurlencode 为 %5B%5D）
    parse_str($rawQuery, $q);
    expect($q['config'])->toBe(['https']);
});

test('POST：token 进 query，JSON body', function () {
    $history = new ArrayObject;
    $client = baishanClientWithMock([new Response(200, [], json_encode(['code' => 0, 'data' => ['cert_id' => 5]]))], $history);

    $client->post('/v2/domain/certificate', ['name' => 'n', 'certificate' => 'C', 'key' => 'K']);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getHeaderLine('Content-Type'))->toContain('application/json');
    expect($req->getUri()->getQuery())->toContain('token=TOKEN-SECRET-TEST');

    $body = json_decode((string) $req->getBody(), true);
    expect($body['name'])->toBe('n');
    expect($body['certificate'])->toBe('C');
    expect($body['key'])->toBe('K');
});

test('响应体 code 非 0 → 抛 BaishanApiException（code + message）', function () {
    $client = baishanClientWithMock([
        new Response(200, [], json_encode(['code' => 400699, 'message' => 'this certificate is exists, id: 12345'])),
    ], new ArrayObject);

    try {
        $client->post('/v2/domain/certificate', ['name' => 'n']);
        expect(false)->toBeTrue('应抛异常');
    } catch (BaishanApiException $e) {
        expect($e->getErrorCode())->toBe('400699');
        expect($e->getErrorMessage())->toContain('this certificate is exists');
    }
});

test('code=0 视为成功，原样返回解析后的数组', function () {
    $client = baishanClientWithMock([new Response(200, [], json_encode(['code' => 0, 'data' => ['cert_id' => 777]]))], new ArrayObject);

    $resp = $client->post('/v2/domain/certificate', ['name' => 'n']);
    expect($resp['data']['cert_id'])->toBe(777);
});

test('HTTP 非 2xx 且无 code 体 → 抛 BaishanApiException（HTTP 状态码）', function () {
    $client = baishanClientWithMock([new Response(502, [], 'bad gateway')], new ArrayObject);

    expect(fn () => $client->get('/x'))
        ->toThrow(BaishanApiException::class, '502');
});
