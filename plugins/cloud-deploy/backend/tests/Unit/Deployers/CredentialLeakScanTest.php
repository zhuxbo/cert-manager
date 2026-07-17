<?php

use AlibabaCloud\Oss\V2\Exception\OperationException as OssOperationException;
use AlibabaCloud\Oss\V2\Exception\ServiceException as OssServiceException;
use AlibabaCloud\Tea\Exception\TeaError;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunAlbDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunApigwDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunCasUploader;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunCdnDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunClbDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunDcdnDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunDdosproDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunEsaDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunFcDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunGaDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunLiveDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunNlbDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunOssDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunSlbUploader;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunVodDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunWafDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentCdnDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentClbDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentCosDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentCssDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentEcdnDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentEoDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentGaapDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentScfDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentSslDeployDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentSslUploader;
use Plugins\CloudDeploy\Deployers\Tencent\TencentVodDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentWafDeployer;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ssl\V20191205\Models\DeployCertificateInstanceResponse;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 凭证泄露系统性兜底扫描：遍历早期阿里/腾讯通用矩阵 + 3 uploader + poller 路径，给 SDK mock 注入
 * 含真实凭证子串的**对应真实异常类型**（阿里 TeaError/OSS ServiceException/OssOperationException；
 * 腾讯 TencentCloudSDKException），经 bind/upload 路径触发脱敏重抛，断言重抛异常的
 * getMessage() + getTraceAsString() **全无任一凭证子串**。
 *
 * 与各端点既有单测的「单点脱敏」分散覆盖互补——这里是阿里/腾讯 SDK 异常形态的统一矩阵兜底。
 * 其余端点由各自 provider 目录下的 mock 单测 + DeployerTestCoverageTest 承接。
 *
 * 注入的凭证子串覆盖阿里签名查询串（AccessKeyId/Signature）+ 腾讯 SecretId/SecretKey；证书私钥 PEM
 * 不入跨用例 needle 集（私钥是 bind 实参、必现于 trace 的参数列表，非脱敏失败，且威胁模型针对云账号凭证）。
 */

/** 阿里签名 URI 风格泄露：AccessKeyId(AKIA 字面量)/Signature 在查询串。 */
const LEAK_ALIYUN_URI = 'cURL error 7: Failed to connect; uri=https://x.aliyuncs.com/?AccessKeyId=AKIAEXAMPLE123456789&Signature=wJalrXUtnFEMIbcdEXAMPLEKEY&k=v';

/** 阿里 AccessKeyId 字面量（AKIA + 16 位），单独验证 scrubber pattern。 */
const LEAK_AK = 'AKIAEXAMPLE123456789';

/** 阿里签名值。 */
const LEAK_SIG = 'wJalrXUtnFEMIbcdEXAMPLEKEY';

/** 腾讯 SecretId/SecretKey 实参（也是 secret_ pattern 的素材）。 */
const LEAK_SECRET_ID = 'AKIDz8krbsJ5yKBZQpnEXAMPLE';

const LEAK_SECRET_KEY = 'wJalrXUtnFEMIK7MDENGbPxRfiCYEXAMPLEKEY';

/**
 * 全量凭证子串集：任一出现在重抛 message / trace 即判泄露。
 *
 * **不含证书私钥 PEM**：私钥是被部署的证书材料、作为 bind() 实参传入，PHP `getTraceAsString()`（启用
 * args 时）必然把它作为调用帧参数列出——这是输入参数、非脱敏失败，且威胁模型针对的是**云账号凭证**
 * （AK/SK/SecretId/SecretKey）泄露进日志，私钥本就已在 DB。PEM 出现在异常 **message** 里的脱敏由专门的
 * CredentialScrubberTest 覆盖。
 */
function allLeakNeedles(): array
{
    return [LEAK_AK, LEAK_SIG, LEAK_SECRET_ID, LEAK_SECRET_KEY, 'AccessKeyId=', 'Signature=', 'secret_id', 'secret_key'];
}

/** 断言重抛异常 message + trace 全无凭证子串，且不挂 previous（trace 不带含凭证的 SDK 帧）。 */
function assertSanitizedThrow(callable $fn): void
{
    try {
        $fn();
        expect(false)->toBeTrue('应抛异常');
    } catch (RuntimeException $e) {
        $haystack = $e->getMessage()."\n".$e->getTraceAsString();
        foreach (allLeakNeedles() as $needle) {
            expect($haystack)->not->toContain($needle);
        }
        expect($e->getPrevious())->toBeNull();
    }
}

