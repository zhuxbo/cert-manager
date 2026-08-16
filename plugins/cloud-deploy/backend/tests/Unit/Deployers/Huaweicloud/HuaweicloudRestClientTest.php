<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudApiException;
use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudRestClient;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 用 Guzzle MockHandler + History 中间件捕获实际外发请求，校验华为云 SDK-HMAC-SHA256 签名协议
 * （逐字节对齐官方 SDK core/auth/signer，全语言一致）：
 *   - Authorization 头形如 `SDK-HMAC-SHA256 Access={AK}, SignedHeaders=host;x-sdk-date, Signature={hex}`。
 *   - X-Sdk-Date 头形如 UTC `Ymd\THis\Z`。
 *   - Signature 值用「独立参考实现」从捕获到的请求（method/path/query/body + 客户端内部生成的 X-Sdk-Date）重算并断言相等。
 *   - 错误归一：响应体 error_code / HTTP 非 2xx → HuaweicloudApiException。
 */

/**
 * 构造一个注入了 MockHandler 的 HuaweicloudRestClient，并把外发请求写入传入的 $history 容器（按引用）。
 *
 * @param  list<Response>  $responses
 */
function hwClientWithMock(array $responses, ArrayObject $history, string $host = 'scm.cn-north-4.myhuaweicloud.com', string $projectId = ''): HuaweicloudRestClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false]);

    return new HuaweicloudRestClient($host, 'AKID-TEST', 'SK-SECRET-TEST', $projectId, $http);
}

/**
 * 参考实现：华为云 SDK-HMAC-SHA256 签名（与被测代码独立的第二份实现，逐字节对齐官方 SDK signer）。
 * 仅签 host + x-sdk-date（被测实现的最小确定签名集）。
 *
 * @param  array<string,string>  $query
 */
function hwReferenceSignature(string $method, string $path, array $query, string $payload, string $host, string $sdkDate, string $secret, string $projectId = ''): string
{
    // CanonicalURI：逐段 rawurlencode，保证以 / 结尾
    $segments = array_map('rawurlencode', explode('/', $path === '' ? '/' : $path));
    $canonicalUri = implode('/', $segments);
    if (! str_ends_with($canonicalUri, '/')) {
        $canonicalUri .= '/';
    }

    // CanonicalQuery：键升序，enc(k)=enc(v)
    ksort($query, SORT_STRING);
    $qParts = [];
    foreach ($query as $k => $v) {
        $qParts[] = rawurlencode((string) $k).'='.rawurlencode((string) $v);
    }
    $canonicalQuery = implode('&', $qParts);

    // 签名头：host + 可选 x-project-id + x-sdk-date（按官方 SDK 字典序）
    $signed = ['host' => $host, 'x-sdk-date' => $sdkDate];
    if ($projectId !== '') {
        $signed['x-project-id'] = $projectId;
    }
    ksort($signed, SORT_STRING);
    $canonicalHeaders = '';
    foreach ($signed as $name => $value) {
        $canonicalHeaders .= $name.':'.trim($value)."\n";
    }
    $signedHeaders = implode(';', array_keys($signed));

    $canonicalRequest = implode("\n", [
        strtoupper($method),
        $canonicalUri,
        $canonicalQuery,
        $canonicalHeaders,
        $signedHeaders,
        hash('sha256', $payload),
    ]);

    $stringToSign = implode("\n", [
        'SDK-HMAC-SHA256',
        $sdkDate,
        hash('sha256', $canonicalRequest),
    ]);

    return hash_hmac('sha256', $stringToSign, $secret);
}

/** 从 Authorization 头解析出 Access / SignedHeaders / Signature。 */
function hwParseAuthorization(string $authorization): array
{
    expect($authorization)->toStartWith('SDK-HMAC-SHA256 ');
    $rest = substr($authorization, strlen('SDK-HMAC-SHA256 '));
    $out = [];
    foreach (explode(', ', $rest) as $kv) {
        [$k, $v] = explode('=', $kv, 2);
        $out[$k] = $v;
    }

    return $out;
}

