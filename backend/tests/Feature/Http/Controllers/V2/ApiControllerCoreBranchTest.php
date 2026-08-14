<?php

use App\Exceptions\ApiResponseException;
use App\Http\Controllers\V2\ApiController;
use App\Models\Order;
use App\Services\Order\Action;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Traits\CreatesTestData;

uses(CreatesTestData::class);

beforeEach(function () {
    Cache::flush();
});

function setV2ControllerProperty(ApiController $controller, string $property, mixed $value): void
{
    $reflection = new ReflectionClass($controller);
    $prop = $reflection->getProperty($property);
    $prop->setValue($controller, $value);
}

function buildV2Controller(array $input, string $method, Action $action, int $userId): ApiController
{
    $request = Request::create('/api/v2/test', $method, $input);

    // 跳过构造函数（构造函数依赖 Auth guard，测试中不可用）
    $reflection = new ReflectionClass(ApiController::class);
    $controller = $reflection->newInstanceWithoutConstructor();

    setV2ControllerProperty($controller, 'request', $request);
    setV2ControllerProperty($controller, 'user_id', $userId);
    setV2ControllerProperty($controller, 'model', new Order);
    setV2ControllerProperty($controller, 'action', $action);

    return $controller;
}

function captureApiResponse(callable $callback): array
{
    try {
        $callback();
        test()->fail('Expected ApiResponseException but none was thrown.');
    } catch (ApiResponseException $exception) {
        return $exception->getApiResponse();
    }
}

test('v2 cancel 对已取消订单直接返回成功（幂等）', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product);
    $cert = $this->createTestCert($order, ['status' => 'cancelled']);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldNotReceive('deleteTask');
    $mockAction->shouldNotReceive('cancel');
    $mockAction->shouldNotReceive('cancelPending');
    $mockAction->shouldNotReceive('guardCancelDuplicate');
    $mockAction->shouldNotReceive('prepareImmediateCancel');

    $controller = buildV2Controller([
        'order_id' => $order->id,
    ], 'POST', $mockAction, $user->id);

    $response = captureApiResponse(fn () => $controller->cancel());

    expect($response['code'])->toBe(1);
});

test('v2 cancel 精确退款期边界允许取消', function () {
    Carbon::setTestNow('2026-08-07 12:00:00');

    $user = $this->createTestUser();
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product);
    $order->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();
    $cert = $this->createTestCert($order, ['status' => 'active']);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('guardCancelDuplicate')->once()->with($order->id);
    $mockAction->shouldReceive('prepareImmediateCancel')->once()->with($order->id)->andReturnUsing(function () use ($cert) {
        $cert->update(['status' => 'cancelling']);

        return false;
    });
    $mockAction->shouldNotReceive('deleteTask');
    $mockAction->shouldReceive('cancel')->once()->with($order->id);

    $controller = buildV2Controller(['order_id' => $order->id], 'POST', $mockAction, $user->id);
    $controller->cancel();

    expect($order->latestCert()->first()->status)->toBe('cancelling');
});
