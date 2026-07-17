<?php

use App\Bootstrap\ApiExceptions;
use App\Exceptions\MutationBusyException;
use App\Exceptions\ProductPriceMutationBusyException;
use App\Exceptions\ProductPriceMutationLockException;
use App\Services\LogBuffer;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(fn () => LogBuffer::clear());
afterEach(fn () => LogBuffer::clear());

// 锁定 MutationBusyException 在同步入口的处理契约：503（建议客户端重试）+ 友好文案 + 免日志。
// 与 TaskJob 的异步 release 分流互补（异步路径见 TaskJob 测试）。

function invokeProtected(object $obj, string $method, ...$args): mixed
{
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);

    return $ref->invoke($obj, ...$args);
}

test('MutationBusyException 映射为 503', function () {
    $status = invokeProtected(new ApiExceptions, 'getExceptionStatusCode', new MutationBusyException);

    expect($status)->toBe(503);
});

test('MutationBusyException 响应体为 code 0 + 友好文案', function () {
    $resp = invokeProtected(new ApiExceptions, 'handleApiException', new MutationBusyException);
    $data = $resp->getData(true);

    expect($resp->getStatusCode())->toBe(503)
        ->and($data['code'])->toBe(0)
        ->and($data['msg'])->toBe('该订单正在处理中，请稍后重试');
});

test('MutationBusyException 不记入 error_logs（高频忙信号降噪）', function () {
    $shouldNotLog = invokeProtected(new ApiExceptions, 'shouldNotLog', new MutationBusyException);

    expect($shouldNotLog)->toBeTrue();
});

test('ProductPriceMutationBusyException 映射为 503 和价格专用文案', function () {
    $resp = invokeProtected(new ApiExceptions, 'handleApiException', new ProductPriceMutationBusyException);
    $data = $resp->getData(true);

    expect($resp->getStatusCode())->toBe(503)
        ->and($data['code'])->toBe(0)
        ->and($data['msg'])->toBe('产品价格正在变更，请稍后重试');
});

test('ProductPriceMutationLockException 映射为 503 和基础设施文案', function () {
    $resp = invokeProtected(new ApiExceptions, 'handleApiException', new ProductPriceMutationLockException);
    $data = $resp->getData(true);

    expect($resp->getStatusCode())->toBe(503)
        ->and($data['code'])->toBe(0)
        ->and($data['msg'])->toBe('产品价格变更失败，请稍后重试');
});

test('产品价格忙异常免日志而锁基础设施异常进入日志缓冲', function () {
    $exceptions = new ApiExceptions;
    $exceptions->logException(new ProductPriceMutationBusyException);
    expect(LogBuffer::count())->toBe(0);

    $exceptions->logException(new ProductPriceMutationLockException('release failed'));
    expect(LogBuffer::count())->toBe(1);
});
