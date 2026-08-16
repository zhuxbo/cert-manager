<?php

use Plugins\CloudDeploy\Deployers\Tencent\TencentTseDeployer;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateRequest;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateResponse;
use TencentCloud\Ssl\V20191205\SslClient;
use TencentCloud\Tse\V20201207\Models\CreateCloudNativeAPIGatewayCertificateRequest;
use TencentCloud\Tse\V20201207\Models\CreateCloudNativeAPIGatewayCertificateResponse;
use TencentCloud\Tse\V20201207\Models\DescribeCloudNativeAPIGatewayCertificatesRequest;
use TencentCloud\Tse\V20201207\Models\DescribeCloudNativeAPIGatewayCertificatesResponse;
use TencentCloud\Tse\V20201207\Models\ModifyCloudNativeAPIGatewayCertificateRequest;
use TencentCloud\Tse\V20201207\Models\ModifyCloudNativeAPIGatewayCertificateResponse;
use TencentCloud\Tse\V20201207\TseClient;
use Tests\TestCase;

uses(TestCase::class);

/** SAN = tse.example.com, www.tse.example.com 的自签证书（容器 OpenSSL 3.x 生成）。 */
const TSE_SAN_CERT = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIDSDCCAjCgAwIBAgIUUBjemBHBqHCbVNdOC9tLQIdvdRowDQYJKoZIhvcNAQEL
BQAwGjEYMBYGA1UEAwwPdHNlLmV4YW1wbGUuY29tMB4XDTI2MDYyNzE4MjkyNFoX
DTI2MDYyOTE4MjkyNFowGjEYMBYGA1UEAwwPdHNlLmV4YW1wbGUuY29tMIIBIjAN
BgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAt6XhkbT1an1z2BPB+Xa0mq/jmc8E
p2ihYk2/xRABLG35iUj5mCu2kavuj+nMSJiT62JF1pGHTsdR0DtmjmCu3zKQfewr
8DjTb1/ZzvXO1WtBQm4LXd6NsgHaAOAnhmk0Ddh02N4T5GbD6+KoHy/Ktp2P4E5c
ZeAjsF6/ook9tRJyZUObwNM8E46pZsYSbbbRrmH3QKXu2Yu6BR1CHv0P2ux8Yweb
1QOc1SIE6Go931j8q3QKDRQ6Rb+2qr55Q2GhmnALGADf2NV3O5x2RZD4txDWBnWE
6kXj4+gUjLQy2i2sFzWB8yxPQjFZ6TcVqaPj8gyLiKoK9hMy1Pe77pKmiQIDAQAB
o4GFMIGCMB0GA1UdDgQWBBTgWU811d3lpBD7IRbSGu/X/zyC0TAfBgNVHSMEGDAW
gBTgWU811d3lpBD7IRbSGu/X/zyC0TAPBgNVHRMBAf8EBTADAQH/MC8GA1UdEQQo
MCaCD3RzZS5leGFtcGxlLmNvbYITd3d3LnRzZS5leGFtcGxlLmNvbTANBgkqhkiG
9w0BAQsFAAOCAQEAMewYdmvAULhHXIEV6e8gaKvA0BLt4woSuWLH85T279mOaqya
h5ZZcYu1kEidqudz9jOLP5Dq5JjjncEAN4u7GsgqMi9sZjB9xj670O1djf1BQL1j
HsTiNdMbnoAJwGIaF4krMW1qpm27n6L+Mlmio9OD6+7oBFtPC4Oe3T8TdMj45NWo
v86T+J2Q4tjAzSI9lwzkDAVSDYpCJnCHBgjrBgJINi9dbWZaF507I22St818ReYf
4477YlJZCuKUIwPYYD8qp7dUAUYugqGE4xyeC+ZQAydaior6besc8+nHl7llh3N3
B07nvkFS/GxizoRVwpxaNC6TtdEY6TeKBIG9+Q==
-----END CERTIFICATE-----
PEM;