/**
 * 每个 deployer 类 → 一个「makeClient 返回总是抛 $throw 的 mock」的真实子类工厂。
 * mock 用 shouldReceive(...)->andThrow，但因方法名各异，改用一个对任意方法都抛的 mock：
 * Mockery 无「任意方法抛」直接 API，故用 makeAlwaysThrowingClient() 包一层匿名对象。
 *
 * @return array<string, callable(Throwable):AbstractDeployer>
 */
function leakDeployerFactories(): array
{
    return [
        // ---- 阿里云：makeClient 2 参 ----
        'aliyun.cdn' => fn (Throwable $t) => new class($t) extends AliyunCdnDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials): object
            {
                return alwaysThrow($this->t);
            }
        },
        'aliyun.dcdn' => fn (Throwable $t) => new class($t) extends AliyunDcdnDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials): object
            {
                return alwaysThrow($this->t);
            }
        },
        'aliyun.live' => fn (Throwable $t) => new class($t) extends AliyunLiveDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials): object
            {
                return alwaysThrow($this->t);
            }
        },
        'aliyun.vod' => fn (Throwable $t) => new class($t) extends AliyunVodDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials): object
            {
                return alwaysThrow($this->t);
            }
        },
        'aliyun.ga' => fn (Throwable $t) => new class($t) extends AliyunGaDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials): object
            {
                return alwaysThrow($this->t);
            }
        },
        'aliyun.fc' => fn (Throwable $t) => new class($t) extends AliyunFcDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials): object
            {
                return alwaysThrow($this->t);
            }
        },
        'aliyun.apigw' => fn (Throwable $t) => new class($t) extends AliyunApigwDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials): object
            {
                return alwaysThrow($this->t);
            }
        },
        'aliyun.ddospro' => fn (Throwable $t) => new class($t) extends AliyunDdosproDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials): object
            {
                return alwaysThrow($this->t);
            }
        },
        'aliyun.esa' => fn (Throwable $t) => new class($t) extends AliyunEsaDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials): object
            {
                return alwaysThrow($this->t);
            }
        },
        // ---- 阿里云：makeClient 3 参（带 region）----
        'aliyun.alb' => fn (Throwable $t) => new class($t) extends AliyunAlbDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials, string $region = ''): object
            {
                return alwaysThrow($this->t);
            }
        },
        'aliyun.nlb' => fn (Throwable $t) => new class($t) extends AliyunNlbDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials, string $region = ''): object
            {
                return alwaysThrow($this->t);
            }
        },
        'aliyun.clb' => fn (Throwable $t) => new class($t) extends AliyunClbDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials, string $region = ''): object
            {
                return alwaysThrow($this->t);
            }
        },
        'aliyun.waf' => fn (Throwable $t) => new class($t) extends AliyunWafDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials, string $region = ''): object
            {
                return alwaysThrow($this->t);
            }
        },
        // OSS 单独（异常类型不同：OssServiceException / OssOperationException），在专属用例覆盖
        'aliyun.oss' => fn (Throwable $t) => new class($t) extends AliyunOssDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials): object
            {
                return alwaysThrow($this->t);
            }
        },
        // ---- 腾讯云：makeClient 2 参 ----
        'tencent.cdn' => fn (Throwable $t) => new class($t) extends TencentCdnDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials): object
            {
                return alwaysThrow($this->t);
            }
        },
        'tencent.ecdn' => fn (Throwable $t) => new class($t) extends TencentEcdnDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials): object
            {
                return alwaysThrow($this->t);
            }
        },
        'tencent.eo' => fn (Throwable $t) => new class($t) extends TencentEoDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials): object
            {
                return alwaysThrow($this->t);
            }
        },
        'tencent.css' => fn (Throwable $t) => new class($t) extends TencentCssDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials): object
            {
                return alwaysThrow($this->t);
            }
        },
        'tencent.vod' => fn (Throwable $t) => new class($t) extends TencentVodDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials): object
            {
                return alwaysThrow($this->t);
            }
        },
        'tencent.gaap' => fn (Throwable $t) => new class($t) extends TencentGaapDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials): object
            {
                return alwaysThrow($this->t);
            }
        },
        'tencent.cos' => fn (Throwable $t) => new class($t) extends TencentCosDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials): object
            {
                return alwaysThrow($this->t);
            }

            protected function sleep(int $seconds): void {}
        },
        'tencent.ssl-deploy' => fn (Throwable $t) => new class($t) extends TencentSslDeployDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials, string $region = ''): object
            {
                return alwaysThrow($this->t);
            }

            protected function sleep(int $seconds): void {}
        },
        // ---- 腾讯云：makeClient 3 参 ----
        'tencent.clb' => fn (Throwable $t) => new class($t) extends TencentClbDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials, string $region = ''): object
            {
                return alwaysThrow($this->t);
            }
        },
        'tencent.scf' => fn (Throwable $t) => new class($t) extends TencentScfDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials, string $region = ''): object
            {
                return alwaysThrow($this->t);
            }
        },
        'tencent.waf' => fn (Throwable $t) => new class($t) extends TencentWafDeployer
        {
            public function __construct(private Throwable $t) {}

            protected function makeClient(string $kind, array $credentials, string $region = ''): object
            {
                return alwaysThrow($this->t);
            }
        },
    ];
}

