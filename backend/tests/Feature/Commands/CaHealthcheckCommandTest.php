<?php

use App\Models\Admin;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Api\default\Sdk;
use Illuminate\Support\Facades\Cache;

afterEach(function () {
    Mockery::close();
});

/** 配置 site.adminEmail 并建 Admin，令 SystemAlert 能解析到收件人 */
function caHealthSetupAdmin(): void
{
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => '站点', 'weight' => 1]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'adminEmail'],
        ['type' => 'string', 'value' => 'ops@corp.example', 'weight' => 0]
    );
    Setting::clearGroupCache($group->id);
    Admin::factory()->create(['email' => 'ops@corp.example']);
    Cache::store('runtime')->flush();
}

/** 绑定捕获型 NotificationCenter，返回可读 count/captured 的状态对象 */
function caHealthCaptureCenter(): object
{
    $state = new class
    {
        public int $count = 0;

        public mixed $captured = null;
    };
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->andReturnUsing(function ($intent) use ($state) {
        $state->count++;
        $state->captured = $intent;
    });
    app()->instance(NotificationCenter::class, $mock);

    return $state;
}

/** 绑定返回固定响应的假 Sdk */
function caHealthFakeSdk(array $response): void
{
    $mock = Mockery::mock(Sdk::class);
    $mock->shouldReceive('getProducts')->andReturn($response);
    app()->instance(Sdk::class, $mock);
}

beforeEach(function () {
    caHealthSetupAdmin();
    config()->set('monitoring.ca_healthcheck.enabled', true);
    config()->set('monitoring.ca_healthcheck.dedupe_ttl_hours', 24);
});

test('① code=1 健康 → 无告警且去重键被清', function () {
    // 先占一个去重键，验证健康分支会清它
    Cache::store('runtime')->put('system_alert:ca_credentials', 'stale', now()->addHours(24));
    caHealthFakeSdk(['code' => 1, 'data' => []]);
    $state = caHealthCaptureCenter();

    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();

    expect($state->count)->toBe(0)
        ->and(Cache::store('runtime')->has('system_alert:ca_credentials'))->toBeFalse();
});

test('② 401 主信号 → 告警', function () {
    caHealthFakeSdk(['code' => 0, 'msg' => 'Http status code 401']);
    $state = caHealthCaptureCenter();

    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();

    expect($state->count)->toBe(1)
        ->and($state->captured->code)->toBe('system_alert')
        ->and($state->captured->context['category'])->toBe('ca_credentials');
});

test('③ Unauthorized 辅助信号 → 告警', function () {
    caHealthFakeSdk(['code' => 0, 'msg' => 'Unauthorized']);
    $state = caHealthCaptureCenter();

    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();

    expect($state->count)->toBe(1);
});

test('④ 未配置态（Api url or token is not set）→ 不告警', function () {
    caHealthFakeSdk(['code' => 0, 'msg' => 'Api url or token is not set']);
    $state = caHealthCaptureCenter();

    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();

    expect($state->count)->toBe(0)
        ->and(Cache::store('runtime')->has('system_alert:ca_credentials'))->toBeFalse();
});

test('⑤ 连接超时（连通性维度）→ 不告警', function () {
    caHealthFakeSdk(['code' => 0, 'msg' => '上游连接超时，请稍后重试']);
    $state = caHealthCaptureCenter();

    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();

    expect($state->count)->toBe(0);
});

test('⑥ 连续两次同样鉴权失败 → 仅 1 封（状态指纹去重）', function () {
    caHealthFakeSdk(['code' => 0, 'msg' => 'Http status code 403']);
    $state = caHealthCaptureCenter();

    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();
    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();

    expect($state->count)->toBe(1);
});

test('⑧ details 键名避 denylist 且值全为标量（Builder 掩码回归护栏）', function () {
    caHealthFakeSdk(['code' => 0, 'msg' => 'Http status code 401']);
    $state = caHealthCaptureCenter();

    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();

    expect($state->count)->toBe(1);
    assertSystemAlertDetailsSafe($state->captured->context['details']);
});

test('⑦ enabled=false → 不探测不告警', function () {
    config()->set('monitoring.ca_healthcheck.enabled', false);
    // 绑一个「被调用即失败」的 Sdk 断言：enabled=false 时根本不该调 getProducts
    $sdk = Mockery::mock(Sdk::class);
    $sdk->shouldReceive('getProducts')->never();
    app()->instance(Sdk::class, $sdk);
    $state = caHealthCaptureCenter();

    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();

    expect($state->count)->toBe(0);
});

