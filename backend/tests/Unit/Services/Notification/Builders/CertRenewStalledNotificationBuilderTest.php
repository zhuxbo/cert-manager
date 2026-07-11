<?php

use App\Models\Cert;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notification\Builders\CertRenewStalledNotificationBuilder;
use App\Services\Notification\DTOs\NotificationIntent;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('database');

afterEach(function () {
    Mockery::close();
});

/**
 * 造一个「前驱证书」mock（预载接替 nextCert）：build 只读 nextCert / expires_at / common_name。
 */
function stalledMockPredecessor(string $successorStatus, string $domain, $expiresAt = null): Cert&MockInterface
{
    $successor = Mockery::mock(Cert::class)->makePartial();
    $successor->shouldReceive('getAttribute')->with('status')->andReturn($successorStatus);

    $predecessor = Mockery::mock(Cert::class)->makePartial();
    $predecessor->shouldReceive('getAttribute')->with('nextCert')->andReturn($successor);
    $predecessor->shouldReceive('getAttribute')->with('expires_at')->andReturn($expiresAt ?? now()->addDays(6));
    $predecessor->shouldReceive('getAttribute')->with('common_name')->andReturn($domain);

    return $predecessor;
}

function stalledMockUser(?string $email = 'user@example.com'): User&MockInterface
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $user->shouldReceive('getAttribute')->with('email')->andReturn($email);
    $user->shouldReceive('getAttribute')->with('username')->andReturn('testuser');

    return $user;
}

/**
 * makePartial Builder，覆盖 fetchStalledPairs 返回给定前驱集合（同 CertExpire fetchExpiringOrders 范式）。
 */
