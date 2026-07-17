<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudApiException;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudRestClient;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 天翼云 EOP 签名 KAT + 协议校验（逐字节对齐 certimate pkg/sdk3rd/ctyun/zz-shared-common/signer.go）：
 *   - 三级派生密钥 kTime=HMAC(sk,eopDate) → kAk=HMAC(kTime,ak) → kDate=HMAC(kAk,Ymd)。
 *   - stringToSign = "ctyun-eop-request-id:{id}\neop-date:{date}\n\n{query}\n{sha256hex(payload)}"。
 *   - signature = base64(HMAC(kDate, stringToSign))，写入 eop-authorization 头。
 *   - GET：query 进 URL（键升序 + QueryEscape，空格 `+`），无 body，payloadHash = sha256("")。
 *   - POST/PUT：JSON body 作 payload，进 payloadHash；query 仍可空。
 *   - 错误归一：各服务成功码不同（100000 / 200 / 200·800+error / 0），statusCode/error 触发 CtcccloudApiException。
 *
 * 双重验证防「客户端与参考实现同 bug 假绿」：
 *   1. 硬编码 KAT 常量（由独立脚本对固定输入 ak/sk/eopDate/reqId/query/payload 算出）—— ground truth。
 *   2. 参考实现 ctyunReferenceSign（与被测代码独立的第二份）从捕获到的 eop-date/请求 id 重算并断言相等。
 */

/** 固定 Unix 时间戳 1705305600 → eopDate=20240115T080000Z / Ymd=20240115（与 KAT 常量同源）。 */
const KAT_TS = 1705305600;

const KAT_EOP_DATE = '20240115T080000Z';

const KAT_REQ_ID = 'abc123def456abc123def456abc12300';

const KAT_AK = 'AKID-TEST';

const KAT_SK = 'SK-SECRET-TEST';

/**
 * 确定性客户端子类：把 currentTime()/requestId() 钉死为 KAT 已知值，使签名可对硬编码常量。
 *
 * @param  list<Response>  $responses
 */
function ctyunDeterministicClient(array $responses, ArrayObject $history, array $successCodes = ['100000'], bool $errFail = false, array $allowedErrors = []): CtcccloudRestClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false]);

    return new class('ctcdn-global.ctapi.ctyun.cn', KAT_AK, KAT_SK, $successCodes, $errFail, $allowedErrors, $http) extends CtcccloudRestClient
    {
        protected function currentTime(): int
        {
            return KAT_TS;
        }

        protected function requestId(): string
        {
            return KAT_REQ_ID;
        }
    };
}

/**
 * 参考实现：天翼云 EOP 签名（与被测代码独立的第二份，逐字节对齐 signer.go）。
 */
function ctyunReferenceSign(string $ak, string $sk, string $eopDate, string $eopReqId, string $dateYmd, string $queryStr, string $payload): string
{
    $payloadHashHex = hash('sha256', $payload);
    $kTime = hash_hmac('sha256', $eopDate, $sk, true);
    $kAk = hash_hmac('sha256', $ak, $kTime, true);
    $kDate = hash_hmac('sha256', $dateYmd, $kAk, true);
    $stringToSign = "ctyun-eop-request-id:{$eopReqId}\neop-date:{$eopDate}\n\n{$queryStr}\n{$payloadHashHex}";

    return base64_encode(hash_hmac('sha256', $stringToSign, $kDate, true));
}

/** 从 eop-authorization 头解析出 Signature 值。 */
function parseEopSignature(string $authHeader): string
{
    expect($authHeader)->toMatch('/ Signature=/');
    $pos = strpos($authHeader, 'Signature=');

    return substr($authHeader, $pos + strlen('Signature='));
}

