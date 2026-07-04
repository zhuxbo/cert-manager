<?php

use Plugins\CloudDeploy\Deployers\Onepanel\OnepanelApiException;
use Plugins\CloudDeploy\Deployers\Onepanel\OnepanelClient;
use Plugins\CloudDeploy\Deployers\Onepanel\OnepanelSiteDeployer;
use Tests\TestCase;

uses(TestCase::class);

/** 叶子证书（CN=example.com，SAN=example.com,www.example.com）——CERTSAN 匹配用。 */
const ONEPANEL_LEAF_CERT = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIC2jCCAcKgAwIBAgIJAN9su0DuKwuhMA0GCSqGSIb3DQEBCwUAMBYxFDASBgNV
BAMMC2V4YW1wbGUuY29tMB4XDTI2MDYyNzIxMTAzMloXDTM2MDYyNDIxMTAzMlow
FjEUMBIGA1UEAwwLZXhhbXBsZS5jb20wggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAw
ggEKAoIBAQC0R4y8I2YLygUraatZ1IHBWuK4kGDl+U+01sC4Qp4u4Q0CkrLsgrLc
FAS3CqsqskSfGjZ5HyNkUnpQ8p8NoMLqpGsYt0HH7C0lVA0K96qrGmYyRjwUN2G1
M9m7BTetYP39ARsN773aC3gSqTI/r64BpSU1spL1waRQIqRCuY7OZC/zc9UDtFsD
uQnyMR5B+3vmw76/q5SusI8OEMqDuASnkMH9qXaNlthvv/42lLE2JRxVJWcngJfp
GrHmnKd5u2rWR9UxzQqIbAUi4G6ysY63cjS6YqXyBbZYvt9njEEUP9WPdGImiItO
rUIBXXh7GoFcEgNjyaoovwSXxvT7t2+ZAgMBAAGjKzApMCcGA1UdEQQgMB6CC2V4
YW1wbGUuY29tgg93d3cuZXhhbXBsZS5jb20wDQYJKoZIhvcNAQELBQADggEBABBH
nwDweqxYSw1BAdmcS6FTShiSx9BLklv2RMbrzk2Y78fXu7YFGNuA2Hk+oLBGhM1m
8nIIiPW7AtNETvL83DiJI1C5BY6eY5wzuNFUuOTnTP/9RkU2zaNByILVwRNrObLt
A9DX1TnQ9WIUYtqCTUfX1sTL+RJBN15TobO2208eVOyawDlf8x83n+UqQqAyzvsI
ekYkKeljvzBQ6aKklt5w9z8EbkWn0I+UL+JpITAydbxAvzq4f9kS9YpQVpvtUeOd
8sN756DBbTIXEE7Khh1sr77U+zEnD6Nys+hyIHz/7zYn80RHjS3A6dskmabEuiy2
xIgmdvTJjWw6aUyT6Fk=
-----END CERTIFICATE-----
PEM;

