<?php

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailResponseBody;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateResponse;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateResponseBody;
use AlibabaCloud\SDK\ESA\V20240910\ESA;
use AlibabaCloud\SDK\ESA\V20240910\Models\SetCertificateRequest;
use AlibabaCloud\SDK\ESA\V20240910\Models\SetCertificateResponse;
use AlibabaCloud\Tea\Exception\TeaError;
use Darabonba\OpenApi\Exceptions\ClientException;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunEsaDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 测试子类：override makeClient 注入缝，按 $kind 返回 Mockery mock（cas/esa）。
 * uploader 经 certUploader() 复用同一 makeClient('cas')，故 mock cas 即覆盖上传路径。
 * makeClient('esa', $cred) 的 $cred 含 region（用于 endpoint，由 bind 透传）。
 */
function aliyunEsaDeployerWith(callable $clientFactory): AliyunEsaDeployer
{
    return new class($clientFactory) extends AliyunEsaDeployer
    {
        /** @var callable */
        public $seen;

        public function __construct(private $factory) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            if (isset($this->seen)) {
                ($this->seen)($kind, $credentials);
            }

            return ($this->factory)($kind, $credentials);
        }
    };
}

function esaCasUploadResponse(int $certId): UploadUserCertificateResponse
{
    return new UploadUserCertificateResponse(['body' => new UploadUserCertificateResponseBody(['certId' => $certId])]);
}

function esaCasDetailResponse(string $identifier): GetUserCertificateDetailResponse
{
    return new GetUserCertificateDetailResponse(['body' => new GetUserCertificateDetailResponseBody([
        'certIdentifier' => $identifier,
    ])]);
}

test('阿里云 ESA 走证书服务（CAS）+ 基本元信息 + configSchema 覆盖 bind 读取键', function () {
    $deployer = new AliyunEsaDeployer;
    expect($deployer->usesRemoteCertStore())->toBeTrue();
    expect($deployer->certUploader())->not->toBeNull();
    expect($deployer->certUploader()->storeKind())->toBe('cas');
    expect($deployer->provider())->toBe('aliyun');
    expect($deployer->product())->toBe('esa');
    expect($deployer->label())->toBe('阿里云 ESA');
    expect(array_column($deployer->configSchema(), 'key'))->toContain('site_id')->toContain('region');
});

test('uploader.upload 复用 CAS：UploadUserCertificate + GetUserCertificateDetail 返回 CertIdentifier', function () {
    $uploadReq = null;
    $detailReq = null;
    $cas = Mockery::mock(Cas::class);
    $cas->shouldReceive('uploadUserCertificate')
        ->once()
        ->andReturnUsing(function (UploadUserCertificateRequest $req) use (&$uploadReq) {
            $uploadReq = $req;

            return esaCasUploadResponse(556677);
        });
    $cas->shouldReceive('getUserCertificateDetail')
        ->once()
        ->andReturnUsing(function (GetUserCertificateDetailRequest $req) use (&$detailReq) {
            $detailReq = $req;

            return esaCasDetailResponse('556677-cn-hangzhou');
        });

    $deployer = aliyunEsaDeployerWith(fn (string $kind) => $kind === 'cas' ? $cas : new stdClass);
    $id = $deployer->certUploader()->upload('CERTPEM', 'KEYPEM', 'CHAINPEM', ['access_key_id' => 'AK', 'access_key_secret' => 'SK']);

    expect($id)->toBe('556677-cn-hangzhou');
    expect($uploadReq->cert)->toContain('CERTPEM')->toContain('CHAINPEM');
    expect($uploadReq->key)->toBe('KEYPEM');
    expect($detailReq->certId)->toBe(556677);
});

test('bind 拆出数字 CertId 作 CasId 调 esa.SetCertificate（SiteId int + Type=cas + CasId int + Region）', function () {
    $captured = null;
    $esa = Mockery::mock(ESA::class);
    $esa->shouldReceive('setCertificate')
        ->once()
        ->andReturnUsing(function (SetCertificateRequest $req) use (&$captured) {
            $captured = $req;

            return new SetCertificateResponse;
        });

    $deployer = aliyunEsaDeployerWith(fn (string $kind) => $kind === 'esa' ? $esa : new stdClass);
    $deployer->bind('445566-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'site_id' => '123456',
        'region' => 'cn-hangzhou',
    ]);

    expect($captured->siteId)->toBe(123456);
    expect($captured->siteId)->toBeInt();
    expect($captured->type)->toBe('cas');
    // 关键：CasId 取 CertIdentifier 的**数字 certId 段**（不是整串、不是 region 段），int 类型（对齐 certimate esa）
    expect($captured->casId)->toBe(445566);
    expect($captured->casId)->toBeInt();
    expect($captured->region)->toBe('cn-hangzhou');
});

test('bind CertIdentifier 含多段 region（ap-southeast-1）仍只取首段数字作 CasId', function () {
    $captured = null;
    $esa = Mockery::mock(ESA::class);
    $esa->shouldReceive('setCertificate')->once()->andReturnUsing(function (SetCertificateRequest $req) use (&$captured) {
        $captured = $req;

        return new SetCertificateResponse;
    });

    $deployer = aliyunEsaDeployerWith(fn (string $kind) => $kind === 'esa' ? $esa : new stdClass);
    $deployer->bind('42-ap-southeast-1', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'site_id' => '7',
        'region' => 'ap-southeast-1',
    ]);

    expect($captured->casId)->toBe(42);
    expect($captured->siteId)->toBe(7);
});