test('GET 签名命中硬编码 KAT 常量（ground truth）+ 头三件套 + query 进 URL 无 body', function () {
    $history = new ArrayObject;
    $client = ctyunDeterministicClient([new Response(200, [], json_encode(['statusCode' => '100000']))], $history);

    $client->get('/v1/domain/query-domain-detail', ['domain' => 'cdn.example.com']);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('GET');
    expect((string) $req->getBody())->toBe('');

    // 头三件套
    expect($req->getHeaderLine('ctyun-eop-request-id'))->toBe(KAT_REQ_ID);
    expect($req->getHeaderLine('eop-date'))->toBe(KAT_EOP_DATE);

    // query 进 URL
    expect($req->getUri()->getQuery())->toBe('domain=cdn.example.com');

    // 硬编码 KAT 常量（独立脚本算得）：CASE_A
    $sig = parseEopSignature($req->getHeaderLine('eop-authorization'));
    expect($sig)->toBe('cR1XSUTVwOE51qjHi9d/5kcYUKvpqVqeZFjo2+woEWE=');

    // eop-authorization 前缀含 ak + Headers
    expect($req->getHeaderLine('eop-authorization'))
        ->toBe(KAT_AK.' Headers=ctyun-eop-request-id;eop-date Signature='.$sig);
});

test('POST 签名命中硬编码 KAT 常量（body 进 payloadHash）+ body 为 JSON', function () {
    $history = new ArrayObject;
    $client = ctyunDeterministicClient([new Response(200, [], json_encode(['statusCode' => '100000']))], $history);

    $client->post('/v1/cert/creat-cert', ['name' => 'c1', 'certs' => 'CERTPEM', 'key' => 'KEYPEM']);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getHeaderLine('Content-Type'))->toContain('application/json');
    // body 与 KAT 同源（JSON_UNESCAPED_SLASHES|UNICODE）
    expect((string) $req->getBody())->toBe('{"name":"c1","certs":"CERTPEM","key":"KEYPEM"}');
    // POST 无 query
    expect($req->getUri()->getQuery())->toBe('');

    // 硬编码 KAT 常量：CASE_B
    $sig = parseEopSignature($req->getHeaderLine('eop-authorization'));
    expect($sig)->toBe('y84OgHflK6aEyzDBES9TdNaHjqNECx1VSzTps8LzL5c=');
});

test('参考实现重算一致（捕获 eop-date/req-id 反推，GET 多参数键升序 + 空格→+）', function () {
    $history = new ArrayObject;
    $client = ctyunDeterministicClient([new Response(200, [], json_encode(['statusCode' => '100000']))], $history);

    // 含空格的值 + 多键（验证键升序、空格编 +）
    $client->get('/live/domain/query-domain-detail', ['domain' => 'z', 'a' => 'x y']);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    $query = $req->getUri()->getQuery();
    // 键升序：a 在 domain 前；空格编为 +（非 %20）
    expect($query)->toBe('a=x+y&domain=z');

    $sig = parseEopSignature($req->getHeaderLine('eop-authorization'));
    $expected = ctyunReferenceSign(
        KAT_AK,
        KAT_SK,
        $req->getHeaderLine('eop-date'),
        $req->getHeaderLine('ctyun-eop-request-id'),
        gmdate('Ymd', strtotime($req->getHeaderLine('eop-date'))),
        $query,
        '',
    );
    expect($sig)->toBe($expected);
});

test('真实随机 req-id/时间 下参考实现仍一致（非确定性子类，验证默认 currentTime/requestId 路径）', function () {
    $history = new ArrayObject;
    $mock = new MockHandler([new Response(200, [], json_encode(['statusCode' => '100000']))]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false]);
    // 用真实基类（默认 time()/random req-id）
    $client = new CtcccloudRestClient('ctcdn-global.ctapi.ctyun.cn', KAT_AK, KAT_SK, ['100000'], false, [], $http);

    $client->post('/v1/cert/creat-cert', ['name' => 'c1']);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    // req-id 是 32 字符 [0-9A-Za-z]
    expect($req->getHeaderLine('ctyun-eop-request-id'))->toMatch('/^[0-9A-Za-z]{32}$/');
    // eop-date 形如 UTC 紧凑 ISO8601
    expect($req->getHeaderLine('eop-date'))->toMatch('/^\d{8}T\d{6}Z$/');

    $sig = parseEopSignature($req->getHeaderLine('eop-authorization'));
    $expected = ctyunReferenceSign(
        KAT_AK,
        KAT_SK,
        $req->getHeaderLine('eop-date'),
        $req->getHeaderLine('ctyun-eop-request-id'),
        gmdate('Ymd', strtotime($req->getHeaderLine('eop-date'))),
        '',
        (string) $req->getBody(),
    );
    expect($sig)->toBe($expected);
});