/**
 * 返回一个「任意方法调用都抛 $t」的对象（覆盖各 deployer 不同 SDK 方法名）。
 * 用 __call 而非 Mockery（Mockery 无「任意方法名」通配 shouldReceive），命中所有 SDK 调用。
 */
function alwaysThrow(Throwable $t): object
{
    return new class($t)
    {
        public function __construct(private Throwable $t) {}

        public function __call(string $name, array $args): mixed
        {
            throw $this->t;
        }
    };
}

/** 各 deployer 的合法 dummy config（全 schema key + 受约束字段给合法值）。 */
function leakDummyConfig(AbstractDeployer $deployer): array
{
    $overrides = ['service_type' => 'cloudnative']; // apigw gate（早于 SDK 调用）
    $config = [];
    foreach ($deployer->configSchema() as $field) {
        $key = $field['key'];
        $config[$key] = $overrides[$key]
            ?? (($field['type'] ?? 'string') === 'number' ? '443' : ('dummy_'.$key));
    }

    return $config;
}

/** 含真实凭证实参的两套凭证（阿里 + 腾讯）。 */
function leakCredentials(): array
{
    return [
        'access_key_id' => LEAK_AK, 'access_key_secret' => LEAK_SIG,
        'secret_id' => LEAK_SECRET_ID, 'secret_key' => LEAK_SECRET_KEY,
    ];
}

dataset('aliyunLeakFactories', fn () => array_map(
    fn ($f) => [$f],
    array_filter(leakDeployerFactories(), fn ($k) => str_starts_with($k, 'aliyun.') && $k !== 'aliyun.oss', ARRAY_FILTER_USE_KEY),
));

dataset('tencentLeakFactories', fn () => array_map(
    fn ($f) => [$f],
    array_filter(leakDeployerFactories(), fn ($k) => str_starts_with($k, 'tencent.'), ARRAY_FILTER_USE_KEY),
));

test('阿里云 deployer bind：SDK 抛网络 TeaError（message 含签名 URI）经脱敏重抛无凭证', function (callable $factory) {
    /** @var AbstractDeployer $deployer */
    $deployer = $factory(new TeaError([], LEAK_ALIYUN_URI));

    // 证书服务型收 remote_cert_id 字符串、内联型收 PEM 三元组（PEM 也注入泄露素材验证不漏）
    $certRef = $deployer->usesRemoteCertStore()
        ? '12345-cn-hangzhou'
        : ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];

    assertSanitizedThrow(fn () => $deployer->bind($certRef, leakCredentials(), leakDummyConfig($deployer)));
})->with('aliyunLeakFactories');

test('腾讯云 deployer bind：SDK 抛 TencentCloudSDKException（message 含凭证）经脱敏 + 兜底重抛无凭证', function (callable $factory) {
    // 腾讯 sanitizer 透传 SDK message：把凭证塞进 message，靠 CredentialScrubber 兜底 redact（纵深防御）
    $leakMsg = 'auth failed AccessKeyId=AKIAEXAMPLE123456789 Signature=wJalrXUtnFEMIbcdEXAMPLEKEY secret_id='.LEAK_SECRET_ID.' secret_key='.LEAK_SECRET_KEY;
    /** @var AbstractDeployer $deployer */
    $deployer = $factory(new TencentCloudSDKException('AuthFailure', $leakMsg, 'req-leak'));

    $certRef = $deployer->usesRemoteCertStore()
        ? 'cert-leak-id'
        : ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];

    assertSanitizedThrow(fn () => $deployer->bind($certRef, leakCredentials(), leakDummyConfig($deployer)));
})->with('tencentLeakFactories');

