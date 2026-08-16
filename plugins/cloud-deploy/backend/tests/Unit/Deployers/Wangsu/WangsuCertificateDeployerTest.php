<?php

use Plugins\CloudDeploy\Deployers\Wangsu\WangsuApiException;
use Plugins\CloudDeploy\Deployers\Wangsu\WangsuCertificateDeployer;
use Plugins\CloudDeploy\Deployers\Wangsu\WangsuRestClient;
use Tests\TestCase;

uses(TestCase::class);

function wangsuCertificateDeployerWith(callable $clientFactory): WangsuCertificateDeployer
{
    return new class($clientFactory) extends WangsuCertificateDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

test('网宿云证书中心（仅上传）走证书服务 + 元信息', function () {
    $deployer = new WangsuCertificateDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('wangsu_certificate');
    expect($deployer->provider())->toBe('wangsu');
    expect($deployer->product())->toBe('certificate');
    expect(array_column($deployer->configSchema(), 'key'))->toContain('certificate_id');
});

test('配置 certificate_id 时原位更新既有证书', function () {
    $args = null;
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('updateCertificate')->once()->andReturnUsing(function (string $id, string $name, string $cert, string $key, string $comment) use (&$args) {
        $args = compact('id', 'name', 'cert', 'key', 'comment');
    });
    $client->shouldNotReceive('createCertificate');

    $deployer = wangsuCertificateDeployerWith(fn () => $client);
    $uploader = $deployer->certUploader(['certificate_id' => '200002']);
    $id = $uploader->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']);

    expect($id)->toBe('200002');
    expect($uploader->storeKind())->toBe('wangsu_certificate:200002');
    expect($args['id'])->toBe('200002');
    expect($args['cert'])->toBe("CERTPEM\nCHAINPEM");
});

test('uploader.upload 调 createCertificate（完整链 + 私钥）返回 certId', function () {
    $args = null;
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('createCertificate')
        ->once()
        ->andReturnUsing(function (string $name, string $cert, string $key, string $comment) use (&$args) {
            $args = compact('name', 'cert', 'key', 'comment');

            return '200002';
        });

    $deployer = wangsuCertificateDeployerWith(fn (string $kind) => $kind === 'api' ? $client : new stdClass);
    $certId = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']);

    expect($certId)->toBe('200002');
    expect($args['cert'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($args['key'])->toBe('KEYPEM');
});

test('upload 空 chain 时仅上传证书本体（不拼多余换行）', function () {
    $args = null;
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('createCertificate')->andReturnUsing(function (string $name, string $cert) use (&$args) {
        $args = $cert;

        return '1';
    });

    $deployer = wangsuCertificateDeployerWith(fn () => $client);
    $deployer->certUploader()->upload("CERTPEM\n", 'KEYPEM', '', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']);

    expect($args)->toBe('CERTPEM'); // rtrim 后无尾随换行、无中间证书拼接
});

test('bind 为 no-op：上传已由 RemoteCertStore 完成，不抛异常', function () {
    $deployer = wangsuCertificateDeployerWith(fn () => new stdClass);
    $deployer->bind('200002', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], []);
    expect(true)->toBeTrue();
});

test('upload 未返回 id 抛明确异常', function () {
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('createCertificate')->andReturn('');

    $deployer = wangsuCertificateDeployerWith(fn () => $client);

    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']))
        ->toThrow(RuntimeException::class, 'certId');
});

test('upload 遇 WangsuApiException 脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(WangsuRestClient::class);
    $client->shouldReceive('createCertificate')->andThrow(new WangsuApiException('500', 'internal error'));

    $deployer = wangsuCertificateDeployerWith(fn () => $client);

    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['access_key_id' => 'AK-SECRET-XYZ', 'access_key_secret' => 'SK-SECRET-ABC']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('500')->toContain('internal error');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});
