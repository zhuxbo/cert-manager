<?php

use Plugins\CloudDeploy\Deployers\Linode\LinodeApiException;
use Plugins\CloudDeploy\Deployers\Linode\LinodeClient;
use Plugins\CloudDeploy\Deployers\Linode\LosDeployer;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock LinodeClient。 */
function linodeLosDeployerWith(callable $clientFactory): LosDeployer
{
    return new class($clientFactory) extends LosDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function linodeCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

test('Linode 对象存储为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new LosDeployer;
    expect($deployer->provider())->toBe('linode');
    expect($deployer->product())->toBe('los');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('region_id')->toContain('bucket');
});

test('bind：无既有证书时跳过 DELETE，直接上传（certificate=完整链、private_key=私钥）', function () {
    $captured = null;
    $client = Mockery::mock(LinodeClient::class);
    $client->shouldReceive('getObjectStorageSslEnabled')->once()->with('us-east-1', 'mybucket')->andReturn(false);
    $client->shouldNotReceive('deleteObjectStorageSsl');
    $client->shouldReceive('uploadObjectStorageSsl')->once()->andReturnUsing(function (string $region, string $bucket, array $body) use (&$captured) {
        $captured = [$region, $bucket, $body];
    });

    $deployer = linodeLosDeployerWith(fn () => $client);
    $deployer->bind(linodeCertRef(), ['access_token' => 'TOKEN'], ['region_id' => 'us-east-1', 'bucket' => 'mybucket']);

    [$region, $bucket, $body] = $captured;
    expect($region)->toBe('us-east-1');
    expect($bucket)->toBe('mybucket');
    expect($body['certificate'])->toBe("CERTPEM\nCHAINPEM");
    expect($body['private_key'])->toBe('KEYPEM');
});

test('bind：已有证书时先 DELETE 再上传', function () {
    $order = [];
    $client = Mockery::mock(LinodeClient::class);
    $client->shouldReceive('getObjectStorageSslEnabled')->once()->andReturn(true);
    $client->shouldReceive('deleteObjectStorageSsl')->once()->with('us-east-1', 'mybucket')->andReturnUsing(function () use (&$order) {
        $order[] = 'delete';
    });
    $client->shouldReceive('uploadObjectStorageSsl')->once()->andReturnUsing(function () use (&$order) {
        $order[] = 'upload';
    });

    $deployer = linodeLosDeployerWith(fn () => $client);
    $deployer->bind(linodeCertRef(), ['access_token' => 'TOKEN'], ['region_id' => 'us-east-1', 'bucket' => 'mybucket']);

    expect($order)->toBe(['delete', 'upload']);
});

test('缺 region_id 抛业务错误', function () {
    $deployer = linodeLosDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(linodeCertRef(), ['access_token' => 'TOKEN'], ['bucket' => 'mybucket']))
        ->toThrow(RuntimeException::class, '缺少配置 region_id');
});

test('缺 bucket 抛业务错误', function () {
    $deployer = linodeLosDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(linodeCertRef(), ['access_token' => 'TOKEN'], ['region_id' => 'us-east-1']))
        ->toThrow(RuntimeException::class, '缺少配置 bucket');
});

test('bind 遇 LinodeApiException 时脱敏重抛（含状态码、无 token、不挂 previous）', function () {
    $client = Mockery::mock(LinodeClient::class);
    $client->shouldReceive('getObjectStorageSslEnabled')->andThrow(new LinodeApiException('400', '[certificate] certificate is invalid'));

    $deployer = linodeLosDeployerWith(fn () => $client);
    try {
        $deployer->bind(linodeCertRef(), ['access_token' => 'TOKEN-LEAK-123'], ['region_id' => 'us-east-1', 'bucket' => 'mybucket']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('400')->toContain('certificate is invalid');
        expect($e->getMessage())->not->toContain('TOKEN-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('TOKEN-LEAK-123');
    }
});
