<?php

use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\FundAudit\FundInvariants;
use App\Services\Notification\Builders\SystemAlertNotificationBuilder;
use App\Services\Payment\PaymentGateway;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Yansongda\Pay\Pay;
use Yansongda\Supports\Collection;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class)->in('Feature');

require_once __DIR__.'/Support/FundAuditGuard.php';

// 仅 mysql：所有 Feature 测试统一走 RefreshDatabase；下方列出的子目录精确控制
// 哪些用真实 DB（其它如 Feature/Compat 用 mock 或不跑 DB）。
uses(RefreshDatabase::class)->in(
    'Feature/Commands',
    'Feature/Console',
    'Feature/Database',
    'Feature/Factories',
    'Feature/FundAudit',
    'Feature/Http',
    'Feature/Jobs',
    'Feature/Middleware',
    'Feature/Models',
    'Feature/Services',
    'Feature/Timezone',
);

/*
|--------------------------------------------------------------------------
| 资金审计 afterEach 守门
|--------------------------------------------------------------------------
|
| 动了 funds / transactions / users.balance 的测试，afterEach 时自动跑
| FundInvariants 4 条 SQL 校验，任一违反即 fail，暴露漏写 transaction /
| 金额不匹配等隐患。afterEach 在 RefreshDatabase rollback 之前跑，能看到
| 测试期间的全部变更。
*/
uses()->afterEach(function () {
    $violations = app(FundInvariants::class)->all();
    if (! empty($violations)) {
        $msg = '资金审计破：'.collect($violations)
            ->map(fn ($v) => $v['layer'].' '.$v['message'])
            ->implode('; ');
        test()->fail($msg);
    }
})->in(...fundAuditGuardedTestPaths());

/*
|--------------------------------------------------------------------------
| API 兼容性快照对照
|--------------------------------------------------------------------------
|
| 钩子安装在 Tests\TestCase::setUp / tearDown，仅在 COMPAT_CAPTURE / COMPAT_COMPARE
| 环境变量启用时激活。
|
| - capture 模式：监听 RequestHandled 事件 + 把响应 schema 写到 tests/Compat/fixtures/
| - compare 模式：实时对比 fixture，差异在 tearDown 时断言失败
|
| 不修改任何业务测试用例，对默认 CI 完全透明（两个 env 都不开时零开销）。
*/

require_once __DIR__.'/Compat/Helpers.php';
require_once __DIR__.'/Compat/GlobalFunctions.php';

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * SystemAlert details 消费方安全护栏（监控/告警命令测试共用）：
 * 逐键断言 ① 值为标量——非标量会被 SystemAlertNotificationBuilder 换成 [filtered:non-scalar] 占位、信息丢失；
 * ② 键名不命中 Builder 敏感键 denylist——命中即值被掩码为 ***、运维定位信息丢失。
 * 正则经反射读 Builder 私有常量（本体冻结、不改可见性），与实现同源不漂移。
 */
function assertSystemAlertDetailsSafe(array $details): void
{
    $pattern = (new ReflectionClassConstant(
        SystemAlertNotificationBuilder::class,
        'SENSITIVE_KEY_PATTERN'
    ))->getValue();

    expect($details)->not->toBeEmpty();
    foreach ($details as $key => $value) {
        expect(is_scalar($value))->toBeTrue("details.$key 应为标量（非标量会被 Builder 占位过滤）")
            ->and(preg_match($pattern, (string) $key))->toBe(0, "details.$key 键名命中 Builder denylist（值会被掩码）");
    }
}

/**
 * 创建或更新工商查询 Setting 配置项
 */
function setEnterpriseLookupSetting(string $key, mixed $value, string $type = 'string'): void
{
    $group = SettingGroup::firstOrCreate(
        ['name' => 'enterprise'],
        ['title' => '工商信息查询', 'weight' => 9],
    );
    $setting = Setting::where('group_id', $group->id)->where('key', $key)->first();
    if (! $setting) {
        $setting = new Setting(['group_id' => $group->id, 'key' => $key, 'type' => $type]);
    }
    $setting->type = $type;
    $setting->value = $value;
    $setting->save();
    Setting::clearGroupCache($group->id);
}

/**
 * 造一个 shell 脚本，模拟 mysql/mysqldump 的 --version 输出，供
 * BinaryLocator::probeWith 的 proc_open 探测识别为合法 mysql 客户端。
 *
 * 返回脚本绝对路径。注册 shutdown 时自动清理。
 */
