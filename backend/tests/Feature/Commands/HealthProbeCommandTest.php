<?php

use App\Models\Admin;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Utils\Email;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

afterEach(function () {
    Mockery::close();
    Cache::forget('monitor:probe:down');
});

/** 配置 site.adminEmail + 建 Admin，令拨测能解析到收件人 */
function probeSetupAdmin(): void
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

/** 注入一个「配置可用、send 成功」的 mock Email，返回其实例供断言 Timeout */
function probeMockSendableMail(): Email
{
    $mock = Mockery::mock(Email::class)->makePartial();
    $mock->configured = true;
    $mock->shouldReceive('isSMTP')->once();
    $mock->shouldReceive('isHTML')->once();
    $mock->shouldReceive('addAddress')->once();
    $mock->shouldReceive('setSubject')->once();
    $mock->shouldReceive('send')->once()->andReturn(true);
    app()->instance(Email::class, $mock);

    return $mock;
}

/** 注入一个「绝不该被使用」的 mock Email（健康/去重窗路径不该发信） */
function probeMockNoSendMail(): void
{
    $mock = Mockery::mock(Email::class);
    $mock->shouldReceive('isSMTP')->never();
    app()->instance(Email::class, $mock);
}

beforeEach(function () {
    probeSetupAdmin();
    config()->set('monitoring.probe.url', 'http://127.0.0.1/api/health');
    config()->set('monitoring.probe.dedupe_ttl_hours', 1);
    Http::preventStrayRequests();
});

test('健康（200 + status=ok）→ 不发信 + 清去重键', function () {
    // 预置去重键，验证恢复后清键
    Cache::put('monitor:probe:down', true, now()->addHour());
    Http::fake(['127.0.0.1/*' => Http::response(['status' => 'ok', 'freeze' => false], 200)]);
    probeMockNoSendMail();

    $this->artisan('monitor:probe')->assertSuccessful();

    expect(Cache::has('monitor:probe:down'))->toBeFalse();
});

test('degraded（200 + status=degraded）→ 不发信（新装机/清缓存不误报）', function () {
    Http::fake(['127.0.0.1/*' => Http::response(['status' => 'degraded', 'freeze' => false], 200)]);
    probeMockNoSendMail();

    $this->artisan('monitor:probe')->assertSuccessful();

    expect(Cache::has('monitor:probe:down'))->toBeFalse();
});

test('503 → 同步发信一次 + 落去重键 + PHPMailer Timeout=15', function () {
    Http::fake(['127.0.0.1/*' => Http::response(['status' => 'error'], 503)]);
    $mail = probeMockSendableMail();

    $this->artisan('monitor:probe')->assertSuccessful();

    expect(Cache::get('monitor:probe:down'))->toBeTrue()
        ->and($mail->Timeout)->toBe(15);
});

test('连接失败（异常）→ 同步发信 + 落去重键', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));
    probeMockSendableMail();

    $this->artisan('monitor:probe')->assertSuccessful();

    expect(Cache::get('monitor:probe:down'))->toBeTrue();
});

test('down 去重窗内二次跑不重复发信（TTL 内每周期一封）', function () {
    // 预置去重键（模拟首封已发）
    Cache::put('monitor:probe:down', true, now()->addHour());
    Http::fake(['127.0.0.1/*' => Http::response(['status' => 'error'], 503)]);
    probeMockNoSendMail();

    $this->artisan('monitor:probe')->assertSuccessful();

    // 键仍在，未重复发信
    expect(Cache::get('monitor:probe:down'))->toBeTrue();
});

test('邮件未配置时不占去重键（先确认可达后置键，下轮重试）', function () {
    Http::fake(['127.0.0.1/*' => Http::response(['status' => 'error'], 503)]);

    // 未配置的 Email → sendAlertMail 返回 false
    $mock = Mockery::mock(Email::class)->makePartial();
    $mock->configured = false;
    $mock->shouldReceive('isSMTP')->once();
    $mock->shouldReceive('isHTML')->once();
    app()->instance(Email::class, $mock);

    $this->artisan('monitor:probe')->assertSuccessful();

    // mail 未发出 → 不占键，下轮再来立即重试
    expect(Cache::has('monitor:probe:down'))->toBeFalse();
});

// ⑦ 最后防线：CACHE_DRIVER=redis 且 redis 宕机时，probe 判 down 后仍须发出告警。
// 修复前：alertDown 首行 Cache::get 抛 RedisException（在 handle 的 try/catch 外）→ 命令中止、
// sendAlertMail 永不执行；加重项 sendAlertMail 里 get_system_setting 走 Cache::remember 也抛。
test('cache 后端故障时（redis 宕机）probe 判 down 仍不崩溃并同步发信一次', function () {
    Http::fake(['127.0.0.1/*' => Http::response(['status' => 'error'], 503)]);
    // send()->once() 在 Mockery::close（afterEach）校验：命令若崩在发信前，该期望不满足即报错
    $mail = probeMockSendableMail();

    // 模拟 redis 全故障：dedupe 的 Cache::get、settings 的 Cache::remember 全抛
    Cache::swap(throwingCacheRepository());

    try {
        // 关键：命令不崩（dedupe fail-open），退出码成功
        $this->artisan('monitor:probe')->assertSuccessful();
    } finally {
        // 恢复 cache 供 afterEach 的 Cache::forget 安全清理（无论断言成败）
        restoreArrayCache();
    }

    // Timeout=15 证明 sendAlertMail 走到底（adminEmail 经 Setting 回落 DB 读到、未静默丢告警）
    expect($mail->Timeout)->toBe(15);
});
