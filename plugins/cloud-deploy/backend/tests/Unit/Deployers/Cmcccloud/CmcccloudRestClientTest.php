<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Cmcccloud\CmcccloudApiException;
use Plugins\CloudDeploy\Deployers\Cmcccloud\CmcccloudRestClient;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 用 Guzzle MockHandler + History 中间件捕获实际外发请求，校验移动云 eCloud AKSK 签名协议
 * （逐字节对齐 ecloudsdkcore auth.AKSKCredential.Sign）：
 *   - 签名参数集 = query + AccessKey + Timestamp + SignatureMethod=HmacSHA256 + SignatureVersion=V2.0 + SignatureNonce。
 *   - 键升序，PercentEncode（urlencode 且 +→%20、*→%2A、%7E→~），canonicalQueryString。
 *   - hashString = lowerhex(sha256(canonicalQueryString))。
 *   - stringToSign = METHOD + "\n" + PercentEncode(path) + "\n" + hashString。
 *   - signature = lowerhex(HMAC-SHA256(stringToSign, "BC_SIGNATURE&" + secretKey))。
 *   - 最终 path = path + "?" + canonicalQueryString + "&Signature=" + PercentEncode(signature)。
 *   - 用独立参考实现从捕获到的全部签名参数（含客户端生成的 Timestamp/Nonce）重算并断言相等。
 *   - 错误归一：响应体 state=ERROR / HTTP 非 2xx → CmcccloudApiException。
 */
function cmcccloudClientWithMock(array $responses, ArrayObject $history, string $poolId = 'CIDC-CORE-00'): CmcccloudRestClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false]);

    return new CmcccloudRestClient('AKID-TEST', 'SK-SECRET-TEST', $poolId, $http);
}

/** 参考实现：eCloud PercentEncode（urlencode + 三步替换）。 */
function cmcccloudPctEncode(string $v): string
{
    $e = urlencode($v);
    $e = str_replace('+', '%20', $e);
    $e = str_replace('*', '%2A', $e);

    return str_replace('%7E', '~', $e);
}

/** 参考实现：eCloud AKSK 签名（与被测代码独立的第二份实现）。$signParams 含全部签名参数（除 Signature）。 */
function cmcccloudReferenceSign(string $method, string $path, array $signParams, string $secret): string
{
    ksort($signParams, SORT_STRING);
    $parts = [];
    foreach ($signParams as $k => $v) {
        $parts[] = cmcccloudPctEncode((string) $k).'='.cmcccloudPctEncode((string) $v);
    }
    $canonical = implode('&', $parts);
    $hashString = strtolower(hash('sha256', $canonical));
    $stringToSign = $method."\n".cmcccloudPctEncode($path)."\n".$hashString;

    return strtolower(hash_hmac('sha256', $stringToSign, 'BC_SIGNATURE&'.$secret));
}

