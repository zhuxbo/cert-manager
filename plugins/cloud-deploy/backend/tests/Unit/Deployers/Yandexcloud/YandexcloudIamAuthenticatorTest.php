<?php

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Yandexcloud\Ps256SignerInterface;
use Plugins\CloudDeploy\Deployers\Yandexcloud\YandexcloudApiException;
use Plugins\CloudDeploy\Deployers\Yandexcloud\YandexcloudIamAuthenticator;
use Tests\TestCase;

uses(TestCase::class);

function yandexServiceAccountKey(string $privateKey = 'PRIVATE-KEY-MATERIAL'): string
{
    return (string) json_encode([
        'id' => 'key-id',
        'service_account_id' => 'service-account-id',
        'private_key' => "PLEASE DO NOT REMOVE THIS LINE! Yandex.Cloud SA Key ID <key-id>\n$privateKey",
    ]);
}

test('IAM JWT 使用 PS256 头、官方 audience 和一小时有效期，并剥离私钥元数据首行', function () {
    $captured = [];
    $signer = new class($captured) implements Ps256SignerInterface
    {
        public function __construct(private array &$captured) {}

        public function sign(array $header, array $claims, string $privateKey): string
        {
            $this->captured = compact('header', 'claims', 'privateKey');

            return 'signed.jwt.value';
        }
    };

    $http = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->once()->andReturnUsing(function (string $method, string $uri, array $options) {
        expect($method)->toBe('POST');
        expect($uri)->toBe('iam/v1/tokens');
        expect($options['json'])->toBe(['jwt' => 'signed.jwt.value']);

        return new Response(200, [], (string) json_encode([
            'iamToken' => 'iam-token-value',
            'expiresAt' => '2026-08-12T12:00:00Z',
        ]));
    });

    $auth = new YandexcloudIamAuthenticator($signer, $http, fn (): int => 1_723_456_789);
    expect($auth->fetchIamToken(yandexServiceAccountKey()))->toBe('iam-token-value');
    expect($captured['header'])->toBe(['typ' => 'JWT', 'alg' => 'PS256', 'kid' => 'key-id']);
    expect($captured['claims'])->toMatchArray([
        'iss' => 'service-account-id',
        'aud' => 'https://iam.api.cloud.yandex.net/iam/v1/tokens',
        'iat' => 1_723_456_789,
        'nbf' => 1_723_456_789,
        'exp' => 1_723_460_389,
    ]);
    expect($captured['privateKey'])->toBe('PRIVATE-KEY-MATERIAL');
});

test('非法或缺字段的服务账号 JSON 在发请求前失败', function () {
    $signer = Mockery::mock(Ps256SignerInterface::class);
    $http = Mockery::mock(ClientInterface::class);
    $http->shouldNotReceive('request');
    $auth = new YandexcloudIamAuthenticator($signer, $http);

    expect(fn () => $auth->fetchIamToken('not-json'))
        ->toThrow(YandexcloudApiException::class, '服务账号密钥不是合法 JSON');
    expect(fn () => $auth->fetchIamToken('{}'))
        ->toThrow(YandexcloudApiException::class, '缺少 id / service_account_id / private_key');
});

test('IAM 失败响应和异常不泄露 JWT、私钥或 IAM Token', function () {
    $signer = Mockery::mock(Ps256SignerInterface::class);
    $signer->shouldReceive('sign')->andReturn('secret.jwt.material');
    $http = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->andReturn(new Response(401, [], (string) json_encode([
        'code' => 16,
        'message' => 'invalid secret.jwt.material PRIVATE-KEY-MATERIAL iam-token-value',
    ])));

    $auth = new YandexcloudIamAuthenticator($signer, $http);
    try {
        $auth->fetchIamToken(yandexServiceAccountKey());
        expect(false)->toBeTrue('应抛出异常');
    } catch (YandexcloudApiException $e) {
        expect($e->getMessage())->toContain('Unauthenticated');
        expect($e->getMessage())
            ->not->toContain('secret.jwt.material')
            ->not->toContain('PRIVATE-KEY-MATERIAL')
            ->not->toContain('iam-token-value');
    }
});
