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
// → 并发访问同一订单行的 for update 报 1205 锁等待超时。修复：锁内调用加 45s 超时（锁内仅一个上游调用，45 < 50）。
// 锁外调用也须有超时上限 —— 否则上游挂时 FPM worker 被无限占用拖死（max_execution_time 不计 socket
// 阻塞，只有 FPM request_terminate_timeout 能兜、不可靠）：文档上传(单文件 ≤5MB，< worker --timeout 60
// 让 Guzzle 先干净断) 55s、其他查询/操作(sync get / getProducts / getOrders / revalidate / updateDCV) 30s。
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
    Cache::put('setting:group_name:ca', ['url' => 'https://upstream.test/api/v2', 'token' => 'tok'], 60);
});

// 锁内：commit 下单（new/renew/reissue）+ cancel —— 必须带 45s 超时（锁内仅一个上游调用，45 < innodb_lock_wait_timeout 50s）
test('锁内上游调用带 45s 超时', function (string $method, array $args) {
    $sdk = new CapturingOrderSdk;

    $sdk->$method(...$args);

    expect($sdk->configs)->toHaveCount(1)
        ->and($sdk->configs[0])->toMatchArray(['connect_timeout' => 10, 'timeout' => 45]);
})->with([
    'new' => ['new', [['refer_id' => 'a']]],
    'renew' => ['renew', [['refer_id' => 'a']]],
    'reissue' => ['reissue', [['refer_id' => 'a']]],
    'cancel' => ['cancel', [123]],
]);

// 锁外文档上传：单文件 ≤5MB（base64 ~6.7MB，正常网络 <10s）—— 55s（< worker --timeout 60，
// 让 Guzzle 自己先干净断，而非被 worker SIGALRM 硬杀）
test('文档上传带 55s 超时', function () {
    $sdk = new CapturingOrderSdk;

    $sdk->uploadDocument('UP1', ['type' => 'A', 'fileName' => 'a.pdf', 'document_content' => 'QQ==']);

    expect($sdk->configs)->toHaveCount(1)
        ->and($sdk->configs[0])->toMatchArray(['connect_timeout' => 10, 'timeout' => 55]);
});

// 其他锁外查询/操作：非耗时但有 FPM 同步入口（手动 sync / 导入产品 / 重新验证 / 改 DCV）
// —— 30s 防上游挂时 FPM worker 被无限占用拖死
test('其他锁外查询/操作带 30s 超时', function (string $method, array $args) {
    $sdk = new CapturingOrderSdk;

    $sdk->$method(...$args);

    expect($sdk->configs)->toHaveCount(1)
        ->and($sdk->configs[0])->toMatchArray(['connect_timeout' => 10, 'timeout' => 30]);
})->with([
    'get(sync)' => ['get', [123]],
    'getProducts' => ['getProducts', []],
    'getOrders' => ['getOrders', []],
    'revalidate' => ['revalidate', [123]],
    'updateDCV' => ['updateDCV', [123, 'http']],
]);
