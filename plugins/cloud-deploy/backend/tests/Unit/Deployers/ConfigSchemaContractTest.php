<?php

use Plugins\CloudDeploy\Deployers\Aliyun\AliyunAlbDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunApigwDeployer;
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
use Plugins\CloudDeploy\Deployers\Tencent\TencentVodDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentWafDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * configSchema ⊇ bind 实际读取键契约：遍历早期阿里/腾讯通用矩阵，给每个 deployer 喂 configSchema 全 key 的
 * dummy 值跑 bind，收集 AbstractDeployer::touchedConfigKeys()（requireConfig 累加），断言
 * **configSchema 声明的 key 集 ⊇ bind 经 requireConfig 实际读取的 key 集**。
 *
 * 防漂移（只挡「漏声明」）：
 *   - bind 读了某 key 但 configSchema 没声明 → 前端表单不渲染该字段 → 用户填不上 → bind 必 fail
 *     「缺少配置 X」。此测试断言 touched ⊆ schemaKeys，该情形直接红 →【发现即改代码补 schema】。
 *   - schema 声明却 bind 没读 → 可接受（纯展示/前置校验字段），不报错。
 *
 * 为何 try/catch 全包仍可靠：已逐一核实本矩阵 deployer 的 bind **所有 requireConfig 都在任何 SDK 调用之前**。
 * 故即便 generic mock 让 bind 在首个 SDK 调用处抛（响应 shape 不匹配 / 链式属性访问得 null 短路），
 * 此前的 requireConfig 已全部记账，touchedConfigKeys 完整。内联型 / 证书服务型（bind 直接收 dummy
 * remote_cert_id 字符串）/ 异步轮询型（recordId mock 返回 null → 触发即成功不轮询）/ get-then-update 型
 * （listDomains 返回 null → 业务 fail）一律能跑过「所有 requireConfig 已执行」的点。
 */

/**
 * 通用 SDK mock：任何方法调用都吞掉返回 null（shouldIgnoreMissing 默认）。
 * 让 bind 内 SDK 调用不抛 BadMethodCall，链式属性访问（->body 等）得 null 自然短路。
 */
function genericSdkMock(): object
{
    return Mockery::mock()->shouldIgnoreMissing();
}

/**
 * 每个具体 deployer 类 → 一个「仅 override makeClient 注入 generic mock（轮询型再 override sleep no-op）」的
 * 真实子类实例工厂。bind 走真实实现、requireConfig 真实记账，只把 SDK client 换成吞调用的 mock。
 * makeClient 有 2 参（多数）/ 3 参（带 region：alb/nlb/clb-aliyun/waf-aliyun/tencent clb/scf/waf）两种签名，
 * 各自匿名子类按真实父类签名 override（PHP 子类 override 须签名兼容）。
 *
 * @return array<string, callable():AbstractDeployer>
 */
