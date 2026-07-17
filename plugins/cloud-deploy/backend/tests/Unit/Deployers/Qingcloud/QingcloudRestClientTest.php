<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Qingcloud\QingcloudApiException;
use Plugins\CloudDeploy\Deployers\Qingcloud\QingcloudRestClient;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 用 Guzzle MockHandler + History 中间件捕获实际外发请求，校验青云 QY 签名协议（逐字节对齐 yunify SDK signer.go + builder.go）：
 *   - 参数集 = 入参（数组按 key.N 平铺）+ action + zone + access_key_id + signature_method + signature_version + time_stamp。
 *   - 键升序，PercentEncode（urlencode 且 +→%20），stringToSign = METHOD + \n + /iaas + \n + urlParams。
 *   - signature = url_encode(base64(HMAC-SHA256(stringToSign, secret)))。
 *   - GET：signature 进 query；POST：进 form body。
 *   - 用独立参考实现重算签名并断言相等。
 *   - 错误归一：响应体 ret_code 非 0 / HTTP 非 2xx → QingcloudApiException。
 */
function qingcloudClientWithMock(array $responses, ArrayObject $history): QingcloudRestClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false]);

    return new QingcloudRestClient('AKID-TEST', 'SK-SECRET-TEST', 'pek3a', $http);
}

/** 参考实现：青云 QY 签名（与被测代码独立的第二份实现）。$params 为已平铺的 string=>string 集合。 */
function qingcloudReferenceSign(string $method, array $params, string $secret): string
{
    ksort($params, SORT_STRING);
    $parts = [];
    foreach ($params as $k => $v) {
        $ek = str_replace('+', '%20', urlencode((string) $k));
        $ev = str_replace('+', '%20', urlencode((string) $v));
        $parts[] = "$ek=$ev";
    }
    $stringToSign = $method."\n/iaas\n".implode('&', $parts);

    return rawurlencode(base64_encode(hash_hmac('sha256', $stringToSign, $secret, true)));
}

test('GET：固定参数齐全，signature 进 query，参考实现重算一致', function () {
    $history = new ArrayObject;
    $client = qingcloudClientWithMock([new Response(200, [], json_encode(['ret_code' => 0]))], $history);

    $client->get('DescribeLoadBalancerListeners', ['loadbalancer' => 'lb-1', 'offset' => 0, 'limit' => 100]);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('GET');
    expect($req->getUri()->getHost())->toBe('api.qingcloud.com');
    expect($req->getUri()->getPath())->toBe('/iaas');
    expect((string) $req->getBody())->toBe('');

    parse_str($req->getUri()->getQuery(), $q);
    expect($q['action'])->toBe('DescribeLoadBalancerListeners');
    expect($q['zone'])->toBe('pek3a');
    expect($q['access_key_id'])->toBe('AKID-TEST');
    expect($q['signature_method'])->toBe('HmacSHA256');
    expect($q['signature_version'])->toBe('1');
    expect($q)->toHaveKey('time_stamp');
    expect($q)->toHaveKey('signature');
    expect($q['loadbalancer'])->toBe('lb-1');

    // 用捕获到的全部参数（除 signature）经参考实现重算，断言一致
    $signParams = $q;
    $clientSig = rawurlencode($signParams['signature']); // parse_str 已 decode，重新 encode 比对
    unset($signParams['signature']);
    expect($clientSig)->toBe(qingcloudReferenceSign('GET', $signParams, 'SK-SECRET-TEST'));

    // time_stamp 形如 ISO8601 UTC
    expect($q['time_stamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

test('POST：数组参数按 key.N（1 起）平铺，signature 进 form body', function () {
    $history = new ArrayObject;
    $client = qingcloudClientWithMock([new Response(200, [], json_encode(['ret_code' => 0]))], $history);

    $client->post('AssociateServerCertsToLBListener', [
        'loadbalancer_listener' => 'lbl-1',
        'server_certificates' => ['sc-1', 'sc-2'],
    ]);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getHeaderLine('Content-Type'))->toContain('application/x-www-form-urlencoded');

    parse_str((string) $req->getBody(), $form);
    // 数组参数平铺为 server_certificates.1 / .2（parse_str 把点转下划线，需查原始 body 串）
    $bodyStr = (string) $req->getBody();
    expect($bodyStr)->toContain('server_certificates.1=sc-1');
    expect($bodyStr)->toContain('server_certificates.2=sc-2');
    expect($bodyStr)->toContain('loadbalancer_listener=lbl-1');
    expect($bodyStr)->toContain('&signature=');

    // 重算签名：把 body 拆出去掉 signature，逐项 decode 后重算
    $pairs = explode('&', $bodyStr);
    $signParams = [];
    $clientSig = '';
    foreach ($pairs as $pair) {
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
        if ($k === 'signature') {
            $clientSig = $v;

            continue;
        }
        $signParams[urldecode($k)] = urldecode($v);
    }
    expect($clientSig)->toBe(qingcloudReferenceSign('POST', $signParams, 'SK-SECRET-TEST'));
});

test('PercentEncode：值含空格编为 %20（非 +）', function () {
    $history = new ArrayObject;
    $client = qingcloudClientWithMock([new Response(200, [], json_encode(['ret_code' => 0]))], $history);

    $client->get('DescribeServerCertificates', ['search_word' => 'a b']);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    $rawQuery = $req->getUri()->getQuery();
    expect($rawQuery)->toContain('search_word=a%20b');
    expect($rawQuery)->not->toContain('search_word=a+b');
});

test('响应体 ret_code 非 0 → 抛 QingcloudApiException（ret_code + message）', function () {
    $client = qingcloudClientWithMock([
        new Response(200, [], json_encode(['ret_code' => 1300, 'message' => 'permission denied'])),
    ], new ArrayObject);

    try {
        $client->get('DescribeLoadBalancerListeners', ['loadbalancer' => 'lb-1']);
        expect(false)->toBeTrue('应抛异常');
    } catch (QingcloudApiException $e) {
        expect($e->getErrorCode())->toBe('1300');
        expect($e->getErrorMessage())->toBe('permission denied');
    }
});

test('ret_code=0 视为成功，原样返回解析后的数组', function () {
    $client = qingcloudClientWithMock([new Response(200, [], json_encode(['ret_code' => 0, 'server_certificate_id' => 'sc-9']))], new ArrayObject);

    $resp = $client->post('CreateServerCertificate', ['server_certificate_name' => 'n']);
    expect($resp['server_certificate_id'])->toBe('sc-9');
});

test('HTTP 非 2xx 且无 ret_code 体 → 抛 QingcloudApiException（HTTP 状态码）', function () {
    $client = qingcloudClientWithMock([new Response(503, [], 'unavailable')], new ArrayObject);

    expect(fn () => $client->get('DescribeServerCertificates'))
        ->toThrow(QingcloudApiException::class, '503');
});
