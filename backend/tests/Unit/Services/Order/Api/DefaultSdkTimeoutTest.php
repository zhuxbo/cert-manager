<?php

use App\Services\Order\Api\default\Sdk;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

// 锁定 Order default Sdk 的「锁内调用必须带超时、锁外调用不设超时」契约。
//
// 背景：commit()/cancel() 在 orders 行锁内同步调上游（Action.php:415/1017），上游用的
// Guzzle 客户端原先 `new Client` 无 timeout（默认无限），上游慢/挂时持锁 > innodb_lock_wait_timeout(50s)
// → 并发访问同一订单行的 for update 报 1205 锁等待超时。修复：仅给锁内调用加 30s 超时（反查 5s），
// 锁外的耗时文档上传(uploadDocument)/同步查询(get) 保持无超时不被掐断。
//
// Order Sdk 用 Guzzle（非 Laravel Http facade），Http::fake 拦不到；故经 makeClient() 注入缝捕获
// 传给 Guzzle 的 config，直接断言 timeout —— 测「我们传给 Guzzle 的超时配置」，不测 Guzzle 自身行为。
// 配置走 setting 缓存注入（array driver），不碰 DB，无需 RefreshDatabase。

/**
 * 捕获每次 makeClient 收到的 Guzzle config，并用 MockHandler 短路真实 HTTP。
 */
class CapturingOrderSdk extends Sdk
{
    /** @var array<int, array> 每次 makeClient 收到的 config，按调用顺序 */
    public array $configs = [];

    /** @var array<int, Response> 按 makeClient 调用顺序弹出的预设响应；空则回落成功响应 */
    public array $responseQueue = [];

    protected function makeClient(array $config = []): Client
    {
        $this->configs[] = $config;

        $resp = array_shift($this->responseQueue)
            ?? new Response(200, [], (string) json_encode(['code' => 1, 'data' => ['api_id' => 'X']]));

        return new Client(['handler' => HandlerStack::create(new MockHandler([$resp]))]);
    }
}

// get_system_setting('ca',$k) → Setting::getValue → getByGroupName('ca')
//   → Cache::remember('setting:group_name:ca', ...)（Setting::CACHE_PREFIX = 'setting:'）
// 直接 put 该缓存键即可命中、不查 DB。
beforeEach(function () {
    Cache::put('setting:group_name:ca', ['url' => 'https://gateway.test/api/v2', 'token' => 'tok'], 60);
});

// 锁内：commit 下单（new/renew/reissue）+ cancel —— 必须带 28s 超时（与反查 10+10 合计 < innodb_lock_wait_timeout 50s）
test('锁内上游调用带 28s 超时', function (string $method, array $args) {
    $sdk = new CapturingOrderSdk;

    $sdk->$method(...$args);

    expect($sdk->configs)->toHaveCount(1)
        ->and($sdk->configs[0])->toMatchArray(['connect_timeout' => 10, 'timeout' => 28]);
})->with([
    'new' => ['new', [['refer_id' => 'a']]],
    'renew' => ['renew', [['refer_id' => 'a']]],
    'reissue' => ['reissue', [['refer_id' => 'a']]],
    'cancel' => ['cancel', [123]],
]);

// 锁外：耗时文档上传 / 同步查询 / 产品列表 —— 不设超时（保护慢上传不被掐断）
test('锁外上游调用不设超时', function (string $method, array $args) {
    $sdk = new CapturingOrderSdk;

    $sdk->$method(...$args);

    expect($sdk->configs)->toHaveCount(1)
        ->and($sdk->configs[0])->not->toHaveKey('timeout')
        ->and($sdk->configs[0])->not->toHaveKey('connect_timeout');
})->with([
    'uploadDocument' => ['uploadDocument', ['UP1', ['type' => 'A', 'fileName' => 'a.pdf', 'document_content' => 'QQ==']]],
    'get(sync)' => ['get', [123]],
    'getProducts' => ['getProducts', []],
    'getOrders' => ['getOrders', []],
]);

// refer_id 反查（commit 锁内栈内再串两次上游）：get-order-id + get 各 10s，
// 锁内最坏 28+10+10=48 < 50（留 2s 裕度）；反查与 new 超时互斥但 t_new 可逼近 28s 故仍受此约束
test('refer_id 反查链路用 10s 超时', function () {
    $sdk = new CapturingOrderSdk;
    $sdk->responseQueue = [
        new Response(200, [], (string) json_encode(['code' => 0, 'msg' => 'Refer id already used'])),    // new
        new Response(200, [], (string) json_encode(['code' => 1, 'data' => ['order_id' => 999]])),        // get-order-id-by-refer-id
        new Response(200, [], (string) json_encode(['code' => 1, 'data' => ['cert_apply_status' => 0]])), // 反查的 get
    ];

    $sdk->new(['refer_id' => 'abc']);

    expect($sdk->configs)->toHaveCount(3)
        ->and($sdk->configs[0])->toMatchArray(['timeout' => 28])  // new 本身
        ->and($sdk->configs[1])->toMatchArray(['timeout' => 10])  // get-order-id-by-refer-id
        ->and($sdk->configs[2])->toMatchArray(['timeout' => 10]); // 反查的 get
});