/** 测试子类：override makeClient（ssl/tse kind）。 */
function tencentTseDeployerWith(callable $clientFactory): TencentTseDeployer
{
    return new class($clientFactory) extends TencentTseDeployer
    {
        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            $client = ($this->factory)($kind, $credentials);
            if ($kind === 'tse' && $client instanceof TseClient) {
                $response = new DescribeCloudNativeAPIGatewayCertificatesResponse;
                $response->deserialize(['Result' => ['CertificatesList' => []], 'RequestId' => 'r']);
                $client->shouldReceive('DescribeCloudNativeAPIGatewayCertificates')->byDefault()->andReturn($response);
            }

            return $client;
        }
    };
}

function tseUploadResponse(string $id): UploadCertificateResponse
{
    $resp = new UploadCertificateResponse;
    $resp->deserialize(['CertificateId' => $id, 'RequestId' => 'r']);

    return $resp;
}

function tseCertRef(string $cert = 'CERTPEM'): array
{
    return ['cert' => $cert, 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];
}

test('腾讯云 TSE 为内联型（usesRemoteCertStore=false）+ 元信息', function () {
    $deployer = new TencentTseDeployer;
    expect($deployer->provider())->toBe('tencent');
    expect($deployer->product())->toBe('tse');
    expect($deployer->usesRemoteCertStore())->toBeFalse();
    expect($deployer->certUploader())->toBeNull();
    expect(array_column($deployer->configSchema(), 'key'))
        ->toContain('region')->toContain('service_type')->toContain('gateway_id')->toContain('domains')->toContain('certificate_id');
});

test('新建路径：上传 SSL 拿 CertId → CreateCloudNativeAPIGatewayCertificate（GatewayId/CertId/BindDomains=config domains）', function () {
    $uploadReq = null;
    $createReq = null;
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')->once()->andReturnUsing(function (UploadCertificateRequest $r) use (&$uploadReq) {
        $uploadReq = $r;

        return tseUploadResponse('gw-cert-1');
    });
    $tse = Mockery::mock(TseClient::class);
    $tse->shouldReceive('CreateCloudNativeAPIGatewayCertificate')->once()->andReturnUsing(function (CreateCloudNativeAPIGatewayCertificateRequest $r) use (&$createReq) {
        $createReq = $r;

        return new CreateCloudNativeAPIGatewayCertificateResponse;
    });

    $deployer = tencentTseDeployerWith(fn (string $kind) => $kind === 'ssl' ? $ssl : $tse);
    $deployer->bind(tseCertRef(), ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'region' => 'ap-guangzhou', 'gateway_id' => 'gw-1', 'domains' => 'a.example.com,b.example.com',
    ]);

    expect($createReq->GatewayId)->toBe('gw-1');
    expect($createReq->CertId)->toBe('gw-cert-1');
    expect($createReq->BindDomains)->toBe(['a.example.com', 'b.example.com']);
    expect($createReq->Name)->toStartWith('clouddeploy_');
    // 上传走完整链
    expect($uploadReq->CertificatePublicKey)->toContain('CERTPEM')->toContain('CHAINPEM');
});

test('新建路径 domains 留空时取证书 SAN 作 BindDomains', function () {
    $createReq = null;
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')->andReturn(tseUploadResponse('gw-cert-1'));
    $tse = Mockery::mock(TseClient::class);
    $tse->shouldReceive('CreateCloudNativeAPIGatewayCertificate')->andReturnUsing(function (CreateCloudNativeAPIGatewayCertificateRequest $r) use (&$createReq) {
        $createReq = $r;

        return new CreateCloudNativeAPIGatewayCertificateResponse;
    });

    $deployer = tencentTseDeployerWith(fn (string $kind) => $kind === 'ssl' ? $ssl : $tse);
    $deployer->bind(tseCertRef(TSE_SAN_CERT), ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'region' => 'ap-guangzhou', 'gateway_id' => 'gw-1',
    ]);

    expect($createReq->BindDomains)->toBe(['tse.example.com', 'www.tse.example.com']);
});

