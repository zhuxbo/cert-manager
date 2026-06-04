<?php

use App\Models\Cert;
use App\Models\CnameDelegation;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Delegation\CnameDelegationService;

beforeEach(function () {
    $this->delegationService = Mockery::mock(CnameDelegationService::class);
    $this->app->instance(CnameDelegationService::class, $this->delegationService);
});

test('签名为 delegation:check', function () {
    $this->artisan('delegation:check')
        ->expectsOutputToContain('检查完成')
        ->assertSuccessful();
});

test('有效委托记录标记为有效', function () {
    $user = User::factory()->create();
    $delegation = CnameDelegation::factory()->create(['user_id' => $user->id]);

    $this->delegationService->shouldReceive('checkAndUpdateValidity')
        ->once()
        ->andReturn(true);

    $this->artisan('delegation:check')
        ->expectsOutputToContain('有效')
        ->assertSuccessful();
});

test('无效委托且无活跃证书时删除', function () {
    $user = User::factory()->create();
    $delegation = CnameDelegation::factory()->create([
        'user_id' => $user->id,
        'zone' => 'unused.com',
    ]);

    $this->delegationService->shouldReceive('checkAndUpdateValidity')
        ->once()
        ->andReturn(false);

    $this->artisan('delegation:check')
        ->expectsOutputToContain('已删除')
        ->assertSuccessful();

    expect(CnameDelegation::find($delegation->id))->toBeNull();
});

test('无效委托但有活跃证书时保留', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $delegation = CnameDelegation::factory()->create([
        'user_id' => $user->id,
        'zone' => 'active-domain.com',
    ]);

    // 创建一个使用该域名的活跃证书
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    Cert::factory()->active()->create([
        'order_id' => $order->id,
        'common_name' => 'active-domain.com',
        'alternative_names' => 'active-domain.com',
        // 需要跳过 retrieved 事件中 issuer 检查
        'issuer' => null,
    ]);

    $this->delegationService->shouldReceive('checkAndUpdateValidity')
        ->once()
        ->andReturn(false);

    $this->artisan('delegation:check')
        ->expectsOutputToContain('保留')
        ->assertSuccessful();

    expect(CnameDelegation::find($delegation->id))->not->toBeNull();
});

test('dry-run 模式不删除记录', function () {
    $user = User::factory()->create();
    $delegation = CnameDelegation::factory()->create([
        'user_id' => $user->id,
        'zone' => 'unused.com',
    ]);

    $this->delegationService->shouldReceive('checkAndUpdateValidity')
        ->once()
        ->andReturn(false);

    $this->artisan('delegation:check --dry-run')
        ->expectsOutputToContain('dry-run')
        ->assertSuccessful();

    // dry-run 模式下记录不应被删除
    expect(CnameDelegation::find($delegation->id))->not->toBeNull();
});

test('检查异常时记录错误并继续', function () {
    $user = User::factory()->create();
    CnameDelegation::factory()->create(['user_id' => $user->id]);

    $this->delegationService->shouldReceive('checkAndUpdateValidity')
        ->once()
        ->andThrow(new Exception('DNS 查询超时'));

    $this->artisan('delegation:check')
        ->expectsOutputToContain('检查异常')
        ->assertSuccessful();
});

test('连续失败次数超过阈值时输出预警', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    $delegation = CnameDelegation::factory()->create([
        'user_id' => $user->id,
        'zone' => 'failing.com',
        'fail_count' => 5,
    ]);

    // 创建使用该域名的活跃证书（保留委托记录）
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    Cert::factory()->active()->create([
        'order_id' => $order->id,
        'common_name' => 'failing.com',
        'issuer' => null,
    ]);

    $this->delegationService->shouldReceive('checkAndUpdateValidity')
        ->once()
        ->andReturn(false);

    $this->artisan('delegation:check')
        ->expectsOutputToContain('连续失败')
        ->assertSuccessful();
});

test('无委托记录时正常完成', function () {
    $this->artisan('delegation:check')
        ->expectsOutputToContain('检查完成')
        ->assertSuccessful();
});
