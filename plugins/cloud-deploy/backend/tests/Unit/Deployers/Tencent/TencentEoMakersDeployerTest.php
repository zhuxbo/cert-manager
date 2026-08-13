<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Contracts\DeployBusinessException;
use Plugins\CloudDeploy\Deployers\Tencent\TencentEoMakersClient;
use Plugins\CloudDeploy\Deployers\Tencent\TencentEoMakersDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentProvider;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Teo\V20220901\Models\ModifyHostsCertificateRequest;
use TencentCloud\Teo\V20220901\Models\ModifyHostsCertificateResponse;
use TencentCloud\Teo\V20220901\TeoClient;
use Tests\TestCase;

uses(TestCase::class);

function tencentEoMakersDeployerWith(callable $clientFactory): TencentEoMakersDeployer
{
    return new class($clientFactory) extends TencentEoMakersDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function makersRsaCertificate(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'a.example.com'], $key, ['digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($x509, $pem);

    return $pem;
}

function makersConfig(array $overrides = []): array
{
    return array_replace([
        'project_id' => 'makers-project',
        'domain_match_pattern' => 'exact',
        'domains' => ['a.example.com'],
    ], $overrides);
}

function makersCredentials(array $overrides = []): array
{
    return array_replace([
        'secret_id' => 'SID',
        'secret_key' => 'SKEY',
        'api_token' => 'makers-token',
    ], $overrides);
}

test('EO Makers 复用腾讯 SSL 证书库并暴露 Certimate 配置', function () {
    $deployer = new TencentEoMakersDeployer;

    expect($deployer->provider())->toBe('tencent')
        ->and($deployer->product())->toBe('eo-makers')
        ->and($deployer->usesRemoteCertStore())->toBeTrue()
        ->and($deployer->certUploader()?->storeKind())->toBe('tencent_ssl')
        ->and(array_column($deployer->configSchema(), 'key'))
        ->toContain('endpoint', 'project_id', 'domain_match_pattern', 'domains', 'enable_multiple_ssl')
        ->not->toContain('api_token')
        ->and(collect((new TencentProvider)->credentialSchema())->firstWhere('key', 'api_token'))
        ->toMatchArray(['secret' => true, 'required' => false]);
});

test('EO Makers API Token 仅从加密凭证读取且缺失时明确失败', function () {
    $deployer = tencentEoMakersDeployerWith(fn () => throw new RuntimeException('不应创建客户端'));

    expect(fn () => $deployer->bind('cert-new', [
        'secret_id' => 'SID',
        'secret_key' => 'SKEY',
    ], makersConfig(['api_token' => 'target-config-token'])))
        ->toThrow(DeployBusinessException::class, '缺少凭证 api_token');
});

test('Makers 客户端 POST Action 与 ProjectId 并过滤非 Custom 和无 ZoneId 域名', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], json_encode([
        'Code' => 0,
        'Data' => ['Response' => ['PagesDomains' => [
            ['Type' => 'Custom', 'Domain' => 'a.example.com', 'ZoneId' => 'zone-1'],
            ['Type' => 'Default', 'Domain' => 'default.example.com', 'ZoneId' => 'zone-1'],
            ['Type' => 'Custom', 'Domain' => 'missing-zone.example.com', 'ZoneId' => ''],
        ]]],
    ]))]));
    $stack->push(Middleware::history($history));
    $client = new TencentEoMakersClient(new Client([
        'base_uri' => 'https://pages-api.cloud.tencent.com/v1/',
        'handler' => $stack,
        'headers' => ['Authorization' => 'Bearer makers-token'],
    ]));

    $domains = $client->listCustomDomains('makers-project');
    $body = json_decode((string) $history[0]['request']->getBody(), true);

    expect($history[0]['request']->getMethod())->toBe('POST')
        ->and($history[0]['request']->getUri()->getPath())->toBe('/v1/')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer makers-token')
        ->and($body)->toMatchArray(['Action' => 'DescribePagesZoneCustomDomains', 'ProjectId' => 'makers-project'])
        ->and($domains)->toBe([['domain' => 'a.example.com', 'zone_id' => 'zone-1']]);
});