function buildStalledPartialBuilder(Collection $predecessors): CertRenewStalledNotificationBuilder&MockInterface
{
    $builder = Mockery::mock(CertRenewStalledNotificationBuilder::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $builder->shouldReceive('fetchStalledPairs')->andReturn($predecessors);

    // build() 顶部调 get_system_setting('site', ...)，预填 cache 短路真实查询
    Cache::put('setting:group_name:site', ['url' => 'https://ssl.test/', 'name' => 'SSL证书管理系统'], 3600);

    return $builder;
}

test('接收者非 User 时抛出异常', function () {
    $builder = new CertRenewStalledNotificationBuilder;
    $intent = new NotificationIntent('cert_renew_stalled', 'user', 1);

    /** @var Model $notifiable */
    $notifiable = Mockery::mock(Model::class);

    $builder->build($intent, $notifiable);
})->throws(RuntimeException::class, '通知接收者必须为用户');

test('邮箱为空时抛出异常', function () {
    $builder = new CertRenewStalledNotificationBuilder;
    $intent = new NotificationIntent('cert_renew_stalled', 'user', 1);

    $builder->build($intent, stalledMockUser(email: null));
})->throws(RuntimeException::class, '邮箱为空');

// ── 测试 15：5 态 → stall_status/action_hint 映射逐一断言 ───────────────────────
test('5 态 → stall_status/action_hint 映射：unpaid 中性、已扣费勿重付、failed 指重新购买且不断言退款', function () {
    $predecessors = new Collection([
        stalledMockPredecessor('unpaid', 'u.com'),
        stalledMockPredecessor('pending', 'p.com'),
        stalledMockPredecessor('processing', 'pr.com'),
        stalledMockPredecessor('approving', 'ap.com'),
        stalledMockPredecessor('failed', 'f.com'),
    ]);
    $builder = buildStalledPartialBuilder($predecessors);
    $intent = new NotificationIntent('cert_renew_stalled', 'user', 1, ['email' => 'user@example.com']);

    $result = $builder->build($intent, stalledMockUser());
    $byDomain = collect($result->data['certificates'])->keyBy('domain');

    // unpaid：中性化（不硬承诺去支付；提示可能被清理，与 O4 配套）
    expect($byDomain['u.com']['stall_status'])->toBe('unpaid')
        ->and($byDomain['u.com']['action_hint'])->toContain('可重新支付以继续签发，或取消该订单')
        ->and($byDomain['u.com']['action_hint'])->toContain('可能被系统自动清理');

    // pending：已扣费，勿重复支付
    expect($byDomain['p.com']['stall_status'])->toBe('pending')
        ->and($byDomain['p.com']['action_hint'])->toContain('费用已扣除')
        ->and($byDomain['p.com']['action_hint'])->toContain('请勿重复下单或重复支付');

    // processing/approving：已扣费、验证/审核中（审计 critical 路径 3 的正面文案）
    expect($byDomain['pr.com']['stall_status'])->toBe('processing')
        ->and($byDomain['pr.com']['action_hint'])->toContain('域名验证/审核')
        ->and($byDomain['pr.com']['action_hint'])->toContain('费用已扣除');
    expect($byDomain['ap.com']['stall_status'])->toBe('approving')
        ->and($byDomain['ap.com']['action_hint'])->toContain('域名验证/审核');

    // failed：指「重新购买」+「联系客服」，不断言退款状态（notify R-1）
    expect($byDomain['f.com']['stall_status'])->toBe('failed')
        ->and($byDomain['f.com']['action_hint'])->toContain('重新购买证书')
        ->and($byDomain['f.com']['action_hint'])->toContain('联系客服')
        ->and($byDomain['f.com']['action_hint'])->not->toContain('已退款');
});

// ── 测试 16：空结果 → build 返回 null ──────────────────────────────────────────
test('空结果 → build 返回 null（不发空邮件）', function () {
    $builder = buildStalledPartialBuilder(new Collection);
    $intent = new NotificationIntent('cert_renew_stalled', 'user', 1, ['email' => 'user@example.com']);

    expect($builder->build($intent, stalledMockUser()))->toBeNull();
});

// ── 测试 17：site_url/site_name Builder 注入、不依赖 context ─────────────────────
test('site_url/site_name 由 Builder 从系统设置注入，不进 context/variables', function () {
    $builder = buildStalledPartialBuilder(new Collection([stalledMockPredecessor('unpaid', 'x.com')]));
    // context 仅 email，无 site_url/site_name
    $intent = new NotificationIntent('cert_renew_stalled', 'user', 1, ['email' => 'user@example.com']);

    $result = $builder->build($intent, stalledMockUser());

    expect($result->data['site_url'])->toBe('https://ssl.test/')
        ->and($result->data['site_name'])->toBe('SSL证书管理系统')
        ->and($result->data['email'])->toBe('user@example.com')
        ->and($result->data['username'])->toBe('testuser')
        ->and($result->data['_meta']['subject'])->toContain('证书续期停滞提醒')
        ->and($result->data['_meta']['is_html'])->toBeTrue();
});

// ── 测试 18：三件套 + 强制发（不入偏好表钉死） ──────────────────────────────────
test('三件套 + 强制发：config builder 命中 + 不入偏好表 + allowsNotification 默认 true + seeder 幂等 + 模板性质说明', function () {
    // ① config builders 命中新 code（缺则端到端回落 DefaultBuilder、渲染不出证书列表）
    expect(config('notification.builders')['cert_renew_stalled'])->toBe(CertRenewStalledNotificationBuilder::class);

    // ② 强制发第三重（r3/notify R-2）：不入 user_default_preferences。normalizeNotificationSettings 按该表
    //    白名单归一化——误加入表即强制发静默失效且用户可关，故一行断言锁死。
    expect(array_key_exists('cert_renew_stalled', config('notification.user_default_preferences')))->toBeFalse();

    // ③ allowsNotification 未登记 code 默认 true；且用户显式塞 false 会被 setter 白名单丢弃 → 仍强制发
    $user = User::factory()->create();
    expect($user->allowsNotification('cert_renew_stalled'))->toBeTrue();
    $user->notification_settings = ['cert_renew_stalled' => false];
    $user->save();
    expect($user->fresh()->allowsNotification('cert_renew_stalled'))->toBeTrue();

    // ④ seeder 幂等（firstOrCreate，新 code 首建、重跑 no-op）
    (new NotificationTemplateSeeder)->run();
    (new NotificationTemplateSeeder)->run();
    $templates = NotificationTemplate::where('code', 'cert_renew_stalled')->get();
    expect($templates)->toHaveCount(1)
        ->and($templates->first()->status)->toBe(1);

    // ⑤ 模板含性质说明段（notify M-4）
    expect($templates->first()->content)
        ->toContain('续期停滞重要提醒')
        ->toContain('不受常规到期提醒偏好控制');
});
