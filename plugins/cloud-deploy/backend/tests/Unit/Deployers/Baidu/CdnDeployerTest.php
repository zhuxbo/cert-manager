<?php

use BaiduBce\Exception\BceServiceException;
use Plugins\CloudDeploy\Deployers\Baidu\BaiduCdnDeployer;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient 注入缝，按 $kind 返回 mock（cdn）。 */
function baiduCdnDeployerWith(callable $clientFactory): BaiduCdnDeployer
{
    return new class($clientFactory) extends BaiduCdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function baiduCdnCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('百度 CDN：内联型（usesRemoteCertStore=false、certUploader=null）', function () {
    $deployer = new BaiduCdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect($deployer->provider())->toBe('baidu');
    expect($deployer->product())->toBe('cdn');
});

test('bind 调 PUT /v2/{domain}/certificates 直灌 PEM（certificate 子对象 + httpsEnable=ON）', function () {
    $captured = null;
    $cdn = Mockery::mock();
    $cdn->shouldReceive('request')
        ->once()
        ->andReturnUsing(function (string $method, string $path, ?array $body, array $params) use (&$captured) {
            $captured = compact('method', 'path', 'body', 'params');

            return new stdClass;
        });

    $deployer = baiduCdnDeployerWith(fn (string $kind) => $kind === 'cdn' ? $cdn : new stdClass);
    $deployer->bind(
        ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'],
        baiduCdnCreds(),
        ['domain' => 'cdn.example.com'],
    );

    expect($captured['method'])->toBe('PUT');
    expect($captured['path'])->toBe('/v2/cdn.example.com/certificates');
    expect($captured['body']['httpsEnable'])->toBe('ON');
    expect($captured['body']['certificate']['certServerData'])->toBe('CERTPEM');
    expect($captured['body']['certificate']['certPrivateData'])->toBe('KEYPEM');
    expect($captured['body']['certificate']['certLinkData'])->toBe('CHAINPEM');
    expect($captured['body']['certificate']['certName'])->toStartWith('clouddeploy_');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = baiduCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => 'CH'], baiduCdnCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 BceServiceException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $cdn = Mockery::mock();
    $cdn->shouldReceive('request')->andThrow(new BceServiceException('req-1', 'DomainNotFound', 'the domain does not exist', 404));

    $deployer = baiduCdnDeployerWith(fn () => $cdn);

    try {
        $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => 'CH'], ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('DomainNotFound');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});
