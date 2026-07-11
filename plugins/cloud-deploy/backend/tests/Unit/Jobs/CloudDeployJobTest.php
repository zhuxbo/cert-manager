<?php

use AlibabaCloud\Tea\Exception\TeaError;
use App\Models\Cert;
use App\Models\Chain;
use App\Models\Order;
use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use Darabonba\OpenApi\Exceptions\ClientException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunErrorSanitizer;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\DeployBusinessException;
use Plugins\CloudDeploy\Deployers\Contracts\DeployPollPendingException;
use Plugins\CloudDeploy\Deployers\Contracts\ResumesRemoteJob;
use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Deployers\Tencent\TencentErrorSanitizer;
use Plugins\CloudDeploy\Jobs\CloudDeployJob;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployLog;
use Plugins\CloudDeploy\Models\CloudDeployRemoteCert;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Plugins\CloudDeploy\Notifications\CloudDeployFailedNotificationBuilder;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * 编排测试：用 fake deployer 替换容器内 Registry 单例，记录 bind/upload 调用、不触网。
 * SDK 真实绑定/上传由各 deployer 的 SDK-mock 单测覆盖；此处只验 Job 编排逻辑。
 */

// 调用记录（每个测试 beforeEach 重置）
final class CloudDeployJobTestSpy
{
    /** @var list<array{cert:string|array,config:array}> */
    public static array $binds = [];

    /** @var list<array{cert:string,key:string,chain:string}> */
    public static array $uploads = [];

    /** @var list<string> resumePoll 收到的 jobId（G2 续查） */
    public static array $resumes = [];

    public static function reset(): void
    {
        self::$binds = [];
        self::$uploads = [];
        self::$resumes = [];
    }
}

/** 内联型 fake deployer（aliyun cdn）：bind 记录、不触网。 */
function jobFakeInlineDeployer(): AbstractDeployer
{
    return new class extends AbstractDeployer
    {
        public function provider(): string
        {
            return 'aliyun';
        }

        public function product(): string
        {
            return 'cdn';
        }

        public function label(): string
        {
            return '阿里云 CDN';
        }

        public function configSchema(): array
        {
            return [['key' => 'domain', 'label' => '域名', 'required' => true]];
        }

        public function bind(string|array $certRef, array $credentials, array $config): void
        {
            CloudDeployJobTestSpy::$binds[] = ['cert' => $certRef, 'config' => $config];
        }

        protected function makeClient(string $kind, array $credentials): object
        {
            throw new RuntimeException('fake 不应造真实 client');
        }

        protected function sanitize(Throwable $e): string
        {
            return 'x';
        }
    };
}

/** 证书服务型 fake deployer（tencent cdn）：certUploader 记录上传、bind 记录、不触网。 */
function jobFakeRemoteStoreDeployer(string $returnId = 'cert-fake'): AbstractDeployer
{
    return new class($returnId) extends AbstractDeployer
    {
        public function __construct(private string $rid) {}

        public function provider(): string
        {
            return 'tencent';
        }

        public function product(): string
        {
            return 'cdn';
        }

        public function label(): string
        {
            return '腾讯云 CDN';
        }

        public function configSchema(): array
        {
            return [['key' => 'domain', 'label' => '域名', 'required' => true]];
        }

        public function usesRemoteCertStore(): bool
        {
            return true;
        }

        public function certUploader(array $config = []): ?CertUploaderInterface
        {
            $rid = $this->rid;

            return new class($rid) implements CertUploaderInterface
            {
                public function __construct(private string $rid) {}

                public function storeKind(): string
                {
                    return 'tencent_ssl';
                }

                public function upload(string $c, string $k, string $ch, array $cred): string
                {
                    CloudDeployJobTestSpy::$uploads[] = ['cert' => $c, 'key' => $k, 'chain' => $ch];

                    return $this->rid;
                }
            };
        }

        public function bind(string|array $certRef, array $credentials, array $config): void
        {
            CloudDeployJobTestSpy::$binds[] = ['cert' => $certRef, 'config' => $config];
        }

        protected function makeClient(string $kind, array $credentials): object
        {
            throw new RuntimeException('fake 不应造真实 client');
        }

        protected function sanitize(Throwable $e): string
        {
            return 'x';
        }
    };
}

/** 替换容器内 Registry 单例，注册指定的 fake deployer。 */
function bindFakeRegistry(string $provider, string $product, callable $deployerFactory): void
{
    $registry = new Registry;
    $registry->registerDeployer($provider, $product, $deployerFactory);
    app()->instance(Registry::class, $registry);
}

beforeEach(function () {
    CloudDeployJobTestSpy::reset();
    // failed() 会派失败通知；默认 mock NotificationCenter 避免真跑（撞被 mock 的 Log facade / 真发邮件）。
    // 需验证派发的测试自行 app()->instance 覆盖为带断言的 mock。
    app()->instance(NotificationCenter::class, Mockery::mock(NotificationCenter::class)->shouldIgnoreMissing());
});

