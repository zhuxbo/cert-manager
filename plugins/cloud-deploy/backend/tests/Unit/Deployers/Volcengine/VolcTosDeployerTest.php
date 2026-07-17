<?php

use Plugins\CloudDeploy\Deployers\Volcengine\VolcApiException;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcRestClient;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcTosDeployer;
use Tests\TestCase;

uses(TestCase::class);

function volcTosDeployerWith(callable $clientFactory): VolcTosDeployer
{
    return new class($clientFactory) extends VolcTosDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials, string $region = ''): object
        {
            return ($this->factory)($kind, $credentials, $region);
        }
    };
}

function volcTosCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('火山 TOS：证书服务型（storeKind volc_certcenter）', function () {
    $deployer = new VolcTosDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader(['region' => 'cn-beijing'])->storeKind())->toBe('volc_certcenter');
    expect($deployer->product())->toBe('tos');
});

test('bind：putTos 把 bucket/region + CustomDomainRule{Domain,CertId} 传给资源', function () {
    $args = null;
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('putTos')->once()->andReturnUsing(function (string $bucket, string $region, array $body) use (&$args) {
        $args = compact('bucket', 'region', 'body');
    });

    $deployer = volcTosDeployerWith(fn (string $kind) => $kind === 'tos' ? $client : new stdClass);
    $deployer->bind('cert-9', volcTosCreds(), ['region' => 'cn-beijing', 'bucket' => 'my-bucket', 'domain' => 'cdn.example.com']);

    expect($args['bucket'])->toBe('my-bucket');
    expect($args['region'])->toBe('cn-beijing');
    expect($args['body'])->toBe(['CustomDomainRule' => ['Domain' => 'cdn.example.com', 'CertId' => 'cert-9']]);
});

test('region 透传：tos client 与 putTos 都用 config.region', function () {
    $tosRegion = null;
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('putTos')->andReturnUsing(function (string $bucket, string $region) use (&$tosRegion) {
        $tosRegion = $region;
    });

    $clientRegion = null;
    $deployer = volcTosDeployerWith(function (string $kind, array $creds, string $region) use ($client, &$clientRegion) {
        if ($kind === 'tos') {
            $clientRegion = $region;
        }

        return $client;
    });

    $deployer->bind('c', volcTosCreds(), ['region' => 'ap-southeast-1', 'bucket' => 'b', 'domain' => 'd.example.com']);
    expect($clientRegion)->toBe('ap-southeast-1');
    expect($tosRegion)->toBe('ap-southeast-1');
});

test('缺 region / bucket / domain 抛业务错误', function () {
    $deployer = volcTosDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', volcTosCreds(), ['bucket' => 'b', 'domain' => 'd']))
        ->toThrow(RuntimeException::class, '缺少配置 region');
    expect(fn () => $deployer->bind('c', volcTosCreds(), ['region' => 'cn-beijing', 'domain' => 'd']))
        ->toThrow(RuntimeException::class, '缺少配置 bucket');
    expect(fn () => $deployer->bind('c', volcTosCreds(), ['region' => 'cn-beijing', 'bucket' => 'b']))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 VolcApiException 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('putTos')->andThrow(new VolcApiException('TosErr', 'domain bind failed'));

    $deployer = volcTosDeployerWith(fn () => $client);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], ['region' => 'cn-beijing', 'bucket' => 'b', 'domain' => 'd.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('TosErr');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});
