<?php

use App\Exceptions\ApiResponseException;
use App\Exceptions\MutationBusyException;
use App\Services\Order\OrderCommitResilience;

/**
 * commit 段吞并守卫收敛原语——锁定吞与不吞的 P0 边界，收敛前后一条不挪。
 */

// ---- commit 段：吞 code=0（订单停 pending、不冒泡）----
test('action=commit 吞 SDK code=0：返回 []、不调 onError', function () {
    $onErrorCalled = false;
    $result = OrderCommitResilience::run(
        fn () => throw new ApiResponseException('上游超时', null, null, 0),
        'commit',
        function () use (&$onErrorCalled) {
            $onErrorCalled = true;
        },
    );

    expect($result)->toBe([])
        ->and($onErrorCalled)->toBeFalse();
});

// ---- commit 段：吞 MutationBusyException（抢锁忙 = 成功态、不外抛 503）----
test('action=commit 吞 MutationBusyException：返回 []', function () {
    $result = OrderCommitResilience::run(
        fn () => throw new MutationBusyException('busy'),
        'commit',
        fn () => null,
    );

    expect($result)->toBe([]);
});

// ---- 非 commit 段：code=0 走 onError（重新构造错误、照常报错）----
test('action=pay 遇 code=0：调用 onError（不吞）', function () {
    $seen = null;
    $run = function () use (&$seen) {
        return OrderCommitResilience::run(
            fn () => throw new ApiResponseException('余额不足', ['x' => 1], null, 0),
            'pay',
            function (array $r) use (&$seen) {
                $seen = $r;
                throw new ApiResponseException($r['msg'], $r['errors'] ?? null, null, 0);
            },
        );
    };
    expect($run)->toThrow(ApiResponseException::class);

    expect($seen['msg'])->toBe('余额不足')
        ->and($seen['errors'])->toBe(['x' => 1]);
});

// ---- 非 commit 段：MutationBusyException 向上抛（Deploy M-2 不对称：pay 的 busy 仍 503）----
test('action=pay 遇 MutationBusyException：向上抛（M-2 不对称保留）', function () {
    expect(fn () => OrderCommitResilience::run(
        fn () => throw new MutationBusyException('busy'),
        'pay',
        fn () => null,
    ))->toThrow(MutationBusyException::class);
});

// ---- 成功态：code=1 原样返回（commit 成功由 success() 抛出）----
test('code=1 成功响应原样返回', function () {
    $result = OrderCommitResilience::run(
        fn () => throw new ApiResponseException('', null, ['order_id' => 42], 1),
        'commit',
        fn () => null,
    );

    expect($result['code'])->toBe(1)
        ->and($result['data']['order_id'])->toBe(42);
});

// ---- 无异常（invoke 正常返回）：返回 []----
test('invoke 正常返回（无异常）：返回 []', function () {
    $result = OrderCommitResilience::run(fn () => null, 'commit', fn () => null);

    expect($result)->toBe([]);
});
