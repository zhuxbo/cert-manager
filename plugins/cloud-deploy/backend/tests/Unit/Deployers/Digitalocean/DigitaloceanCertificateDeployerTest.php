<?php

use Plugins\CloudDeploy\Deployers\Digitalocean\DigitaloceanApiException;
use Plugins\CloudDeploy\Deployers\Digitalocean\DigitaloceanCertificateDeployer;
use Plugins\CloudDeploy\Deployers\Digitalocean\DigitaloceanCertUploader;
use Plugins\CloudDeploy\Deployers\Digitalocean\DigitaloceanClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock DigitaloceanClient。 */
function digitaloceanDeployerWith(callable $clientFactory): DigitaloceanCertificateDeployer
{
    return new class($clientFactory) extends DigitaloceanCertificateDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

test('DigitalOcean 证书（仅上传）走证书服务 + 元信息', function () {
    $deployer = new DigitaloceanCertificateDeployer;
    expect($deployer->provider())->toBe('digitalocean');
    expect($deployer->product())->toBe('certificate');
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->toBeInstanceOf(DigitaloceanCertUploader::class);
    expect($deployer->certUploader()->storeKind())->toBe('digitalocean_certificate');
    expect($deployer->configSchema())->toBe([]);
});

test('uploader.upload 调 createCertificate（type=custom、leaf/chain/key 拆分）返回 id', function () {
    $captured = null;
    $client = Mockery::mock(DigitaloceanClient::class);
    $client->shouldReceive('createCertificate')->once()->andReturnUsing(function (array $body) use (&$captured) {
        $captured = $body;

        return 'do-cert-1';
    });

    $deployer = digitaloceanDeployerWith(fn () => $client);
    $id = $deployer->certUploader()->upload('LEAFPEM', 'KEYPEM', 'CHAINPEM', ['access_token' => 'TOKEN']);

    expect($id)->toBe('do-cert-1');
    expect($captured['type'])->toBe('custom');
    expect($captured['leaf_certificate'])->toBe('LEAFPEM');
    expect($captured['certificate_chain'])->toBe('CHAINPEM');
    expect($captured['private_key'])->toBe('KEYPEM');
    expect($captured['name'])->toStartWith('clouddeploy-');
});

test('bind 为 no-op：上传已由 RemoteCertStore 完成，不抛异常', function () {
    $deployer = digitaloceanDeployerWith(fn () => new stdClass);
    $deployer->bind('do-cert-1', ['access_token' => 'TOKEN'], []);
    expect($deployer->touchedConfigKeys())->toBe([]);
});

test('upload 未返回 id 抛明确异常', function () {
    $client = Mockery::mock(DigitaloceanClient::class);
    $client->shouldReceive('createCertificate')->andReturn('');

    $deployer = digitaloceanDeployerWith(fn () => $client);
    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', ['access_token' => 'TOKEN']))
        ->toThrow(RuntimeException::class, '未返回证书 id');
});

test('upload 遇 DigitaloceanApiException 脱敏重抛（无 token、不挂 previous）', function () {
    $client = Mockery::mock(DigitaloceanClient::class);
    $client->shouldReceive('createCertificate')->andThrow(new DigitaloceanApiException('unprocessable_entity', 'certificate is invalid'));

    $deployer = digitaloceanDeployerWith(fn () => $client);
    try {
        $deployer->certUploader()->upload('C', 'K', 'CH', ['access_token' => 'TOKEN-LEAK-9']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('unprocessable_entity')->toContain('certificate is invalid');
        expect($e->getMessage())->not->toContain('TOKEN-LEAK-9');
        expect($e->getPrevious())->toBeNull();
    }
});
