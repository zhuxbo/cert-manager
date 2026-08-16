<?php

use Plugins\CloudDeploy\Deployers\Cmcccloud\CmcccloudApiException;
use Plugins\CloudDeploy\Deployers\Cmcccloud\CmcccloudCdnDeployer;
use Plugins\CloudDeploy\Deployers\Cmcccloud\CmcccloudRestClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 mock（cdn）。
 */
function cmcccloudCdnDeployerWith(callable $clientFactory): CmcccloudCdnDeployer
{
    return new class($clientFactory) extends CmcccloudCdnDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return ($this->factory)($kind, $credentials);
        }
    };
}

function cmcccloudCreds(): array
{
    return ['access_key_id' => 'AK', 'access_key_secret' => 'SK'];
}

function cmcccloudCertRef(): array
{
    return ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

function cmcccloudCertificateWithSan(): string
{
    $conf = tempnam(sys_get_temp_dir(), 'cmcc_san_');
    file_put_contents($conf, "[v3]\nsubjectAltName=DNS:a.example.com,DNS:*.wild.example.com\n");
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'a.example.com'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 1, [
        'digest_alg' => 'sha256', 'config' => $conf, 'x509_extensions' => 'v3',
    ]);
    openssl_x509_export($cert, $pem);
    @unlink($conf);

    return $pem;
}

test('移动云 CDN：内联型（usesRemoteCertStore=false + certUploader=null + 元信息）', function () {
    $deployer = new CmcccloudCdnDeployer;
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect($deployer->provider())->toBe('cmcccloud');
    expect($deployer->product())->toBe('cdn');
    expect($deployer->label())->toBe('移动云 CDN');
    expect(array_column($deployer->configSchema(), 'key'))->toContain('domain_match_pattern');
});

test('bind：wildcard 匹配所有符合证书通配规则的域名', function () {
    $bound = [];
    $client = Mockery::mock(CmcccloudRestClient::class);
    $client->shouldReceive('call')->andReturnUsing(function (string $method, string $path, array $pathParams = [], array $query = [], ?array $body = null) use (&$bound) {
        if (str_contains($path, 'describeUserDomains')) {
            return ['body' => ['list' => [
                ['domainId' => 1, 'domainName' => 'a.example.com', 'domainStatus' => 'RUNNING'],
                ['domainId' => 2, 'domainName' => 'deep.a.example.com', 'domainStatus' => 'RUNNING'],
                ['domainId' => 3, 'domainName' => '.example.com', 'domainStatus' => 'RUNNING'],
            ]]];
        }
        $bound[] = $body['domainId'];

        return [];
    });
    cmcccloudCdnDeployerWith(fn () => $client)->bind(cmcccloudCertRef(), cmcccloudCreds(), [
        'domain_match_pattern' => 'wildcard', 'domain' => '*.example.com',
    ]);
    expect($bound)->toBe([1, 3]);
});

test('bind：certsan 按叶证书 SAN/CN 枚举匹配域名且无需 domain', function () {
    $bound = [];
    $client = Mockery::mock(CmcccloudRestClient::class);
    $client->shouldReceive('call')->andReturnUsing(function (string $method, string $path, array $pathParams = [], array $query = [], ?array $body = null) use (&$bound) {
        if (str_contains($path, 'describeUserDomains')) {
            return ['body' => ['list' => [
                ['domainId' => 1, 'domainName' => 'a.example.com', 'domainStatus' => 'RUNNING'],
                ['domainId' => 2, 'domainName' => 'x.wild.example.com', 'domainStatus' => 'RUNNING'],
                ['domainId' => 3, 'domainName' => 'deep.x.wild.example.com', 'domainStatus' => 'RUNNING'],
                ['domainId' => 4, 'domainName' => 'other.example.com', 'domainStatus' => 'RUNNING'],
            ]]];
        }
        $bound[] = $body['domainId'];

        return [];
    });
    $certRef = cmcccloudCertRef();
    $certRef['cert'] = cmcccloudCertificateWithSan();
    cmcccloudCdnDeployerWith(fn () => $client)->bind($certRef, cmcccloudCreds(), [
        'domain_match_pattern' => 'certsan',
    ]);
    expect($bound)->toBe([1, 2]);
});

test('bind：exact 匹配域名后逐个 AddDomainServerCertificate（domainId(int) + certificate/privateKey）', function () {
    $calls = [];
    $client = Mockery::mock(CmcccloudRestClient::class);
    $client->shouldReceive('call')->andReturnUsing(function (string $method, string $path, array $pathParams = [], array $query = [], ?array $body = null) use (&$calls) {
        $calls[] = compact('method', 'path', 'query', 'body');
        if (str_contains($path, 'describeUserDomains')) {
            return ['state' => 'OK', 'body' => ['list' => [
                ['domainId' => 1001, 'domainName' => 'cdn.example.com', 'domainStatus' => 'RUNNING'],
                ['domainId' => 1002, 'domainName' => 'other.example.com', 'domainStatus' => 'RUNNING'],
                ['domainId' => 1003, 'domainName' => 'cdn.example.com', 'domainStatus' => 'OFFLINE'], // 跳过状态
                ['domainId' => 1004, 'domainName' => 'cdn.example.com', 'domainStatus' => 'RUNNING', 'deleted' => true], // 跳过已删除
            ]]];
        }

        return ['state' => 'OK'];
    });

    $deployer = cmcccloudCdnDeployerWith(fn (string $kind) => $kind === 'cdn' ? $client : new stdClass);
    $deployer->bind(cmcccloudCertRef(), cmcccloudCreds(), ['domain' => 'cdn.example.com']);

    $addCalls = collect($calls)->filter(fn ($c) => str_contains($c['path'], 'addDomainServerCertificate'))->values();
    expect($addCalls)->toHaveCount(1);
    $body = $addCalls[0]['body'];
    expect($body['domainId'])->toBe(1001); // int
    expect($body['crtName'])->toStartWith('clouddeploy_');
    expect($body['certificate'])->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($body['privateKey'])->toBe('KEYPEM');
});

