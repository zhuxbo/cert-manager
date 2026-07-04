<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Ksyun\KsyunApiException;
use Plugins\CloudDeploy\Deployers\Ksyun\KsyunRestClient;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 用 Guzzle MockHandler + History 中间件捕获实际外发请求，校验金山云签名协议（逐字节对齐 certimate signer.go）：
 *   - GET：参数平铺进 query、键升序、escapeQuery 编码（空格 %20）、末尾追加 Signature；签名覆盖业务参数 + 5 个签名字段。
 *   - POST：Action/Version 进 query，其余参数 + 签名字段（含 Signature）作 JSON body。
 *   - 签名值用「独立参考实现」从捕获到的参数（含客户端内部生成的 Timestamp）重算并断言相等。
 *   - 错误归一：响应体 Error 对象 / HTTP 非 2xx → KsyunApiException。
 */

/**
 * 构造一个注入了 MockHandler 的 KsyunRestClient，并把外发请求写入传入的 $history 容器（按引用）。
 *
 * 用 ArrayObject 作 history 容器：Middleware::history 对其 append，跨 helper 边界引用稳定
 * （普通数组按值返回会丢中间件后续 append 的内容）。
 *
 * @param  list<Response>  $responses
 */
function ksyunClientWithMock(array $responses, ArrayObject $history, string $service = 'cdn', string $endpoint = 'cdn.api.ksyun.com'): KsyunRestClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false]);

    return new KsyunRestClient($service, $endpoint, 'AKID-TEST', 'SK-SECRET-TEST', $http);
}

/**
 * 参考实现：金山云签名（与被测代码独立的第二份实现，逐字节对齐 certimate signer.go）。
 *
 * @param  array<string,string>  $params
 */
function ksyunReferenceSign(array $params, string $secret): string
{
    ksort($params, SORT_STRING);
    $parts = [];
    foreach ($params as $k => $v) {
        $ek = str_replace('+', '%20', urlencode((string) $k));
        $ev = str_replace('+', '%20', urlencode((string) $v));
        $parts[] = "$ek=$ev";
    }

    return strtolower(hash_hmac('sha256', implode('&', $parts), $secret));
}