test('GET：签名参数齐全，Signature 进 query，参考实现重算一致；endpoint=ecloud.10086.cn', function () {
    $history = new ArrayObject;
    $client = cmcccloudClientWithMock([new Response(200, [], json_encode(['state' => 'OK', 'body' => ['list' => []]]))], $history);

    $path = '/api/openapi-ecdn/domainManager/openapi/domain/describeUserDomains';
    $client->call('GET', $path, [], ['page' => '1', 'pageSize' => '10']);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('GET');
    expect($req->getUri()->getHost())->toBe('ecloud.10086.cn');
    expect($req->getUri()->getPath())->toBe($path);
    expect((string) $req->getBody())->toBe('');
    expect($req->getHeaderLine('Pool-Id'))->toBe('CIDC-CORE-00');

    parse_str($req->getUri()->getQuery(), $q);
    expect($q['AccessKey'])->toBe('AKID-TEST');
    expect($q['SignatureMethod'])->toBe('HmacSHA256');
    expect($q['SignatureVersion'])->toBe('V2.0');
    expect($q)->toHaveKey('SignatureNonce');
    expect($q)->toHaveKey('Timestamp');
    expect($q)->toHaveKey('Signature');
    expect($q['page'])->toBe('1');
    expect($q['pageSize'])->toBe('10');

    // 用捕获到的全部签名参数（除 Signature）经参考实现重算
    $signParams = $q;
    $clientSig = $signParams['Signature'];
    unset($signParams['Signature']);
    expect($clientSig)->toBe(cmcccloudReferenceSign('GET', $path, $signParams, 'SK-SECRET-TEST'));

    // Timestamp 形如 ...T...Z（移动云 SDK：Shanghai 本地时间 + 字面 Z）
    expect($q['Timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
    // SignatureNonce 形如 32 位 hex（uuid 去横线）
    expect($q['SignatureNonce'])->toMatch('/^[0-9a-f]{32}$/');
});

test('POST：body 为 JSON，签名参数仅 5 个固定字段（无业务 query），路径参数替换', function () {
    $history = new ArrayObject;
    $client = cmcccloudClientWithMock([new Response(200, [], json_encode(['state' => 'OK']))], $history);

    $path = '/api/openapi-ecdn/domainManager/openapi/certificate/addDomainServerCertificate';
    $client->call('POST', $path, [], [], ['domainId' => 123, 'crtName' => 'n', 'certificate' => 'C', 'privateKey' => 'K']);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getHeaderLine('Content-Type'))->toContain('application/json');

    $body = json_decode((string) $req->getBody(), true);
    expect($body['domainId'])->toBe(123);
    expect($body['certificate'])->toBe('C');

    // 签名参数（query）只含 5 个固定字段
    parse_str($req->getUri()->getQuery(), $q);
    $signParams = $q;
    $clientSig = $signParams['Signature'];
    unset($signParams['Signature']);
    expect(array_keys($signParams))->toEqualCanonicalizing(['AccessKey', 'Timestamp', 'SignatureMethod', 'SignatureVersion', 'SignatureNonce']);
    expect($clientSig)->toBe(cmcccloudReferenceSign('POST', $path, $signParams, 'SK-SECRET-TEST'));
});

test('路径参数 {loadBalanceId} 替换进 path + 参与签名', function () {
    $history = new ArrayObject;
    $client = cmcccloudClientWithMock([new Response(200, [], json_encode(['state' => 'OK', 'body' => ['content' => []]]))], $history, 'CIDC-RP-29');

    $client->call('GET', '/api/openapi-vlb/lb-console/protocol/v3/listener/{loadBalanceId}/listeners/https', ['loadBalanceId' => 'lb-9'], ['page' => '1', 'pageSize' => '10']);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    // 资源池 CIDC-RP-29 → console-beijing-2 endpoint
    expect($req->getUri()->getHost())->toBe('console-beijing-2.cmecloud.cn');
    expect($req->getUri()->getPath())->toBe('/api/openapi-vlb/lb-console/protocol/v3/listener/lb-9/listeners/https');
    expect($req->getHeaderLine('Pool-Id'))->toBe('CIDC-RP-29');
});

test('响应体 state=ERROR → 抛 CmcccloudApiException（errorCode + errorMessage）', function () {
    $client = cmcccloudClientWithMock([
        new Response(200, [], json_encode(['state' => 'ERROR', 'errorCode' => 'PARAM_ERROR', 'errorMessage' => 'bad param'])),
    ], new ArrayObject);

    try {
        $client->call('GET', '/x', [], ['k' => 'v']);
        expect(false)->toBeTrue('应抛异常');
    } catch (CmcccloudApiException $e) {
        expect($e->getErrorCode())->toBe('PARAM_ERROR');
        expect($e->getErrorMessage())->toBe('bad param');
    }
});

test('state=OK 视为成功，原样返回解析后的数组', function () {
    $client = cmcccloudClientWithMock([new Response(200, [], json_encode(['state' => 'OK', 'body' => 'cert-id-1']))], new ArrayObject);

    $resp = $client->call('POST', '/x', [], [], ['k' => 'v']);
    expect($resp['body'])->toBe('cert-id-1');
});

test('HTTP 非 2xx 且无 state 体 → 抛 CmcccloudApiException（HTTP 状态码）', function () {
    $client = cmcccloudClientWithMock([new Response(500, [], 'oops')], new ArrayObject);

    expect(fn () => $client->call('GET', '/x'))
        ->toThrow(CmcccloudApiException::class, '500');
});

test('未知资源池 → 回落默认 endpoint ecloud.10086.cn', function () {
    $history = new ArrayObject;
    $client = cmcccloudClientWithMock([new Response(200, [], json_encode(['state' => 'OK']))], $history, 'CIDC-UNKNOWN-99');

    $client->call('GET', '/x');

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getUri()->getHost())->toBe('ecloud.10086.cn');
});