test('bind region 同时透传进 esa client endpoint 与 SetCertificate.Region 入参', function () {
    $seenRegion = null;
    $captured = null;
    $esa = Mockery::mock(ESA::class);
    $esa->shouldReceive('setCertificate')->once()->andReturnUsing(function (SetCertificateRequest $req) use (&$captured) {
        $captured = $req;

        return new SetCertificateResponse;
    });

    $deployer = aliyunEsaDeployerWith(fn (string $kind) => $kind === 'esa' ? $esa : new stdClass);
    $deployer->seen = function (string $kind, array $cred) use (&$seenRegion) {
        if ($kind === 'esa') {
            $seenRegion = $cred['region'] ?? null;
        }
    };
    $deployer->bind('1-ap-southeast-1', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'site_id' => '5',
        'region' => 'ap-southeast-1',
    ]);

    expect($seenRegion)->toBe('ap-southeast-1');
    expect($captured->region)->toBe('ap-southeast-1');
});

test('bind 遇 Certificate.Duplicated 幂等成功（不抛、视作已配置）', function () {
    $esa = Mockery::mock(ESA::class);
    $esa->shouldReceive('setCertificate')->once()->andThrow(new ClientException([
        'statusCode' => 400,
        'code' => 'Certificate.Duplicated',
        'message' => 'code: 400, certificate already configured request id: REQ-DUP',
        'description' => '',
        'data' => ['Code' => 'Certificate.Duplicated', 'Message' => 'certificate already configured', 'RequestId' => 'REQ-DUP'],
        'accessDeniedDetail' => [],
        'requestId' => 'REQ-DUP',
    ]));

    $deployer = aliyunEsaDeployerWith(fn (string $kind) => $kind === 'esa' ? $esa : new stdClass);

    // 不应抛异常
    $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'site_id' => '9',
        'region' => 'cn-hangzhou',
    ]);

    expect(true)->toBeTrue();
});

test('bind 非 Duplicated 的 ClientException 仍脱敏重抛（不被幂等吞掉）', function () {
    $esa = Mockery::mock(ESA::class);
    $esa->shouldReceive('setCertificate')->andThrow(new ClientException([
        'statusCode' => 404,
        'code' => 'SiteNotFound',
        'message' => 'code: 404, site not found request id: REQ-X',
        'description' => '',
        'data' => ['Code' => 'SiteNotFound', 'Message' => 'site not found', 'RequestId' => 'REQ-X'],
        'accessDeniedDetail' => [],
        'requestId' => 'REQ-X',
    ]));

    $deployer = aliyunEsaDeployerWith(fn (string $kind) => $kind === 'esa' ? $esa : new stdClass);

    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-SECRET-XYZ', 'access_key_secret' => 'SK-SECRET-ABC'], [
            'site_id' => '9', 'region' => 'cn-hangzhou',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('SiteNotFound')->toContain('site not found');
        expect($e->getMessage())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
        expect($e->getPrevious())->toBeNull();
        expect($e->getTraceAsString())->not->toContain('AK-SECRET-XYZ')->not->toContain('SK-SECRET-ABC');
    }
});

test('缺 site_id 配置抛业务错误', function () {
    $deployer = aliyunEsaDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], ['region' => 'cn-hangzhou']))
        ->toThrow(RuntimeException::class, '缺少配置 site_id');
});

test('site_id 非数字抛业务错误（不静默 cast 成 0）', function () {
    $deployer = aliyunEsaDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'site_id' => 'not-a-number', 'region' => 'cn-hangzhou',
    ]))->toThrow(RuntimeException::class, 'site_id');
});

test('bind 收到无效 CertIdentifier 抛业务错误', function () {
    $deployer = aliyunEsaDeployerWith(fn () => new stdClass);
    expect(fn () => $deployer->bind('not-a-valid-id', ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], [
        'site_id' => '9', 'region' => 'cn-hangzhou',
    ]))->toThrow(RuntimeException::class, 'CertIdentifier');
});

test('bind SDK 抛网络类 TeaError（message 含签名 URI）时脱敏只暴露类名', function () {
    $esa = Mockery::mock(ESA::class);
    $esa->shouldReceive('setCertificate')->andThrow(new TeaError(
        [],
        'cURL error 7: Failed to connect https://esa.cn-hangzhou.aliyuncs.com/?AccessKeyId=AK-LEAK-9999&Signature=SIG-LEAK',
        0,
    ));

    $deployer = aliyunEsaDeployerWith(fn (string $kind) => $kind === 'esa' ? $esa : new stdClass);

    try {
        $deployer->bind('1-cn-hangzhou', ['access_key_id' => 'AK-LEAK-9999', 'access_key_secret' => 'SK'], [
            'site_id' => '9', 'region' => 'cn-hangzhou',
        ]);
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('AK-LEAK-9999')->not->toContain('SIG-LEAK');
        expect($e->getMessage())->toContain('阿里云调用失败');
        expect($e->getPrevious())->toBeNull();
    }
});
