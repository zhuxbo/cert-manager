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
    Cache::flush();
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
    Cache::put('system_alert:ca_credentials', 'stale', now()->addHours(24));
    caHealthFakeSdk(['code' => 1, 'data' => []]);
    $state = caHealthCaptureCenter();

    $this->artisan('schedule:ca-healthcheck')->assertSuccessful();

    expect($state->count)->toBe(0)
        ->and(Cache::has('system_alert:ca_credentials'))->toBeFalse();
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
        ->and(Cache::has('system_alert:ca_credentials'))->toBeFalse();
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