test('阿里云 OSS bind：OssServiceException（request_target/snapshot 含签名 URI）脱敏无凭证', function () {
    $factory = leakDeployerFactories()['aliyun.oss'];
    $leak = new OssServiceException([
        'status_code' => 403,
        'code' => 'AccessDenied',
        'message' => 'permission denied',
        'request_id' => 'req-oss',
        'request_target' => 'PUT https://b.oss-cn-hangzhou.aliyuncs.com/?cname&OSSAccessKeyId=AKIAEXAMPLE123456789&Signature=wJalrXUtnFEMIbcdEXAMPLEKEY',
        'snapshot' => 'OSSAccessKeyId=AKIAEXAMPLE123456789&Signature=wJalrXUtnFEMIbcdEXAMPLEKEY',
    ]);
    /** @var AbstractDeployer $deployer */
    $deployer = $factory($leak);

    assertSanitizedThrow(fn () => $deployer->bind(
        ['cert' => 'C', 'key' => 'KEYPEM', 'chain' => 'CH'],
        leakCredentials(),
        ['bucket' => 'b', 'domain' => 'oss.example.com', 'region' => 'cn-hangzhou'],
    ));
});

test('阿里云 OSS bind：OssOperationException（底层 Guzzle 链入 previous，message 含签名 URI）脱敏无凭证', function () {
    $factory = leakDeployerFactories()['aliyun.oss'];
    $guzzleLike = new RuntimeException('cURL error 7: connect oss.aliyuncs.com/?OSSAccessKeyId=AKIAEXAMPLE123456789&Signature=wJalrXUtnFEMIbcdEXAMPLEKEY');
    /** @var AbstractDeployer $deployer */
    $deployer = $factory(new OssOperationException('PutCname', $guzzleLike));

    assertSanitizedThrow(fn () => $deployer->bind(
        ['cert' => 'C', 'key' => 'KEYPEM', 'chain' => 'CH'],
        leakCredentials(),
        ['bucket' => 'b', 'domain' => 'oss.example.com', 'region' => 'cn-hangzhou'],
    ));
});

// ============ 上传器（CertUploader）泄露面 ============

test('阿里云 CAS 上传器 upload：SDK 抛网络 TeaError 经脱敏无凭证', function () {
    $cas = alwaysThrow(new TeaError([], LEAK_ALIYUN_URI));
    $uploader = new AliyunCasUploader(fn (array $cred): object => $cas);

    assertSanitizedThrow(fn () => $uploader->upload('CERT', 'KEYPEM', 'CHAIN', leakCredentials()));
});

test('阿里云 SLB 上传器 upload：SDK 抛网络 TeaError 经脱敏无凭证', function () {
    $slb = alwaysThrow(new TeaError([], LEAK_ALIYUN_URI));
    $uploader = new AliyunSlbUploader(fn (array $cred): object => $slb, 'cn-hangzhou');

    assertSanitizedThrow(fn () => $uploader->upload('CERT', 'KEYPEM', 'CHAIN', leakCredentials()));
});

test('腾讯 SSL 上传器 upload：SDK 抛 TencentCloudSDKException（message 含凭证）经脱敏 + 兜底无凭证', function () {
    $leakMsg = 'upload failed secret_id='.LEAK_SECRET_ID.' secret_key='.LEAK_SECRET_KEY.' AccessKeyId=AKIAEXAMPLE123456789';
    $ssl = alwaysThrow(new TencentCloudSDKException('InternalError', $leakMsg, 'req-up'));
    $uploader = new TencentSslUploader(fn (array $cred): object => $ssl);

    assertSanitizedThrow(fn () => $uploader->upload('CERT', 'KEYPEM', 'CHAIN', leakCredentials()));
});

// ============ 轮询器（DescribeHostDeployRecordDetail）泄露面 ============

test('腾讯 COS bind 轮询阶段 SDK 抛异常（message 含凭证）经脱敏 + 兜底无凭证', function () {
    // DeployCertificateInstance 成功返回 recordId，DescribeHostDeployRecordDetail（轮询）抛含凭证异常
    $leakMsg = 'describe failed secret_id='.LEAK_SECRET_ID.' AccessKeyId=AKIAEXAMPLE123456789 Signature=wJalrXUtnFEMIbcdEXAMPLEKEY';
    $ssl = Mockery::mock();
    $ssl->shouldReceive('DeployCertificateInstance')->andReturnUsing(function () {
        $resp = new DeployCertificateInstanceResponse;
        $resp->deserialize(['DeployRecordId' => 777, 'RequestId' => 'r']);

        return $resp;
    });
    $ssl->shouldReceive('DescribeHostDeployRecordDetail')->andThrow(new TencentCloudSDKException('InternalError', $leakMsg, 'req-poll'));

    $deployer = new class($ssl) extends TencentCosDeployer
    {
        public function __construct(private object $ssl) {}

        protected function makeClient(string $kind, array $credentials): object
        {
            return $this->ssl;
        }

        protected function sleep(int $seconds): void {}
    };

    assertSanitizedThrow(fn () => $deployer->bind('cert-leak', leakCredentials(), [
        'region' => 'ap-guangzhou', 'bucket' => 'b', 'domain' => 'd.example.com',
    ]));
});
