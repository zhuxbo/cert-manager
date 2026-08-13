<?php

use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudAoDeployer;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudCdnDeployer;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudIcdnDeployer;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudLvdnDeployer;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient 注入缝（ao）。 */
function ctyunAoDeployerWith(callable $clientFactory): CtcccloudAoDeployer
{
    return new class($clientFactory) extends CtcccloudAoDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function ctyunAoCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('天翼云 AO：证书服务型（storeKind ctcccloud_ao + 元信息 + schema）', function () {
    $deployer = new CtcccloudAoDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('ctcccloud_ao');
    expect($deployer->provider())->toBe('ctcccloud');
    expect($deployer->product())->toBe('ao');
    expect($deployer->label())->toBe('天翼云边缘安全加速 AccessOne');
    expect(array_column($deployer->configSchema(), 'key'))->toBe(['domain_match_pattern', 'domain']);
});

test('AO/CDN/ICDN/LVDN 均暴露官方 domain_match_pattern', function () {
    foreach ([new CtcccloudAoDeployer, new CtcccloudCdnDeployer, new CtcccloudIcdnDeployer, new CtcccloudLvdnDeployer] as $deployer) {
        expect(array_column($deployer->configSchema(), 'key'))->toContain('domain_match_pattern');
    }
});

test('AO wildcard 分页列举并更新单层匹配域名', function () {
    $updated = [];
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('get')->once()->with('/ctapi/v2/domain/query', Mockery::type('array'))->andReturn([
        'returnObj' => ['result' => [
            ['domain' => 'a.example.com', 'status' => 0],
            ['domain' => 'deep.a.example.com', 'status' => 0],
        ]],
    ]);
    $client->shouldReceive('post')->andReturnUsing(function ($path, $body) use (&$updated) {
        if ($path === '/ctapi/v1/accessone/domain/config') {
            return ['returnObj' => ['product_code' => '020', 'origin' => []]];
        }
        $updated[] = $body['domain'];

        return [];
    });
    ctyunAoDeployerWith(fn () => $client)->bind('cert-1', ctyunAoCreds(), [
        'domain_match_pattern' => 'wildcard', 'domain' => '*.example.com',
    ]);
    expect($updated)->toBe(['a.example.com']);
});

test('uploader.upload 走 AO create 路径 /ctapi/v1/accessone/cert/create 返回 CertName', function () {
    $captured = null;
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('post')->once()->andReturnUsing(function (string $path, array $body) use (&$captured) {
        $captured = compact('path', 'body');

        return ['statusCode' => '100000', 'returnObj' => ['id' => 55]];
    });

    $deployer = ctyunAoDeployerWith(fn () => $client);
    $certName = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ctyunAoCreds());

    expect($certName)->toStartWith('clouddeploy-');
    expect($captured['path'])->toBe('/ctapi/v1/accessone/cert/create');
});

test('bind：domain/config 查询 + scdn/modify_config 绑证书，回写 origin（weight 0→1 转字符串）+ 用查询的 product_code', function () {
    $calls = [];
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('post')
        ->twice()
        ->andReturnUsing(function (string $path, array $body) use (&$calls) {
            $calls[] = compact('path', 'body');
            if ($path === '/ctapi/v1/accessone/domain/config') {
                return [
                    'statusCode' => '100000',
                    'returnObj' => [
                        'domain' => 'ao.example.com',
                        'product_code' => '021',  // 与查询入参 020 不同，确认用回返回值
                        'origin' => [
                            ['origin' => '1.1.1.1', 'role' => 'master', 'weight' => 0],   // 0→1
                            ['origin' => '2.2.2.2', 'role' => 'slave', 'weight' => 3],     // 保留
                        ],
                    ],
                ];
            }

            return ['statusCode' => '100000'];
        });

    $deployer = ctyunAoDeployerWith(fn () => $client);
    $deployer->bind('ao-cert-1', ctyunAoCreds(), ['domain' => 'ao.example.com']);

    // 查询用 product_code=020
    expect($calls[0]['path'])->toBe('/ctapi/v1/accessone/domain/config');
    expect($calls[0]['body'])->toBe(['domain' => 'ao.example.com', 'product_code' => '020']);

    // 修改：scdn 路径 + 回写 origin（weight 转字符串、0→1）+ 用查询返回的 product_code 021
    $modify = $calls[1];
    expect($modify['path'])->toBe('/ctapi/v1/scdn/domain/modify_config');
    expect($modify['body']['domain'])->toBe('ao.example.com');
    expect($modify['body']['product_code'])->toBe('021');
    expect($modify['body']['https_status'])->toBe('on');
    expect($modify['body']['cert_name'])->toBe('ao-cert-1');
    expect($modify['body']['origin'])->toBe([
        ['origin' => '1.1.1.1', 'role' => 'master', 'weight' => '1'],
        ['origin' => '2.2.2.2', 'role' => 'slave', 'weight' => '3'],
    ]);
});

test('bind：查询无 product_code 时回落到默认 020；无 origin 时回写空数组', function () {
    $calls = [];
    $client = Mockery::mock(CtcccloudRestClient::class);
    $client->shouldReceive('post')->twice()->andReturnUsing(function (string $path, array $body) use (&$calls) {
        $calls[] = compact('path', 'body');
        if ($path === '/ctapi/v1/accessone/domain/config') {
            return ['statusCode' => '100000', 'returnObj' => ['domain' => 'ao.example.com']];
        }

        return ['statusCode' => '100000'];
    });

    $deployer = ctyunAoDeployerWith(fn () => $client);
    $deployer->bind('ao-cert-1', ctyunAoCreds(), ['domain' => 'ao.example.com']);

    expect($calls[1]['body']['product_code'])->toBe('020');
    expect($calls[1]['body']['origin'])->toBe([]);
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = ctyunAoDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-1', ctyunAoCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});