test('Makers 客户端不采信上游错误字段且不会回显 API Token', function () {
    $token = 'opaque-makers-token-LEAK-ME';
    $client = new TencentEoMakersClient(new Client([
        'base_uri' => 'https://pages-api.cloud.tencent.com/v1/',
        'handler' => HandlerStack::create(new MockHandler([new Response(200, [], json_encode([
            'Code' => 999,
            'Message' => "failed $token",
            'RequestId' => $token,
        ]))])),
    ]));

    try {
        $client->listCustomDomains('project');
        expect(false)->toBeTrue('应拒绝业务错误响应');
    } catch (TencentCloudSDKException $e) {
        expect($e->getErrorCode())->toBe('MakersApiError')
            ->and($e->getMessage())->toBe('EdgeOne Makers 接口返回业务错误')
            ->and($e->getMessage())->not->toContain($token)->not->toContain('999')
            ->and($e->getRequestId())->toBe('');
    }
});

test('Makers 客户端拒绝畸形 JSON、非对象和缺基础响应结构', function (string $body) {
    $client = new TencentEoMakersClient(new Client([
        'base_uri' => 'https://pages-api.cloud.tencent.com/v1/',
        'handler' => HandlerStack::create(new MockHandler([new Response(200, [], $body)])),
    ]));

    try {
        $client->listCustomDomains('project');
        expect(false)->toBeTrue('应拒绝畸形响应');
    } catch (TencentCloudSDKException $e) {
        expect($e->getErrorCode())->toBe('MalformedResponse')
            ->and($e->getMessage())->toBe('EdgeOne Makers 接口响应格式错误');
    }
})->with([
    'empty body' => [''],
    'invalid JSON' => ['not-json'],
    'JSON list' => ['[]'],
    'missing Code' => ['{}'],
    'missing domain response' => ['{"Code":0}'],
]);

test('EO Makers 国际腾讯 endpoint 选择 pages-api.edgeone.ai', function () {
    $deployer = new class extends TencentEoMakersDeployer
    {
        public string $baseUri = '';

        public function makers(array $credentials): TencentEoMakersClient
        {
            return $this->makeClient('makers', $credentials);
        }

        protected function outboundHttpClient(string $baseUri, array $options = []): Client
        {
            $this->baseUri = $baseUri;

            return new Client(['base_uri' => $baseUri]);
        }
    };

    $deployer->makers([
        'endpoint' => 'teo.intl.tencentcloudapi.com',
        'api_token' => 'token',
    ]);

    expect($deployer->baseUri)->toBe('https://pages-api.edgeone.ai/v1/');
});

test('EO Makers wildcard 仅绑定匹配域名并跳过已有新证书的域名', function () {
    $makers = Mockery::mock(TencentEoMakersClient::class);
    $makers->shouldReceive('listCustomDomains')->once()->with('makers-project')->andReturn([
        ['domain' => 'a.example.com', 'zone_id' => 'zone-1'],
        ['domain' => 'b.example.com', 'zone_id' => 'zone-1'],
        ['domain' => 'a.b.example.com', 'zone_id' => 'zone-1'],
    ]);
    $teo = Mockery::mock(TeoClient::class);
    $teo->shouldReceive('callJson')->once()->with('DescribeHostCertificates', json_encode(['ZoneId' => 'zone-1']))->andReturn([
        'HostCertificates' => [
            ['Host' => 'a.example.com', 'HostCertInfo' => [['CertId' => 'old']]],
            ['Host' => 'b.example.com', 'HostCertInfo' => [['CertId' => 'cert-new']]],
        ],
    ]);
    $teo->shouldReceive('ModifyHostsCertificate')->once()
        ->withArgs(fn (ModifyHostsCertificateRequest $request): bool => $request->Hosts === ['a.example.com'])
        ->andReturn(new ModifyHostsCertificateResponse);

    tencentEoMakersDeployerWith(fn (string $kind) => $kind === 'makers' ? $makers : $teo)
        ->bind('cert-new', makersCredentials(), makersConfig([
            'domain_match_pattern' => 'wildcard',
            'domains' => ['*.example.com'],
        ]));
});

