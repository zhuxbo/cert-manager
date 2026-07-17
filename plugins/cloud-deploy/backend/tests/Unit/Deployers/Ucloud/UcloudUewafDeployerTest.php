<?php

use Plugins\CloudDeploy\Deployers\Ucloud\UcloudApiException;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudRestClient;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudUewafDeployer;
use Tests\TestCase;

uses(TestCase::class);

function ucloudUewafDeployerWith(callable $clientFactory): UcloudUewafDeployer
{
    return new class($clientFactory) extends UcloudUewafDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

const UEWAF_CREDS = ['public_key' => 'PUB', 'private_key' => 'PRIV'];

test('UEWAF 为内联型（usesRemoteCertStore=false，无 uploader）', function () {
    $deployer = new UcloudUewafDeployer;
    expect($deployer->provider())->toBe('ucloud');
    expect($deployer->product())->toBe('uewaf');
    expect($deployer->label())->toBe('优刻得 Web 应用防火墙');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
});

test('bind 内联：AddWafDomainCertificateInfo 直灌 base64 证书/私钥 + md5（不走 USSL）', function () {
    $captured = null;
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('addWafDomainCertificateInfo')
        ->once()
        ->andReturnUsing(function (string $domain, string $name, string $pub, string $priv, string $md5) use (&$captured) {
            $captured = compact('domain', 'name', 'pub', 'priv', 'md5');
        });

    $deployer = ucloudUewafDeployerWith(fn (string $kind) => $kind === 'api' ? $client : new stdClass);
    $deployer->bind(
        ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'],
        UEWAF_CREDS,
        ['domain' => 'waf.example.com'],
    );

    expect($captured['domain'])->toBe('waf.example.com');
    expect($captured['name'])->toStartWith('clouddeploy_');
    // base64 证书含 cert + chain；私钥 base64
    expect(base64_decode($captured['pub']))->toContain('CERTPEM')->toContain('CHAINPEM');
    expect(base64_decode($captured['priv']))->toContain('KEYPEM');
    // md5 = md5(base64cert + base64key)
    expect($captured['md5'])->toBe(md5($captured['pub'].$captured['priv']));
});

test('bind 收到字符串 certRef（非内联三元组）抛业务错误', function () {
    $deployer = ucloudUewafDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('cert-id-string', UEWAF_CREDS, ['domain' => 'd.example.com']))
        ->toThrow(RuntimeException::class, '内联型');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = ucloudUewafDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => 'CH'], UEWAF_CREDS, []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 UcloudApiException 脱敏重抛（含错误码、无凭证、不挂 previous）', function () {
    $client = Mockery::mock(UcloudRestClient::class);
    $client->shouldReceive('addWafDomainCertificateInfo')->andThrow(new UcloudApiException('230', 'domain not found'));

    $deployer = ucloudUewafDeployerWith(fn () => $client);

    try {
        $deployer->bind(
            ['cert' => 'C', 'key' => 'KEYPEM', 'chain' => 'CH'],
            ['public_key' => 'PUB-LEAK', 'private_key' => 'PRIV-LEAK-SECRET'],
            ['domain' => 'x.example.com'],
        );
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('230')->toContain('domain not found');
        expect($e->getMessage())->not->toContain('PRIV-LEAK-SECRET');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('PRIV-LEAK-SECRET');
    }
});
