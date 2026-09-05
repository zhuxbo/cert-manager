<?php

use App\Exceptions\MutationBusyException;
use App\Services\Acme\Action as AcmeAction;
use App\Services\Order\Action as OrderAction;
use Illuminate\Support\Facades\Cache;

// 验证 commit/cancel/pay/cancelNow 已被 order 级互斥锁包裹（方案 C）：
// 用不存在的订单 id 占住互斥锁，调用应抛 MutationBusyException（抢锁失败）
// 而非「订单不存在」/ModelNotFound——精确证明 withMutex 在方法最外层、
// 抢锁早于查 DB/调上游（否则会先查库抛别的异常）。array driver 进程内互斥，不碰真实 DB。
beforeEach(function () {
    config(['cache.default' => 'array']);
    Cache::store('array')->flush();
});

test('互斥锁被占用时立即抛 MutationBusyException（抢锁早于查 DB/上游）', function (string $actionClass, string $method, string $keyPrefix) {
    $id = 999999;
    expect(Cache::store('runtime')->lock("{$keyPrefix}{$id}", 60)->get())->toBeTrue();

    expect(fn () => app($actionClass)->$method($id))
        ->toThrow(MutationBusyException::class);
})->with([
    'Order commit' => [OrderAction::class, 'commit', 'order_mutate_'],
    'Order cancel' => [OrderAction::class, 'cancel', 'order_mutate_'],
    'ACME commit' => [AcmeAction::class, 'commit', 'acme_mutate_'],
    'ACME pay' => [AcmeAction::class, 'pay', 'acme_mutate_'],
    'ACME cancel' => [AcmeAction::class, 'cancel', 'acme_mutate_'],
    'ACME cancelNow' => [AcmeAction::class, 'cancelNow', 'acme_mutate_'],
]);

test('不同订单 id 的互斥锁互不阻塞', function () {
    expect(Cache::store('runtime')->lock('order_mutate_111', 60)->get())->toBeTrue();

    // 占了 111，对 222 的 commit 不应抛 MutationBusyException（会因订单不存在抛别的）
    expect(fn () => app(OrderAction::class)->commit(222))
        ->not->toThrow(MutationBusyException::class);
});