function contractDeployerFactories(): array
{
    return [
        // ---- 阿里云：makeClient 2 参 ----
        'aliyun.cdn' => fn () => new class extends AliyunCdnDeployer
        {
            protected function makeClient(string $kind, array $credentials): object
            {
                return genericSdkMock();
            }
        },
        'aliyun.dcdn' => fn () => new class extends AliyunDcdnDeployer
        {
            protected function makeClient(string $kind, array $credentials): object
            {
                return genericSdkMock();
            }
        },
        'aliyun.live' => fn () => new class extends AliyunLiveDeployer
        {
            protected function makeClient(string $kind, array $credentials): object
            {
                return genericSdkMock();
            }
        },
        'aliyun.vod' => fn () => new class extends AliyunVodDeployer
        {
            protected function makeClient(string $kind, array $credentials): object
            {
                return genericSdkMock();
            }
        },
        'aliyun.ga' => fn () => new class extends AliyunGaDeployer
        {
            protected function makeClient(string $kind, array $credentials): object
            {
                return genericSdkMock();
            }
        },
        'aliyun.oss' => fn () => new class extends AliyunOssDeployer
        {
            protected function makeClient(string $kind, array $credentials): object
            {
                return genericSdkMock();
            }
        },
        'aliyun.fc' => fn () => new class extends AliyunFcDeployer
        {
            protected function makeClient(string $kind, array $credentials): object
            {
                return genericSdkMock();
            }
        },
        'aliyun.apigw' => fn () => new class extends AliyunApigwDeployer
        {
            protected function makeClient(string $kind, array $credentials): object
            {
                return genericSdkMock();
            }
        },
        'aliyun.ddospro' => fn () => new class extends AliyunDdosproDeployer
        {
            protected function makeClient(string $kind, array $credentials): object
            {
                return genericSdkMock();
            }
        },
        'aliyun.esa' => fn () => new class extends AliyunEsaDeployer
        {
            protected function makeClient(string $kind, array $credentials): object
            {
                return genericSdkMock();
            }
        },
        // ---- 阿里云：makeClient 3 参（带 region）----
        'aliyun.alb' => fn () => new class extends AliyunAlbDeployer
        {
            protected function makeClient(string $kind, array $credentials, string $region = ''): object
            {
                return genericSdkMock();
            }
        },
        'aliyun.nlb' => fn () => new class extends AliyunNlbDeployer
        {
            protected function makeClient(string $kind, array $credentials, string $region = ''): object
            {
                return genericSdkMock();
            }
        },
        'aliyun.clb' => fn () => new class extends AliyunClbDeployer
        {
            protected function makeClient(string $kind, array $credentials, string $region = ''): object
            {
                return genericSdkMock();
            }
        },
        'aliyun.waf' => fn () => new class extends AliyunWafDeployer
        {
            protected function makeClient(string $kind, array $credentials, string $region = ''): object
            {
                return genericSdkMock();
            }
        },
        // ---- 腾讯云：makeClient 2 参 ----
        'tencent.cdn' => fn () => new class extends TencentCdnDeployer
        {
            protected function makeClient(string $kind, array $credentials): object
            {
                return genericSdkMock();
            }
        },
        'tencent.ecdn' => fn () => new class extends TencentEcdnDeployer
        {
            protected function makeClient(string $kind, array $credentials): object
            {
                return genericSdkMock();
            }
        },
        'tencent.eo' => fn () => new class extends TencentEoDeployer
        {
            protected function makeClient(string $kind, array $credentials): object
            {
                return genericSdkMock();
            }
        },
        'tencent.css' => fn () => new class extends TencentCssDeployer
        {
            protected function makeClient(string $kind, array $credentials): object
            {
                return genericSdkMock();
            }
        },
        'tencent.vod' => fn () => new class extends TencentVodDeployer
        {
            protected function makeClient(string $kind, array $credentials): object
            {
                return genericSdkMock();
            }
        },
        'tencent.gaap' => fn () => new class extends TencentGaapDeployer
        {
            protected function makeClient(string $kind, array $credentials): object
            {
                return genericSdkMock();
            }
        },
        // 轮询型：再 override sleep no-op（mock recordId 返回 null → 实际不轮询，但稳妥起见仍置 no-op）
        'tencent.cos' => fn () => new class extends TencentCosDeployer
        {
            protected function makeClient(string $kind, array $credentials): object
            {
                return genericSdkMock();
            }

            protected function sleep(int $seconds): void {}
        },
        'tencent.ssl-deploy' => fn () => new class extends TencentSslDeployDeployer
        {
            protected function makeClient(string $kind, array $credentials, string $region = ''): object
            {
                return genericSdkMock();
            }

            protected function sleep(int $seconds): void {}
        },
        // ---- 腾讯云：makeClient 3 参（带 region）----
        'tencent.clb' => fn () => new class extends TencentClbDeployer
        {
            protected function makeClient(string $kind, array $credentials, string $region = ''): object
            {
                return genericSdkMock();
            }
        },
        'tencent.scf' => fn () => new class extends TencentScfDeployer
        {
            protected function makeClient(string $kind, array $credentials, string $region = ''): object
            {
                return genericSdkMock();
            }
        },
        'tencent.waf' => fn () => new class extends TencentWafDeployer
        {
            protected function makeClient(string $kind, array $credentials, string $region = ''): object
            {
                return genericSdkMock();
            }
        },
    ];
}

