<?php

use Plugins\CloudDeploy\Deployers\Vercel\CertificateDeployer;
use Plugins\CloudDeploy\Deployers\Vercel\VercelApiException;
use Plugins\CloudDeploy\Deployers\Vercel\VercelClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock VercelClient。 */
function vercelCertificateDeployerWith(callable $clientFactory): CertificateDeployer
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

function vercelCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

test('Vercel 证书为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new CertificateDeployer;
    expect($deployer->provider())->toBe('vercel');
    expect($deployer->product())->toBe('certificate');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect($deployer->configSchema())->toBe([]);
});

test('bind 调 uploadCert（ca=中间证书、cert=服务器证书、key=私钥、skipValidation=true，无 teamId）', function () {
    $captured = null;
    $client = Mockery::mock(VercelClient::class);
    $client->shouldReceive('uploadCert')->once()->andReturnUsing(function (array $body, string $teamId = '') use (&$captured) {
        $captured = [$body, $teamId];
    });

    $deployer = vercelCertificateDeployerWith(fn () => $client);
    $deployer->bind(vercelCertRef(), ['api_access_token' => 'TOKEN'], []);

    [$body, $teamId] = $captured;
    expect($body['ca'])->toBe('CHAINPEM');
    expect($body['cert'])->toBe('CERTPEM');
    expect($body['key'])->toBe('KEYPEM');
    expect($body['skipValidation'])->toBeTrue();
    expect($teamId)->toBe('');
    expect($deployer->touchedConfigKeys())->toBe([]);
});

test('凭证含 team_id 时透传为 teamId 查询参', function () {
    $captured = null;
    $client = Mockery::mock(VercelClient::class);
    $client->shouldReceive('uploadCert')->once()->andReturnUsing(function (array $body, string $teamId = '') use (&$captured) {
        $captured = $teamId;
    });

    $deployer = vercelCertificateDeployerWith(fn () => $client);
    $deployer->bind(vercelCertRef(), ['api_access_token' => 'TOKEN', 'team_id' => 'team_abc'], []);

    expect($captured)->toBe('team_abc');
});

test('bind 遇 VercelApiException 时脱敏重抛（含错误码、无 token、不挂 previous）', function () {
    $client = Mockery::mock(VercelClient::class);
    $client->shouldReceive('uploadCert')->andThrow(new VercelApiException('forbidden', 'Not authorized to access this resource'));

    $deployer = vercelCertificateDeployerWith(fn () => $client);
    try {
        $deployer->bind(vercelCertRef(), ['api_access_token' => 'TOKEN-LEAK-123', 'team_id' => 'team-LEAK'], []);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('forbidden')->toContain('Not authorized');
        expect($e->getMessage())->not->toContain('TOKEN-LEAK-123');
        expect($e->getMessage())->not->toContain('team-LEAK');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('TOKEN-LEAK-123');
    }
});