// ==========================================
// M7：整体连通性告警（连续 N 次失败 + 固定指纹 + healthy 清键）
// ==========================================

test('⑨ 连通性失败 <N 次不告警（计数累加），第 N 次达阈值才告警', function () {
    caHealthFakeSdk(['code' => 0, 'msg' => '上游连接超时，请稍后重试']);
    $state = caHealthCaptureCenter();

    // 默认阈值 3：前两次连通性失败仅累计计数、不告警
    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();
    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();
    expect($state->count)->toBe(0)
        ->and((int) Cache::store('runtime')->get('ca_healthcheck:connectivity_fails'))->toBe(2);

    // 第 3 次达阈值 → 告警（category=ca_connectivity，details.consecutive=3）
    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();
    expect($state->count)->toBe(1)
        ->and($state->captured->code)->toBe('system_alert')
        ->and($state->captured->context['category'])->toBe('ca_connectivity')
        ->and($state->captured->context['details']['consecutive'])->toBe(3);
});

test('⑩ 固定指纹 ca_outage：达阈值后连续失败不重复告警（consecutive churn 不击穿）', function () {
    caHealthFakeSdk(['code' => 0, 'msg' => 'No return code']);
    $state = caHealthCaptureCenter();

    // 跑到阈值触发第一封
    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();
    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();
    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();
    expect($state->count)->toBe(1);

    // 第 4/5 次仍失败（consecutive 变 4/5）：固定指纹 ca_outage → 去重不重复告警
    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();
    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();
    expect($state->count)->toBe(1); // 仍 1 封（非固定指纹会因 consecutive 变化 churn 击穿）
});

test('⑪ 成功 → 连通性计数清零 + 清连通性去重键 + 清凭证去重键', function () {
    // 先占连通性计数 + 两类去重键
    Cache::store('runtime')->forever('ca_healthcheck:connectivity_fails', 2);
    Cache::store('runtime')->put('system_alert:ca_connectivity', 'ca_outage', now()->addHours(6));
    Cache::store('runtime')->put('system_alert:ca_credentials', 'stale', now()->addHours(24));
    caHealthFakeSdk(['code' => 1, 'data' => []]);
    $state = caHealthCaptureCenter();

    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();

    expect($state->count)->toBe(0)
        ->and(Cache::store('runtime')->has('ca_healthcheck:connectivity_fails'))->toBeFalse()
        ->and(Cache::store('runtime')->has('system_alert:ca_connectivity'))->toBeFalse()
        ->and(Cache::store('runtime')->has('system_alert:ca_credentials'))->toBeFalse();
});

test('⑫ 鉴权失败 → 连通性计数重置（上游可达），凭证告警照旧', function () {
    // 上游先前连通性失败累计 2 次；现返回鉴权错误（上游可达）
    Cache::store('runtime')->forever('ca_healthcheck:connectivity_fails', 2);
    caHealthFakeSdk(['code' => 0, 'msg' => 'Http status code 401']);
    $state = caHealthCaptureCenter();

    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();

    expect($state->count)->toBe(1)
        ->and($state->captured->context['category'])->toBe('ca_credentials')
        // 上游可达 → 连通性计数被重置，避免「上游回来但坏 token」残留混叠
        ->and(Cache::store('runtime')->has('ca_healthcheck:connectivity_fails'))->toBeFalse();
});

test('⑬ 连通性告警 details 键名避 denylist 且值全标量（Builder 掩码护栏）', function () {
    caHealthFakeSdk(['code' => 0, 'msg' => '上游请求失败']);
    $state = caHealthCaptureCenter();

    for ($i = 0; $i < 3; $i++) {
        $this->artisan('schedule:ca-healthcheck')->assertSuccessful();
    }

    expect($state->count)->toBe(1);
    assertSystemAlertDetailsSafe($state->captured->context['details']);
});

test('⑭ 未配置态不动连通性计数（新装/测试实例未填上游）', function () {
    Cache::store('runtime')->forever('ca_healthcheck:connectivity_fails', 1);
    caHealthFakeSdk(['code' => 0, 'msg' => 'Api url or token is not set']);
    $state = caHealthCaptureCenter();

    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();

    expect($state->count)->toBe(0)
        // 未配置态既不告警也不动计数（既不累加也不重置）
        ->and((int) Cache::store('runtime')->get('ca_healthcheck:connectivity_fails'))->toBe(1);
});