test('EO Makers 多证书按域名保留未过期的异算法证书', function () {
    $makers = Mockery::mock(TencentEoMakersClient::class);
    $makers->shouldReceive('listCustomDomains')->andReturn([['domain' => 'a.example.com', 'zone_id' => 'zone-1']]);
    $teo = Mockery::mock(TeoClient::class);
    $teo->shouldReceive('callJson')->andReturn(['HostCertificates' => [[
        'Host' => 'a.example.com',
        'HostCertInfo' => [
            ['CertId' => 'rsa-old', 'SignAlgo' => 'RSA SHA256', 'ExpireTime' => '2099-01-01T00:00:00Z'],
            ['CertId' => 'ecc-keep', 'SignAlgo' => 'ECC SHA256', 'ExpireTime' => '2099-01-01T00:00:00Z'],
            ['CertId' => 'ecc-expired', 'SignAlgo' => 'ECC SHA256', 'ExpireTime' => '2000-01-01T00:00:00Z'],
        ],
    ]]]);
    $captured = null;
    $teo->shouldReceive('ModifyHostsCertificate')->once()->andReturnUsing(function (ModifyHostsCertificateRequest $request) use (&$captured) {
        $captured = $request;

        return new ModifyHostsCertificateResponse;
    });

    tencentEoMakersDeployerWith(fn (string $kind) => $kind === 'makers' ? $makers : $teo)->bind([
        'remote_cert_id' => 'rsa-new',
        'cert' => makersRsaCertificate(),
        'chain' => '',
    ], makersCredentials(), makersConfig(['enable_multiple_ssl' => true]));

    expect(array_map(fn ($item): string => $item->CertId, $captured->ServerCertInfo))
        ->toBe(['rsa-new', 'ecc-keep']);
});

test('EO Makers 直接 API 传字符串 false 时不启用多证书', function () {
    $makers = Mockery::mock(TencentEoMakersClient::class);
    $makers->shouldReceive('listCustomDomains')->andReturn([['domain' => 'a.example.com', 'zone_id' => 'zone-1']]);
    $teo = Mockery::mock(TeoClient::class);
    $teo->shouldReceive('callJson')->andReturn(['HostCertificates' => [[
        'Host' => 'a.example.com',
        'HostCertInfo' => [['CertId' => 'ecc-old', 'SignAlgo' => 'ECC SHA256', 'ExpireTime' => '2099-01-01T00:00:00Z']],
    ]]]);
    $teo->shouldReceive('ModifyHostsCertificate')->once()
        ->withArgs(fn (ModifyHostsCertificateRequest $request): bool => count($request->ServerCertInfo) === 1)
        ->andReturn(new ModifyHostsCertificateResponse);

    tencentEoMakersDeployerWith(fn (string $kind) => $kind === 'makers' ? $makers : $teo)->bind([
        'remote_cert_id' => 'rsa-new',
        'cert' => makersRsaCertificate(),
        'chain' => '',
    ], makersCredentials(), makersConfig(['enable_multiple_ssl' => 'false']));
});

test('EO Makers certsan 从项目自定义域名中按证书 SAN 选择', function () {
    $makers = Mockery::mock(TencentEoMakersClient::class);
    $makers->shouldReceive('listCustomDomains')->andReturn([
        ['domain' => 'a.example.com', 'zone_id' => 'zone-1'],
        ['domain' => 'other.example.net', 'zone_id' => 'zone-1'],
    ]);
    $teo = Mockery::mock(TeoClient::class);
    $teo->shouldReceive('callJson')->andReturn(['HostCertificates' => []]);
    $teo->shouldReceive('ModifyHostsCertificate')->once()
        ->withArgs(fn (ModifyHostsCertificateRequest $request): bool => $request->Hosts === ['a.example.com'])
        ->andReturn(new ModifyHostsCertificateResponse);

    tencentEoMakersDeployerWith(fn (string $kind) => $kind === 'makers' ? $makers : $teo)->bind([
        'remote_cert_id' => 'cert-new',
        'cert' => makersRsaCertificate(),
        'chain' => '',
    ], makersCredentials(), makersConfig([
        'domain_match_pattern' => 'certsan',
        'domains' => [],
    ]));
});
