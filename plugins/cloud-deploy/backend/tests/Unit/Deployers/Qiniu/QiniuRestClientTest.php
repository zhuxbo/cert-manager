<?php

use Plugins\CloudDeploy\Deployers\Qiniu\QiniuRestClient;
use Qiniu\Auth;
use Qiniu\Http\Response;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @return array{0:QiniuRestClient,1:Closure():array<string,mixed>}
 */
function qiniuRestClientWithFake(Response $response): array
{
    $capture = [];
    $client = new QiniuRestClient(
        new Auth('AKID-TEST', 'SK-SECRET-TEST'),
        function (string $method, string $url, string $payload, array $headers) use (&$capture, $response): Response {
            $capture = compact('method', 'url', 'payload', 'headers');

            return $response;
        },
    );

    return [$client, static function () use (&$capture): array {
        return $capture;
    }];
}

test('上传证书按 Certimate 使用 api 端点和 Qiniu V2 鉴权', function () {
    [$client, $captured] = qiniuRestClientWithFake(new Response(
        200,
        0.01,
        ['Content-Type' => 'application/json'],
        json_encode(['certID' => 'cert-001']),
    ));

    $certId = $client->uploadSslCert('cert-name', 'example.com', 'FULLCHAIN', 'PRIVATEKEY');
    $request = $captured();

    expect($certId)->toBe('cert-001');
    expect($request['method'])->toBe('POST');
    expect($request['url'])->toBe('https://api.qiniu.com/sslcert');
    expect($request['headers']['Authorization'])->toStartWith('Qiniu AKID-TEST:');
    expect($request['headers'])->toHaveKey('X-Qiniu-Date');
    expect(json_decode($request['payload'], true))->toBe([
        'name' => 'cert-name',
        'common_name' => 'example.com',
        'ca' => 'FULLCHAIN',
        'pri' => 'PRIVATEKEY',
    ]);
});

test('七牛响应体 code 200 视为成功', function () {
    [$client] = qiniuRestClientWithFake(new Response(
        200,
        0.01,
        ['Content-Type' => 'application/json'],
        json_encode(['code' => 200, 'certID' => 'cert-002']),
    ));

    expect($client->uploadSslCert('cert-name', 'example.com', 'FULLCHAIN', 'PRIVATEKEY'))
        ->toBe('cert-002');
});