test('GET：Authorization 头格式正确 + Signature 参考实现重算一致 + X-Sdk-Date 形态', function () {
    $history = new ArrayObject;
    $client = hwClientWithMock([new Response(200, [], json_encode(['projects' => []]))], $history);

    $client->get('/v3/projects', ['name' => 'cn-north-4']);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('GET');
    expect($req->getUri()->getHost())->toBe('scm.cn-north-4.myhuaweicloud.com');
    expect($req->getUri()->getPath())->toBe('/v3/projects');
    expect((string) $req->getBody())->toBe('');

    $sdkDate = $req->getHeaderLine('X-Sdk-Date');
    expect($sdkDate)->toMatch('/^\d{8}T\d{6}Z$/');

    $auth = hwParseAuthorization($req->getHeaderLine('Authorization'));
    expect($auth['Access'])->toBe('AKID-TEST');
    expect($auth['SignedHeaders'])->toBe('host;x-sdk-date');

    $expected = hwReferenceSignature('GET', '/v3/projects', ['name' => 'cn-north-4'], '', 'scm.cn-north-4.myhuaweicloud.com', $sdkDate, 'SK-SECRET-TEST');
    expect($auth['Signature'])->toBe($expected);
});

test('POST：body 进签名（payload hash），Content-Type application/json，参考实现重算一致', function () {
    $history = new ArrayObject;
    $client = hwClientWithMock([new Response(200, [], json_encode(['certificate_id' => 'c-1']))], $history);

    $body = ['name' => 'cert-x', 'certificate' => 'PEM', 'private_key' => 'KEY'];
    $client->post('/v3/scm/certificates/import', $body);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getHeaderLine('Content-Type'))->toContain('application/json');

    $payload = (string) $req->getBody();
    // body 是被测实现 json_encode 的结果；参考实现用同样 flags 重算保证一致
    $expectedPayload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    expect($payload)->toBe($expectedPayload);

    $sdkDate = $req->getHeaderLine('X-Sdk-Date');
    $auth = hwParseAuthorization($req->getHeaderLine('Authorization'));
    $expected = hwReferenceSignature('POST', '/v3/scm/certificates/import', [], $payload, 'scm.cn-north-4.myhuaweicloud.com', $sdkDate, 'SK-SECRET-TEST');
    expect($auth['Signature'])->toBe($expected);
});

test('PUT + query：CanonicalURI 以 / 结尾、query 进签名，参考实现重算一致', function () {
    $history = new ArrayObject;
    $client = hwClientWithMock([new Response(200, [], '{}')], $history, 'live.cn-north-4.myhuaweicloud.com');

    $body = ['tls_certificate' => ['source' => 'scm', 'cert_id' => 'c-9']];
    $client->put('/v1/proj-1/guard/https-cert', $body, ['domain' => 'live.example.com']);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('PUT');
    parse_str($req->getUri()->getQuery(), $q);
    expect($q['domain'])->toBe('live.example.com');

    $payload = (string) $req->getBody();
    $sdkDate = $req->getHeaderLine('X-Sdk-Date');
    $auth = hwParseAuthorization($req->getHeaderLine('Authorization'));
    // 路径不以 / 结尾 → CanonicalURI 应补 /；参考实现同样补
    $expected = hwReferenceSignature('PUT', '/v1/proj-1/guard/https-cert', ['domain' => 'live.example.com'], $payload, 'live.cn-north-4.myhuaweicloud.com', $sdkDate, 'SK-SECRET-TEST');
    expect($auth['Signature'])->toBe($expected);
});