function makeTargetWithCert(string $provider, string $product, ?string $intermediate = 'CHAIN', array $credentials = ['k' => 'v']): array
{
    $user = User::factory()->create();
    $access = CloudDeployAccess::create(['user_id' => $user->id, 'name' => 'acc', 'provider' => $provider, 'credentials' => $credentials]);
    $order = Order::factory()->create(['user_id' => $user->id]);

    // intermediate_cert 经 Chain 表 accessor：需 issuer 非空 + Chain 行；intermediate=null 模拟缺链 fail closed
    $issuer = 'TEST-R3-CA';
    if ($intermediate !== null) {
        Chain::firstOrCreate(['common_name' => $issuer], ['intermediate_cert' => $intermediate]);
    }
    $cert = Cert::factory()->create([
        'order_id' => $order->id, 'status' => 'active', 'issuer' => $issuer,
        'cert' => 'CERTPEM', 'private_key' => 'KEYPEM', 'fingerprint' => 'FP1',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);
    $target = CloudDeployTarget::create([
        'user_id' => $user->id, 'access_id' => $access->id, 'order_id' => $order->id,
        'product' => $product, 'config' => ['domain' => 'cdn.example.com'],
    ]);

    return [$target, $cert, $access, $user];
}

test('内联型直传成功，更新 target + 写 success log + bind 收到 PEM 三元组', function () {
    bindFakeRegistry('aliyun', 'cdn', fn () => jobFakeInlineDeployer());
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle();

    $target->refresh();
    expect($target->last_status)->toBe('success');
    expect($target->last_cert_id)->toBe($cert->id);
    expect(CloudDeployLog::where('target_id', $target->id)->where('status', 'success')->where('is_final', true)->exists())->toBeTrue();
    // 内联型：bind 收到 cert/key/chain 三元组，无上传
    expect(CloudDeployJobTestSpy::$uploads)->toBeEmpty();
    expect(CloudDeployJobTestSpy::$binds)->toHaveCount(1);
    expect(CloudDeployJobTestSpy::$binds[0]['cert'])->toMatchArray(['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAIN']);
});

test('证书服务型走证书服务，落 remote_cert + bind 收到 remote_cert_id', function () {
    bindFakeRegistry('tencent', 'cdn', fn () => jobFakeRemoteStoreDeployer('cert-t'));
    [$target, $cert] = makeTargetWithCert('tencent', 'cdn');

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle();

    $target->refresh();
    expect($target->last_status)->toBe('success');
    // 落库去重表（store_kind=tencent_ssl）
    expect(CloudDeployRemoteCert::where('access_id', $target->access_id)->where('store_kind', 'tencent_ssl')->where('fingerprint', 'FP1')->value('remote_cert_id'))->toBe('cert-t');
    // 上传一次 + bind 收到 id 而非 PEM
    expect(CloudDeployJobTestSpy::$uploads)->toHaveCount(1);
    expect(CloudDeployJobTestSpy::$binds[0]['cert'])->toBe('cert-t');
});

test('缺中间证书 fail closed：不推、记 missing_chain、target failed、不调 deployer', function () {
    bindFakeRegistry('aliyun', 'cdn', fn () => jobFakeInlineDeployer());
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn', intermediate: null);

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle();

    $target->refresh();
    expect($target->last_status)->toBe('failed');
    expect(CloudDeployLog::where('target_id', $target->id)->where('error_code', 'missing_chain')->exists())->toBeTrue();
    expect($target->last_cert_id)->toBe($cert->id); // 前置拒绝标记已处理（配合 sweep 不重扫）
    expect(CloudDeployJobTestSpy::$binds)->toBeEmpty();
});

test('target 已删（加载不到）→ 写 skip log 退出，不抛', function () {
    bindFakeRegistry('aliyun', 'cdn', fn () => jobFakeInlineDeployer());
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');
    $tid = $target->id;
    $target->delete();

    (new CloudDeployJob($tid, $cert->id, 'auto'))->handle();

    expect(CloudDeployLog::where('target_id', $tid)->where('error_code', 'target_missing')->exists())->toBeTrue();
});

test('Job 层第 4 处租户校验：order 属他人 → 记 tenant_mismatch、不推（异步无 UserScope 的纵深防御）', function () {
    bindFakeRegistry('aliyun', 'cdn', fn () => jobFakeInlineDeployer());
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $access = CloudDeployAccess::create(['user_id' => $userA->id, 'name' => 'a', 'provider' => 'aliyun', 'credentials' => ['k' => 'v']]);
    $orderB = Order::factory()->create(['user_id' => $userB->id]);
    Chain::firstOrCreate(['common_name' => 'TEST-R3-CA'], ['intermediate_cert' => 'CHAIN']);
    $cert = Cert::factory()->create(['order_id' => $orderB->id, 'status' => 'active', 'issuer' => 'TEST-R3-CA', 'cert' => 'C', 'private_key' => 'K', 'fingerprint' => 'FPX']);
    // target 漂移：user_id=A、access=A，但 order=B（属 B）
    $target = CloudDeployTarget::create(['user_id' => $userA->id, 'access_id' => $access->id, 'order_id' => $orderB->id, 'product' => 'cdn', 'config' => ['domain' => 'x.example.com']]);

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle();

    expect(CloudDeployLog::where('target_id', $target->id)->where('error_code', 'tenant_mismatch')->exists())->toBeTrue();
    $target->refresh();
    expect($target->last_status)->not->toBe('success');
    expect(CloudDeployJobTestSpy::$binds)->toBeEmpty(); // 租户校验挡在部署前
});

test('failed() 重试耗尽写终态 is_final=true 失败日志（设计 §8.3）', function () {
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->failed(new RuntimeException('boom'));

    $target->refresh();
    expect($target->last_status)->toBe('failed');
    expect(CloudDeployLog::where('target_id', $target->id)->where('status', 'failed')->where('is_final', true)->exists())->toBeTrue();
});

test('Job 层幂等：已成功推过同证书则短路、不重复推', function () {
    bindFakeRegistry('aliyun', 'cdn', fn () => jobFakeInlineDeployer());
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');
    $target->update(['last_cert_id' => $cert->id, 'last_status' => 'success']);

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle();

    expect(CloudDeployJobTestSpy::$binds)->toBeEmpty(); // 幂等短路，未再推
});

test('force=true 绕过幂等强制重推', function () {
    bindFakeRegistry('aliyun', 'cdn', fn () => jobFakeInlineDeployer());
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');
    $target->update(['last_cert_id' => $cert->id, 'last_status' => 'success']);

    (new CloudDeployJob($target->id, $cert->id, 'manual', force: true))->handle();

    expect(CloudDeployJobTestSpy::$binds)->toHaveCount(1); // 强制重推
});

test('deployer 抛异常 → target failed + 重抛触发退避，错误信息入库不含敏感（脱敏在 deployer 层保证）', function () {
    bindFakeRegistry('aliyun', 'cdn', function () {
        return new class extends AbstractDeployer
        {
            public function provider(): string
            {
                return 'aliyun';
            }

            public function product(): string
            {
                return 'cdn';
            }

            public function label(): string
            {
                return 'x';
            }

            public function configSchema(): array
            {
                return [];
            }

            public function bind(string|array $certRef, array $credentials, array $config): void
            {
                throw new RuntimeException('[InvalidDomain] domain not found'); // 已脱敏的异常
            }

            protected function makeClient(string $kind, array $credentials): object
            {
                return new stdClass;
            }

            protected function sanitize(Throwable $e): string
            {
                return 'x';
            }
        };
    });
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');

    expect(fn () => (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle())
        ->toThrow(RuntimeException::class);

    $target->refresh();
    expect($target->last_status)->toBe('failed');
    expect($target->last_error)->toContain('InvalidDomain');
});

/*
|--------------------------------------------------------------------------
| 落库无凭证守门（不变量 pin）：SDK 异常含凭证（阿里签名 URI / 私钥块 / 厂商密钥）时，
| 经 AbstractDeployer::guardSdk → 真实 sanitizer → CloudDeployJob catch/failed 落库，
| 所有外部可见落点（target.last_error / cloud_deploy_logs.message / Log::error context）
| 一律不得带出凭证。固化 Task 1.1 的脱敏防线。
|
| 威胁模型差异（两厂商 SDK 把凭证放不同位置，故脱敏策略与本测试注入点不同）：
| - 阿里：网络错误时 SDK 把 Guzzle 异常 message 包进 TeaError，该 message 含完整签名请求 URI
|   （查询串带 AccessKeyId/Signature）→ AliyunErrorSanitizer 仅信 data 数组（响应体），其余只回类名。
|   故注入「message 含签名 URI」验证它被丢弃。
| - 腾讯：TC3 签名把凭证放 Authorization 请求头（非 URL、非 body），SDK 异常 message 来自
|   响应体 Error.Message / Guzzle URI，均不含客户端 AK/SK；凭证仅存在于传入 deployer 的
|   credentials 数组。故注入点是 credentials（真实泄露面），断言其值不落库——与
|   TencentCdnDeployerTest 既定契约（message 透传、凭证实参不漏）在 Job 层对齐。
|--------------------------------------------------------------------------
*/

/**
 * 内联型 fake deployer：bind() 经真实 guardSdk 包裹后抛出 $leak，sanitize 委托真实 sanitizer
 * （aliyun→AliyunErrorSanitizer / tencent→TencentErrorSanitizer），与生产 deployer::bind 同路径。
 */
function jobLeakyInlineDeployer(string $vendor, Throwable $leak): AbstractDeployer
{
    return new class($vendor, $leak) extends AbstractDeployer
    {
        public function __construct(private string $vendor, private Throwable $leak) {}

        public function provider(): string
        {
            return $this->vendor;
        }

        public function product(): string
        {
            return 'cdn';
        }

        public function label(): string
        {
            return 'x';
        }

        public function configSchema(): array
        {
            return [['key' => 'domain', 'label' => '域名', 'required' => true]];
        }

        public function bind(string|array $certRef, array $credentials, array $config): void
        {
            // 镜像 AliyunCdnDeployer::bind：SDK 调用裹在 guardSdk 内，异常走真实脱敏
            $leak = $this->leak;
            $this->guardSdk(function () use ($leak) {
                throw $leak;
            });
        }

        protected function makeClient(string $kind, array $credentials): object
        {
            return new stdClass;
        }

        protected function sanitize(Throwable $e): string
        {
            return $this->vendor === 'aliyun'
                ? AliyunErrorSanitizer::sanitize($e)
                : TencentErrorSanitizer::sanitize($e);
        }
    };
}

/**
 * 证书服务型 fake deployer：certUploader()->upload() 经真实 TencentErrorSanitizer 脱敏后抛 $leak，
 * 覆盖「上传环节」泄露面（RemoteCertStore::ensure → upload）。
 */
function jobLeakyUploadDeployer(Throwable $leak): AbstractDeployer
{
    return new class($leak) extends AbstractDeployer
    {
        public function __construct(private Throwable $leak) {}

        public function provider(): string
        {
            return 'tencent';
        }

        public function product(): string
        {
            return 'cdn';
        }

        public function label(): string
        {
            return 'x';
        }

        public function configSchema(): array
        {
            return [['key' => 'domain', 'label' => '域名', 'required' => true]];
        }

        public function usesRemoteCertStore(): bool
        {
            return true;
        }

        public function certUploader(array $config = []): ?CertUploaderInterface
        {
            $leak = $this->leak;

            return new class($leak) implements CertUploaderInterface
            {
                public function __construct(private Throwable $leak) {}

                public function storeKind(): string
                {
                    return 'tencent_ssl';
                }

                public function upload(string $c, string $k, string $ch, array $cred): string
                {
                    // 镜像 TencentSslUploader::upload 的 catch：原始异常经真实 sanitizer 后重抛干净异常
                    try {
                        throw $this->leak;
                    } catch (Throwable $e) {
                        throw new RuntimeException(TencentErrorSanitizer::sanitize($e), 0);
                    }
                }
            };
        }

        public function bind(string|array $certRef, array $credentials, array $config): void
        {
            // 不会走到：upload 先抛
        }

        protected function makeClient(string $kind, array $credentials): object
        {
            return new stdClass;
        }

        protected function sanitize(Throwable $e): string
        {
            return TencentErrorSanitizer::sanitize($e);
        }
    };
}

/**
 * 断言指定 target 的所有外部可见落点（target.last_error + 全部 cloud_deploy_logs.message +
 * 传入的 Log::error 实参）都不含任一 $needles 凭证子串；并反向自检确实落了库（否则断言空跑）。
 *
 * @param  list<string>  $needles  本场景的凭证子串集（按厂商威胁模型不同）
 * @param  list<mixed>  $logContexts  额外参与守门的 Log::error 实参 / 捕获文本
 */
function expectNoCredentialLeak(int $targetId, array $needles, array $logContexts = []): void
{
    $lastError = (string) (CloudDeployTarget::withoutGlobalScopes()->find($targetId)?->last_error ?? '');
    $logMessages = CloudDeployLog::where('target_id', $targetId)->pluck('message')->implode("\n");
    $haystack = $lastError."\n".$logMessages."\n".json_encode($logContexts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    foreach ($needles as $needle) {
        expect($haystack)->not->toContain($needle);
    }
    // 反向自检：确实落了库（last_error 非空），否则断言形同虚设
    expect($lastError)->not->toBe('');
}

test('落库无凭证（阿里网络错误，TeaError.data=null，message 含签名 URI）：last_error + 日志 + Log context 均脱敏', function () {
    // 阿里网络错误路径：SDK 把 Guzzle 异常 message（含完整签名请求 URI）包进 TeaError，data 为 null。
    // AliyunErrorSanitizer 对 data 非数组路径只回类名，绝不回传 getMessage()。
    $leakyUri = 'cURL error 7: Failed to connect; uri=https://cdn.aliyuncs.com/?Action=SetCdnDomainSSLCertificate&AccessKeyId=TCLOUD_TEST_SECRET_ID&Signature=TCLOUD_TEST_SIGNATURE';
    $leak = new TeaError([], $leakyUri);

    bindFakeRegistry('aliyun', 'cdn', fn () => jobLeakyInlineDeployer('aliyun', $leak));
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');

    // 提前装好捕获：failed() 里 Log::error($msg, ['target'=>,'cert'=>,'message'=>$msg]) 的实参全收进 $logCalls
    $logCalls = [];
    Log::shouldReceive('error')->andReturnUsing(function (...$args) use (&$logCalls) {
        $logCalls[] = $args;
    });

    $captured = [];
    // handle() 抛 → failed() 写终态行 + Log::error；模拟 worker 重试耗尽链路
    $job = new CloudDeployJob($target->id, $cert->id, 'auto');
    try {
        $job->handle();
    } catch (Throwable $e) {
        $captured['handle_rethrow'] = $e->getMessage();
        $job->failed($e); // 重试耗尽：写 is_final 终态 + Log::error
    }

    $target->refresh();
    expect($target->last_status)->toBe('failed');
    // 脱敏后只剩类名文案
    expect($target->last_error)->toContain('阿里云调用失败');
    // 证 failed() 确实记了日志（否则 context 守门是空跑）
    expect($logCalls)->not->toBeEmpty();

    // last_error + 所有日志行 message + Log::error(message+context) 全部参与守门：签名 URI 子串一个不许漏
    expectNoCredentialLeak(
        $target->id,
        needles: ['TCLOUD_TEST_SECRET_ID', 'AccessKeyId', 'Signature=', 'TCLOUD_TEST_SIGNATURE'],
        logContexts: [$captured, $logCalls],
    );
});

test('落库无凭证（阿里结构化 API 错误，data 数组）：仅 Code+Message 入库，不回传 message 内任何注入凭证', function () {
    // 结构化错误路径：data 是响应体。即便上游响应 Message 里混入了凭证样子的串，
    // sanitizer 取的是 data['Message']（响应体，正常不含凭证），原始 getMessage() 不回传。
    // 这里把凭证塞进 getMessage()（模拟 SDK 异常 message 含签名）验证它不被落库。
    $leak = new TeaError([
        'code' => 'InvalidDomain.NotFound',
        'message' => 'AccessKeyId=TCLOUD_TEST_SECRET_ID Signature=TCLOUD_TEST_SIGNATURE', // getMessage()——必须被丢弃
        'data' => ['Code' => 'InvalidDomain.NotFound', 'Message' => '域名不存在', 'RequestId' => 'req-123'],
    ]);

    bindFakeRegistry('aliyun', 'cdn', fn () => jobLeakyInlineDeployer('aliyun', $leak));
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');

    $job = new CloudDeployJob($target->id, $cert->id, 'auto');
    try {
        $job->handle();
    } catch (Throwable $e) {
        $job->failed($e);
    }

    $target->refresh();
    // 安全文案：[code] + 响应体 Message
    expect($target->last_error)->toContain('InvalidDomain.NotFound');
    expect($target->last_error)->toContain('域名不存在');
    // getMessage() 里注入的签名串被丢弃（只取 data['Message']）
    expectNoCredentialLeak($target->id, needles: ['TCLOUD_TEST_SECRET_ID', 'AccessKeyId', 'Signature=', 'TCLOUD_TEST_SIGNATURE']);
});

test('落库无凭证（阿里新一代 openapi-core AlibabaCloudException）：仅 Code+Message 入库，getMessage 内注入凭证被丢弃', function () {
    // 新一代 openapi-core 结构化 API 错误（apigw/esa/ddospro 4xx）→ ClientException（AlibabaCloudException 子类）。
    // sanitizer 走新增 AlibabaCloudException 分支：取 public $code + $data['Message']，绝不整段回传 getMessage()。
    // 这里把签名串塞进 getMessage()（message 字段）验证它不被落库——固化 Task 1 sanitizer 适配的 Job 落库防线。
    $leak = new ClientException([
        'statusCode' => 404,
        'code' => 'InvalidDomain.NotFound',
        'message' => 'AccessKeyId=TCLOUD_TEST_SECRET_ID Signature=TCLOUD_TEST_SIGNATURE', // getMessage()——必须被丢弃
        'description' => '',
        'data' => ['Code' => 'InvalidDomain.NotFound', 'Message' => '域名不存在', 'RequestId' => 'req-oc-1'],
        'accessDeniedDetail' => [],
        'requestId' => 'req-oc-1',
    ]);

    bindFakeRegistry('aliyun', 'cdn', fn () => jobLeakyInlineDeployer('aliyun', $leak));
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');

    $job = new CloudDeployJob($target->id, $cert->id, 'auto');
    try {
        $job->handle();
    } catch (Throwable $e) {
        $job->failed($e);
    }

    $target->refresh();
    // 安全文案：[code] + 响应体 Message（来自 public $code + $data['Message']）
    expect($target->last_error)->toContain('InvalidDomain.NotFound');
    expect($target->last_error)->toContain('域名不存在');
    // getMessage() 里注入的签名串被丢弃
    expectNoCredentialLeak($target->id, needles: ['TCLOUD_TEST_SECRET_ID', 'AccessKeyId', 'Signature=', 'TCLOUD_TEST_SIGNATURE']);
});

test('落库无凭证（腾讯 bind SDK 异常）：access.credentials 的 secret_id/secret_key 不落 last_error/日志', function () {
    // 腾讯威胁模型：凭证仅存在于传入 deployer 的 credentials 数组（TC3 放 Authorization 头，
    // 不入 URL/body，故 getMessage() 不含客户端 AK/SK）。注入真实 cred 值到 access，
    // SDK 抛 API 风格错误（message 为厂商错误描述），断言 cred 值不出现在任何落点。
    $leak = new TencentCloudSDKException('AuthFailure.SignatureFailure', '签名过期，请检查系统时间', 'req-tc-1');

    bindFakeRegistry('tencent', 'cdn', fn () => jobLeakyInlineDeployer('tencent', $leak));
    [$target, $cert] = makeTargetWithCert('tencent', 'cdn', credentials: [
        'secret_id' => 'TCLOUD_TEST_SECRET_ID',
        'secret_key' => 'TCLOUD_TEST_SECRET_KEY',
    ]);

    $job = new CloudDeployJob($target->id, $cert->id, 'auto');
    try {
        $job->handle();
    } catch (Throwable $e) {
        $job->failed($e);
    }

    $target->refresh();
    expect($target->last_status)->toBe('failed');
    // 厂商错误码 + 描述照常入库（既定契约：message 透传）
    expect($target->last_error)->toContain('AuthFailure.SignatureFailure');
    expect($target->last_error)->toContain('签名过期');
    // 但凭证实参（secret_id/secret_key 值）一个不许漏
    expectNoCredentialLeak($target->id, needles: ['TCLOUD_TEST_SECRET_ID', 'TCLOUD_TEST_SECRET_KEY']);
});

test('落库无凭证（证书服务上传环节 SDK 异常）：upload 经 TencentSslUploader 同款脱敏，cred 不落库', function () {
    // 上传环节（RemoteCertStore::ensure → certUploader->upload）抛 SDK 异常，
    // 经 TencentSslUploader 同款 catch→sanitize 后重抛干净异常；断言 access cred 不落 Job 任何落点。
    $leak = new TencentCloudSDKException('InternalError.UploadFailed', '证书服务内部错误', 'req-up-1');

    bindFakeRegistry('tencent', 'cdn', fn () => jobLeakyUploadDeployer($leak));
    [$target, $cert] = makeTargetWithCert('tencent', 'cdn', credentials: [
        'secret_id' => 'TCLOUD_TEST_SECRET_ID',
        'secret_key' => 'TCLOUD_TEST_SECRET_KEY',
    ]);

    $job = new CloudDeployJob($target->id, $cert->id, 'auto');
    try {
        $job->handle();
    } catch (Throwable $e) {
        $job->failed($e);
    }

    $target->refresh();
    expect($target->last_status)->toBe('failed');
    expect($target->last_error)->toContain('InternalError.UploadFailed');
    // 上传失败不应落 remote_cert 行（异常前置，ensure 未返回 id）
    expect(CloudDeployRemoteCert::where('access_id', $target->access_id)->exists())->toBeFalse();
    expectNoCredentialLeak($target->id, needles: ['TCLOUD_TEST_SECRET_ID', 'TCLOUD_TEST_SECRET_KEY']);
});

/** bind 抛指定异常的 fake deployer(走真实 catch 分流,不裹 guardSdk)。 */
function jobThrowingDeployer(Throwable $e): AbstractDeployer
{
    return new class($e) extends AbstractDeployer
    {
        public function __construct(private Throwable $e) {}

        public function provider(): string
        {
            return 'aliyun';
        }

        public function product(): string
        {
            return 'cdn';
        }

        public function label(): string
        {
            return 'x';
        }

        public function configSchema(): array
        {
            return [];
        }

        public function bind(string|array $certRef, array $credentials, array $config): void
        {
            throw $this->e;
        }

        protected function makeClient(string $kind, array $credentials): object
        {
            return new stdClass;
        }

        protected function sanitize(Throwable $e): string
        {
            return 'x';
        }
    };
}

test('DeployBusinessException → 不 rethrow、is_final、business_error、不重试', function () {
    bindFakeRegistry('aliyun', 'cdn', fn () => jobThrowingDeployer(new DeployBusinessException('缺少配置 domain')));
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle();

    $target->refresh();
    expect($target->last_status)->toBe('failed');
    expect(CloudDeployLog::where('target_id', $target->id)
        ->where('error_code', 'business_error')->where('is_final', true)->exists())->toBeTrue();
});

test('普通 Throwable → rethrow 触发退避(瞬态,is_final=false)', function () {
    bindFakeRegistry('aliyun', 'cdn', fn () => jobThrowingDeployer(new RuntimeException('[Timeout] upstream 5xx')));
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');

    expect(fn () => (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle())
        ->toThrow(RuntimeException::class);

    expect(CloudDeployLog::where('target_id', $target->id)
        ->where('error_code', 'deploy_error')->where('is_final', false)->exists())->toBeTrue();
    // M1 修复：瞬态失败也标记 last_cert_id，让重试耗尽后 sweep 走条件 B（7 天节流）而非每天重扫
    expect($target->fresh()->last_cert_id)->toBe($cert->id);
});

test('failed() 重试耗尽设 last_cert_id（走 sweep 条件 B 7 天节流，不每天扫）', function () {
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');
    $target->update(['last_cert_id' => null]); // 模拟从未成功推过

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->failed(new RuntimeException('boom'));

    expect($target->fresh()->last_cert_id)->toBe($cert->id);
});

test('缺私钥 → missing_private_key、is_final、设 last_cert_id、不推', function () {
    bindFakeRegistry('aliyun', 'cdn', fn () => jobFakeInlineDeployer());
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');
    $cert->update(['private_key' => '']); // 用户自带 CSR：无私钥

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle();

    $target->refresh();
    expect($target->last_status)->toBe('failed');
    expect($target->last_cert_id)->toBe($cert->id);
    expect(CloudDeployLog::where('target_id', $target->id)->where('error_code', 'missing_private_key')->where('is_final', true)->exists())->toBeTrue();
    expect(CloudDeployJobTestSpy::$binds)->toBeEmpty();
});

test('SM2 证书 → unsupported_algorithm、is_final、设 last_cert_id、不推', function () {
    bindFakeRegistry('aliyun', 'cdn', fn () => jobFakeInlineDeployer());
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');
    $cert->update(['encryption_alg' => 'SM2']); // 大小写无关（strtolower）

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle();

    $target->refresh();
    expect($target->last_cert_id)->toBe($cert->id);
    expect(CloudDeployLog::where('target_id', $target->id)->where('error_code', 'unsupported_algorithm')->exists())->toBeTrue();
    expect(CloudDeployJobTestSpy::$binds)->toBeEmpty();
});

test('failed() 重试耗尽派 cloud_deploy_failed 通知，context 仅白名单', function () {
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');

    $spy = Mockery::mock(NotificationCenter::class);
    $spy->shouldReceive('dispatch')->once()->withArgs(function (NotificationIntent $intent) use ($target) {
        return $intent->code === 'cloud_deploy_failed'
            && $intent->notifiableType === 'user'
            && $intent->notifiableId === (int) $target->user_id
            && ($intent->context['error_code'] ?? null) === 'retries_exhausted'
            && ! array_key_exists('error', $intent->context);   // 白名单：无 message 原文
    });
    app()->instance(NotificationCenter::class, $spy);

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->failed(new RuntimeException('[InvalidDomain] x'));
});

test('ServiceProvider 注册了 cloud_deploy_failed 专用 Builder', function () {
    expect(config('notification.builders.cloud_deploy_failed'))
        ->toBe(CloudDeployFailedNotificationBuilder::class);
});

/*
|--------------------------------------------------------------------------
| G2：jobId 持久化 + resumePoll 续查（job-id 型 deployer 长轮询超窗）
| G4：failed() 补写 last_deployed_at + 不清 pending cache
| G5：业务终态失败发通知（复用 cloud_deploy_failed）+ sweep-B 复扫再发（有界）
|--------------------------------------------------------------------------
*/

/** ResumesRemoteJob fake deployer：bind 可抛 poll_pending；resumePoll 行为注入。 */
function jobResumableDeployer(?string $bindJobId, ?callable $resume = null): AbstractDeployer
{
    return new class($bindJobId, $resume) extends AbstractDeployer implements ResumesRemoteJob
    {
        public function __construct(private ?string $bindJobId, private $resume) {}

        public function provider(): string
        {
            return 'aliyun';
        }

        public function product(): string
        {
            return 'cdn';
        }

        public function label(): string
        {
            return 'x';
        }

        public function configSchema(): array
        {
            return [['key' => 'domain', 'label' => '域名', 'required' => true]];
        }

        public function bind(string|array $certRef, array $credentials, array $config): void
        {
            CloudDeployJobTestSpy::$binds[] = ['cert' => $certRef, 'config' => $config];
            if ($this->bindJobId !== null) {
                throw new DeployPollPendingException($this->bindJobId, '云端部署任务处理中');
            }
        }

        public function resumePoll(string $remoteJobId, array $credentials, array $config): void
        {
            CloudDeployJobTestSpy::$resumes[] = $remoteJobId;
            if ($this->resume !== null) {
                ($this->resume)($remoteJobId);
            }
        }

        protected function makeClient(string $kind, array $credentials): object
        {
            throw new RuntimeException('fake 不应造真实 client');
        }

        protected function sanitize(Throwable $e): string
        {
            return 'x';
        }
    };
}

test('G2 bind 抛 poll_pending → 写 Cache pending + target failed/last_deployed_at + log poll_pending(is_final=false) + 重抛', function () {
    bindFakeRegistry('aliyun', 'cdn', fn () => jobResumableDeployer('job-123'));
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');

    expect(fn () => (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle())
        ->toThrow(DeployPollPendingException::class);

    $pending = Cache::get("cloud-deploy:pending-job:$target->id");
    expect($pending)->toBeArray();
    expect($pending['job_id'])->toBe('job-123');
    expect($pending['cert_id'])->toBe($cert->id);
    expect($pending['product'])->toBe('cdn');

    $target->refresh();
    expect($target->last_status)->toBe('failed');
    expect($target->last_cert_id)->toBe($cert->id);
    expect($target->last_deployed_at)->not->toBeNull();
    expect(CloudDeployLog::where('target_id', $target->id)->where('error_code', 'poll_pending')->where('is_final', false)->exists())->toBeTrue();
});

test('G2 预置 pending → 走 resumePoll 续查（bind 不被调）；成功 → success + forget cache', function () {
    bindFakeRegistry('aliyun', 'cdn', fn () => jobResumableDeployer(bindJobId: null)); // bind 若被调不会抛，靠 spy 断言未调
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');
    Cache::put("cloud-deploy:pending-job:$target->id", ['cert_id' => $cert->id, 'product' => 'cdn', 'job_id' => 'job-9', 'remote_cert_id' => null], now()->addDays(10));

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle();

    expect(CloudDeployJobTestSpy::$resumes)->toBe(['job-9']); // 续查同一 jobId
    expect(CloudDeployJobTestSpy::$binds)->toBeEmpty();        // bind 未被调（不重建任务）
    expect(Cache::get("cloud-deploy:pending-job:$target->id"))->toBeNull(); // 收敛后 forget
    $target->refresh();
    expect($target->last_status)->toBe('success');
});

test('G2 resumePoll 抛 DeployBusinessException（云端终态失败）→ 终态 + forget + 派通知', function () {
    bindFakeRegistry('aliyun', 'cdn', fn () => jobResumableDeployer(
        bindJobId: null,
        resume: function () {
            throw new DeployBusinessException('云端部署任务失败');
        },
    ));
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');
    Cache::put("cloud-deploy:pending-job:$target->id", ['cert_id' => $cert->id, 'product' => 'cdn', 'job_id' => 'job-x', 'remote_cert_id' => null], now()->addDays(10));

    $spy = Mockery::mock(NotificationCenter::class);
    $spy->shouldReceive('dispatch')->once()->withArgs(fn (NotificationIntent $i) => $i->code === 'cloud_deploy_failed' && ($i->context['error_code'] ?? null) === 'business_error');
    app()->instance(NotificationCenter::class, $spy);

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle();

    expect(Cache::get("cloud-deploy:pending-job:$target->id"))->toBeNull(); // 业务终态清 pending
    $target->refresh();
    expect($target->last_status)->toBe('failed');
    expect(CloudDeployLog::where('target_id', $target->id)->where('error_code', 'business_error')->where('is_final', true)->exists())->toBeTrue();
});

test('G2 pending cert_id 不匹配（重签发换证）→ forget + 走正常 bind（绝不跨 cert 复用 jobId）', function () {
    bindFakeRegistry('aliyun', 'cdn', fn () => jobResumableDeployer(bindJobId: null));
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');
    // pending 属旧 cert（id+1 一定不等当前 cert）
    Cache::put("cloud-deploy:pending-job:$target->id", ['cert_id' => $cert->id + 1000, 'product' => 'cdn', 'job_id' => 'stale', 'remote_cert_id' => null], now()->addDays(10));

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle();

    expect(CloudDeployJobTestSpy::$resumes)->toBeEmpty(); // 不续查旧 jobId
    expect(CloudDeployJobTestSpy::$binds)->toHaveCount(1); // 走正常 bind
    $target->refresh();
    expect($target->last_status)->toBe('success');
});

test('G4：failed() 补写 last_deployed_at 且不清 pending cache（收敛链留给 sweep-B）', function () {
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');
    $target->update(['last_deployed_at' => null]);
    Cache::put("cloud-deploy:pending-job:$target->id", ['cert_id' => $cert->id, 'product' => 'cdn', 'job_id' => 'keep-me', 'remote_cert_id' => null], now()->addDays(10));

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->failed(new DeployPollPendingException('keep-me', '待确认'));

    $target->refresh();
    expect($target->last_status)->toBe('failed');
    expect($target->last_deployed_at)->not->toBeNull();                             // G4：补写
    expect(Cache::get("cloud-deploy:pending-job:$target->id"))->not->toBeNull();    // 不清 pending
    expect(CloudDeployLog::where('target_id', $target->id)->where('error_code', 'poll_pending')->where('is_final', true)->exists())->toBeTrue();
});

test('G5：业务终态失败（DeployBusinessException）→ 派一次 cloud_deploy_failed', function () {
    bindFakeRegistry('aliyun', 'cdn', fn () => jobThrowingDeployer(new DeployBusinessException('缺少配置 domain')));
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');

    $spy = Mockery::mock(NotificationCenter::class);
    $spy->shouldReceive('dispatch')->once()->withArgs(fn (NotificationIntent $i) => $i->code === 'cloud_deploy_failed'
        && ($i->context['error_code'] ?? null) === 'business_error'
        && ! array_key_exists('error', $i->context));
    app()->instance(NotificationCenter::class, $spy);

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle();
});

test('G5：missing_private_key / SM2 → 各派一次 cloud_deploy_failed（确定性业务码）', function () {
    bindFakeRegistry('aliyun', 'cdn', fn () => jobFakeInlineDeployer());
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');
    $cert->update(['private_key' => '']);

    $spy = Mockery::mock(NotificationCenter::class);
    $spy->shouldReceive('dispatch')->once()->withArgs(fn (NotificationIntent $i) => ($i->context['error_code'] ?? null) === 'missing_private_key');
    app()->instance(NotificationCenter::class, $spy);

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle();
});

test('G5：missing_chain → 不派通知（等 Backfill 补链，非终态）', function () {
    bindFakeRegistry('aliyun', 'cdn', fn () => jobFakeInlineDeployer());
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn', intermediate: null);

    $spy = Mockery::mock(NotificationCenter::class);
    $spy->shouldReceive('dispatch')->never();
    app()->instance(NotificationCenter::class, $spy);

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle();

    expect(CloudDeployLog::where('target_id', $target->id)->where('error_code', 'missing_chain')->exists())->toBeTrue();
});

test('G5：sweep-B 复扫重推同一确定性失败 → 再派一次（每次终态失败一封，有界）', function () {
    bindFakeRegistry('aliyun', 'cdn', fn () => jobThrowingDeployer(new DeployBusinessException('缺少配置 domain')));
    [$target, $cert] = makeTargetWithCert('aliyun', 'cdn');

    $count = 0;
    $spy = Mockery::mock(NotificationCenter::class);
    $spy->shouldReceive('dispatch')->andReturnUsing(function () use (&$count) {
        $count++;
    });
    app()->instance(NotificationCenter::class, $spy);

    (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle(); // 首扫
    (new CloudDeployJob($target->id, $cert->id, 'auto'))->handle(); // sweep-B 复扫重推

    expect($count)->toBe(2); // 每次终态失败发一次（非「仅一次」永久去重）
});