test('GET 请求：参数进 query + 末尾 Signature，签名覆盖业务参数 + 5 签名字段（参考实现重算一致）', function () {
    $history = new ArrayObject;
    $client = ksyunClientWithMock([new Response(200, [], json_encode(['Domains' => []]))], $history);

    $client->get('/2019-06-01/GetCdnDomains', [
        'Action' => 'GetCdnDomains',
        'Version' => '2019-06-01',
        'PageNumber' => '1',
        'PageSize' => '100',
    ]);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('GET');

    $uri = $req->getUri();
    expect($uri->getHost())->toBe('cdn.api.ksyun.com');
    expect($uri->getPath())->toBe('/2019-06-01/GetCdnDomains');

    // GET 无 body
    expect((string) $req->getBody())->toBe('');

    // 解析 query（注意末尾 &Signature= 是手工拼的，parse_str 能解析）
    parse_str($uri->getQuery(), $q);
    expect($q)->toHaveKey('Signature');
    expect($q['Accesskey'])->toBe('AKID-TEST');
    expect($q['Service'])->toBe('cdn');
    expect($q['SignatureVersion'])->toBe('1.0');
    expect($q['SignatureMethod'])->toBe('HMAC-SHA256');
    expect($q['Action'])->toBe('GetCdnDomains');
    expect($q['PageNumber'])->toBe('1');
    expect($q)->toHaveKey('Timestamp');

    // 用捕获到的全部签名参数（除 Signature 外）经参考实现重算，断言与客户端产出的 Signature 一致
    $signParams = $q;
    $clientSig = $signParams['Signature'];
    unset($signParams['Signature']);
    expect($clientSig)->toBe(ksyunReferenceSign($signParams, 'SK-SECRET-TEST'));

    // Timestamp 形如 UTC ISO8601（Y-m-dTH:i:sZ）
    expect($q['Timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

test('GET query 键升序 + escapeQuery 编码（空格→%20，与签名同规则）', function () {
    $history = new ArrayObject;
    $client = ksyunClientWithMock([new Response(200, [], '{}')], $history);

    // DomainName 含空格 → 应编为 %20（非 +）；键应升序排列
    $client->get('/2019-06-01/GetCdnDomains', [
        'Action' => 'GetCdnDomains',
        'Version' => '2019-06-01',
        'DomainName' => 'a b',
    ]);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    $rawQuery = $req->getUri()->getQuery();

    // 空格被编为 %20（不是 +）
    expect($rawQuery)->toContain('DomainName=a%20b');
    expect($rawQuery)->not->toContain('DomainName=a+b');
    // 末尾追加 Signature
    expect($rawQuery)->toContain('&Signature=');
    // 键升序：Accesskey 在 Action 之前？实际排序是字符串序：Accesskey < Action（'k' < 't'）
    $accesskeyPos = strpos($rawQuery, 'Accesskey=');
    $actionPos = strpos($rawQuery, 'Action=');
    expect($accesskeyPos)->toBeLessThan($actionPos);
});

test('POST 请求：Action/Version 进 query，其余参数 + 签名字段 + Signature 作 JSON body', function () {
    $history = new ArrayObject;
    $client = ksyunClientWithMock([new Response(200, [], json_encode(['CertificateId' => 'c-1']))], $history);

    $client->post('/2016-09-01/cert/ConfigCertificate', [
        'Action' => 'ConfigCertificate',
        'Version' => '2016-09-01',
        'Enable' => 'on',
        'DomainIds' => 'd-1',
        'CertificateName' => 'name-1',
        'ServerCertificate' => 'CERTPEM',
        'PrivateKey' => 'KEYPEM',
    ]);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getHeaderLine('Content-Type'))->toContain('application/json');

    // Action/Version 在 query
    parse_str($req->getUri()->getQuery(), $q);
    expect($q['Action'])->toBe('ConfigCertificate');
    expect($q['Version'])->toBe('2016-09-01');
    // body 不应再含 Action/Version（被移到 query）
    $body = json_decode((string) $req->getBody(), true);
    expect($body)->not->toHaveKey('Action');
    expect($body)->not->toHaveKey('Version');

    // body 含业务参数 + 5 签名字段 + Signature
    expect($body['Enable'])->toBe('on');
    expect($body['DomainIds'])->toBe('d-1');
    expect($body['ServerCertificate'])->toBe('CERTPEM');
    expect($body['PrivateKey'])->toBe('KEYPEM');
    expect($body['Accesskey'])->toBe('AKID-TEST');
    expect($body['Service'])->toBe('cdn');
    expect($body['SignatureVersion'])->toBe('1.0');
    expect($body['SignatureMethod'])->toBe('HMAC-SHA256');
    expect($body)->toHaveKey('Signature');
    expect($body)->toHaveKey('Timestamp');

    // 签名覆盖 = 业务参数 + Action/Version（签名时仍在 params 内）+ 5 签名字段，用参考实现重算一致
    $signParams = $body;
    $clientSig = $signParams['Signature'];
    unset($signParams['Signature']);
    // Action/Version 参与签名（certimate 在 body 提取前已纳入 params）
    $signParams['Action'] = 'ConfigCertificate';
    $signParams['Version'] = '2016-09-01';
    expect($clientSig)->toBe(ksyunReferenceSign($signParams, 'SK-SECRET-TEST'));
});

test('POST body 值为字符串（bool/int 归一），且 service 进签名（kcm 实例）', function () {
    $history = new ArrayObject;
    $client = ksyunClientWithMock(
        [new Response(200, [], json_encode(['Success' => true, 'Ret' => ['CertID' => 'k-1']]))],
        $history,
        'kcm',
        'kcm.api.ksyun.com',
    );

    $client->post('/', [
        'Action' => 'UploadCertificate',
        'Version' => '2016-03-04',
        'ProjectId' => 0,            // int → "0"
        'CertName' => 'n',
        'CertFile' => 'F',
        'CertKey' => 'K',
    ]);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getUri()->getHost())->toBe('kcm.api.ksyun.com');
    $body = json_decode((string) $req->getBody(), true);
    // int 归一为字符串 "0"
    expect($body['ProjectId'])->toBe('0');
    expect($body['Service'])->toBe('kcm');
});

test('响应体含 Error 对象 → 抛 KsyunApiException（Type_Code 拼接 + Message）', function () {
    $client = ksyunClientWithMock([
        new Response(200, [], json_encode([
            'RequestId' => 'r-1',
            'Error' => ['Type' => 'Sender', 'Code' => 'InvalidParam', 'Message' => 'bad param'],
        ])),
    ], new ArrayObject);

    try {
        $client->get('/2019-06-01/GetCdnDomains', ['Action' => 'GetCdnDomains', 'Version' => '2019-06-01']);
        expect(false)->toBeTrue('应抛异常');
    } catch (KsyunApiException $e) {
        expect($e->getErrorCode())->toBe('Sender_InvalidParam');
        expect($e->getErrorMessage())->toBe('bad param');
    }
});

test('HTTP 非 2xx 且无 Error 体 → 抛 KsyunApiException（HTTP 状态码）', function () {
    $client = ksyunClientWithMock([new Response(500, [], 'gateway error')], new ArrayObject);

    expect(fn () => $client->get('/x', ['Action' => 'A', 'Version' => 'V']))
        ->toThrow(KsyunApiException::class, '500');
});

test('成功响应原样返回解析后的数组', function () {
    $client = ksyunClientWithMock([new Response(200, [], json_encode(['Domains' => [['DomainId' => '1']]]))], new ArrayObject);

    $resp = $client->get('/x', ['Action' => 'A', 'Version' => 'V']);
    expect($resp['Domains'][0]['DomainId'])->toBe('1');
});
