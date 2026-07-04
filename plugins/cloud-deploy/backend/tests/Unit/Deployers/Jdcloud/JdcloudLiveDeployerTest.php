<?php

use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudApiException;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudLiveDeployer;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

function jdcloudLiveDeployerWith(callable $clientFactory): JdcloudLiveDeployer
{
    return new class($clientFactory) extends JdcloudLiveDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

if (! function_exists('jdCreds')) {
    function jdCreds(): array
    {
        return ['access_key_id' => 'AK', 'access_key_secret' => 'SK'];
    }
}

test('京东云直播：内联型（usesRemoteCertStore=false，无 uploader）', function () {
    $deployer = new JdcloudLiveDeployer;
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect($deployer->provider())->toBe('jdcloud');
    expect($deployer->product())->toBe('live');
});

test('bind：SetLiveDomainCertificate 直灌完整链 PEM + 私钥', function () {
    $captured = null;
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('setLiveDomainCertificate')
        ->once()
        ->andReturnUsing(function (string $domain, string $cert, string $key) use (&$captured) {
            $captured = compact('domain', 'cert', 'key');
        });

    $deployer = jdcloudLiveDeployerWith(fn (string $kind) => $kind === 'live' ? $client : new stdClass);
    $deployer->bind(
        ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'],
        jdCreds(),
        ['domain' => 'live.example.com'],
    );

    expect($captured['domain'])->toBe('live.example.com');
    // cert = leaf + chain 完整链
    expect($captured['cert'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($captured['key'])->toBe('KEYPEM');
});

test('bind 收到非数组 certRef（内联型必须 PEM 三元组）抛业务错误', function () {
    $deployer = jdcloudLiveDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('jdcert-001', jdCreds(), ['domain' => 'd.example.com']))
        ->toThrow(RuntimeException::class, '内联型');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = jdcloudLiveDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => ''], jdCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 JdcloudApiException 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('setLiveDomainCertificate')->andThrow(new JdcloudApiException('ERR', 'live error'));

    $deployer = jdcloudLiveDeployerWith(fn () => $client);

    try {
        $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => ''], ['access_key_id' => 'AK', 'access_key_secret' => 'SK-LEAK'], ['domain' => 'd']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('ERR')->toContain('live error');
        expect($e->getMessage())->not->toContain('SK-LEAK');
        expect($e->getPrevious())->toBeNull();
    }
});
