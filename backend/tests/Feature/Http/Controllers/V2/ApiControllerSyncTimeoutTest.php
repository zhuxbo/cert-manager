<?php

use App\Exceptions\ApiResponseException;
use App\Models\ApiToken;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Order\Api\Api;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\Traits\CreatesTestData;

/**
 * get 端点在同步（sync）阶段遇上游超时的柔性回归。
 *
 * 背景：Order\Api\Api::get → handleResult（Api.php:181）在 code!==1 时抛 ApiResponseException，
 * 这与 pay/commit 调用点一致（V1/V2 ApiController::get 均有 try-catch）。但 sync 调用点
 * （V1 ApiController.php / V2 ApiController.php）历史上漏了 try-catch，导致上游 get 超时时
 * 未捕获的异常直接冒泡为 get 端点响应，返回 {code:0,...} 而非本地已有订单数据——
 * 与代码注释「同步失败不影响返回已有数据」矛盾。
 *
 * 验证：不预置 api_get_ 缓存（让 sync 真正触发），桩 Order\Api\Api::get 返回 code=0
 * （模拟 SDK 把上游超时压成的失败结果，对齐 handleResult 抛出的 ApiResponseException），
 * 断言 get 端点仍返回 code=1 + 本地订单字段，而非把同步失败暴露给下游调用方。
 */
uses(CreatesTestData::class);

beforeEach(function () {
    Cache::flush();
});

function syncTimeoutV2AuthHeaders(User $user): array
{
    $plainToken = ApiToken::createToken($user->id);

    return ['Authorization' => "Bearer $plainToken"];
}

/**
 * 上游桩：get() 直接抛 ApiResponseException(code=0)，等价于 handleResult 对上游超时/失败结果的处理
 * （Sdk::call() catch Guzzle 异常后返回 code=0 数组 → handleResult 转 $this->error() 抛出）。
 */
function bindSyncTimeoutStub(): MockInterface
{
    $mock = Mockery::mock(Api::class);
    $mock->shouldReceive('get')->andThrow(new ApiResponseException('上游连接超时，请稍后重试'));
    app()->instance(Api::class, $mock);

    return $mock;
}

test('V2 get 端点：上游 sync 超时不影响返回本地订单数据', function () {
    $user = $this->createTestUser(['balance' => '100.00']);
    $product = Product::factory()->create();

    $order = $user->orders()->create([
        'product_id' => $product->id,
        'brand' => $product->brand,
        'period' => 12,
        'amount' => '100.00',
        'period_from' => now(),
        'period_till' => now()->addYear(),
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);

    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'processing',
        'api_id' => 'upstream-api-id',
        'amount' => '100.00',
        'standard_count' => 1,
        'wildcard_count' => 0,
        'vendor_id' => 'vendor-abc',
        'common_name' => 'sync-timeout.example.com',
    ]);

    $order->update(['latest_cert_id' => $cert->id]);

    Transaction::create([
        'user_id' => $user->id,
        'type' => 'order',
        'transaction_id' => $order->id,
        'amount' => '-100.00',
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]);

    // 不预置 api_get_ 缓存 —— get 端点会因 processing 状态触发 sync 调上游
    bindSyncTimeoutStub();

    $headers = syncTimeoutV2AuthHeaders($user);
    $response = test()->withHeaders($headers)->getJson('/api/v2/get?order_id='.$order->id);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.vendor_id'))->toBe('vendor-abc');
    expect($response->json('data.common_name'))->toBe('sync-timeout.example.com');
});
