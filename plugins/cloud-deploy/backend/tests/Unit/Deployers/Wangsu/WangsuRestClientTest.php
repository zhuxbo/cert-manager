<?php

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Wangsu\WangsuApiException;
use Plugins\CloudDeploy\Deployers\Wangsu\WangsuRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 网宿云 CNC-HMAC-SHA256 签名 + REST 客户端测试。
 *
 * WangsuRestClient 构造接受注入的 GuzzleHttp\ClientInterface（$http），故用 mock 短路真实 HTTP +
 * 捕获**实际签名后**发出的请求头/URL/body，再用「独立重算」校验签名（拿请求头里的 X-CNC-Timestamp
 * 复算，不依赖 frozen clock）—— 测「我们生成的签名 == certimate signer.go 规范」，而非测 Guzzle 行为。
 *
 * 逐字节对齐 certimate pkg/sdk3rd/wangsu/zz-shared-common/signer.go。
 */

/** 抓取 WangsuRestClient 外发请求（method/uri/headers/body）的 mock HTTP client。 */
function wangsuCaptureHttp(array &$capture, Response $response): ClientInterface
{
    $http = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')
        ->andReturnUsing(function (string $method, string $uri, array $options) use (&$capture, $response) {
            $capture = [
                'method' => $method,
                'uri' => $uri,
                'headers' => $options['headers'] ?? [],
                'body' => $options['body'] ?? '',
            ];

            return $response;
        });

    return $http;
}

/** 独立重算 CNC-HMAC-SHA256 签名（规范实现，作金标准对照 client 输出）。 */
function wangsuExpectedSignature(string $method, string $path, string $body, string $timestamp, string $sk): string
{
    $contentType = 'application/json';
    $host = 'open.chinanetcenter.com';
    $canonicalHeaders = "content-type:$contentType\nhost:$host\n";
    $signedHeaders = 'content-type;host';
    $payloadHash = strtolower(hash('sha256', strtoupper($method) === 'GET' ? '' : $body));
    $canonicalRequest = implode("\n", [strtoupper($method), $path, '', $canonicalHeaders, $signedHeaders, $payloadHash]);
    $stringToSign = implode("\n", ['CNC-HMAC-SHA256', $timestamp, strtolower(hash('sha256', $canonicalRequest))]);

    return strtolower(hash_hmac('sha256', $stringToSign, $sk));
}

test('POST 请求签名对齐 certimate 规范（含 Authorization / X-CNC-* 头）', function () {
    $capture = [];
    // createCertificate 返回 Location 头（含 certId）。
    $http = wangsuCaptureHttp($capture, new Response(200, ['Location' => 'https://open.chinanetcenter.com/api/certificate/100001']));
    $client = new WangsuRestClient('AKID-TEST-12345', 'SECRET-TEST-67890', $http);

    $certId = $client->createCertificate('t', 'CERTPEM', 'KEYPEM', 'c');

    // Location 头解析出 certId
    expect($certId)->toBe('100001');

    $h = $capture['headers'];
    expect($capture['method'])->toBe('POST');
    expect($capture['uri'])->toBe('https://open.chinanetcenter.com/api/certificate');

    // 固定头齐全
    expect($h['X-CNC-Auth-Method'])->toBe('AKSK');
    expect($h['X-CNC-AccessKey'])->toBe('AKID-TEST-12345');
    expect($h['Content-Type'])->toBe('application/json');
    expect($h)->toHaveKey('X-CNC-Timestamp');
    expect($h)->toHaveKey('Date');

    // Authorization 头按规范重算（用客户端实际外发的 timestamp + body 复算，逐字节对齐）
    $expectedSig = wangsuExpectedSignature('POST', '/api/certificate', (string) $capture['body'], (string) $h['X-CNC-Timestamp'], 'SECRET-TEST-67890');
    expect($h['Authorization'])->toBe(
        "CNC-HMAC-SHA256 Credential=AKID-TEST-12345, SignedHeaders=content-type;host, Signature=$expectedSig",
    );

    // 私钥/SecretKey 绝不外发（不在 header/url/body）
    $blob = $capture['uri'].json_encode($h).$capture['body'];
    expect($blob)->not->toContain('SECRET-TEST-67890');
});

