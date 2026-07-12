<?php

use App\Models\CnameDelegation;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Notification\Builders\DelegationInvalidNotificationBuilder;
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

/** 造一个委托记录 mock（build 只读 zone/prefix/target_fqdn/fail_count）。 */
function invalidMockDelegation(string $zone, string $prefix, string $target, int $failCount): CnameDelegation&MockInterface
{
    $d = Mockery::mock(CnameDelegation::class)->makePartial();
    $d->shouldReceive('getAttribute')->with('zone')->andReturn($zone);
    $d->shouldReceive('getAttribute')->with('prefix')->andReturn($prefix);
    $d->shouldReceive('getAttribute')->with('target_fqdn')->andReturn($target);
    $d->shouldReceive('getAttribute')->with('fail_count')->andReturn($failCount);

    return $d;
}

function invalidMockUser(?string $email = 'u@example.com'): User&MockInterface
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $user->shouldReceive('getAttribute')->with('email')->andReturn($email);
    $user->shouldReceive('getAttribute')->with('username')->andReturn('testuser');

    return $user;
}

/** makePartial Builder，覆盖 fetchInvalidDelegations 注入缝返回给定委托集合。 */
function buildInvalidPartial(Collection $delegations): DelegationInvalidNotificationBuilder&MockInterface
{
    $builder = Mockery::mock(DelegationInvalidNotificationBuilder::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $builder->shouldReceive('fetchInvalidDelegations')->andReturn($delegations);

    // build() 顶部调 get_system_setting('site', ...)，预填 cache 短路真实查询
    Cache::put('setting:group_name:site', ['url' => 'https://ssl.test/', 'name' => 'SSL证书管理系统'], 3600);

    return $builder;
}

test('接收者非 User 时抛出异常', function () {
    $builder = new DelegationInvalidNotificationBuilder;
    $intent = new NotificationIntent('delegation_invalid', 'user', 1);

    /** @var Model $notifiable */
    $notifiable = Mockery::mock(Model::class);

    $builder->build($intent, $notifiable);
})->throws(RuntimeException::class, '通知接收者必须为用户');

test('邮箱为空时抛出异常', function () {
    $builder = new DelegationInvalidNotificationBuilder;
    $intent = new NotificationIntent('delegation_invalid', 'user', 1);

    $builder->build($intent, invalidMockUser(email: null));
})->throws(RuntimeException::class, '邮箱为空');

test('渲染 zone/prefix/target_fqdn/fail_count 列表', function () {
    $delegations = new Collection([
        invalidMockDelegation('a.com', '_dnsauth', 'lbl.proxy.test', 3),
        invalidMockDelegation('b.com', '_certum', 'lbl2.proxy.test', 5),
    ]);
    $builder = buildInvalidPartial($delegations);
    $intent = new NotificationIntent('delegation_invalid', 'user', 1, ['email' => 'u@example.com', 'delegation_ids' => [1, 2]]);

    $result = $builder->build($intent, invalidMockUser());
    $rows = collect($result->data['delegations'])->keyBy('zone');

    expect($rows['a.com']['prefix'])->toBe('_dnsauth')
        ->and($rows['a.com']['target_fqdn'])->toBe('lbl.proxy.test')
        ->and($rows['a.com']['fail_count'])->toBe(3)
        ->and($rows['b.com']['prefix'])->toBe('_certum')
        ->and($rows['b.com']['fail_count'])->toBe(5);
});

test('全恢复/全删（fetchInvalidDelegations 空）→ build 返 null 不发', function () {
    $builder = buildInvalidPartial(new Collection);
    $intent = new NotificationIntent('delegation_invalid', 'user', 1, ['email' => 'u@example.com', 'delegation_ids' => [1]]);

    expect($builder->build($intent, invalidMockUser()))->toBeNull();
});

test('payload 不含 last_error/原始异常文本 + 固定友好文案 + 无携密字段（I2 回归护栏）', function () {
    $delegations = new Collection([invalidMockDelegation('a.com', '_dnsauth', 'lbl.proxy.test', 3)]);
    $builder = buildInvalidPartial($delegations);
    $intent = new NotificationIntent('delegation_invalid', 'user', 1, ['email' => 'u@example.com', 'delegation_ids' => [1]]);

    $result = $builder->build($intent, invalidMockUser());
    $json = json_encode($result->data);

    // last_error / SQLSTATE 绝不进 payload
    expect($json)->not->toContain('last_error')
        ->and($json)->not->toContain('SQLSTATE');

    // 固定用户友好文案
    expect($result->data['hint'])->toContain('CNAME')
        ->and($result->data['hint'])->toContain('委托解析');

    // 无携密字段
    foreach (['csr', 'private_key', 'token', 'hmac', 'password'] as $sensitive) {
        expect($result->data)->not->toHaveKey($sensitive);
    }
});

test('site_url/site_name 由 Builder 从系统设置注入，subject/is_html 正确', function () {
    $builder = buildInvalidPartial(new Collection([invalidMockDelegation('a.com', '_dnsauth', 't', 2)]));
    $intent = new NotificationIntent('delegation_invalid', 'user', 1, ['email' => 'u@example.com', 'delegation_ids' => [1]]);

    $result = $builder->build($intent, invalidMockUser());

    expect($result->data['site_url'])->toBe('https://ssl.test/')
        ->and($result->data['site_name'])->toBe('SSL证书管理系统')
        ->and($result->data['email'])->toBe('u@example.com')
        ->and($result->data['username'])->toBe('testuser')
        ->and($result->data['_meta']['subject'])->toContain('域名委托失效提醒')
        ->and($result->data['_meta']['is_html'])->toBeTrue();
});

test('fetchInvalidDelegations 真库单侧重查：只返 valid=false，valid=true 与已删 id 剔除', function () {
    $user = User::factory()->create();
    $invalid = CnameDelegation::factory()->create(['user_id' => $user->id, 'valid' => false, 'zone' => 'inv.com']);
    $valid = CnameDelegation::factory()->create(['user_id' => $user->id, 'valid' => true, 'zone' => 'val.com']);

    $builder = new DelegationInvalidNotificationBuilder;
    $method = new ReflectionMethod($builder, 'fetchInvalidDelegations');
    $method->setAccessible(true);

    // 传 valid=false + valid=true + 不存在的 id（模拟已删）
    $result = $method->invoke($builder, [$invalid->id, $valid->id, 999999999]);

    expect($result)->toHaveCount(1)
        ->and($result->first()->id)->toBe($invalid->id);
});

test('三件套 + 强制发：config builder 命中 + 不入偏好表 + allowsNotification 默认 true + seeder 幂等 + 模板性质说明', function () {
    // ① config builders 命中新 code（缺则端到端回落 DefaultBuilder、渲染不出委托列表）
    expect(config('notification.builders')['delegation_invalid'])->toBe(DelegationInvalidNotificationBuilder::class);

    // ② 强制发：不入 user_default_preferences（误加入表即强制发静默失效且用户可关）
    expect(array_key_exists('delegation_invalid', config('notification.user_default_preferences')))->toBeFalse();

    // ③ allowsNotification 未登记 code 默认 true；用户显式塞 false 被 setter 白名单丢弃 → 仍强制发
    $user = User::factory()->create();
    expect($user->allowsNotification('delegation_invalid'))->toBeTrue();
    $user->notification_settings = ['delegation_invalid' => false];
    $user->save();
    expect($user->fresh()->allowsNotification('delegation_invalid'))->toBeTrue();

    // ④ seeder 幂等（firstOrCreate，新 code 首建、重跑 no-op）
    (new NotificationTemplateSeeder)->run();
    (new NotificationTemplateSeeder)->run();
    $templates = NotificationTemplate::where('code', 'delegation_invalid')->get();
    expect($templates)->toHaveCount(1)
        ->and($templates->first()->status)->toBe(1);

    // ⑤ 模板含性质说明段（强制发家族）
    expect($templates->first()->content)
        ->toContain('委托失效重要提醒')
        ->toContain('不受常规到期提醒偏好控制');
});