test('新建路径查到网关已存在同 CertId 证书时不重复创建', function () {
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldReceive('UploadCertificate')->once()->andReturn(tseUploadResponse('gw-cert-1'));
    $tse = Mockery::mock(TseClient::class);
    $response = new DescribeCloudNativeAPIGatewayCertificatesResponse;
    $response->deserialize(['Result' => ['CertificatesList' => [[
        'Id' => 'gateway-cert-id', 'Name' => 'old-name', 'CertId' => 'gw-cert-1', 'Crt' => 'OLD',
    ]]], 'RequestId' => 'r']);
    $tse->shouldReceive('DescribeCloudNativeAPIGatewayCertificates')->once()
        ->andReturnUsing(function (DescribeCloudNativeAPIGatewayCertificatesRequest $request) use ($response) {
            expect($request->GatewayId)->toBe('gw-1');
            expect($request->Offset)->toBe(0);
            expect($request->Limit)->toBe(100);

            return $response;
        });
    $tse->shouldNotReceive('CreateCloudNativeAPIGatewayCertificate');

    tencentTseDeployerWith(fn (string $kind) => $kind === 'ssl' ? $ssl : $tse)->bind(
        tseCertRef(), ['secret_id' => 'AK', 'secret_key' => 'SK'],
        ['region' => 'ap-guangzhou', 'gateway_id' => 'gw-1'],
    );
});

test('更新路径（填 certificate_id）：ModifyCloudNativeAPIGatewayCertificate 直灌 Crt/Key + CertSource=native，不上传 SSL', function () {
    $modifyReq = null;
    $ssl = Mockery::mock(SslClient::class);
    $ssl->shouldNotReceive('UploadCertificate'); // 更新路径不上传
    $tse = Mockery::mock(TseClient::class);
    $tse->shouldReceive('ModifyCloudNativeAPIGatewayCertificate')->once()->andReturnUsing(function (ModifyCloudNativeAPIGatewayCertificateRequest $r) use (&$modifyReq) {
        $modifyReq = $r;

        return new ModifyCloudNativeAPIGatewayCertificateResponse;
    });

    $deployer = tencentTseDeployerWith(fn (string $kind) => $kind === 'ssl' ? $ssl : $tse);
    $deployer->bind(tseCertRef(), ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'region' => 'ap-guangzhou', 'gateway_id' => 'gw-1', 'certificate_id' => 'existing-cert-9',
    ]);

    expect($modifyReq->GatewayId)->toBe('gw-1');
    expect($modifyReq->Id)->toBe('existing-cert-9');
    expect($modifyReq->Crt)->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($modifyReq->Key)->toBe('KEYPEM');
    expect($modifyReq->CertSource)->toBe('native');
});

test('缺 region / gateway_id 抛业务错误', function () {
    $deployer = tencentTseDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind(tseCertRef(), ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'gateway_id' => 'gw-1',
    ]))->toThrow(RuntimeException::class, '缺少配置 region');

    expect(fn () => $deployer->bind(tseCertRef(), ['secret_id' => 'AK', 'secret_key' => 'SK'], [
        'region' => 'ap-guangzhou',
    ]))->toThrow(RuntimeException::class, '缺少配置 gateway_id');
});

test('更新路径 SDK 抛异常时脱敏重抛（无 AK/SK、不挂 previous）', function () {
    $tse = Mockery::mock(TseClient::class);
    $tse->shouldReceive('ModifyCloudNativeAPIGatewayCertificate')->andThrow(new TencentCloudSDKException('FailedOperation', 'modify failed', 'req-1'));

    $deployer = tencentTseDeployerWith(fn (string $kind) => $kind === 'tse' ? $tse : new stdClass);
    try {
        $deployer->bind(tseCertRef(), ['secret_id' => 'SECRET-ID-LEAK', 'secret_key' => 'SECRET-KEY-LEAK'], [
            'region' => 'ap-guangzhou', 'gateway_id' => 'gw-1', 'certificate_id' => 'c-9',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('FailedOperation');
        expect($e->getMessage())->not->toContain('SECRET-ID-LEAK')->not->toContain('SECRET-KEY-LEAK');
        expect($e->getPrevious())->toBeNull();
    }
});