test('签名固定输入 → 固定输出（golden vector，timestamp=1700000000）', function () {
    // 钉死一条权威向量：固定 ak/sk/body/timestamp 时签名必为该值（防签名实现被悄改）。
    $expected = wangsuExpectedSignature(
        'POST',
        '/api/certificate',
        '{"name":"t","certificate":"CERT","privateKey":"KEY","comment":""}',
        '1700000000',
        'SECRET-TEST-67890',
    );
    // 独立重算函数与生产 sign() 同算法，二者必一致；并钉死具体字面量。
    expect($expected)->toBe('0d468a345ded68a3749953c025696abb335a7af99f2bd075eb50cf3983791c97');
});

test('GET 请求 body 视为空串参与签名（payloadHash=sha256("")）', function () {
    $capture = [];
    $http = wangsuCaptureHttp($capture, new Response(200, [], (string) json_encode(['hostname' => 'example.com'])));
    $client = new WangsuRestClient('AKID-TEST-12345', 'SECRET-TEST-67890', $http);

    $client->getCdnProHostnameDetail('example.com');

    $h = $capture['headers'];
    expect($capture['method'])->toBe('GET');
    expect($capture['uri'])->toBe('https://open.chinanetcenter.com/cdn/hostnames/example.com');
    expect((string) $capture['body'])->toBe('');

    // GET 用空 body 算 payloadHash
    $expectedSig = wangsuExpectedSignature('GET', '/cdn/hostnames/example.com', '', (string) $h['X-CNC-Timestamp'], 'SECRET-TEST-67890');
    expect($h['Authorization'])->toContain("Signature=$expectedSig");
});

test('CDN Pro 证书接口 X-CNC-Timestamp 头与签名时间戳一致', function () {
    $capture = [];
    $http = wangsuCaptureHttp($capture, new Response(200, ['Location' => 'https://open.chinanetcenter.com/cdn/certificates/abc123']));
    $client = new WangsuRestClient('AK', 'SK', $http);

    $client->createCdnProCertificate('name', ['certificate' => 'C', 'privateKey' => 'ENC'], 1700000000);

    $h = $capture['headers'];
    // 显式传入的 timestamp 既用于签名也置 X-CNC-Timestamp 头（网宿 CDN Pro 要求一致）
    expect($h['X-CNC-Timestamp'])->toBe('1700000000');
    $expectedSig = wangsuExpectedSignature('POST', '/cdn/certificates', (string) $capture['body'], '1700000000', 'SK');
    expect($h['Authorization'])->toContain("Signature=$expectedSig");
});

test('createCdnProCertificate 从 Location 解析对象 id，版本恒 1', function () {
    $capture = [];
    $http = wangsuCaptureHttp($capture, new Response(200, ['Location' => 'http://open.chinanetcenter.com/cdn/certificates/5dca2205f9e9cc0001df7b33']));
    $client = new WangsuRestClient('AK', 'SK', $http);

    $cert = $client->createCdnProCertificate('n', ['certificate' => 'C', 'privateKey' => 'E'], 1700000000);

    expect($cert)->toBe(['certId' => '5dca2205f9e9cc0001df7b33', 'version' => 1]);
});

test('updateCdnProCertificate 从 Location 解析对象 id + 版本号', function () {
    $capture = [];
    $http = wangsuCaptureHttp($capture, new Response(200, ['Location' => 'http://open.chinanetcenter.com/cdn/certificates/329f12c1fe6708c23c31e91f/versions/5']));
    $client = new WangsuRestClient('AK', 'SK', $http);

    $cert = $client->updateCdnProCertificate('329f12c1fe6708c23c31e91f', 'n', ['certificate' => 'C', 'privateKey' => 'E'], 1700000000);

    expect($cert)->toBe(['certId' => '329f12c1fe6708c23c31e91f', 'version' => 5]);
});

