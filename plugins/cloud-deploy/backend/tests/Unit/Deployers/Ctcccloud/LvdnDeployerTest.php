<?php

use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudLvdnDeployer;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient 注入缝（lvdn）。 */
function ctyunLvdnDeployerWith(callable $clientFactory): CtcccloudLvdnDeployer
{
    return new class($clientFactory) extends CtcccloudLvdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function ctyunLvdnCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('天翼云 LVDN：证书服务型（product key=lvdn + storeKind ctcccloud_lvdn + schema）', function () {
    $deployer = new CtcccloudLvdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('ctcccloud_lvdn');
    expect($deployer->provider())->toBe('ctcccloud');
    // product key 用 lvdn（非 provider.go 笔误的 ldvn）
    expect($deployer->product())->toBe('lvdn');
    expect($deployer->label())->toBe('天翼云视频直播加速');
    expect(array_column($deployer->configSchema(), 'key'))->toBe(['domain_match_pattern', 'domain']);
});

test('uploader.upload 走 LVDN create-cert /cert/creat-cert（无 /v1 前缀）返回 CertName', function () {
    $captured = null;
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('post')->once()->andReturnUsing(function (string $path, array $body) use (&$captured) {
        $captured = compact('path', 'body');

        return ['statusCode' => '100000', 'returnObj' => ['id' => 7]];
    });

    $deployer = ctyunLvdnDeployerWith(fn () => $client);
    $certName = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ctyunLvdnCreds());

    expect($certName)->toStartWith('clouddeploy-');
    // 关键差异：lvdn 创建证书路径无 /v1 前缀
    expect($captured['path'])->toBe('/cert/creat-cert');
});

test('bind：/live/* 路径 + product_code=005 + https_switch=1（int）+ cert_name', function () {
    $getCall = null;
    $postCall = null;
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('get')->once()->andReturnUsing(function (string $path, array $query) use (&$getCall) {
        $getCall = compact('path', 'query');

        return ['statusCode' => '100000', 'returnObj' => []];
    });
    $client->shouldReceive('post')->once()->andReturnUsing(function (string $path, array $body) use (&$postCall) {
        $postCall = compact('path', 'body');

        return ['statusCode' => '100000'];
    });

    $deployer = ctyunLvdnDeployerWith(fn () => $client);
    $deployer->bind('lvdn-cert-1', ctyunLvdnCreds(), ['domain' => 'live.example.com']);

    expect($getCall['path'])->toBe('/live/domain/query-domain-detail');
    expect($getCall['query'])->toBe(['domain' => 'live.example.com', 'product_code' => '005']);
    expect($postCall['path'])->toBe('/live/domain/update-domain');
    expect($postCall['body'])->toBe([
        'domain' => 'live.example.com',
        'product_code' => '005',
        'https_switch' => 1,
        'cert_name' => 'lvdn-cert-1',
    ]);
    // https_switch 是 int 1（LVDN 用 https_switch 而非 https_status）
    expect($postCall['body']['https_switch'])->toBeInt();
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = ctyunLvdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ctyunLvdnCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});
