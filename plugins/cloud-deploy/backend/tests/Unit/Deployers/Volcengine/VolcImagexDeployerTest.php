<?php

use Plugins\CloudDeploy\Deployers\Volcengine\VolcApiException;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcImagexDeployer;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcRestClient;
use Tests\TestCase;

uses(TestCase::class);

function volcImagexDeployerWith(callable $clientFactory): VolcImagexDeployer
{
    return new class($clientFactory) extends VolcImagexDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function volcImagexCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('火山 veImageX：证书服务型（storeKind volc_certcenter）', function () {
    $deployer = new VolcImagexDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('volc_certcenter');
    expect($deployer->product())->toBe('imagex');
});

test('bind：GetDomainConfig(query) 读配置 → UpdateHttps(POST，ServiceId 入 query) snake_case body 设 cert_id', function () {
    $update = null;
    $getQuery = null;
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callQuery')->andReturnUsing(function (string $action, string $version, array $params) use (&$getQuery) {
        $getQuery = compact('action', 'params');

        return ['HTTPSConfig' => ['EnableHTTPS' => true, 'EnableHTTP2' => true, 'TLSVersions' => ['tlsv1.2']]];
    });
    $client->shouldReceive('callJson')->andReturnUsing(function (string $action, string $version, array $body, array $extraQuery) use (&$update) {
        $update = compact('action', 'version', 'body', 'extraQuery');

        return [];
    });

    $deployer = volcImagexDeployerWith(fn (string $kind) => $kind === 'imagex' ? $client : new stdClass);
    $deployer->bind('cert-9', volcImagexCreds(), ['service_id' => 'svc-1', 'domain' => 'img.example.com']);

    // GetDomainConfig 走 query GET，ServiceId/DomainName 为 query 参数
    expect($getQuery['action'])->toBe('GetDomainConfig');
    expect($getQuery['params'])->toBe(['ServiceId' => 'svc-1', 'DomainName' => 'img.example.com']);

    // UpdateHttps（注意 Action 大小写）+ ServiceId 入 query + snake_case body
    expect($update['action'])->toBe('UpdateHttps');
    expect($update['version'])->toBe('2018-08-01');
    expect($update['extraQuery'])->toBe(['ServiceId' => 'svc-1']);
    expect($update['body']['domain'])->toBe('img.example.com');
    expect($update['body']['https']['cert_id'])->toBe('cert-9');
    expect($update['body']['https']['enable_https'])->toBeTrue();
    // 保留既有开关
    expect($update['body']['https']['enable_http2'])->toBeTrue();
    expect($update['body']['https']['tls_versions'])->toBe(['tlsv1.2']);
});

test('缺 service_id / domain 抛业务错误', function () {
    $deployer = volcImagexDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', volcImagexCreds(), ['domain' => 'd']))
        ->toThrow(RuntimeException::class, '缺少配置 service_id');
    expect(fn () => $deployer->bind('c', volcImagexCreds(), ['service_id' => 's']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 VolcApiException 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callQuery')->andReturn([]);
    $client->shouldReceive('callJson')->andThrow(new VolcApiException('ImagexErr', 'update https failed'));

    $deployer = volcImagexDeployerWith(fn () => $client);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], ['service_id' => 's', 'domain' => 'd.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('ImagexErr');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});
