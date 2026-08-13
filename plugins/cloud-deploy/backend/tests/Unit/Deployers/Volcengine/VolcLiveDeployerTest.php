<?php

use Plugins\CloudDeploy\Deployers\Volcengine\VolcApiException;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcLiveDeployer;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcRestClient;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（'live' kind）。uploader 经 certUploader 复用 makeClient('live')。 */
function volcLiveDeployerWith(callable $clientFactory): VolcLiveDeployer
{
    return new class($clientFactory) extends VolcLiveDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function volcLiveCreds(): array
{
    return ['access_key_id' => 'AK', 'secret_access_key' => 'SK'];
}

test('火山 Live：证书服务型，自有证书空间（storeKind volc_live）', function () {
    $deployer = new VolcLiveDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader()->storeKind())->toBe('volc_live');
    expect($deployer->product())->toBe('live');
});

test('uploader.upload 调 CreateCert 返回 ChainID（Rsa.Prikey/Pubkey 嵌套）', function () {
    $args = null;
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->once()->andReturnUsing(function (string $action, string $version, array $body) use (&$args) {
        $args = compact('action', 'version', 'body');

        return ['ChainID' => 'chain-1'];
    });

    $deployer = volcLiveDeployerWith(fn (string $kind) => $kind === 'live' ? $client : new stdClass);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', volcLiveCreds() + ['project_name' => 'project-a']);

    expect($id)->toBe('chain-1');
    expect($args['action'])->toBe('CreateCert');
    expect($args['version'])->toBe('2023-01-01');
    // Rsa 嵌套：Prikey=私钥、Pubkey=完整链
    expect($args['body']['Rsa']['Prikey'])->toBe('KEYPEM');
    expect($args['body']['Rsa']['Pubkey'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($args['body']['UseWay'])->toBe('https');
    expect($args['body']['ProjectName'])->toBe('project-a');
    expect($args['body']['CertName'])->toStartWith('clouddeploy_');
});

test('upload 未返回 ChainID 抛明确异常', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andReturn([]);

    $deployer = volcLiveDeployerWith(fn () => $client);
    expect(fn () => $deployer->certUploader()->upload('C', 'K', 'CH', volcLiveCreds()))
        ->toThrow(RuntimeException::class, 'ChainID');
});

test('bind 调 BindCert（ChainID、Domain、HTTPS 大写键=true）', function () {
    $args = null;
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->once()->andReturnUsing(function (string $action, string $version, array $body) use (&$args) {
        $args = compact('action', 'version', 'body');

        return [];
    });

    $deployer = volcLiveDeployerWith(fn () => $client);
    $deployer->bind('chain-1', volcLiveCreds(), ['domain' => 'live.example.com']);

    expect($args['action'])->toBe('BindCert');
    expect($args['version'])->toBe('2023-01-01');
    expect($args['body'])->toBe(['ChainID' => 'chain-1', 'Domain' => 'live.example.com', 'HTTPS' => true]);
});

test('wildcard 分页列举启用域名并仅绑定单层匹配项', function () {
    $requests = [];
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andReturnUsing(function (string $action, string $version, array $body) use (&$requests) {
        $requests[] = compact('action', 'version', 'body');
        if ($action === 'ListDomainDetail') {
            return ['Result' => ['DomainList' => [
                ['Domain' => 'a.example.com'],
                ['Domain' => 'deep.a.example.com'],
            ]]];
        }

        return [];
    });

    $deployer = volcLiveDeployerWith(fn () => $client);
    $deployer->bind('chain-1', volcLiveCreds(), ['domain_match_pattern' => 'wildcard', 'domain' => '*.example.com']);

    expect($requests[0]['body'])->toMatchArray(['DomainStatusList' => [0], 'PageNum' => 1, 'PageSize' => 1000]);
    expect($requests)->toHaveCount(2);
    expect($requests[1]['body']['Domain'])->toBe('a.example.com');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = volcLiveDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('c', volcLiveCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 VolcApiException 时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(VolcRestClient::class);
    $client->shouldReceive('callJson')->andThrow(new VolcApiException('BindErr', 'bind cert failed'));

    $deployer = volcLiveDeployerWith(fn () => $client);

    try {
        $deployer->bind('c', ['access_key_id' => 'AK-SECRET-XYZ', 'secret_access_key' => 'SK-SECRET-ABC'], ['domain' => 'd.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('BindErr');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
    }
});