test('projectId 非空时 X-Project-Id 纳入 CanonicalHeaders 和 SignedHeaders', function () {
    $history = new ArrayObject;
    $client = hwClientWithMock([new Response(200, [], '{}')], $history, 'elb.cn-north-4.myhuaweicloud.com', 'proj-42');

    $client->get('/v3/proj-42/elb/listeners/l-1');

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    $sdkDate = $req->getHeaderLine('X-Sdk-Date');
    $auth = hwParseAuthorization($req->getHeaderLine('Authorization'));
    $expected = hwReferenceSignature(
        'GET',
        '/v3/proj-42/elb/listeners/l-1',
        [],
        '',
        'elb.cn-north-4.myhuaweicloud.com',
        $sdkDate,
        'SK-SECRET-TEST',
        'proj-42',
    );

    expect($req->getHeaderLine('X-Project-Id'))->toBe('proj-42')
        ->and($auth['SignedHeaders'])->toBe('host;x-project-id;x-sdk-date')
        ->and($auth['Signature'])->toBe($expected);
});

test('响应体含 error_code → 抛 HuaweicloudApiException（code + error_msg）', function () {
    $client = hwClientWithMock([
        new Response(400, [], json_encode(['error_code' => 'SCM.0001', 'error_msg' => 'invalid certificate'])),
    ], new ArrayObject);

    try {
        $client->post('/v3/scm/certificates/import', ['name' => 'x']);
        expect(false)->toBeTrue('应抛异常');
    } catch (HuaweicloudApiException $e) {
        expect($e->getErrorCode())->toBe('SCM.0001');
        expect($e->getErrorMessage())->toBe('invalid certificate');
    }
});

test('嵌套 error.code 错误体也能归一', function () {
    $client = hwClientWithMock([
        new Response(403, [], json_encode(['error' => ['code' => 'APIGW.0301', 'message' => 'forbidden']])),
    ], new ArrayObject);

    expect(fn () => $client->get('/v2/p/apigw/certificates/c-1'))
        ->toThrow(HuaweicloudApiException::class, 'APIGW.0301');
});

test('HTTP 非 2xx 且无错误体 → 抛 HuaweicloudApiException（HTTP 状态码）', function () {
    $client = hwClientWithMock([new Response(502, [], 'bad gateway')], new ArrayObject);

    expect(fn () => $client->get('/x', ['Action' => 'A']))
        ->toThrow(HuaweicloudApiException::class, '502');
});

test('成功响应原样返回解析后的数组', function () {
    $client = hwClientWithMock([new Response(200, [], json_encode(['certificate' => ['id' => 'e-1', 'name' => 'n']]))], new ArrayObject);

    $resp = $client->post('/v3/p/elb/certificates', ['certificate' => []]);
    expect($resp['certificate']['id'])->toBe('e-1');
});

test('非空 2xx 响应必须是合法 JSON 对象', function (string $body) {
    $client = hwClientWithMock([new Response(200, [], $body)], new ArrayObject);

    expect(fn () => $client->get('/v3/projects'))
        ->toThrow(HuaweicloudApiException::class, 'HuaweicloudInvalidResponse');
})->with([
    '畸形 JSON' => ['{"projects":'],
    'JSON 数组' => ['[]'],
    'JSON 标量' => ['true'],
]);

test('空 2xx 响应与华为云 SDK 空 body 语义一致', function () {
    $client = hwClientWithMock([new Response(204)], new ArrayObject);

    expect($client->put('/v1/project/https-cert', []))->toBe([]);
});

test('CanonicalURI 对 path 段做 RFC3986 编码（空格 %20，参考实现一致）', function () {
    $history = new ArrayObject;
    $client = hwClientWithMock([new Response(200, [], '{}')], $history);

    // path 段含空格 → rawurlencode 为 %20
    $client->get('/v1/a b/c');

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    $sdkDate = $req->getHeaderLine('X-Sdk-Date');
    $auth = hwParseAuthorization($req->getHeaderLine('Authorization'));
    $expected = hwReferenceSignature('GET', '/v1/a b/c', [], '', 'scm.cn-north-4.myhuaweicloud.com', $sdkDate, 'SK-SECRET-TEST');
    expect($auth['Signature'])->toBe($expected);
});
