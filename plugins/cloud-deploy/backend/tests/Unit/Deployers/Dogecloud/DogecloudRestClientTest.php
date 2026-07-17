<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Dogecloud\DogecloudApiException;
use Plugins\CloudDeploy\Deployers\Dogecloud\DogecloudRestClient;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 用 Guzzle MockHandler + History 中间件捕获实际外发请求，校验多吉云签名协议（逐字节对齐 certimate signer.go）：
 *   - stringToSign = path[?query] + "\n" + body。
 *   - signature = lowerhex(HMAC-SHA1(stringToSign, secretKey))。
 *   - Authorization 头 = "TOKEN {accessKey}:{signature}"。
 *   - 签名值用「独立参考实现」重算并断言相等。
 *   - 错误归一：响应体 code 非 0/200 / HTTP 非 2xx → DogecloudApiException。
 */
function dogecloudClientWithMock(array $responses, ArrayObject $history): DogecloudRestClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false]);

    return new DogecloudRestClient('AKID-TEST', 'SK-SECRET-TEST', $http);
}

/** 参考实现：多吉云签名（与被测代码独立的第二份实现，逐字节对齐 certimate signer.go）。 */
function dogecloudReferenceSign(string $path, string $body, string $secret): string
{
    return strtolower(hash_hmac('sha1', $path."\n".$body, $secret));
}

test('POST：body 为 JSON、Authorization=TOKEN {ak}:{sig}（参考实现重算一致，stringToSign=path\\nbody）', function () {
    $history = new ArrayObject;
    $client = dogecloudClientWithMock([new Response(200, [], json_encode(['code' => 200, 'data' => ['id' => 123]]))], $history);

    $client->post('/cdn/cert/upload.json', ['note' => 'n', 'cert' => 'CERTPEM', 'private' => 'KEYPEM']);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getUri()->getHost())->toBe('api.dogecloud.com');
    expect($req->getUri()->getPath())->toBe('/cdn/cert/upload.json');
    expect($req->getHeaderLine('Content-Type'))->toContain('application/json');

    $bodyStr = (string) $req->getBody();
    $auth = $req->getHeaderLine('Authorization');
    $expectedSig = dogecloudReferenceSign('/cdn/cert/upload.json', $bodyStr, 'SK-SECRET-TEST');
    expect($auth)->toBe('TOKEN AKID-TEST:'.$expectedSig);

    // body 是合法 JSON 且含传入字段
    $body = json_decode($bodyStr, true);
    expect($body['note'])->toBe('n');
    expect($body['cert'])->toBe('CERTPEM');
    expect($body['private'])->toBe('KEYPEM');
});

test('GET：无 body，stringToSign=path\\n（空 body），Authorization 参考实现一致', function () {
    $history = new ArrayObject;
    $client = dogecloudClientWithMock([new Response(200, [], json_encode(['code' => 200, 'data' => ['domains' => []]]))], $history);

    $client->get('/cdn/domain/list.json');

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('GET');
    expect((string) $req->getBody())->toBe('');

    $auth = $req->getHeaderLine('Authorization');
    $expectedSig = dogecloudReferenceSign('/cdn/domain/list.json', '', 'SK-SECRET-TEST');
    expect($auth)->toBe('TOKEN AKID-TEST:'.$expectedSig);
});

test('响应体 code 非 0/200 → 抛 DogecloudApiException（code + msg）', function () {
    $client = dogecloudClientWithMock([
        new Response(200, [], json_encode(['code' => 4002, 'msg' => 'bad cert'])),
    ], new ArrayObject);

    try {
        $client->post('/cdn/cert/upload.json', ['note' => 'n']);
        expect(false)->toBeTrue('应抛异常');
    } catch (DogecloudApiException $e) {
        expect($e->getErrorCode())->toBe('4002');
        expect($e->getErrorMessage())->toBe('bad cert');
    }
});

test('code=200 视为成功（多吉云成功码 0 或 200）', function () {
    $client = dogecloudClientWithMock([new Response(200, [], json_encode(['code' => 200, 'data' => ['id' => 9]]))], new ArrayObject);

    $resp = $client->post('/cdn/cert/upload.json', ['note' => 'n']);
    expect($resp['data']['id'])->toBe(9);
});

test('HTTP 非 2xx 且无 code 体 → 抛 DogecloudApiException（HTTP 状态码）', function () {
    $client = dogecloudClientWithMock([new Response(500, [], 'gateway error')], new ArrayObject);

    expect(fn () => $client->get('/x'))
        ->toThrow(DogecloudApiException::class, '500');
});
