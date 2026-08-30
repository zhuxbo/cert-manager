<?php

use App\Models\Cert;
use App\Models\CnameDelegation;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Delegation\CnameDelegationService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    $this->delegationService = Mockery::mock(CnameDelegationService::class);
    $this->app->instance(CnameDelegationService::class, $this->delegationService);
});

afterEach(fn () => Mockery::close());

function checkDelegationRow(User $user, array $overrides = []): CnameDelegation
{
    return CnameDelegation::factory()->create(array_merge(['user_id' => $user->id], $overrides));
}

function checkReferencedCert(User $user, string $domain, string $status = 'active', ?string $alternativeNames = null): Cert
{
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => Product::factory(),
    ]);

    return Cert::factory()->create([
        'order_id' => $order->id,
        'status' => $status,
        'common_name' => $domain,
        'alternative_names' => $alternativeNames ?? $domain,
        'issuer' => null,
    ]);
}

test('无有效证书引用时立即删除委托且不查询 DNS', function () {
    $user = User::factory()->create();
    $delegation = checkDelegationRow($user, [
        'zone' => 'unused.example.com',
        'valid' => true,
        'fail_count' => 0,
    ]);

    $this->delegationService->shouldReceive('probeConfiguredDomains')->never();
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')->never();

    $this->artisan('delegation:check')
        ->expectsOutputToContain('无有效证书引用，已删除')
        ->assertSuccessful();

    expect($delegation->fresh())->toBeNull();
});

test('dry-run 只报告无引用记录且不删除不查询 DNS', function () {
    $user = User::factory()->create();
    $delegation = checkDelegationRow($user, ['zone' => 'unused.example.com']);

    $this->delegationService->shouldReceive('probeConfiguredDomains')->never();
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')->never();

    $this->artisan('delegation:check --dry-run')
        ->expectsOutputToContain('无有效证书引用，将删除 (dry-run)')
        ->assertSuccessful();

    expect($delegation->fresh())->not->toBeNull();
});

test('有引用时全局检测并把命中的委托域交给 CAS 落库', function () {
    $user = User::factory()->create();
    $delegation = checkDelegationRow($user, [
        'zone' => 'example.com',
        'proxy_domain' => 'old.example.net',
    ]);
    checkReferencedCert($user, 'www.example.com');

    $this->delegationService->shouldReceive('probeConfiguredDomains')
        ->once()
        ->withArgs(fn (CnameDelegation $value) => $value->is($delegation))
        ->andReturn(['outcome' => 'valid', 'proxy_domain' => 'new.example.net']);
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')
        ->once()
        ->with($delegation->id, 'valid', null, 'new.example.net')
        ->andReturn(true);

    $this->artisan('delegation:check')
        ->expectsOutputToContain('有效')
        ->assertSuccessful();

    expect($delegation->fresh())->not->toBeNull();
});

test('有引用但全局检测无效时仍保留委托记录', function () {
    $user = User::factory()->create();
    $delegation = checkDelegationRow($user, [
        'zone' => 'example.com',
        'valid' => true,
        'fail_count' => 99,
    ]);
    checkReferencedCert($user, 'example.com', 'pending');

    $this->delegationService->shouldReceive('probeConfiguredDomains')
        ->once()
        ->andReturn(['outcome' => 'invalid', 'proxy_domain' => null]);
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')
        ->once()
        ->with($delegation->id, 'invalid', null, null)
        ->andReturn(true);

    $this->artisan('delegation:check')
        ->expectsOutputToContain('无效但仍有证书引用，保留')
        ->assertSuccessful();

    expect($delegation->fresh())->not->toBeNull();
});

test('cancelling 证书也属于有效引用', function () {
    $user = User::factory()->create();
    $delegation = checkDelegationRow($user, ['zone' => 'example.com']);
    checkReferencedCert($user, 'example.com', 'cancelling');

    $this->delegationService->shouldReceive('probeConfiguredDomains')
        ->once()
        ->andReturn(['outcome' => 'unreachable', 'proxy_domain' => null]);
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')
        ->once()
        ->with($delegation->id, 'unreachable', null, null)
        ->andReturn(true);

    $this->artisan('delegation:check')->assertSuccessful();

    expect($delegation->fresh())->not->toBeNull();
});

test('Unicode 委托域能识别 Punycode 证书引用', function () {
    $user = User::factory()->create();
    $delegation = checkDelegationRow($user, ['zone' => '例子.中国']);
    checkReferencedCert($user, 'www.xn--fsqu00a.xn--fiqs8s');

    $this->delegationService->shouldReceive('probeConfiguredDomains')
        ->once()
        ->andReturn(['outcome' => 'unreachable', 'proxy_domain' => null]);
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')
        ->once()
        ->andReturn(true);

    $this->artisan('delegation:check')->assertSuccessful();

    expect($delegation->fresh())->not->toBeNull();
});

test('alternative_names 中的子域证书引用也会保留委托', function () {
    $user = User::factory()->create();
    $delegation = checkDelegationRow($user, ['zone' => 'example.com']);
    checkReferencedCert($user, 'unrelated.test', 'approving', 'unrelated.test,api.example.com');

    $this->delegationService->shouldReceive('probeConfiguredDomains')
        ->once()
        ->andReturn(['outcome' => 'unreachable', 'proxy_domain' => null]);
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')
        ->once()
        ->andReturn(true);

    $this->artisan('delegation:check')->assertSuccessful();

    expect($delegation->fresh())->not->toBeNull();
});

test('CAS 未命中时跳过陈旧探测结果', function () {
    $user = User::factory()->create();
    $delegation = checkDelegationRow($user, ['zone' => 'example.com']);
    checkReferencedCert($user, 'example.com');

    $this->delegationService->shouldReceive('probeConfiguredDomains')
        ->once()
        ->andReturn(['outcome' => 'invalid', 'proxy_domain' => null]);
    $this->delegationService->shouldReceive('applyProbeOutcomeIfUnchanged')
        ->once()
        ->andReturn(false);

    $this->artisan('delegation:check')
        ->expectsOutputToContain('本轮结论作废')
        ->assertSuccessful();

    expect($delegation->fresh())->not->toBeNull();
});

test('检查异常时记录错误并继续', function () {
    $user = User::factory()->create();
    $delegation = checkDelegationRow($user, ['zone' => 'example.com']);
    checkReferencedCert($user, 'example.com');

    $this->delegationService->shouldReceive('probeConfiguredDomains')
        ->once()
        ->andThrow(new RuntimeException('DNS 查询超时'));

    $this->artisan('delegation:check')
        ->expectsOutputToContain('检查异常')
        ->assertSuccessful();

    expect($delegation->fresh())->not->toBeNull();
});

test('无委托记录时正常完成', function () {
    $this->artisan('delegation:check')
        ->expectsOutputToContain('检查完成')
        ->assertSuccessful();
});

test('schedule 注册 delegation:check 为周一 07:00', function () {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);
    $event = collect($schedule->events())
        ->first(fn (Event $value) => str_contains((string) ($value->command ?? ''), 'delegation:check'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 7 * * 1');
})->group('database');

test('委托周巡检不配置独立用户通知 Builder', function () {
    expect(config('notification.builders'))->not->toHaveKey('delegation_invalid');
});