test('createCdnProDeploymentTask 从 Location 解析任务 id + 仅 webhook 非空时带 webhook', function () {
    $capture = [];
    $http = wangsuCaptureHttp($capture, new Response(200, ['Location' => 'http://open.chinanetcenter.com/cdn/deploymentTasks/task-789']));
    $client = new WangsuRestClient('AK', 'SK', $http);

    $taskId = $client->createCdnProDeploymentTask('n', 'production', 'cert-1', 3, 'wh-1');

    expect($taskId)->toBe('task-789');
    $body = json_decode((string) $capture['body'], true);
    expect($body['target'])->toBe('production');
    expect($body['webhook'])->toBe('wh-1');
    expect($body['actions'][0])->toBe(['action' => 'deploy_cert', 'certificateId' => 'cert-1', 'version' => 3]);
});

test('createCdnProDeploymentTask 未传 webhook 时 body 不含 webhook 键', function () {
    $capture = [];
    $http = wangsuCaptureHttp($capture, new Response(200, ['Location' => 'http://x/cdn/deploymentTasks/t1']));
    $client = new WangsuRestClient('AK', 'SK', $http);

    $client->createCdnProDeploymentTask('n', 'staging', 'cert-1', 1);

    $body = json_decode((string) $capture['body'], true);
    expect($body)->not->toHaveKey('webhook');
});

test('batchUpdateCertificateConfig 外发 certificateId(int) + domainNames[]', function () {
    $capture = [];
    $http = wangsuCaptureHttp($capture, new Response(200, [], '{}'));
    $client = new WangsuRestClient('AK', 'SK', $http);

    $client->batchUpdateCertificateConfig(100001, ['.example.com']);

    expect($capture['method'])->toBe('PUT');
    expect($capture['uri'])->toBe('https://open.chinanetcenter.com/api/config/certificate/batch');
    $body = json_decode((string) $capture['body'], true);
    expect($body['certificateId'])->toBe(100001); // 数字（非字符串）
    expect($body['domainNames'])->toBe(['.example.com']);
});

test('getCdnProDeploymentTaskDetail 归一 status + finishTime', function () {
    $capture = [];
    $http = wangsuCaptureHttp($capture, new Response(200, [], (string) json_encode([
        'status' => 'succeeded', 'finishTime' => '2023-11-14T00:00:00Z',
    ])));
    $client = new WangsuRestClient('AK', 'SK', $http);

    $detail = $client->getCdnProDeploymentTaskDetail('task-1');

    expect($detail)->toBe(['status' => 'succeeded', 'finishTime' => '2023-11-14T00:00:00Z']);
});

test('HTTP 非 2xx 抛 WangsuApiException（携 HTTP 状态码 + message）', function () {
    $capture = [];
    $http = wangsuCaptureHttp($capture, new Response(404, [], (string) json_encode(['message' => 'certificate not found'])));
    $client = new WangsuRestClient('AK', 'SK', $http);

    try {
        $client->getCdnProHostnameDetail('x.example.com');
        expect(false)->toBeTrue('应抛异常');
    } catch (WangsuApiException $e) {
        expect($e->getErrorCode())->toBe('404');
        expect($e->getErrorMessage())->toBe('certificate not found');
    }
});

test('响应体 code≠0 抛 WangsuApiException（即便 HTTP 2xx）', function () {
    $capture = [];
    $http = wangsuCaptureHttp($capture, new Response(200, [], (string) json_encode([
        'code' => 'CERT_INVALID', 'message' => 'invalid certificate',
    ])));
    $client = new WangsuRestClient('AK', 'SK', $http);

    try {
        $client->batchUpdateCertificateConfig(1, ['d.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (WangsuApiException $e) {
        expect($e->getErrorCode())->toBe('CERT_INVALID');
        expect($e->getErrorMessage())->toBe('invalid certificate');
    }
});

test('响应体 code="0" 视为成功（不抛）', function () {
    $capture = [];
    $http = wangsuCaptureHttp($capture, new Response(200, [], (string) json_encode(['code' => '0', 'hostname' => 'e.com'])));
    $client = new WangsuRestClient('AK', 'SK', $http);

    $body = $client->getCdnProHostnameDetail('e.com');
    expect($body['hostname'])->toBe('e.com');
});

test('createCertificate 缺 Location 头返回空串（由上层判空报错）', function () {
    $capture = [];
    $http = wangsuCaptureHttp($capture, new Response(200, [], '{}'));
    $client = new WangsuRestClient('AK', 'SK', $http);

    expect($client->createCertificate('n', 'C', 'K'))->toBe('');
});
