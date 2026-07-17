<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudApiException;
use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweiObsClient;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 校验华为云 OBS V2 签名（HMAC-SHA1，逐字节对齐 certimate pkg/sdk3rd/huaweicloud/obs/signer.go）：
 *   - Authorization 头 `OBS {AK}:{base64(HMAC-SHA1)}`。
 *   - stringToSign = METHOD\nContent-MD5\nContent-Type\nDate\nCanonicalizedResource。
 *   - CanonicalizedResource = escapePath("/{bucket}/") + ?customdomain={enc(domain)}。
 *   - 用独立参考实现从捕获请求重算 Signature 并断言相等。
 */
function hwObsClientWithMock(array $responses, ArrayObject $history, string $bucket = 'mybucket', string $region = 'cn-north-4'): HuaweiObsClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $http = new Client(['handler' => $stack, 'http_errors' => false]);

    return new HuaweiObsClient($bucket, $region, 'AKID-OBS', 'SK-OBS-SECRET', $http);
}

/** escapePath 参考实现（仅保留 A-Za-z0-9-._~/，其余 %XX 大写）。 */
function hwObsEscapePath(string $path): string
{
    $out = '';
    $len = strlen($path);
    for ($i = 0; $i < $len; $i++) {
        $c = $path[$i];
        $out .= preg_match('/[A-Za-z0-9\-._~\/]/', $c) === 1 ? $c : '%'.strtoupper(bin2hex($c));
    }

    return $out;
}

/** OBS V2 签名参考实现。 */
function hwObsReferenceSignature(string $method, string $contentMd5, string $contentType, string $date, string $bucket, string $subResource, string $secret): string
{
    $canonicalizedResource = hwObsEscapePath('/'.$bucket.'/');
    if ($subResource !== '') {
        $canonicalizedResource .= '?'.$subResource;
    }
    $stringToSign = implode("\n", [$method, $contentMd5, $contentType, $date, $canonicalizedResource]);

    return base64_encode(hash_hmac('sha1', $stringToSign, $secret, true));
}

test('PutBucketCustomDomain：虚拟主机式 host + customdomain 查询 + XML body（Name/CertificateId）', function () {
    $history = new ArrayObject;
    $client = hwObsClientWithMock([new Response(200, [], '')], $history, 'mybucket', 'cn-north-4');

    $client->putBucketCustomDomain('static.example.com', 'cert-name-1', 'scm-cert-99');

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('PUT');
    expect($req->getUri()->getHost())->toBe('mybucket.obs.cn-north-4.myhuaweicloud.com');
    parse_str($req->getUri()->getQuery(), $q);
    expect($q)->toHaveKey('customdomain');
    expect($q['customdomain'])->toBe('static.example.com');

    $body = (string) $req->getBody();
    expect($body)->toContain('<Name>cert-name-1</Name>');
    expect($body)->toContain('<CertificateId>scm-cert-99</CertificateId>');
    // 不内联 PEM（引用 SCM 托管 id）
    expect($body)->not->toContain('<PrivateKey>');
});

test('OBS Authorization 头 = OBS {AK}:{sig}，签名参考实现重算一致', function () {
    $history = new ArrayObject;
    $client = hwObsClientWithMock([new Response(200, [], '')], $history, 'mybucket', 'cn-north-4');

    $client->putBucketCustomDomain('static.example.com', 'cert-name-1', 'scm-cert-99');

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    $authorization = $req->getHeaderLine('Authorization');
    expect($authorization)->toStartWith('OBS AKID-OBS:');

    $sig = substr($authorization, strlen('OBS AKID-OBS:'));
    $contentMd5 = $req->getHeaderLine('Content-MD5');
    $contentType = $req->getHeaderLine('Content-Type');
    $date = $req->getHeaderLine('Date');
    expect($contentType)->toBe('application/xml');
    // Content-MD5 = base64(md5(body, raw))
    expect($contentMd5)->toBe(base64_encode(md5((string) $req->getBody(), true)));
    // Date 形如 RFC1123 GMT
    expect($date)->toMatch('/GMT$/');

    $subResource = 'customdomain='.rawurlencode('static.example.com');
    $expected = hwObsReferenceSignature('PUT', $contentMd5, $contentType, $date, 'mybucket', $subResource, 'SK-OBS-SECRET');
    expect($sig)->toBe($expected);
});

test('HTTP 非 2xx + XML 错误体 → 抛 HuaweicloudApiException（Code + Message）', function () {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>NoSuchBucket</Code><Message>bucket not found</Message></Error>';
    $client = hwObsClientWithMock([new Response(404, [], $xml)], new ArrayObject);

    try {
        $client->putBucketCustomDomain('d.example.com', 'n', 'id-1');
        expect(false)->toBeTrue('应抛异常');
    } catch (HuaweicloudApiException $e) {
        expect($e->getErrorCode())->toBe('NoSuchBucket');
        expect($e->getErrorMessage())->toBe('bucket not found');
    }
});

test('HTTP 非 2xx 无 XML 错误体 → HTTP 状态码', function () {
    $client = hwObsClientWithMock([new Response(500, [], 'oops')], new ArrayObject);

    expect(fn () => $client->putBucketCustomDomain('d.example.com', 'n', 'id-1'))
        ->toThrow(HuaweicloudApiException::class, '500');
});