test('bind：多个匹配域名各 AddDomainServerCertificate 一次', function () {
    $count = 0;
    $client = Mockery::mock(CmcccloudRestClient::class);
    $client->shouldReceive('call')->andReturnUsing(function (string $method, string $path) use (&$count) {
        if (str_contains($path, 'describeUserDomains')) {
            return ['state' => 'OK', 'body' => ['list' => [
                ['domainId' => 1, 'domainName' => 'cdn.example.com', 'domainStatus' => 'RUNNING'],
                ['domainId' => 9, 'domainName' => 'cdn.example.com', 'domainStatus' => 'RUNNING'],
            ]]];
        }
        $count++;

        return ['state' => 'OK'];
    });

    $deployer = cmcccloudCdnDeployerWith(fn () => $client);
    $deployer->bind(cmcccloudCertRef(), cmcccloudCreds(), ['domain' => 'cdn.example.com']);

    expect($count)->toBe(2);
});

test('bind：分页拉取（满页 10 条继续翻页，page 递增）', function () {
    $pages = [];
    $client = Mockery::mock(CmcccloudRestClient::class);
    $client->shouldReceive('call')->andReturnUsing(function (string $method, string $path, array $pathParams = [], array $query = [], ?array $body = null) use (&$pages) {
        if (str_contains($path, 'describeUserDomains')) {
            $pages[] = $query['page'];
            if ($query['page'] === '1') {
                $list = [];
                for ($i = 0; $i < 10; $i++) {
                    $list[] = ['domainId' => $i + 100, 'domainName' => 'nomatch.example.com', 'domainStatus' => 'RUNNING'];
                }

                return ['state' => 'OK', 'body' => ['list' => $list]];
            }

            return ['state' => 'OK', 'body' => ['list' => [['domainId' => 1, 'domainName' => 'cdn.example.com', 'domainStatus' => 'RUNNING']]]];
        }

        return ['state' => 'OK'];
    });

    $deployer = cmcccloudCdnDeployerWith(fn () => $client);
    $deployer->bind(cmcccloudCertRef(), cmcccloudCreds(), ['domain' => 'cdn.example.com']);

    expect($pages)->toBe(['1', '2']);
});

test('bind：无匹配域名 → 业务失败，不调 AddDomainServerCertificate', function () {
    $client = Mockery::mock(CmcccloudRestClient::class);
    $client->shouldReceive('call')->andReturnUsing(function (string $method, string $path) {
        if (str_contains($path, 'describeUserDomains')) {
            return ['state' => 'OK', 'body' => ['list' => [['domainId' => 2, 'domainName' => 'other.example.com', 'domainStatus' => 'RUNNING']]]];
        }
        throw new RuntimeException('不应调用 AddDomainServerCertificate');
    });

    $deployer = cmcccloudCdnDeployerWith(fn () => $client);

    expect(fn () => $deployer->bind(cmcccloudCertRef(), cmcccloudCreds(), ['domain' => 'cdn.example.com']))
        ->toThrow(RuntimeException::class, '未找到匹配');
});

test('缺 domain 配置抛业务错误', function () {
    $deployer = cmcccloudCdnDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(cmcccloudCertRef(), cmcccloudCreds(), []))
        ->toThrow(RuntimeException::class, '缺少配置 domain');
});

test('bind SDK 抛 CmcccloudApiException 时脱敏重抛（含错误码、无 AK/SK、不挂 previous）', function () {
    $client = Mockery::mock(CmcccloudRestClient::class);
    $client->shouldReceive('call')->andThrow(new CmcccloudApiException('PARAM_ERROR', 'invalid domain'));

    $deployer = cmcccloudCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind(cmcccloudCertRef(), ['access_key_id' => 'AK-SECRET-XYZ', 'access_key_secret' => 'SK-SECRET-ABC'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('PARAM_ERROR')->toContain('invalid domain');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('bind SDK 抛网络类异常（含签名 URL）时脱敏只暴露类名', function () {
    $client = Mockery::mock(CmcccloudRestClient::class);
    $client->shouldReceive('call')->andThrow(new RuntimeException(
        'cURL error 7: connect https://ecloud.10086.cn/api/openapi-ecdn?AccessKey=AK-LEAK&Signature=deadbeef',
    ));

    $deployer = cmcccloudCdnDeployerWith(fn () => $client);

    try {
        $deployer->bind(cmcccloudCertRef(), ['access_key_id' => 'AK-LEAK', 'access_key_secret' => 'SK'], ['domain' => 'x.example.com']);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('移动云调用失败');
        expect($e->getMessage())->not->toContain('AK-LEAK');
        expect($e->getMessage())->not->toContain('ecloud.10086.cn');
        expect($e->getPrevious())->toBeNull();
    }
});
