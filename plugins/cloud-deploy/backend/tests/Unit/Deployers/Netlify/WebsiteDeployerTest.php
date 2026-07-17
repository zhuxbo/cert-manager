<?php

use Plugins\CloudDeploy\Deployers\Netlify\NetlifyApiException;
use Plugins\CloudDeploy\Deployers\Netlify\NetlifyClient;
use Plugins\CloudDeploy\Deployers\Netlify\WebsiteDeployer;
use Tests\TestCase;

uses(TestCase::class);

/** 测试子类：override makeClient（api kind）注入 mock NetlifyClient。 */
function netlifyWebsiteDeployerWith(callable $clientFactory): WebsiteDeployer
{
    return new class($clientFactory) extends WebsiteDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function netlifyCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

test('Netlify 网站为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new WebsiteDeployer;
    expect($deployer->provider())->toBe('netlify');
    expect($deployer->product())->toBe('website');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))->toContain('site_id');
});

test('bind 调 provisionSiteTlsCertificate（siteId + certificate/ca_certificates/key）', function () {
    $captured = null;
    $client = Mockery::mock(NetlifyClient::class);
    $client->shouldReceive('provisionSiteTlsCertificate')->once()->andReturnUsing(function (string $siteId, array $params) use (&$captured) {
        $captured = [$siteId, $params];
    });

    $deployer = netlifyWebsiteDeployerWith(fn () => $client);
    $deployer->bind(netlifyCertRef(), ['api_token' => 'TOKEN'], ['site_id' => 'site-1']);

    [$siteId, $params] = $captured;
    expect($siteId)->toBe('site-1');
    expect($params['certificate'])->toBe('CERTPEM');
    expect($params['ca_certificates'])->toBe('CHAINPEM');
    expect($params['key'])->toBe('KEYPEM');
});

test('缺 site_id 抛业务错误', function () {
    $deployer = netlifyWebsiteDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(netlifyCertRef(), ['api_token' => 'TOKEN'], []))
        ->toThrow(RuntimeException::class, '缺少配置 site_id');
});

test('bind 遇 NetlifyApiException 时脱敏重抛（含错误码、无 token、不挂 previous）', function () {
    $client = Mockery::mock(NetlifyClient::class);
    $client->shouldReceive('provisionSiteTlsCertificate')->andThrow(new NetlifyApiException('422', 'certificate and key do not match'));

    $deployer = netlifyWebsiteDeployerWith(fn () => $client);
    try {
        $deployer->bind(netlifyCertRef(), ['api_token' => 'TOKEN-LEAK-123'], ['site_id' => 'site-1']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('422')->toContain('do not match');
        expect($e->getMessage())->not->toContain('TOKEN-LEAK-123');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('TOKEN-LEAK-123');
    }
});