/** 测试子类：override makeClient（api kind）注入 mock OnepanelClient。 */
function onepanelSiteDeployerWith(callable $clientFactory): OnepanelSiteDeployer
{
    return new class($clientFactory) extends OnepanelSiteDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function onepanelSiteCertRef(): array
{
    return ['cert' => ONEPANEL_LEAF_CERT, 'key' => 'KEYPEM', 'chain' => 'INTERMEDIAPEM'];
}

function onepanelSiteCreds(): array
{
    return ['server_url' => 'https://p', 'api_version' => 'v1', 'api_key' => 'KEY'];
}

test('1Panel 网站为内联型 + 元信息', function () {
    $deployer = new OnepanelSiteDeployer;
    expect($deployer->provider())->toBe('onepanel');
    expect($deployer->product())->toBe('site');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect(array_column($deployer->configSchema(), 'key'))
        ->toContain('deploy_target')->toContain('website_match_pattern')->toContain('website_id')->toContain('certificate_id');
});

test('website + specified：去重未命中 → 上传 → 查回 ID → 绑定指定网站', function () {
    $uploaded = null;
    $boundBody = null;
    $client = Mockery::mock(OnepanelClient::class);
    $client->shouldReceive('isV2')->andReturnFalse();
    // 第一次 search 无命中（上传前），第二次命中（上传后查回 ID=55）
    $client->shouldReceive('searchWebsiteSSL')->twice()->andReturn(
        ['items' => [], 'total' => 0],
        ['items' => [['id' => 55, 'pem' => ONEPANEL_LEAF_CERT."\nINTERMEDIAPEM", 'privateKey' => 'KEYPEM']], 'total' => 1],
    );
    $client->shouldReceive('uploadWebsiteSSL')->once()->andReturnUsing(function (array $body) use (&$uploaded) {
        $uploaded = $body;
    });
    $client->shouldReceive('getWebsiteHttps')->once()->with(7)->andReturn(['enable' => false]);
    $client->shouldReceive('postWebsiteHttps')->once()->andReturnUsing(function (int $id, array $body) use (&$boundBody) {
        $boundBody = [$id, $body];
    });

    $deployer = onepanelSiteDeployerWith(fn () => $client);
    $deployer->bind(onepanelSiteCertRef(), onepanelSiteCreds(), [
        'deploy_target' => 'website', 'website_match_pattern' => 'specified', 'website_id' => 7,
    ]);

    expect($uploaded['type'])->toBe('paste');
    expect($uploaded['certificate'])->toContain('INTERMEDIAPEM'); // 完整链
    expect($uploaded)->not->toHaveKey('sslID'); // 新建不带 sslID
    [$id, $body] = $boundBody;
    expect($id)->toBe(7);
    expect($body['websiteSSLId'])->toBe(55);
    expect($body['enable'])->toBeTrue();
    expect($body['type'])->toBe('existed');
});

test('website + specified：去重命中（内容相同）→ 复用 ID，不再上传', function () {
    $client = Mockery::mock(OnepanelClient::class);
    $client->shouldReceive('isV2')->andReturnFalse();
    $client->shouldReceive('searchWebsiteSSL')->once()->andReturn([
        'items' => [['id' => 99, 'pem' => ONEPANEL_LEAF_CERT."\nINTERMEDIAPEM", 'privateKey' => 'KEYPEM']],
        'total' => 1,
    ]);
    $client->shouldReceive('uploadWebsiteSSL')->never();
    $client->shouldReceive('getWebsiteHttps')->once()->andReturn(['enable' => true, 'websiteSSLId' => 99]);
    // 已启用且 SSL ID 相同 → 跳过 postWebsiteHttps
    $client->shouldReceive('postWebsiteHttps')->never();

    $deployer = onepanelSiteDeployerWith(fn () => $client);
    $deployer->bind(onepanelSiteCertRef(), onepanelSiteCreds(), [
        'deploy_target' => 'website', 'website_id' => 3,
    ]);

    expect(true)->toBeTrue();
});

test('website + certsan：按 SAN 匹配网站 primaryDomain + domains 收集网站并绑定', function () {
    $boundIds = [];
    $client = Mockery::mock(OnepanelClient::class);
    $client->shouldReceive('isV2')->andReturnFalse();
    // 上传去重：未命中→上传→查回 ID=10
    $client->shouldReceive('searchWebsiteSSL')->andReturn(
        ['items' => [], 'total' => 0],
        ['items' => [['id' => 10, 'pem' => ONEPANEL_LEAF_CERT."\nINTERMEDIAPEM", 'privateKey' => 'KEYPEM']], 'total' => 1],
    );
    $client->shouldReceive('uploadWebsiteSSL')->once();
    // 网站列表：site1 域名匹配（www.example.com），site2 不匹配（other.com）
    $client->shouldReceive('searchWebsites')->once()->andReturn([
        'items' => [
            ['id' => 1, 'primaryDomain' => 'www.example.com'],
            ['id' => 2, 'primaryDomain' => 'other.com'],
        ],
        'total' => 2,
    ]);
    // site1 详情：domain ssl=true → 收集
    $client->shouldReceive('getWebsite')->once()->with(1)->andReturn([
        'domains' => [['domain' => 'www.example.com', 'ssl' => true]],
    ]);
    $client->shouldReceive('getWebsiteHttps')->once()->with(1)->andReturn(['enable' => false]);
    $client->shouldReceive('postWebsiteHttps')->once()->andReturnUsing(function (int $id) use (&$boundIds) {
        $boundIds[] = $id;
    });

    $deployer = onepanelSiteDeployerWith(fn () => $client);
    $deployer->bind(onepanelSiteCertRef(), onepanelSiteCreds(), [
        'deploy_target' => 'website', 'website_match_pattern' => 'certsan',
    ]);

    expect($boundIds)->toBe([1]); // 仅匹配的 site1
});

test('certificate：直接替换指定证书内容（带 sslID）', function () {
    $captured = null;
    $client = Mockery::mock(OnepanelClient::class);
    $client->shouldReceive('uploadWebsiteSSL')->once()->andReturnUsing(function (array $body) use (&$captured) {
        $captured = $body;
    });
    $client->shouldReceive('searchWebsiteSSL')->never();

    $deployer = onepanelSiteDeployerWith(fn () => $client);
    $deployer->bind(onepanelSiteCertRef(), onepanelSiteCreds(), [
        'deploy_target' => 'certificate', 'certificate_id' => 42,
    ]);

    expect($captured['sslID'])->toBe(42);
    expect($captured['type'])->toBe('paste');
    expect($captured['certificate'])->toContain('INTERMEDIAPEM');
    expect($captured['privateKey'])->toBe('KEYPEM');
});

test('v2 website：postWebsiteHttps 带 http3 字段', function () {
    $boundBody = null;
    $client = Mockery::mock(OnepanelClient::class);
    $client->shouldReceive('isV2')->andReturnTrue();
    $client->shouldReceive('searchWebsiteSSL')->andReturn(['items' => [['id' => 5, 'pem' => ONEPANEL_LEAF_CERT."\nINTERMEDIAPEM", 'privateKey' => 'KEYPEM']], 'total' => 1]);
    $client->shouldReceive('getWebsiteHttps')->andReturn(['enable' => false, 'http3' => true]);
    $client->shouldReceive('postWebsiteHttps')->once()->andReturnUsing(function (int $id, array $body) use (&$boundBody) {
        $boundBody = $body;
    });

    $deployer = onepanelSiteDeployerWith(fn () => $client);
    $deployer->bind(onepanelSiteCertRef(), ['server_url' => 'https://p', 'api_version' => 'v2', 'api_key' => 'K'], [
        'deploy_target' => 'website', 'website_id' => 8,
    ]);

    expect($boundBody)->toHaveKey('http3');
    expect($boundBody['http3'])->toBeTrue();
});

test('缺 deploy_target 抛业务错误', function () {
    $deployer = onepanelSiteDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(onepanelSiteCertRef(), onepanelSiteCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 deploy_target');
});

test('website + specified 缺 website_id 抛业务错误', function () {
    $deployer = onepanelSiteDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(onepanelSiteCertRef(), onepanelSiteCreds(), ['deploy_target' => 'website']))
        ->toThrow(RuntimeException::class, '缺少配置 website_id');
});

test('certificate 缺 certificate_id 抛业务错误', function () {
    $deployer = onepanelSiteDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(onepanelSiteCertRef(), onepanelSiteCreds(), ['deploy_target' => 'certificate']))
        ->toThrow(RuntimeException::class, '缺少配置 certificate_id');
});

test('bind 遇 OnepanelApiException 时脱敏重抛（无 apiKey、不挂 previous）', function () {
    $client = Mockery::mock(OnepanelClient::class);
    $client->shouldReceive('isV2')->andReturnFalse();
    $client->shouldReceive('searchWebsiteSSL')->andThrow(new OnepanelApiException('500', 'search failed'));

    $deployer = onepanelSiteDeployerWith(fn () => $client);
    try {
        $deployer->bind(onepanelSiteCertRef(), [
            'server_url' => 'https://p', 'api_version' => 'v1', 'api_key' => 'APIKEY-LEAK-9',
        ], ['deploy_target' => 'website', 'website_id' => 1]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('500')->toContain('search failed');
        expect($e->getMessage())->not->toContain('APIKEY-LEAK-9');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('APIKEY-LEAK-9');
    }
});
