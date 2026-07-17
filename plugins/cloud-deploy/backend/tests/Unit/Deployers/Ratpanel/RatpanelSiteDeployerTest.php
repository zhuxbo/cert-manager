<?php

use Plugins\CloudDeploy\Deployers\Ratpanel\RatpanelApiException;
use Plugins\CloudDeploy\Deployers\Ratpanel\RatpanelRestClient;
use Plugins\CloudDeploy\Deployers\Ratpanel\RatpanelSiteDeployer;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock RatpanelRestClient。 */
function ratpanelSiteDeployerWith(callable $clientFactory): RatpanelSiteDeployer
{
    return new class($clientFactory) extends RatpanelSiteDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function ratpanelCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

function ratpanelCreds(): array
{
    return ['server_url' => 'https://panel:8888', 'access_token_id' => 1, 'access_token' => 'TOKEN'];
}

test('耗子面板 site 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new RatpanelSiteDeployer;
    expect($deployer->provider())->toBe('ratpanel');
    expect($deployer->product())->toBe('site');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('site_names');
});

test('bind 多站点逐个 setWebsiteCert（分号分隔、完整链→cert、key→key）', function () {
    $calls = [];
    $client = Mockery::mock(RatpanelRestClient::class);
    $client->shouldReceive('setWebsiteCert')->twice()
        ->andReturnUsing(function (string $name, string $cert, string $key) use (&$calls) {
            $calls[] = compact('name', 'cert', 'key');
        });

    $deployer = ratpanelSiteDeployerWith(fn () => $client);
    $deployer->bind(ratpanelCertRef(), ratpanelCreds(), ['site_names' => 'a.example.com; b.example.com']);

    expect($calls)->toHaveCount(2);
    expect(array_column($calls, 'name'))->toBe(['a.example.com', 'b.example.com']);
    expect($calls[0]['cert'])->toContain('CERTPEM')->toContain('CHAINPEM'); // 完整链
    expect($calls[0]['key'])->toBe('KEYPEM');
});

test('多站点中单站点失败：尝试全部并聚合错误', function () {
    $client = Mockery::mock(RatpanelRestClient::class);
    $client->shouldReceive('setWebsiteCert')->with('ok.example.com', Mockery::any(), Mockery::any())->once();
    $client->shouldReceive('setWebsiteCert')->with('bad.example.com', Mockery::any(), Mockery::any())->once()
        ->andThrow(new RatpanelApiException('RatPanelError', 'site not found'));

    $deployer = ratpanelSiteDeployerWith(fn () => $client);
    try {
        $deployer->bind(ratpanelCertRef(), ratpanelCreds(), ['site_names' => 'ok.example.com;bad.example.com']);
        expect(false)->toBeTrue('应抛聚合错误');
    } catch (RuntimeException $e) {
        // 两站点都被尝试（mock once×2 已校验），错误聚合含失败站点
        expect($e->getMessage())->toContain('bad.example.com')->toContain('site not found');
    }
});

test('缺 site_names 抛业务错误', function () {
    $deployer = ratpanelSiteDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(ratpanelCertRef(), ratpanelCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 site_names');
});

test('site_names 仅含分隔符（解析后为空）也抛业务错误', function () {
    $deployer = ratpanelSiteDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(ratpanelCertRef(), ratpanelCreds(), ['site_names' => ' ; , ']))
        ->toThrow(RuntimeException::class, '缺少配置 site_names');
});

test('bind 遇 RatpanelApiException 时脱敏（含错误码、无凭证、不挂 previous）', function () {
    $client = Mockery::mock(RatpanelRestClient::class);
    $client->shouldReceive('setWebsiteCert')->andThrow(new RatpanelApiException('RatPanelError', 'cert invalid'));

    $deployer = ratpanelSiteDeployerWith(fn () => $client);
    try {
        $deployer->bind(ratpanelCertRef(), [
            'server_url' => 'https://panel:8888', 'access_token_id' => 1, 'access_token' => 'TOKEN-LEAK-123',
        ], ['site_names' => 'a.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('cert invalid');
        expect($e->getMessage())->not->toContain('TOKEN-LEAK-123');
        expect($e->getTraceAsString())->not->toContain('TOKEN-LEAK-123');
    }
});
