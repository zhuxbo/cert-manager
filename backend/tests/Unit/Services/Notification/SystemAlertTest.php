<?php

use App\Models\Admin;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Notification\SystemAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});

/**
 * 配置 site.adminEmail（并清缓存）。
 */
function setAdminEmailSetting(?string $email): void
{
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => '站点', 'weight' => 1]);
    if ($email === null) {
        Setting::where('group_id', $group->id)->where('key', 'adminEmail')->delete();
    } else {
        Setting::updateOrCreate(
            ['group_id' => $group->id, 'key' => 'adminEmail'],
            ['type' => 'string', 'value' => $email, 'weight' => 0]
        );
    }
    Setting::clearGroupCache($group->id);
}

/**
 * 绑定一个捕获 intent 的假 NotificationCenter，返回一个可读 captured/count 的状态对象
 * （用对象而非数组引用，避免 list 解构断开 & 引用）。
 */
function bindCapturingCenter(): object
{
    $state = new class
    {
        public mixed $captured = null;

        public int $count = 0;
    };

    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->andReturnUsing(function ($intent) use ($state) {
        $state->captured = $intent;
        $state->count++;
    });
    app()->instance(NotificationCenter::class, $mock);

    return $state;
}

// ⑦ 写端接线：admin_email = site.adminEmail ?: admin->email（镜像 FundAuditCommand:119）
test('⑦ 写端接线：site.adminEmail 非 Admin 邮箱时，intent 携带 admin_email === adminEmail', function () {
    // fixture 显式错开：adminEmail 是运维分发别名，Admin 登录邮箱不同
    Admin::factory()->create(['email' => null]);
    setAdminEmailSetting('ops-alias@corp.example');

    $state = bindCapturingCenter();

    $sent = app(SystemAlert::class)->send('ca_credentials', '标题', '正文', ['probe' => 'x']);

    expect($sent)->toBeTrue();
    $intent = $state->captured;
    expect($intent)->toBeInstanceOf(NotificationIntent::class)
        ->and($intent->code)->toBe('system_alert')
        ->and($intent->notifiableType)->toBe('admin')
        ->and($intent->context['admin_email'])->toBe('ops-alias@corp.example')
        ->and($intent->context['category'])->toBe('ca_credentials')
        ->and($intent->context['title'])->toBe('标题')
        ->and($intent->context['message'])->toBe('正文')
        ->and($intent->context['details'])->toBe(['probe' => 'x']);
});

test('⑦ 写端接线：未配置 adminEmail 时 admin_email 回落 admin->email', function () {
    Admin::factory()->create(['email' => null]);
    $admin = Admin::factory()->create(['email' => 'only-admin@corp.example']);
    setAdminEmailSetting(null);

    $state = bindCapturingCenter();

    $sent = app(SystemAlert::class)->send('clock', 't', 'm');

    expect($sent)->toBeTrue()
        ->and($state->captured->context['admin_email'])->toBe('only-admin@corp.example')
        ->and($state->captured->notifiableId)->toBe($admin->id);
});

// ⑧ 去重时序：无 admin → 返回 false 且不占键
test('⑧ 无任何 Admin → send 返回 false 且去重键未被占（建 Admin 后同 key 立即可发）', function () {
    Admin::query()->delete();
    setAdminEmailSetting(null);

    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->never();
    app()->instance(NotificationCenter::class, $mock);

    $svc = app(SystemAlert::class);
    $sent = $svc->send('clock', 't', 'm', [], 'clock', 6, 'clock_skew');

    expect($sent)->toBeFalse()
        ->and(Cache::store('runtime')->has('system_alert:clock'))->toBeFalse();

    // 建 Admin 后同 key 立即可发（键未被占用）
    Admin::factory()->create(['email' => 'admin@corp.example']);
    $state = bindCapturingCenter();

    $sent2 = $svc->send('clock', 't', 'm', [], 'clock', 6, 'clock_skew');
    expect($sent2)->toBeTrue()
        ->and($state->count)->toBe(1);
});

// ⑧ dispatch 抛异常 → 返回 false 且不占键
test('⑧ dispatch 抛异常 → send 返回 false 且去重键未被占', function () {
    Admin::factory()->create(['email' => 'admin@corp.example']);
    setAdminEmailSetting('admin@corp.example');

    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('dispatch boom'));
    app()->instance(NotificationCenter::class, $mock);

    $svc = app(SystemAlert::class);
    $sent = $svc->send('payment_health', 't', 'm', [], 'payment_health', 168);

    expect($sent)->toBeFalse()
        ->and(Cache::store('runtime')->has('system_alert:payment_health'))->toBeFalse();
});