/**
 * 个别字段是「枚举/受约束值」，在 requireConfig 之前就被业务 gate 校验，generic dummy 串会被早早拒掉
 * （在第一个 requireConfig 执行前抛 → touchedConfigKeys 收不到）。这类字段需给一个能过 gate 的合法值。
 * 仅 apigw.service_type 一例（bind 顶部 service_type gate 早于 requireConfig）。
 *
 * @return array<string, array<string,string>> label => [configKey => 合法 dummy 值]
 */
function contractConfigValueOverrides(): array
{
    return [
        // service_type gate（在 requireConfig 前）：必须是合法枚举值，否则 fail 早于任何 requireConfig
        'aliyun.apigw' => ['service_type' => 'cloudnative'],
    ];
}

/** 喂 configSchema 全 key 的 dummy 值跑 bind，返回 touchedConfigKeys。 */
function collectTouchedConfigKeys(AbstractDeployer $deployer): array
{
    $label = $deployer->provider().'.'.$deployer->product();
    $overrides = contractConfigValueOverrides()[$label] ?? [];

    $config = [];
    foreach ($deployer->configSchema() as $field) {
        $key = $field['key'];
        if (array_key_exists($key, $overrides)) {
            $config[$key] = $overrides[$key];

            continue;
        }
        // requireConfig 只判非空；number 型给数字字符串，其余给普通字符串
        $config[$key] = ($field['type'] ?? 'string') === 'number' ? '443' : ('dummy_'.$key);
    }

    // 两套凭证都给（阿里 access_key_* + 腾讯 secret_*），bind 不校验凭证 schema
    $credentials = [
        'access_key_id' => 'AK-DUMMY', 'access_key_secret' => 'SK-DUMMY',
        'secret_id' => 'SID-DUMMY', 'secret_key' => 'SKEY-DUMMY',
    ];

    // 证书服务型收 remote_cert_id 字符串（合法 CertIdentifier 形态过 dcdn/vod/esa 拆分）；内联型收 PEM 三元组
    $certRef = $deployer->usesRemoteCertStore()
        ? '12345-cn-hangzhou'
        : ['cert' => 'CERTPEM', 'key' => 'KEYPEM', 'chain' => 'CHAINPEM'];

    try {
        $deployer->bind($certRef, $credentials, $config);
    } catch (Throwable) {
        // 预期：generic mock 让 bind 在某 SDK 调用/解析处抛；所有 requireConfig 已在 SDK 调用前执行完。
    }

    return $deployer->touchedConfigKeys();
}

dataset('deployerFactories', fn () => array_map(fn ($f) => [$f], contractDeployerFactories()));

test('阿里/腾讯通用矩阵：configSchema 声明的 key 集 ⊇ bind 实际 requireConfig 读取的 key 集', function (callable $factory) {
    $deployer = $factory();

    $touched = collectTouchedConfigKeys($deployer);
    $schemaKeys = array_column($deployer->configSchema(), 'key');
    $label = $deployer->provider().'.'.$deployer->product();

    // bind 至少读到一个 config key（否则 mock 让 bind 提前退出、未驱动到 requireConfig）
    expect($touched)->not->toBe([], "$label bind 未读取任何 config key（mock 驱动异常？）");

    // 核心：实际读取的每个 key 都在 schema 声明（漏声明 = 用户填不上 = bind 必失败）
    $missing = array_values(array_diff($touched, $schemaKeys));
    expect($missing)->toBe([], "$label bind 读取了 configSchema 未声明的 key: ".implode(',', $missing));
})->with('deployerFactories');

test('阿里/腾讯通用矩阵：required 字段都被 bind 真实 requireConfig 消费（非僵尸声明）', function (callable $factory) {
    $deployer = $factory();

    $touched = collectTouchedConfigKeys($deployer);
    $requiredKeys = array_values(array_map(
        fn ($f) => $f['key'],
        array_filter($deployer->configSchema(), fn ($f) => ! empty($f['required'])),
    ));
    $label = $deployer->provider().'.'.$deployer->product();

    // 反向守卫：声明 required 却没被 requireConfig 读取 = 僵尸声明（应去 required/删字段）或 bind 漏读
    $notConsumed = array_values(array_diff($requiredKeys, $touched));
    expect($notConsumed)->toBe([], "$label 声明 required 但 bind 未读取: ".implode(',', $notConsumed));
})->with('deployerFactories');
