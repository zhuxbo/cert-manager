<?php

use App\Exceptions\ApiResponseException;
use App\Models\Cert;
use App\Models\Task;
use App\Services\Order\Action;
use App\Services\Order\Api\Api;
use Tests\Traits\CreatesTestData;

uses(CreatesTestData::class);

/**
 * 杀手场景回归：sync 在锁外做上游 get（慢 IO）期间，并发重签完成
 *   —— 旧 cert A→reissued、order.latest_cert_id 切到新 cert B(pending)、B 建延时 commit task。
 *
 * 终态守卫与写回必须按"写回目标 cert A 自身"判定，而非 order 当前 latestCert(=B)。
 * 否则：① 上游滞后的 active 把已 reissued 的 A 复活；② 误删 B 的 commit task，
 * 重签已扣费却永不提交、自动重签静默失败、证书到期不续。
 */
test('sync 锁外 get 期间并发重签：不复活旧 cert、不删新 cert 的 commit task', function () {
    $user = $this->createTestUser(['balance' => '100.00']);
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product, [
        'amount' => '100.00',
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);
    // 写回目标 cert A：processing（sync 非 force 允许同步；非终态，上游返回 active 时会触发 status 写回）
    $certA = $this->createTestCert($order, ['status' => 'processing', 'action' => 'new', 'api_id' => 'reissue-race-A']);

    // mock 上游 get：返回滞后的 active，并在"IO 窗口"内模拟并发重签完成（直接改库）
    $mock = Mockery::mock(Api::class);
    $mock->shouldReceive('get')->andReturnUsing(function () use ($order, $certA) {
        Cert::where('id', $certA->id)->update(['status' => 'reissued']); // A→reissued
        $certB = Cert::create([                                          // 新 cert B(pending)
            'order_id' => $order->id,
            'action' => 'reissue',
            'channel' => 'api',
            'status' => 'pending',
            'common_name' => 'example.com',
            'alternative_names' => 'example.com',
            'standard_count' => 1,
            'wildcard_count' => 0,
            'csr' => $certA->csr,
            'dcv' => ['method' => 'txt', 'dns' => ['host' => '_dnsauth']],
            'validation' => [],
            'expires_at' => now()->addDays(90),
        ]);
        $order->update(['latest_cert_id' => $certB->id]); // latestCert 切到 B
        Task::create([                                    // 重签 pay 后建的延时 commit task
            'order_id' => $order->id,
            'action' => 'commit',
            'status' => 'executing',
            'attempts' => 0,
        ]);

        return ['code' => 1, 'data' => ['status' => 'active']];
    });
    app()->instance(Api::class, $mock);

    try {
        app(Action::class)->sync($order->id); // 非 force，末尾 success() 抛 code=1
    } catch (ApiResponseException $e) {
        if ($e->getApiResponse()['code'] !== 1) {
            throw $e;
        }
    }

    // ① 旧 cert A 不被上游滞后 active 复活，保持 reissued
    expect(Cert::find($certA->id)->status)->toBe('reissued');
    // ② B 的延时 commit task 不被误删（否则重签静默失败）
    expect(Task::where('order_id', $order->id)->where('action', 'commit')->where('status', 'executing')->count())->toBe(1);
    // ③ latest_cert_id 仍指向 B（锁外 stale $order->save() 不得回写 A）
    expect($order->fresh()->latest_cert_id)->toBe(Cert::where('order_id', $order->id)->where('status', 'pending')->first()->id);
});