// ⑧-r3 置键事务安全：Cache::store('runtime')->put 经 DB::afterCommit 包裹
test('⑧-r3 事务内 send 后回滚 → 去重键不落，同 key 立即可再发', function () {
    Admin::factory()->create(['email' => 'admin@corp.example']);
    setAdminEmailSetting('admin@corp.example');
    $state = bindCapturingCenter();

    $svc = app(SystemAlert::class);

    DB::beginTransaction();
    $sent = $svc->send('tx_rollback', 't', 'm', [], 'tx_rollback', 24);
    DB::rollBack();

    // 事务回滚：afterCommit 的 NotificationJob 被 Laravel 丢弃（告警丢失），
    // 置键若同步执行则「键已占 + 没发」= 整 TTL 静默——必须随回滚一并丢弃
    expect($sent)->toBeTrue()
        ->and(Cache::store('runtime')->has('system_alert:tx_rollback'))->toBeFalse();

    // 键未落 → 同 key 立即可再发
    $again = $svc->send('tx_rollback', 't', 'm', [], 'tx_rollback', 24);
    expect($again)->toBeTrue()
        ->and($state->count)->toBe(2);
});

test('⑧-r3 事务内 send 后提交 → 去重键落地，去重生效', function () {
    Admin::factory()->create(['email' => 'admin@corp.example']);
    setAdminEmailSetting('admin@corp.example');
    $state = bindCapturingCenter();

    $svc = app(SystemAlert::class);

    DB::beginTransaction();
    $sent = $svc->send('tx_commit', 't', 'm', [], 'tx_commit', 24);
    // 提交前键不落（afterCommit 延迟到提交）
    expect(Cache::store('runtime')->has('system_alert:tx_commit'))->toBeFalse();
    DB::commit();

    expect($sent)->toBeTrue()
        ->and(Cache::store('runtime')->has('system_alert:tx_commit'))->toBeTrue();

    // 提交后同 key 同内容再发 → 去重
    $again = $svc->send('tx_commit', 't', 'm', [], 'tx_commit', 24);
    expect($again)->toBeFalse()
        ->and($state->count)->toBe(1);
});

// ⑨ 状态指纹：同内容不重发；内容变化立即再发并覆盖；clearDedupe 后立即再发
test('⑨ 状态指纹：同 key 同内容二次 send → false 不重发', function () {
    Admin::factory()->create(['email' => 'admin@corp.example']);
    setAdminEmailSetting('admin@corp.example');
    $state = bindCapturingCenter();

    $svc = app(SystemAlert::class);

    $r1 = $svc->send('payment_health', 't', 'm', ['expiring' => 'a'], 'payment_health', 168);
    $r2 = $svc->send('payment_health', 't', 'm', ['expiring' => 'a'], 'payment_health', 168);

    expect($r1)->toBeTrue()
        ->and($r2)->toBeFalse()
        ->and($state->count)->toBe(1);
});

test('⑨ 状态指纹：内容变化 → 立即再发并覆盖键值', function () {
    Admin::factory()->create(['email' => 'admin@corp.example']);
    setAdminEmailSetting('admin@corp.example');
    $state = bindCapturingCenter();

    $svc = app(SystemAlert::class);

    $r1 = $svc->send('payment_health', 't', 'm', ['expiring' => 'a'], 'payment_health', 168);
    // 内容变化（新增到期项）→ 指纹变 → 立即再发
    $r2 = $svc->send('payment_health', 't', 'm', ['expiring' => 'a,b'], 'payment_health', 168);
    // 覆盖后同新内容再发 → 去重
    $r3 = $svc->send('payment_health', 't', 'm', ['expiring' => 'a,b'], 'payment_health', 168);

    expect($r1)->toBeTrue()
        ->and($r2)->toBeTrue()
        ->and($r3)->toBeFalse()
        ->and($state->count)->toBe(2);
});

test('⑨ 固定 fingerprint：内容 churn 但指纹不变 → 去重不被击穿', function () {
    Admin::factory()->create(['email' => 'admin@corp.example']);
    setAdminEmailSetting('admin@corp.example');
    $state = bindCapturingCenter();

    $svc = app(SystemAlert::class);

    // message 每次不同（如偏差秒数波动），但传固定指纹 → 只发一次
    $r1 = $svc->send('clock', 't', '偏差 130s', [], 'clock', 6, 'clock_skew');
    $r2 = $svc->send('clock', 't', '偏差 145s', [], 'clock', 6, 'clock_skew');

    expect($r1)->toBeTrue()
        ->and($r2)->toBeFalse()
        ->and($state->count)->toBe(1);
});

test('⑨ clearDedupe 后立即可再发', function () {
    Admin::factory()->create(['email' => 'admin@corp.example']);
    setAdminEmailSetting('admin@corp.example');
    $state = bindCapturingCenter();

    $svc = app(SystemAlert::class);

    $r1 = $svc->send('test_level', 't', 'm', [], 'test_level', 168, 'fixed');
    $r2 = $svc->send('test_level', 't', 'm', [], 'test_level', 168, 'fixed');
    $svc->clearDedupe('test_level');
    $r3 = $svc->send('test_level', 't', 'm', [], 'test_level', 168, 'fixed');

    expect($r1)->toBeTrue()
        ->and($r2)->toBeFalse()
        ->and($r3)->toBeTrue()
        ->and($state->count)->toBe(2);
});

test('无 dedupeKey → 每次都发（不去重）', function () {
    Admin::factory()->create(['email' => 'admin@corp.example']);
    setAdminEmailSetting('admin@corp.example');
    $state = bindCapturingCenter();

    $svc = app(SystemAlert::class);

    $svc->send('x', 't', 'm');
    $svc->send('x', 't', 'm');

    expect($state->count)->toBe(2);
});