test('PUT 走 body 协议 + 附加请求头（faas 的 regionId）一并下发', function () {
    $history = new ArrayObject;
    $client = ctyunDeterministicClient([new Response(200, [], json_encode(['statusCode' => '0']))], $history, ['0']);

    $client->put('/openapi/v1/domains/customdomains/d.example.com', ['domainName' => 'd.example.com'], [], ['regionId' => 'cn-bj']);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('PUT');
    expect($req->getHeaderLine('regionId'))->toBe('cn-bj');
    expect((string) $req->getBody())->toBe('{"domainName":"d.example.com"}');
});

test('CDN 系成功码 100000 通过；非 100000 抛 CtcccloudApiException（携 message）', function () {
    $ok = ctyunDeterministicClient([new Response(200, [], json_encode(['statusCode' => '100000', 'returnObj' => ['id' => 1]]))], new ArrayObject);
    expect($ok->post('/v1/cert/creat-cert', ['name' => 'c'])['returnObj']['id'])->toBe(1);

    $bad = ctyunDeterministicClient([new Response(200, [], json_encode(['statusCode' => '800001', 'message' => 'domain not found']))], new ArrayObject);
    try {
        $bad->get('/v1/domain/query-domain-detail', ['domain' => 'x']);
        expect(false)->toBeTrue('应抛异常');
    } catch (CtcccloudApiException $e) {
        expect($e->getErrorCode())->toBe('800001');
        expect($e->getErrorMessage())->toBe('domain not found');
    }
});

test('CMS 成功码 200 通过；error 非空即失败', function () {
    $ok = ctyunDeterministicClient([new Response(200, [], json_encode(['statusCode' => '200']))], new ArrayObject, ['200'], true);
    expect($ok->post('/v1/certificate/upload', ['name' => 'c']))->toBeArray();

    // statusCode 200 但 error 非空 → 失败（cms 体系）
    $bad = ctyunDeterministicClient([new Response(200, [], json_encode(['statusCode' => '200', 'error' => 'CCMS_X', 'message' => 'dup']))], new ArrayObject, ['200'], true);
    expect(fn () => $bad->post('/v1/certificate/upload', ['name' => 'c']))
        ->toThrow(CtcccloudApiException::class, 'dup');
});

test('ELB 成功码 200/800 + error=SUCCESS 放行；其他 error 失败', function () {
    // statusCode 800 + error SUCCESS → 通过
    $ok = ctyunDeterministicClient([new Response(200, [], json_encode(['statusCode' => '800', 'error' => 'SUCCESS', 'returnObj' => ['id' => 'c-1']]))], new ArrayObject, ['200', '800'], true, ['SUCCESS']);
    expect($ok->post('/v4/elb/create-certificate', ['name' => 'c'])['returnObj']['id'])->toBe('c-1');

    // statusCode 200 但 error 非 SUCCESS → 失败
    $bad = ctyunDeterministicClient([new Response(200, [], json_encode(['statusCode' => '200', 'error' => 'PARAM_ERROR', 'description' => 'bad region']))], new ArrayObject, ['200', '800'], true, ['SUCCESS']);
    try {
        $bad->post('/v4/elb/update-listener', ['listenerID' => 'l']);
        expect(false)->toBeTrue('应抛异常');
    } catch (CtcccloudApiException $e) {
        expect($e->getErrorCode())->toBe('PARAM_ERROR');
        expect($e->getErrorMessage())->toBe('bad region'); // description 兜底
    }
});

test('HTTP 非 2xx 且响应体无 statusCode → 抛 CtcccloudApiException（HTTP 状态码）', function () {
    $client = ctyunDeterministicClient([new Response(502, [], 'bad gateway')], new ArrayObject);

    expect(fn () => $client->get('/x', ['a' => '1']))
        ->toThrow(CtcccloudApiException::class, '502');
});

test('成功响应原样返回解析后的数组', function () {
    $client = ctyunDeterministicClient([new Response(200, [], json_encode(['statusCode' => '100000', 'returnObj' => ['cert_name' => 'n1']]))], new ArrayObject);
    $resp = $client->get('/x', ['a' => '1']);
    expect($resp['returnObj']['cert_name'])->toBe('n1');
});
