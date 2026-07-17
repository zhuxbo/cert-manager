<?php

use Plugins\CloudDeploy\Deployers\Cachefly\CacheflyApiException;
use Plugins\CloudDeploy\Deployers\Cachefly\CacheflyClient;
use Plugins\CloudDeploy\Deployers\Cachefly\CertificateDeployer;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock CacheflyClient。 */
function cacheflyCertificateDeployerWith(callable $clientFactory): CertificateDeployer
{
    return new class($clientFactory) extends CertificateDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function cacheflyCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

test('CacheFly 证书为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new CertificateDeployer;
    expect($deployer->provider())->toBe('cachefly');
    expect($deployer->product())->toBe('certificate');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect($deployer->configSchema())->toBe([]);
});

test('bind 调 createCertificate（certificate=cert+chain、certificateKey=私钥）', function () {
    $captured = null;
    $client = Mockery::mock(CacheflyClient::class);
    $client->shouldReceive('createCertificate')->once()->andReturnUsing(function (array $body) use (&$captured) {
        $captured = $body;
    });

    $deployer = cacheflyCertificateDeployerWith(fn () => $client);
    $deployer->bind(cacheflyCertRef(), ['api_token' => 'TOKEN'], []);

    expect($captured['certificate'])->toBe("CERTPEM\nCHAINPEM");
    expect($captured['certificateKey'])->toBe('KEYPEM');
    expect($deployer->touchedConfigKeys())->toBe([]);
});

test('bind 遇 CacheflyApiException 时脱敏重抛（含错误码、无 token、不挂 previous）', function () {
    $client = Mockery::mock(CacheflyClient::class);
    $client->shouldReceive('createCertificate')->andThrow(new CacheflyApiException('401', 'Unauthorized'));

    $deployer = cacheflyCertificateDeployerWith(fn () => $client);
    try {
        $deployer->bind(cacheflyCertRef(), ['api_token' => 'TOKEN-LEAK-123'], []);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('401')->toContain('Unauthorized');
        expect($e->getMessage())->not->toContain('TOKEN-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('TOKEN-LEAK-123');
    }
});
