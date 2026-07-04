<?php

use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudCmsDeployer;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient 注入缝（cms）。 */
function ctyunCmsDeployerWith(callable $clientFactory): CtcccloudCmsDeployer
{
    return new class($clientFactory) extends CtcccloudCmsDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function ctyunCmsCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('天翼云 CMS：纯上传型（usesRemoteCertStore + storeKind ctcccloud_cms + 空 schema）', function () {
    $deployer = new CtcccloudCmsDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('ctcccloud_cms');
    expect($deployer->provider())->toBe('ctcccloud');
    expect($deployer->product())->toBe('cms');
    expect($deployer->label())->toBe('天翼云证书管理 CMS（仅上传）');
    // 纯上传无配置
    expect($deployer->configSchema())->toBe([]);
});

test('uploader.upload 上传证书（服务器证书 + 中间证书分列 + INTERNATIONAL）返回本地 certName', function () {
    $captured = null;
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('post')->once()->andReturnUsing(function (string $path, array $body) use (&$captured) {
        $captured = compact('path', 'body');

        // CMS 上传响应不带 id
        return ['statusCode' => '200'];
    });

    $deployer = ctyunCmsDeployerWith(fn () => $client);
    $certName = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ctyunCmsCreds());

    expect($certName)->toStartWith('cm');
    expect($captured['path'])->toBe('/v1/certificate/upload');
    // CMS 分列：certificate=服务器证书、certificateChain=中间证书（不拼完整链）
    expect($captured['body']['certificate'])->toBe('CERTPEM');
    expect($captured['body']['certificateChain'])->toBe('CHAINPEM');
    expect($captured['body']['privateKey'])->toBe('KEYPEM');
    expect($captured['body']['encryptionStandard'])->toBe('INTERNATIONAL');
    expect($captured['body']['name'])->toBe($certName);
});

test('bind 为 no-op（纯上传端点，不调任何接口）', function () {
    // makeClient 返回会对任意调用抛错的对象；bind 不应触碰它
    $deployer = ctyunCmsDeployerWith(fn () => new class
    {
        public function __call($n, $a)
        {
            throw new RuntimeException('bind 不应调用 SDK');
        }
    });

    $deployer->bind('cm123', ctyunCmsCreds(), []);
    expect(true)->toBeTrue();
});

test('uploader.upload SDK 抛异常脱敏重抛（无凭证、不挂 previous）', function () {
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('post')->andThrow(new RuntimeException('cURL https://ccms-global.ctapi.ctyun.cn/x AK-LEAK'));

    $deployer = ctyunCmsDeployerWith(fn () => $client);
    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK-LEAK', 'secret_access_key' => 'SK']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('天翼云调用失败');
        expect($e->getMessage())->not->toContain('AK-LEAK')->not->toContain('ccms-global');
        expect($e->getPrevious())->toBeNull();
    }
});