function fakeMysqlClientBin(string $tool = 'mysqldump'): string
{
    $path = sys_get_temp_dir().'/fake_'.$tool.'_'.uniqid().'.sh';
    file_put_contents(
        $path,
        "#!/bin/sh\necho '$tool Ver 8.0.99 for Linux on x86_64 (MySQL Distrib 8.0.99)'\n"
    );
    chmod($path, 0755);
    register_shutdown_function(static fn () => @unlink($path));

    return $path;
}

/**
 * 配置微信支付公钥（publicKeyId + publicKey 俱全），满足 wechatSerial 发头的对称 gate。
 */
function setWechatPublicKeyId(string $id): void
{
    $group = SettingGroup::firstOrCreate(
        ['name' => 'wechat'],
        ['title' => '微信支付设置', 'weight' => 7],
    );
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'publicKeyId'],
        ['type' => 'string', 'value' => $id],
    );
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'publicKey'],
        ['type' => 'base64', 'value' => 'TEST_PUBLIC_KEY_CONTENT'],
    );
    Setting::clearGroupCache($group->id);
}

/**
 * Mock 支付 provider（经 PaymentGateway 包装），捕获传给 wechat/alipay 的 scan/query 参数。
 * 用于断言微信 v3 请求是否带 _serial_no（Wechatpay-Serial 公钥头），
 * 以及支付查询是否走标准 PaymentGateway 包装（而非旧的 Pay:: 静态 / 坏配置）。
 */
function mockPayCapture(): object
{
    Pay::clear();

    $captured = new stdClass;
    $captured->scan = null;
    $captured->query = null;
    $captured->alipayQuery = null;

    $wechat = Mockery::mock();
    $wechat->shouldReceive('scan')->andReturnUsing(function ($order) use ($captured) {
        $captured->scan = $order;

        return new Collection(['code_url' => 'weixin://wxpay/test']);
    });

    $alipay = Mockery::mock();
    $alipay->shouldReceive('query')->andReturnUsing(function ($order) use ($captured) {
        $captured->alipayQuery = $order;

        return new Collection(['trade_status' => 'WAIT_BUYER_PAY']);
    });

    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('wechat')->andReturn($wechat);
    $gateway->shouldReceive('alipay')->andReturn($alipay);
    // 查单走 PaymentGateway::wechatQuery（内部自定义插件列表注入 Wechatpay-Serial 头，
    // 见 InjectWechatSerialPlugin）。此处捕获参数断言调用方按 gate 合入 _serial_no；
    // 「头真的发出」由 WechatSerialPipelineTest 在 HTTP 层断言。
    $gateway->shouldReceive('wechatQuery')->andReturnUsing(function ($order) use ($captured) {
        $captured->query = $order;

        return new Collection(['trade_state' => 'NOTPAY']);
    });
    app()->instance(PaymentGateway::class, $gateway);

    return $captured;
}

/**
 * 返回一个底层 Store 所有读写操作都抛异常的 Cache Repository。
 * `Cache::swap(throwingCacheRepository())` 之即模拟 redis 后端全故障（get/put/forget/remember 全抛）。
 * 用于验证 HealthController 探针和 Setting 读取在 cache 后端崩溃时降级为结构化输出 / DB 直读，
 * 而非白屏 500。
 *
 * 注意：swap 后 `Cache::has/forget` 也会抛——用完须 `Cache::swap` 回正常 store（或用例末尾恢复），
 * 否则同用例的 afterEach 清理会被 cache 异常打断。
 */
function throwingCacheRepository(): Repository
{
    $store = new class implements Store
    {
        public function get($key)
        {
            throw new RuntimeException('cache backend down');
        }

        public function many(array $keys)
        {
            throw new RuntimeException('cache backend down');
        }

        public function put($key, $value, $seconds)
        {
            throw new RuntimeException('cache backend down');
        }

        public function putMany(array $values, $seconds)
        {
            throw new RuntimeException('cache backend down');
        }

        public function increment($key, $value = 1)
        {
            throw new RuntimeException('cache backend down');
        }

        public function decrement($key, $value = 1)
        {
            throw new RuntimeException('cache backend down');
        }

        public function forever($key, $value)
        {
            throw new RuntimeException('cache backend down');
        }

        public function touch($key, $seconds)
        {
            throw new RuntimeException('cache backend down');
        }

        public function forget($key)
        {
            throw new RuntimeException('cache backend down');
        }

        public function flush()
        {
            throw new RuntimeException('cache backend down');
        }

        public function getPrefix()
        {
            return '';
        }
    };

    return new Repository($store);
}

/**
 * 恢复为可用的 array Cache（配合 throwingCacheRepository 使用，用例末尾调用让 afterEach 清理安全）。
 */
function restoreArrayCache(): void
{
    Cache::swap(
        new Repository(new ArrayStore)
    );
}
