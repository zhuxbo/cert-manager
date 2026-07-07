<?php

use App\Models\Cert;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('order() 关系存在且为 BelongsTo，外键 order_id', function () {
    $target = new CloudDeployTarget;

    expect(method_exists($target, 'order'))->toBeTrue();

    $relation = $target->order();
    expect($relation)->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    expect($relation->getForeignKeyName())->toBe('order_id');
    expect($relation->getRelated())->toBeInstanceOf(Order::class);
});

test('order() 不带全局作用域：跨用户也能取到 order 及其 latestCert', function () {
    // 业主 A 的订单 + 证书；target 属于 A。
    // 工厂接线沿用插件既有惯用法（见 tests/Unit/Jobs/CloudDeployJobTest.php）：先建 order，
    // 再 Cert::factory(order_id=order)，最后 order.update(latest_cert_id)，不造孤儿 order。
    $owner = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $owner->id]);
    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'common_name' => 'relation.example.com',
        'status' => 'active',
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $target = CloudDeployTarget::create([
        'user_id' => $owner->id, 'access_id' => 1, 'order_id' => $order->id,
        'product' => 'cdn', 'config' => ['domain' => 'relation.example.com'],
    ]);

    // 模拟管理端「无当前用户」上下文：不登录任何用户，主系统 Order 若被 UserScope 约束会取不到
    $loaded = CloudDeployTarget::with('order.latestCert')->find($target->id);

    expect($loaded->order)->not->toBeNull();
    expect($loaded->order->id)->toBe($order->id);
    expect($loaded->order->latestCert)->not->toBeNull();
    expect($loaded->order->latestCert->common_name)->toBe('relation.example.com');
});

test('whereHas(order.latestCert) 按证书域名可过滤 target（域名搜索前置）', function () {
    $owner = User::factory()->create();

    $orderHit = Order::factory()->create(['user_id' => $owner->id]);
    $certHit = Cert::factory()->create(['order_id' => $orderHit->id, 'common_name' => 'hit.example.com', 'status' => 'active']);
    $orderHit->update(['latest_cert_id' => $certHit->id]);
    $targetHit = CloudDeployTarget::create([
        'user_id' => $owner->id, 'access_id' => 1, 'order_id' => $orderHit->id,
        'product' => 'cdn', 'config' => ['domain' => 'hit.example.com'],
    ]);

    $orderMiss = Order::factory()->create(['user_id' => $owner->id]);
    $certMiss = Cert::factory()->create(['order_id' => $orderMiss->id, 'common_name' => 'other.example.org', 'status' => 'active']);
    $orderMiss->update(['latest_cert_id' => $certMiss->id]);
    CloudDeployTarget::create([
        'user_id' => $owner->id, 'access_id' => 1, 'order_id' => $orderMiss->id,
        'product' => 'cdn', 'config' => ['domain' => 'other.example.org'],
    ]);

    $ids = CloudDeployTarget::whereHas('order.latestCert', function ($q) {
        $q->where('common_name', 'like', '%hit.example%');
    })->pluck('id')->all();

    expect($ids)->toBe([$targetHit->id]);
});
