<?php

use Plugins\CloudDeploy\Deployers\Contracts\DeployBusinessException;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudApiException;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudRestClient;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudVodDeployer;
use Tests\TestCase;

uses(TestCase::class);

function jdcloudVodDeployerWith(callable $clientFactory, ?callable $matcher = null): JdcloudVodDeployer
{
    return new class($clientFactory, $matcher) extends JdcloudVodDeployer
    {
        public function __construct(private $factory, private $matcher) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }

        protected function certificateMatches(string $certPem, string $domain): bool
        {
            return $this->matcher === null ? parent::certificateMatches($certPem, $domain) : ($this->matcher)($domain);
        }
    };
}

if (! function_exists('jdCreds')) {
    function jdCreds(): array
    {
        return ['access_key_id' => 'AK', 'access_key_secret' => 'SK'];
    }
}

test('京东云点播：内联型（usesRemoteCertStore=false，无 uploader）', function () {
    $deployer = new JdcloudVodDeployer;
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect($deployer->provider())->toBe('jdcloud');
    expect($deployer->product())->toBe('vod');
});

test('bind：findDomainId → GetHttpSsl 取 jumpType → SetHttpSsl 直灌 PEM（source=default/enabled=true）', function () {
    $captured = null;
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('findVodDomainId')->once()->with('vod.example.com')->andReturn(456);
    $client->shouldReceive('getVodHttpSslJumpType')->once()->with(456)->andReturn('redirect');
    $client->shouldReceive('setVodHttpSsl')
        ->once()
        ->andReturnUsing(function (int $domainId, string $title, string $cert, string $key, string $jumpType) use (&$captured) {
            $captured = compact('domainId', 'title', 'cert', 'key', 'jumpType');
        });

    $deployer = jdcloudVodDeployerWith(fn (string $kind) => $kind === 'vod' ? $client : new stdClass);
    $deployer->bind(
        ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'],
        jdCreds(),
        ['domain' => 'vod.example.com'],
    );

    expect($captured['domainId'])->toBe(456);
    expect($captured['title'])->toStartWith('clouddeploy-');
    expect($captured['cert'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($captured['key'])->toBe('KEYPEM');
    expect($captured['jumpType'])->toBe('redirect');
});

test('certsan 列举在线 VOD 域名并批量更新匹配域名', function () {
    $writes = [];
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('listVodDomains')->once()->andReturn([
        ['id' => 1, 'name' => 'a.example.com'], ['id' => 2, 'name' => 'b.example.com'],
    ]);
    $client->shouldReceive('getVodHttpSslJumpType')->once()->with(1)->andReturn('redirect');
    $client->shouldReceive('setVodHttpSsl')->once()->andReturnUsing(function (int $id) use (&$writes) {
        $writes[] = $id;
    });

    jdcloudVodDeployerWith(fn () => $client, fn (string $domain): bool => $domain === 'a.example.com')->bind(
        ['cert' => 'CERTPEM', 'key' => 'KEY', 'chain' => 'CHAIN'], jdCreds(), ['domain_match_pattern' => 'certsan'],
    );

    expect($writes)->toBe([1]);
});

test('域名未找到 → 业务错误（DeployBusinessException，不是被脱敏的 SDK 异常）', function () {
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('findVodDomainId')->once()->andReturn(null);
    $client->shouldReceive('setVodHttpSsl')->never();

    $deployer = jdcloudVodDeployerWith(fn () => $client);

    expect(fn () => $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => ''], jdCreds(), ['domain' => 'nope.example.com']))
        ->toThrow(DeployBusinessException::class, '未找到点播域名');
});

test('bind 收到非数组 certRef（内联型必须 PEM 三元组）抛业务错误', function () {
    $deployer = jdcloudVodDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('jdcert-001', jdCreds(), ['domain' => 'd.example.com']))
        ->toThrow(RuntimeException::class, '内联型');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = jdcloudVodDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => ''], jdCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('SDK 查询抛异常时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(JdcloudRestClient::class);
    $client->shouldReceive('findVodDomainId')->andThrow(new JdcloudApiException('ERR', 'list error'));

    $deployer = jdcloudVodDeployerWith(fn () => $client);

    try {
        $deployer->bind(['cert' => 'C', 'key' => 'K', 'chain' => ''], ['access_key_id' => 'AK', 'access_key_secret' => 'SK-LEAK'], ['domain' => 'd']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('ERR')->toContain('list error');
        expect($e->getMessage())->not->toContain('SK-LEAK');
        expect($e->getPrevious())->toBeNull();
    }
});
