<?php

use App\Models\Admin;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Notification\NotificationCenter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

afterEach(function () {
    Mockery::close();
    Carbon::setTestNow();
});

function clockSetupAdmin(): void
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

function clockCaptureCenter(): object
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

/** 构造某源的 HEAD 响应：Date = 本地 now + $skewSeconds，可选 Age */
function clockResponse(int $skewSeconds, ?int $age = null)
{
    $date = Carbon::now()->addSeconds($skewSeconds)->toRfc7231String();
    $headers = ['Date' => $date];
    if ($age !== null) {
        $headers['Age'] = (string) $age;
    }

    return Http::response('', 200, $headers);
}

beforeEach(function () {
    clockSetupAdmin();
    Carbon::setTestNow(Carbon::parse('2026-07-11 12:00:00'));
    config()->set('monitoring.clock.enabled', true);
    config()->set('monitoring.clock.max_skew_seconds', 120);
    config()->set('monitoring.clock.http_timeout_seconds', 5);
    config()->set('monitoring.clock.sources', ['https://clock-a.test', 'https://clock-b.test']);
    config()->set('monitoring.clock.dedupe_ttl_hours', 6);
    Http::preventStrayRequests();
});

test('① 两源 Date 与本地一致 → 无告警 + 清键', function () {
    Cache::store('runtime')->put('system_alert:clock', 'stale', now()->addHours(6));
    Http::fake([
        '*clock-a.test*' => clockResponse(5),
        '*clock-b.test*' => clockResponse(-3),
    ]);
    $state = clockCaptureCenter();

    $this->artisan('schedule:clock-check')->assertSuccessful();

    expect($state->count)->toBe(0)
        ->and(Cache::store('runtime')->has('system_alert:clock'))->toBeFalse();
});

test('② 两源一致偏差 >120s 同号 → 告警', function () {
    Http::fake([
        '*clock-a.test*' => clockResponse(200),
        '*clock-b.test*' => clockResponse(210),
    ]);
    $state = clockCaptureCenter();

    $this->artisan('schedule:clock-check')->assertSuccessful();

    expect($state->count)->toBe(1)
        ->and($state->captured->context['category'])->toBe('clock');
});

test('③ 仅单源可达且超阈 → 不告警（法定人数 ≥2）', function () {
    Http::fake([
        '*clock-a.test*' => clockResponse(200),
        '*clock-b.test*' => Http::response('', 500),
    ]);
    $state = clockCaptureCenter();

    $this->artisan('schedule:clock-check')->assertSuccessful();

    expect($state->count)->toBe(0);
});

test('④ 一源带 Age>0 被丢弃 → 剩单源 → 不告警', function () {
    Http::fake([
        '*clock-a.test*' => clockResponse(200, 300), // Age=300 缓存命中，丢弃
        '*clock-b.test*' => clockResponse(210),
    ]);
    $state = clockCaptureCenter();

    $this->artisan('schedule:clock-check')->assertSuccessful();

    expect($state->count)->toBe(0);
});

test('⑤ 全源 500/连接异常 → 无告警', function () {
    Http::fake([
        '*clock-a.test*' => Http::response('', 500),
        '*clock-b.test*' => fn () => throw new ConnectionException('down'),
    ]);
    $state = clockCaptureCenter();

    $this->artisan('schedule:clock-check')->assertSuccessful();

    expect($state->count)->toBe(0);
});

test('⑥ 去重（固定指纹）：两次偏差波动仍仅一封', function () {
    Http::fake([
        '*clock-a.test*' => clockResponse(200),
        '*clock-b.test*' => clockResponse(210),
    ]);
    $state = clockCaptureCenter();

    $this->artisan('schedule:clock-check')->assertSuccessful();

    // 偏差值变化（churn），固定指纹仍去重
    Http::fake([
        '*clock-a.test*' => clockResponse(300),
        '*clock-b.test*' => clockResponse(280),
    ]);
    $this->artisan('schedule:clock-check')->assertSuccessful();

    expect($state->count)->toBe(1);
});

test('⑦ 反号超阈（无同号法定人数）→ 不告警', function () {
    Http::fake([
        '*clock-a.test*' => clockResponse(200),  // 本地慢
        '*clock-b.test*' => clockResponse(-200), // 本地快
    ]);
    $state = clockCaptureCenter();

    $this->artisan('schedule:clock-check')->assertSuccessful();

    expect($state->count)->toBe(0);
});

test('⑨ details 键名避 denylist 且值全为标量（Builder 掩码回归护栏）', function () {
    Http::fake([
        '*clock-a.test*' => clockResponse(200),
        '*clock-b.test*' => clockResponse(210),
    ]);
    $state = clockCaptureCenter();

    $this->artisan('schedule:clock-check')->assertSuccessful();

    expect($state->count)->toBe(1);
    assertSystemAlertDetailsSafe($state->captured->context['details']);
});

test('⑧ enabled=false → 不告警不请求', function () {
    config()->set('monitoring.clock.enabled', false);
    // 不 fake：preventStrayRequests 会让任何真实请求抛错，验证根本没请求
    $state = clockCaptureCenter();

    $this->artisan('schedule:clock-check')->assertSuccessful();

    expect($state->count)->toBe(0);
});
