<?php

use App\Models\ApiLog;
use App\Models\AutoDeployReport;
use App\Models\Cert;
use App\Models\DeployToken;
use App\Models\ErrorLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\Notification\SystemAlert;
use App\Services\Order\Api\Api;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

// ===== 辅助函数 =====

function createDeployAuth(?User $user = null): array
{
    $user ??= User::factory()->create(['balance' => '1000.00']);
    $token = DeployToken::factory()->create(['user_id' => $user->id]);

    return [$user, $token];
}

function createDeployOrder(
    User $user,
    string $certStatus = 'active',
    array $certOverrides = [],
    array $orderOverrides = [],
    array $productOverrides = [],
): array {
    $product = Product::factory()->create($productOverrides);
    $order = Order::factory()->create(array_merge([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ], $orderOverrides));

    $certFactory = Cert::factory();
    $certFactory = match ($certStatus) {
        'active' => $certFactory->active(),
        'approving' => $certFactory->approving(),
        default => $certFactory->state(['status' => $certStatus]),
    };

    $cert = $certFactory->create(array_merge(
        ['order_id' => $order->id],
        $certOverrides,
    ));

    $order->update(['latest_cert_id' => $cert->id]);

    ProductPrice::factory()->create([
        'product_id' => $product->id,
        'period' => $order->period,
        'price' => $order->amount,
    ]);

    return [$order, $cert, $product];
}

function deployGet(DeployToken $token, string $query = ''): TestResponse
{
    $sep = $query ? '?' : '';

    return test()->withHeaders(['Authorization' => "Bearer $token->token"])
        ->getJson("/api/deploy/{$sep}{$query}");
}

function deployPost(DeployToken $token, string $uri, array $data = []): TestResponse
{
    return test()->withHeaders(['Authorization' => "Bearer $token->token"])
        ->postJson($uri, $data);
}

// ========================================
// query() — order 形态守卫
// ========================================
//
// 不带 field 的 JSON 查询只接受订单 ID（单个或英文逗号分隔）：
// 空参数列全量、按域名查询都已取消——自动部署链路的发起方永远持有准确订单号，
// 这两种形态只有手工场景、前端从未展示，且域名走 LIKE %domain% 会混入跨域证书。

test('query order 缺失返回 invalid_order', function () {
    [$user, $token] = createDeployAuth();
    createDeployOrder($user, 'active');

    deployGet($token)
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('errors.error_code', 'invalid_order');
});

test('query order 为空白串返回 invalid_order', function () {
    [$user, $token] = createDeployAuth();

    deployGet($token, 'order=%20')
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('errors.error_code', 'invalid_order');
});

// 域名形态在不带 field 时不再受理（带 field 的 URL 拉取仍支持，见 field 域名模式用例）
test('query order 为域名返回 invalid_order', function () {
    [$user, $token] = createDeployAuth();
    createDeployOrder($user, 'active', [
        'common_name' => 'deploy.example.com',
        'alternative_names' => 'deploy.example.com',
    ]);

    deployGet($token, 'order=deploy.example.com')
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('errors.error_code', 'invalid_order');
});

test('query order 为 ID 与域名混合返回 invalid_order', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active');

    deployGet($token, "order=$order->id,example.com")
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('errors.error_code', 'invalid_order');
});

// 响应不再含分页字段：单 ID 恒 1 条、批量受上限约束，total/page/page_size 已无信息量
test('query 响应不含 total page page_size 字段', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active');

    $data = deployGet($token, "order=$order->id")
        ->assertOk()->assertJson(['code' => 1])
        ->json('data');

    expect(array_keys($data))->toEqualCanonicalizing(['data', 'renew_before_days']);
});

// ========================================
// query() — 按 ID 查询
// ========================================

test('query 按 ID 查询返回单条', function () {
    [$user, $token] = createDeployAuth();
    [$order, $cert] = createDeployOrder($user, 'active');

    $response = deployGet($token, "order=$order->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.data'))->toHaveCount(1);
    $response->assertJsonPath('data.data.0.order_id', $order->id);
    $response->assertJsonPath('data.data.0.status', 'active');
    $response->assertJsonPath('data.data.0.domains', $cert->alternative_names);
});

test('query 按 ID 查询支持非 active 状态', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'pending');

    $response = deployGet($token, "order=$order->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    $response->assertJsonPath('data.data.0.order_id', $order->id);
    $response->assertJsonPath('data.data.0.status', 'pending');
});

test('query 按 ID 查询不存在', function () {
    [$user, $token] = createDeployAuth();

    deployGet($token, 'order=99999')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('query 按 ID 查询 UserScope 隔离', function () {
    [$user, $token] = createDeployAuth();
    $otherUser = User::factory()->create();
    [$otherOrder] = createDeployOrder($otherUser, 'active');

    deployGet($token, "order=$otherOrder->id")
        ->assertOk()
        ->assertJson(['code' => 0]);
});

// ========================================
// query() — 已续费订单追踪
// ========================================

test('query 按 ID 查询已续费订单返回新订单', function () {
    [$user, $token] = createDeployAuth();

    // 创建旧订单（cert 状态为 renewed）
    [$oldOrder, $oldCert] = createDeployOrder($user, 'renewed');

    // 创建新订单（续费后的订单）
    [$newOrder, $newCert] = createDeployOrder($user, 'active');
    $newCert->update(['last_cert_id' => $oldCert->id]);

    // 用旧订单 ID 查询，应返回新订单
    $response = deployGet($token, "order=$oldOrder->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    $response->assertJsonPath('data.data.0.order_id', $newOrder->id);
    $response->assertJsonPath('data.data.0.status', 'active');
});

test('query 批量查询已续费订单返回新订单', function () {
    [$user, $token] = createDeployAuth();

    [$oldOrder, $oldCert] = createDeployOrder($user, 'renewed');
    [$newOrder, $newCert] = createDeployOrder($user, 'active');
    $newCert->update(['last_cert_id' => $oldCert->id]);

    $response = deployGet($token, "order=$oldOrder->id,$newOrder->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    // 去重后只有一条（旧订单追踪到新订单，和新订单是同一个）
    expect($response->json('data.data'))->toHaveCount(1);
    $response->assertJsonPath('data.data.0.order_id', $newOrder->id);
});

test('query 多级续费链追踪到最新订单', function () {
    [$user, $token] = createDeployAuth();

    [$order1, $cert1] = createDeployOrder($user, 'renewed');
    [$order2, $cert2] = createDeployOrder($user, 'renewed');
    [$order3, $cert3] = createDeployOrder($user, 'active');

    $cert2->update(['last_cert_id' => $cert1->id]);
    $cert3->update(['last_cert_id' => $cert2->id]);

    $response = deployGet($token, "order=$order1->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    $response->assertJsonPath('data.data.0.order_id', $order3->id);
});

// last_cert_id 链成三跳环（A→B→C→A）：每跳的 $nextCert->order_id 都不等于当前 $order->id，
// 旧的直接自环判据兜不住，循环永远退不出并把 PHP-FPM 进程占死（每轮一次 DB 查询，
// Linux 下 max_execution_time 不计 I/O 等待）。visited 集合须第一次重复即退出。
test('query 续费链成环时停止追踪并返回当前订单', function () {
    Log::spy();

    [$user, $token] = createDeployAuth();

    [$order1, $cert1] = createDeployOrder($user, 'renewed');
    [$order2, $cert2] = createDeployOrder($user, 'renewed');
    [$order3, $cert3] = createDeployOrder($user, 'renewed');

    $cert2->update(['last_cert_id' => $cert1->id]);
    $cert3->update(['last_cert_id' => $cert2->id]);
    $cert1->update(['last_cert_id' => $cert3->id]); // 成环

    $response = deployGet($token, "order=$order1->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    // 停在环上最后一个新访问到的订单；状态仍是 renewed（终态），客户端据此停止等人工，
    // 而不是收到 code=0 当网络错误每天重试
    $response->assertJsonPath('data.data.0.order_id', $order3->id);
    $response->assertJsonPath('data.data.0.status', 'renewed');

    // 日志须能定位脏数据：入口订单 + 停止位置 + 重复的那个 + 完整链路
    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message, $context) => str_contains((string) $message, '续费链成环')
            && $context['entry_order_id'] === $order1->id
            && $context['stopped_at_order_id'] === $order3->id
            && $context['repeated_order_id'] === $order1->id
            && $context['chain'] === [$order1->id, $order2->id, $order3->id])
        ->once();
});

// 链不成环但异常长：硬上限 60 跳兜底
test('query 续费链超长时触顶停止追踪', function () {
    Log::spy();

    [$user, $token] = createDeployAuth();
    $product = Product::factory()->create();

    /** @var array<int, Order> $orders */
    $orders = [];
    $prevCertId = null;

    // 62 个全 renewed 的订单串成一条链，超过 60 跳硬上限
    for ($i = 0; $i < 62; $i++) {
        $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
        $cert = Cert::factory()->create([
            'order_id' => $order->id,
            'status' => 'renewed',
            'last_cert_id' => $prevCertId,
        ]);
        $order->update(['latest_cert_id' => $cert->id]);

        $orders[] = $order;
        $prevCertId = $cert->id;
    }

    $response = deployGet($token, "order={$orders[0]->id}")
        ->assertOk()
        ->assertJson(['code' => 1]);

    // 起点 + 60 跳 = 链上第 61 个订单（下标 60）
    $response->assertJsonPath('data.data.0.order_id', $orders[60]->id);
    $response->assertJsonPath('data.data.0.status', 'renewed');

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message, $context) => str_contains((string) $message, '续费链超过硬上限')
            && $context['entry_order_id'] === $orders[0]->id
            && $context['stopped_at_order_id'] === $orders[60]->id
            && $context['max_hops'] === 60
            && count($context['chain']) === 61)  // 起点 + 60 跳
        ->once();
});

// ========================================
// query() — 批量查询（含逗号）
// ========================================

test('query 批量查询按 ID', function () {
    [$user, $token] = createDeployAuth();
    [$order1] = createDeployOrder($user, 'active');
    [$order2] = createDeployOrder($user, 'active');

    $response = deployGet($token, "order=$order1->id,$order2->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.data'))->toHaveCount(2);
    $ids = collect($response->json('data.data'))->pluck('order_id')->all();
    expect($ids)->toContain($order1->id)->toContain($order2->id);
});

test('query 批量查询重复 ID 去重', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active');

    $response = deployGet($token, "order=$order->id,$order->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.data'))->toHaveCount(1);
});

// 批量上限即返回条数上限（每个 ID 至多一个订单），故响应无需分页
test('query 批量查询超过 100 个 ID 报错', function () {
    [$user, $token] = createDeployAuth();

    $ids = implode(',', range(1, 101));

    deployGet($token, "order=$ids")
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('msg', '单次最多查询 100 条')
        ->assertJsonPath('errors.error_code', 'invalid_order');
});

test('query 批量查询 100 个 ID 不报错', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active');

    // 99 个不存在的 ID + 1 个真实 ID = 恰好 100，边界不越线
    $ids = implode(',', array_merge(range(1, 99), [$order->id]));

    $response = deployGet($token, "order=$ids")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.data'))->toHaveCount(1);
});

test('query 批量查询 UserScope 隔离', function () {
    [$user, $token] = createDeployAuth();
    $otherUser = User::factory()->create();
    [$myOrder] = createDeployOrder($user, 'active');
    [$otherOrder] = createDeployOrder($otherUser, 'active');

    $response = deployGet($token, "order=$myOrder->id,$otherOrder->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    // 只能查到自己的
    expect($response->json('data.data'))->toHaveCount(1);
    expect($response->json('data.data.0.order_id'))->toBe($myOrder->id);
});

// ========================================
// query() — 返回数据结构
// ========================================

test('query 返回数据包含完整字段', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active', [
        'common_name' => 'full.example.com',
        'alternative_names' => 'full.example.com,www.full.example.com',
    ]);

    $response = deployGet($token, "order=$order->id")
        ->assertOk()->assertJson(['code' => 1]);

    $item = $response->json('data.data.0');
    expect($item)
        ->toHaveKeys(['order_id', 'domains', 'status', 'certificate', 'private_key', 'ca_certificate', 'issued_at', 'expires_at'])
        ->not->toHaveKeys(['domain', 'created_at'])
        ->order_id->toBe($order->id)
        ->domains->toBe('full.example.com,www.full.example.com')
        ->status->toBe('active');
});

test('query 所有状态都返回当前签发动作的 csr', function () {
    [$user, $token] = createDeployAuth();
    $csr = "-----BEGIN CERTIFICATE REQUEST-----\nCURRENT\n-----END CERTIFICATE REQUEST-----";

    // processing：客户端要在签发在途时就靠 csr 判断是不是本机提交的，故此阶段必须可见
    [$processingOrder] = createDeployOrder($user, 'processing', ['csr' => $csr]);
    expect(deployGet($token, "order=$processingOrder->id")->assertOk()->json('data.data.0.csr'))->toBe($csr);

    [$activeOrder] = createDeployOrder($user, 'active', ['csr' => $csr]);
    expect(deployGet($token, "order=$activeOrder->id")->assertOk()->json('data.data.0.csr'))->toBe($csr);

    // 无 CSR 的历史订单返回空串
    [$legacyOrder] = createDeployOrder($user, 'active', ['csr' => null]);
    expect(deployGet($token, "order=$legacyOrder->id")->assertOk()->json('data.data.0.csr'))->toBe('');
});

test('query 非 active 状态不返回证书字段', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'pending');

    $response = deployGet($token, "order=$order->id")
        ->assertOk()->assertJson(['code' => 1]);

    $item = $response->json('data.data.0');
    expect($item)
        ->toHaveKeys(['order_id', 'domains', 'status'])
        ->not->toHaveKeys(['certificate', 'private_key', 'ca_certificate', 'issued_at', 'expires_at']);
});

test('query processing 状态含 file 验证信息', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'processing', [
        'dcv' => [
            'method' => 'http',
            'file' => ['path' => '/.well-known/pki-validation/test.txt', 'content' => 'abc123'],
        ],
    ]);

    $response = deployGet($token, "order=$order->id")
        ->assertOk()->assertJson(['code' => 1]);

    $item = $response->json('data.data.0');
    expect($item)->not->toHaveKeys(['certificate', 'private_key', 'ca_certificate']);
    expect($item['file'])
        ->path->toBe('/.well-known/pki-validation/test.txt')
        ->content->toBe('abc123');
});

// ========================================
// query() — field 参数（certimate URL 拉取）
// ========================================

function deployGetRaw(DeployToken $token, string $query): TestResponse
{
    return test()->withHeaders(['Authorization' => "Bearer $token->token"])
        ->get("/api/deploy/?$query");
}

test('query field=certificate 返回 fullchain PEM 纯文本', function () {
    [$user, $token] = createDeployAuth();
    [$order, $cert] = createDeployOrder($user, 'active', [
        'cert' => "-----BEGIN CERTIFICATE-----\nCERT_BODY\n-----END CERTIFICATE-----",
        'intermediate_cert' => "-----BEGIN CERTIFICATE-----\nCA_BODY\n-----END CERTIFICATE-----",
    ]);

    $response = deployGetRaw($token, "order=$order->id&field=certificate")->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/plain');
    expect($response->getContent())->toBe(
        rtrim($cert->cert)."\n".$cert->intermediate_cert
    );
});

test('query field=private_key 返回私钥 PEM 纯文本', function () {
    [$user, $token] = createDeployAuth();
    [$order, $cert] = createDeployOrder($user, 'active', [
        'private_key' => "-----BEGIN PRIVATE KEY-----\nKEY_BODY\n-----END PRIVATE KEY-----",
    ]);

    $response = deployGetRaw($token, "order=$order->id&field=private_key")->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/plain');
    expect($response->getContent())->toBe($cert->private_key);
});

test('field 拉取的纯 PEM 文本响应在 api_logs 记为成功 status=1', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active', [
        'cert' => "-----BEGIN CERTIFICATE-----\nCERT_BODY\n-----END CERTIFICATE-----",
        'intermediate_cert' => '',
    ]);

    deployGetRaw($token, "order=$order->id&field=certificate")->assertOk();

    // 纯 PEM 文本响应无 code 字段、非 'success'：旧逻辑误记 status=0（失败），
    // 修复后回落 HTTP 2xx 判成功，避免 deploy 证书/私钥拉取在日志里全部显示失败
    $log = ApiLog::query()
        ->where('url', 'like', '%field=certificate%')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->status)->toBe(1);
});

test('query field 非法取值返回验证错误', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active');

    deployGetRaw($token, "order=$order->id&field=invalid")
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('query field 要求 order 为单值（ID 或域名）', function () {
    [$user, $token] = createDeployAuth();

    deployGetRaw($token, 'field=certificate')->assertStatus(400);
    deployGetRaw($token, 'order=1,2&field=certificate')->assertStatus(400);
});

test('query field 订单不存在返回 404', function () {
    [, $token] = createDeployAuth();

    deployGetRaw($token, 'order=99999&field=certificate')->assertStatus(404);
    deployGetRaw($token, 'order=no-such.example.com&field=certificate')->assertStatus(404);
});

test('query field 域名模式按 common_name 返回最新 active 证书', function () {
    [$user, $token] = createDeployAuth();
    [, $oldCert] = createDeployOrder($user, 'active', [
        'common_name' => 'site.example.com',
        'issued_at' => now()->subYears(1),
        'cert' => "-----BEGIN CERTIFICATE-----\nOLD_CERT\n-----END CERTIFICATE-----",
        'intermediate_cert' => "-----BEGIN CERTIFICATE-----\nOLD_CA\n-----END CERTIFICATE-----",
    ]);
    [, $newCert] = createDeployOrder($user, 'active', [
        'common_name' => 'site.example.com',
        'issued_at' => now()->subDays(1),
        'cert' => "-----BEGIN CERTIFICATE-----\nNEW_CERT\n-----END CERTIFICATE-----",
        'intermediate_cert' => "-----BEGIN CERTIFICATE-----\nNEW_CA\n-----END CERTIFICATE-----",
    ]);

    $response = deployGetRaw($token, 'order=site.example.com&field=certificate')->assertOk();

    expect($response->getContent())->toBe(
        rtrim($newCert->cert)."\n".$newCert->intermediate_cert
    );
    expect($response->getContent())->not->toContain('OLD_CERT');
});

test('query field 域名模式忽略非 active 证书', function () {
    [$user, $token] = createDeployAuth();
    createDeployOrder($user, 'pending', ['common_name' => 'pend.example.com']);

    deployGetRaw($token, 'order=pend.example.com&field=certificate')->assertStatus(404);
});

test('query field 域名模式 UserScope 隔离', function () {
    [, $tokenA] = createDeployAuth();
    [$userB] = createDeployAuth();
    createDeployOrder($userB, 'active', ['common_name' => 'private.example.com']);

    deployGetRaw($tokenA, 'order=private.example.com&field=certificate')->assertStatus(404);
});

test('query field 非 active 状态返回 400', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'pending');

    deployGetRaw($token, "order=$order->id&field=certificate")->assertStatus(400);
});

test('query field 已续费订单追踪到新订单的证书', function () {
    [$user, $token] = createDeployAuth();
    [$oldOrder, $oldCert] = createDeployOrder($user, 'renewed');
    [, $newCert] = createDeployOrder($user, 'active', [
        'last_cert_id' => $oldCert->id,
        'cert' => "-----BEGIN CERTIFICATE-----\nNEW_CERT\n-----END CERTIFICATE-----",
        'intermediate_cert' => "-----BEGIN CERTIFICATE-----\nNEW_CA\n-----END CERTIFICATE-----",
    ]);

    $response = deployGetRaw($token, "order=$oldOrder->id&field=certificate")->assertOk();

    expect($response->getContent())->toBe(
        rtrim($newCert->cert)."\n".$newCert->intermediate_cert
    );
});

test('query field UserScope 隔离', function () {
    [, $tokenA] = createDeployAuth();
    [$userB] = createDeployAuth();
    [$orderB] = createDeployOrder($userB, 'active');

    deployGetRaw($tokenA, "order=$orderB->id&field=certificate")->assertStatus(404);
});

// ========================================
// callback() 测试
// ========================================

test('callback 每次上报独立记录状态与来源 IP', function () {
    expect(Schema::hasTable('auto_deploy_reports'))->toBeTrue();
    expect(Schema::hasColumn('certs', 'auto_deploy_at'))->toBeFalse();

    [$user, $token] = createDeployAuth();
    [$order, $cert] = createDeployOrder($user, 'active');

    deployPost($token, '/api/deploy/callback', [
        'order_id' => $order->id,
        'status' => 'success',
        'deployed_at' => '2026-01-15 08:30:00',
    ])->assertOk()->assertJson(['code' => 1]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8'])
        ->withHeaders(['Authorization' => "Bearer $token->token"])
        ->postJson('/api/deploy/callback', [
            'order_id' => $order->id,
            'status' => 'failure',
            'message' => 'Connection refused',
        ])->assertOk()->assertJson(['code' => 1]);

    $reports = DB::table('auto_deploy_reports')
        ->where('order_id', $order->id)
        ->orderBy('id')
        ->get();

    expect($reports)->toHaveCount(2)
        ->and($reports[0]->cert_id)->toBe($cert->id)
        ->and($reports[0]->status)->toBe('success')
        ->and($reports[0]->deployed_at)->toBe('2026-01-15 08:30:00')
        ->and($reports[1]->cert_id)->toBe($cert->id)
        ->and($reports[1]->status)->toBe('failure')
        ->and($reports[1]->ip)->toBe('203.0.113.8')
        ->and($reports[1]->message)->toBe('Connection refused');
});

test('callback 成功记录部署时间', function () {
    [$user, $token] = createDeployAuth();
    [$order, $cert] = createDeployOrder($user, 'active');

    $response = deployPost($token, '/api/deploy/callback', [
        'order_id' => $order->id,
        'status' => 'success',
    ])->assertOk()->assertJson(['code' => 1]);

    expect($response->json('data'))
        ->order_id->toBe($order->id)
        ->status->toBe('success')
        ->recorded->toBeTrue()
        ->not->toHaveKey('domain');

    $report = AutoDeployReport::where('order_id', $order->id)->sole();
    expect($report->cert_id)->toBe($cert->id)
        ->and($report->deployed_at)->not->toBeNull();
});

test('callback 失败不记录部署时间', function () {
    [$user, $token] = createDeployAuth();
    [$order, $cert] = createDeployOrder($user, 'active');

    $response = deployPost($token, '/api/deploy/callback', [
        'order_id' => $order->id,
        'status' => 'failure',
        'message' => 'Connection refused',
    ])->assertOk()->assertJson(['code' => 1]);

    expect($response->json('data'))
        ->status->toBe('failure')
        ->recorded->toBeTrue();

    $report = AutoDeployReport::where('order_id', $order->id)->sole();
    expect($report->status)->toBe('failure')
        ->and($report->deployed_at)->toBeNull();
});

test('callback 使用自定义 deployed_at', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active');

    deployPost($token, '/api/deploy/callback', [
        'order_id' => $order->id,
        'status' => 'success',
        'deployed_at' => '2026-01-15 08:30:00',
    ])->assertOk()->assertJson(['code' => 1]);

    expect(AutoDeployReport::where('order_id', $order->id)->sole()->deployed_at->format('Y-m-d H:i:s'))
        ->toBe('2026-01-15 08:30:00');
});

test('callback deployed_at 格式错误使用当前时间', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active');

    $this->travelTo(now()->startOfMinute());

    deployPost($token, '/api/deploy/callback', [
        'order_id' => $order->id,
        'status' => 'success',
        'deployed_at' => 'not-a-date',
    ])->assertOk()->assertJson(['code' => 1]);

    expect(AutoDeployReport::where('order_id', $order->id)->sole()->deployed_at)
        ->not->toBeNull();
});

test('callback 订单不存在', function () {
    [$user, $token] = createDeployAuth();

    deployPost($token, '/api/deploy/callback', [
        'order_id' => 99999,
        'status' => 'success',
    ])->assertOk()->assertJson(['code' => 0]);
});

test('callback 订单没有证书时返回业务错误且不写记录', function () {
    [$user, $token] = createDeployAuth();
    $order = Order::factory()->create(['user_id' => $user->id]);

    deployPost($token, '/api/deploy/callback', [
        'order_id' => $order->id,
        'status' => 'failure',
    ])->assertOk()->assertJson(['code' => 0, 'msg' => '证书不存在']);

    expect(AutoDeployReport::query()->where('order_id', $order->id)->exists())->toBeFalse();
});

test('callback UserScope 隔离', function () {
    [$user, $token] = createDeployAuth();
    $otherUser = User::factory()->create();
    [$otherOrder] = createDeployOrder($otherUser, 'active');

    deployPost($token, '/api/deploy/callback', [
        'order_id' => $otherOrder->id,
        'status' => 'success',
    ])->assertOk()->assertJson(['code' => 0]);
});

test('callback 参数验证', function () {
    [, $token] = createDeployAuth();

    // 缺少必填字段
    deployPost($token, '/api/deploy/callback', [])
        ->assertOk()
        ->assertJson(['code' => 0]);

    // status 值无效
    deployPost($token, '/api/deploy/callback', [
        'order_id' => 1,
        'status' => 'invalid',
    ])->assertOk()->assertJson(['code' => 0]);
});

test('callback 失败入表并触发按订单固定指纹去重告警（不写 error_logs）', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active');

    // 记录全量之上做通知消噪：失败触发一封 SystemAlert，per-order dedupeKey + 固定指纹 + TTL 168h
    $alert = Mockery::mock(SystemAlert::class);
    $alert->shouldReceive('send')->once()
        ->with(
            'deploy_failure',
            Mockery::type('string'),
            Mockery::type('string'),
            Mockery::on(fn ($details) => (int) $details['order_id'] === $order->id
                && (int) $details['user_id'] === $user->id),
            "deploy_failure_{$order->id}",
            168,
            'deploy_failure',
        )
        ->andReturnTrue();
    $alert->shouldNotReceive('clearDedupe');
    app()->instance(SystemAlert::class, $alert);

    deployPost($token, '/api/deploy/callback', [
        'order_id' => $order->id,
        'status' => 'failure',
        'message' => '部署失败',
    ])->assertOk()->assertJson(['code' => 1]);

    expect(AutoDeployReport::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(ErrorLog::query()->where('exception', 'DeployCallbackFailure')->exists())->toBeFalse();
});

test('callback 成功入表并清除该订单失败告警去重键（复发即恢复）', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active');

    $alert = Mockery::mock(SystemAlert::class);
    $alert->shouldReceive('clearDedupe')->once()->with("deploy_failure_{$order->id}");
    $alert->shouldNotReceive('send');
    app()->instance(SystemAlert::class, $alert);

    deployPost($token, '/api/deploy/callback', [
        'order_id' => $order->id,
        'status' => 'success',
    ])->assertOk()->assertJson(['code' => 1]);

    expect(AutoDeployReport::query()->where('order_id', $order->id)->where('status', 'success')->count())->toBe(1);
});

test('callback 失败告警异常不影响上报响应且记录只写一次', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active');

    $alert = Mockery::mock(SystemAlert::class);
    $alert->shouldReceive('send')->once()->andThrow(new RuntimeException('cache-down'));
    app()->instance(SystemAlert::class, $alert);

    deployPost($token, '/api/deploy/callback', [
        'order_id' => $order->id,
        'status' => 'failure',
        'message' => '部署失败',
    ])->assertOk()->assertJson(['code' => 1]);

    expect(AutoDeployReport::query()->where('order_id', $order->id)->count())->toBe(1);
});

test('callback 成功清键异常不影响上报响应且成功记录保留', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active');

    $alert = Mockery::mock(SystemAlert::class);
    $alert->shouldReceive('clearDedupe')->once()->andThrow(new RuntimeException('cache-down'));
    app()->instance(SystemAlert::class, $alert);

    deployPost($token, '/api/deploy/callback', [
        'order_id' => $order->id,
        'status' => 'success',
    ])->assertOk()->assertJson(['code' => 1]);

    expect(AutoDeployReport::query()->where('order_id', $order->id)->where('status', 'success')->count())->toBe(1);
});

// ========================================
// update() — 错误场景
// ========================================

test('update 订单不存在', function () {
    [, $token] = createDeployAuth();

    deployPost($token, '/api/deploy/', [
        'order_id' => 99999,
    ])->assertOk()->assertJson(['code' => 0]);
});

test('update UserScope 隔离', function () {
    [, $token] = createDeployAuth();
    $otherUser = User::factory()->create();
    [$otherOrder] = createDeployOrder($otherUser, 'pending');

    deployPost($token, '/api/deploy/', [
        'order_id' => $otherOrder->id,
    ])->assertOk()->assertJson(['code' => 0]);
});

test('update 参数验证', function () {
    [, $token] = createDeployAuth();

    // 缺少 order_id
    deployPost($token, '/api/deploy/', [])
        ->assertOk()
        ->assertJson(['code' => 0]);

    // validation_method 无效
    deployPost($token, '/api/deploy/', [
        'order_id' => 1,
        'validation_method' => 'invalid',
    ])->assertOk()->assertJson(['code' => 0]);
});

test('update active 产品不支持委托验证', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active', [], [], [
        'validation_methods' => ['txt', 'http'],  // 无 delegation
    ]);

    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'validation_method' => 'delegation',
    ])->assertOk()->assertJson(['code' => 0, 'msg' => '该产品不支持委托验证'])
        ->assertJsonPath('errors.error_code', 'validation_method_unsupported');
});

test('update 本地 CSR 前置校验失败不写签发失败记录', function () {
    [$user, $token] = createDeployAuth();
    // private_key 非空 = 当前证书由服务端签发（客户端首次 local 接管），不触发 local 重签冷却
    [$order] = createDeployOrder($user, 'active', [
        'private_key' => "-----BEGIN PRIVATE KEY-----\nSERVER\n-----END PRIVATE KEY-----",
    ], [], [
        'validation_methods' => ['txt', 'http'],
    ]);

    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'csr' => '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----',
        'validation_method' => 'delegation',
    ])->assertOk()->assertJson(['code' => 0, 'msg' => '该产品不支持委托验证']);

    expect(AutoDeployReport::where('order_id', $order->id)->exists())->toBeFalse();
});

test('update 本地 CSR 进入重签处理后失败自写签发失败记录（ip 留空）', function () {
    [$user, $token] = createDeployAuth();
    // private_key 非空 = 当前证书由服务端签发（客户端首次 local 接管），不触发 local 重签冷却
    [$order, $cert] = createDeployOrder($user, 'active', [
        'private_key' => "-----BEGIN PRIVATE KEY-----\nSERVER\n-----END PRIVATE KEY-----",
    ], [], [
        'validation_methods' => ['delegation', 'txt'],
    ]);

    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'csr' => '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----',
        'validation_method' => 'delegation',
    ])->assertOk()->assertJson(['code' => 0]);

    $report = AutoDeployReport::where('order_id', $order->id)->sole();
    expect($report->status)->toBe('failure')
        ->and($report->cert_id)->toBe($cert->id)
        ->and($report->ip)->toBeNull()          // 服务端自写：来源 IP 留空
        ->and($report->message)->toStartWith('本地签发失败：');
});

test('update 非本地（未携 CSR）处理失败不自写签发失败记录', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active', [], [], [
        'validation_methods' => ['txt', 'http'],
    ]);

    // 未携带 csr（服务端生成 CSR 路径）：同步错误由调用方自行感知，不重复留痕
    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'validation_method' => 'delegation',
    ])->assertOk()->assertJson(['code' => 0, 'msg' => '该产品不支持委托验证']);

    expect(AutoDeployReport::where('order_id', $order->id)->exists())->toBeFalse();
});

// ========================================
// update() — local 重签冷却
// ========================================

test('update local 重签冷却期内拒绝提交', function () {
    // 文案里的可再试时间由服务端按 issued_at 计算，冻结时钟避免断言跨秒失配
    Carbon::setTestNow(Carbon::parse('2026-07-01 10:00:00'));

    [$user, $token] = createDeployAuth();
    // private_key 为空 = 当前证书由客户端提交 CSR 签发，私钥只在那台机器上
    [$order] = createDeployOrder($user, 'active', [
        'private_key' => null,
        'issued_at' => now()->subDays(3),
    ], [], [
        'validation_methods' => ['delegation', 'txt'],
    ]);

    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'csr' => '-----BEGIN CERTIFICATE REQUEST-----other-----END CERTIFICATE REQUEST-----',
        'validation_method' => 'delegation',
    ])->assertOk()
        ->assertJson(['code' => 0])
        ->assertJsonPath('msg', '该证书 15 天内已重签，请于 2026-07-13 10:00:00 后再试');

    // 冷却拦截发生在进入重签处理之前，不留签发失败记录
    expect(AutoDeployReport::where('order_id', $order->id)->exists())->toBeFalse();
});

test('update csr 传 0 按客户端 CSR 处理不静默改走服务端生成', function () {
    [$user, $token] = createDeployAuth();
    // 服务端持钥单：不触发 local 重签冷却，请求必须真正走到 csr_generate 分支才有意义
    [$order] = createDeployOrder($user, 'active', [
        'private_key' => "-----BEGIN PRIVATE KEY-----\nSERVER\n-----END PRIVATE KEY-----",
        'issued_at' => now(),
    ], [], [
        'validation_methods' => ['delegation', 'txt'],
    ]);

    // empty('0') 恒为 true：旧口径把 csr='0' 当「未携带 CSR」静默改走服务端生成 CSR 重签，
    // 客户端以为完成 local 重签、实际拿到另一把私钥的证书。新口径按客户端 CSR 处理，
    // 签发链路显式失败，不产生新的服务端持钥证书行
    $response = deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'csr' => '0',
        'validation_method' => 'delegation',
    ]);

    expect($response->json('code'))->not->toBe(1);
    expect(Cert::where('order_id', $order->id)->count())->toBe(1);

    // 业务失败必须留在「HTTP 200 + code=0」内（有意保留的非 200 出口只有并发忙的 503 与 field= PEM
    // 直出）：'0' 曾被 OrderUtil::convertNumericValues 转成 int 0，撞 CsrUtil::matchKey(string $csr)
    // 抛 TypeError → HTTP 400 + 裸类型错误文案（带内部路径）。这类确定性失败一旦不落在契约内，
    // 下游就按网络错误无限每日重试。
    $response->assertOk();
    expect((string) $response->json('msg'))->not->toContain('must be of type');
});

test('update local 重签冷却期满后放行', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active', [
        'private_key' => null,
        'issued_at' => now()->subDays(16),
    ], [], [
        'validation_methods' => ['delegation', 'txt'],
    ]);

    $response = deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'csr' => '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----',
        'validation_method' => 'delegation',
    ])->assertOk();

    // 放行后进入重签处理（无真实上游故失败），关键是没被冷却拦下
    expect($response->json('msg'))->not->toContain('15 天内已重签');
    expect(AutoDeployReport::where('order_id', $order->id)->exists())->toBeTrue();
});

test('update local 重签冷却只约束重签不拦续费', function () {
    [$user, $token] = createDeployAuth();
    // 临期（period_till < 15 天）走续费分支：当前证书虽是 15 天内客户端签发的，也不得被冷却拦下——
    // 拦下会让客户端在必然失败的路径上每轮空烧签发计数直到触顶停摆
    [$order] = createDeployOrder($user, 'active', [
        'private_key' => null,
        'issued_at' => now()->subDays(3),
    ], [
        'period_till' => now()->addDays(10),
    ], [
        'validation_methods' => ['delegation', 'txt'],
    ]);

    // 已推进到续费分支（此单未开自动续费故止步于此），而非被冷却拦在前面
    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'csr' => '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----',
        'validation_method' => 'delegation',
    ])->assertOk()
        ->assertJson(['code' => 0])
        ->assertJsonPath('errors.error_code', 'auto_renew_disabled');
});

test('update 服务端签发的证书不受 local 重签冷却影响', function () {
    [$user, $token] = createDeployAuth();
    // private_key 非空 = 服务端持有私钥，客户端首次 local 接管，即使刚签发也放行
    [$order] = createDeployOrder($user, 'active', [
        'private_key' => "-----BEGIN PRIVATE KEY-----\nSERVER\n-----END PRIVATE KEY-----",
        'issued_at' => now(),
    ], [], [
        'validation_methods' => ['delegation', 'txt'],
    ]);

    $response = deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'csr' => '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----',
        'validation_method' => 'delegation',
    ])->assertOk();

    expect($response->json('msg'))->not->toContain('15 天内已重签');
    // 正向锚定：确实进了重签处理（无真实上游故失败自写记录），不是被别的前置校验提前挡下
    expect(AutoDeployReport::where('order_id', $order->id)->exists())->toBeTrue();
});

test('update local 重签冷却窗口只看当前证书行 服务端持钥签发即重置', function () {
    [$user, $token] = createDeployAuth();
    // 当前证书为服务端持钥签发，同订单 3 天前另有客户端 CSR 签发的历史证书行
    [$order] = createDeployOrder($user, 'active', [
        'private_key' => "-----BEGIN PRIVATE KEY-----\nSERVER\n-----END PRIVATE KEY-----",
        'issued_at' => now(),
    ], [], [
        'validation_methods' => ['delegation', 'txt'],
    ]);
    Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'reissued',
        'private_key' => null,
        'issued_at' => now()->subDays(3),
    ]);

    // 冷却窗口锚在当前证书行、不追溯历史证书：服务端持钥形态客户端可直接取用私钥，不属于多客户端互抢，
    // 故放行。这条边界是有意的，local setup 关 auto_reissue 才是防 scheduler 抢跑的那道闸
    $response = deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'csr' => '-----BEGIN CERTIFICATE REQUEST-----test-----END CERTIFICATE REQUEST-----',
        'validation_method' => 'delegation',
    ])->assertOk();

    expect($response->json('msg'))->not->toContain('15 天内已重签');
    expect(AutoDeployReport::where('order_id', $order->id)->exists())->toBeTrue();
});

test('update 未携带 CSR 不受 local 重签冷却影响', function () {
    [$user, $token] = createDeployAuth();
    // 当前证书是刚由客户端 CSR 签发的（冷却期内），但本次请求不带 csr
    [$order] = createDeployOrder($user, 'active', [
        'private_key' => null,
        'issued_at' => now(),
    ], [], [
        'validation_methods' => ['delegation', 'txt'],
    ]);

    // 服务端生成 CSR 路径（pull / 推进）不携带 csr，冷却只约束客户端提交 CSR 的 local 重签
    $response = deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'validation_method' => 'delegation',
    ])->assertOk();

    // 成功响应无 msg，强制转字符串保证断言对成功/失败两种形态都成立
    expect((string) $response->json('msg'))->not->toContain('15 天内已重签');
    // 正向锚定：确实走完服务端生成 CSR 的重签（新建服务端持钥证书行），不是被冷却拦在前面
    expect(Cert::where('order_id', $order->id)->whereNotNull('private_key')->exists())->toBeTrue();
});

test('update 冷却在互斥锁内权威复判：抢锁窗口内当前证书被换掉不再放行重签', function () {
    // 锁外那次冷却判定读的是请求开头的 $cert，与真正被重签的证书之间隔着抢锁窗口：期间当前证书
    // 可能已被并发的 Deploy / V1V2 / scheduler 换成刚签发、仍在冷却期内的新证书。用 DB::listen 在
    // 控制器读完订单后、进互斥锁前换证，确定性复现该形态（非真并发、预设态），锁死锁内复判存在。
    config(['cache.default' => 'array']);
    Cache::store('array')->flush();
    Carbon::setTestNow(Carbon::parse('2026-07-01 10:00:00'));

    [$user, $token] = createDeployAuth();
    // 锁外读到的当前证书：客户端持钥但已远过窗口 → 快速失败那一关必然放行
    [$order, $cert] = createDeployOrder($user, 'active', [
        'common_name' => 'race.example.com',
        'alternative_names' => 'race.example.com',
        'private_key' => null,
        'issued_at' => now()->subDays(100),
    ], [
        'period_till' => now()->addMonths(6), // >15 天 → 重签分支
    ], [
        'source' => 'default',
        'reissue' => 1,
        'validation_methods' => ['delegation', 'txt'],
    ]);

    $swapped = false;
    DB::listen(function ($query) use (&$swapped, $order, $cert) {
        // 卡在 latestCert 的 eager load（select * from `certs` ...）之后换证：控制器手里已经是这一刻
        // 读出的旧证书（正是要复现的 stale 读），而库里当前证书自此已换成刚签发的那张
        if ($swapped || ! str_starts_with(strtolower($query->sql), 'select * from `certs`')) {
            return;
        }

        $swapped = true;
        $fresh = Cert::factory()->active()->create([
            'order_id' => $order->id,
            'private_key' => null,
            'issued_at' => now(),
        ]);
        DB::table('certs')->where('id', $cert->id)->update(['status' => 'reissued']);
        DB::table('orders')->where('id', $order->id)->update(['latest_cert_id' => $fresh->id]);
    });

    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'csr' => '-----BEGIN CERTIFICATE REQUEST-----other-----END CERTIFICATE REQUEST-----',
        'validation_method' => 'delegation',
    ])->assertOk()
        ->assertJson(['code' => 0])
        ->assertJsonPath('msg', '该证书 15 天内已重签，请于 2026-07-16 10:00:00 后再试');

    expect($swapped)->toBeTrue();                                  // 确认换证路径确实被触发
    expect(Cert::where('order_id', $order->id)->count())->toBe(2); // 未新建证书行 = 没进重签
    // 冷却是策略拒绝、签发根本没开始，与锁外那次拒绝一样不写「本地签发失败」
    expect(AutoDeployReport::where('order_id', $order->id)->exists())->toBeFalse();
});

test('update active 产品不支持文件验证', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active', [], [], [
        'validation_methods' => ['txt', 'email'],  // 无 file/http/https
    ]);

    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'validation_method' => 'file',
    ])->assertOk()->assertJson(['code' => 0, 'msg' => '该产品不支持文件验证'])
        ->assertJsonPath('errors.error_code', 'validation_method_unsupported');
});

test('update active 续费未开启自动续费', function () {
    [$user, $token] = createDeployAuth(
        User::factory()->create([
            'balance' => '1000.00',
            'auto_settings' => ['auto_renew' => false, 'auto_reissue' => false],
        ])
    );

    // period_till 在 15 天内，触发续费逻辑
    [$order] = createDeployOrder($user, 'active', [], [
        'period_till' => now()->addDays(5),
        'auto_renew' => null,  // 回落到用户设置（false）
    ], [
        'validation_methods' => ['delegation', 'txt'],
    ]);

    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
    ])->assertOk()->assertJson(['code' => 0, 'msg' => '该订单未开启自动续费'])
        ->assertJsonPath('errors.error_code', 'auto_renew_disabled');
});

// 续费余额预检（永久性失败：不充值每天必然重现）——锁 error_code，
// 否则下游按未分类沿用重试策略，把"需人工充值"降级成静默每日重试。
test('update active 续费余额不足返回 insufficient_balance', function () {
    [$user, $token] = createDeployAuth(
        User::factory()->create([
            'balance' => '0.00',
            'credit_limit' => '0.00',
            'auto_settings' => ['auto_renew' => true, 'auto_reissue' => false],
        ])
    );

    // period_till 在 15 天内 → 走续费分支；订单金额远超余额 → 预检不过
    [$order] = createDeployOrder($user, 'active', [
        'common_name' => 'poor.example.com',
        'alternative_names' => 'poor.example.com',
    ], [
        'period_till' => now()->addDays(5),
        'auto_renew' => null,
        'amount' => '999.00',
    ], [
        'source' => 'default',
        'validation_methods' => ['delegation', 'txt', 'http'],
    ]);

    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
    ])->assertOk()->assertJson(['code' => 0, 'msg' => '余额不足，请充值后再续费'])
        ->assertJsonPath('errors.error_code', 'insufficient_balance');
});

// ========================================
// update() — 非 active 的 CSR/域名守卫（F1-1）
// ========================================

test('update unpaid 携带新 csr 显式报错（在途订单 CSR 已定型）', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'unpaid');

    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'csr' => "-----BEGIN CERTIFICATE REQUEST-----\nNEW\n-----END CERTIFICATE REQUEST-----",
    ])->assertOk()->assertJson(['code' => 0])
        ->assertJsonPath('msg', fn ($msg) => str_contains((string) $msg, '无法变更'))
        ->assertJsonPath('errors.error_code', 'order_in_progress');
});

test('update pending 携带新 domains 显式报错（在途订单域名已定型）', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'pending');

    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'domains' => 'a.example.com,b.example.com',
    ])->assertOk()->assertJson(['code' => 0])
        ->assertJsonPath('msg', fn ($msg) => str_contains((string) $msg, '无法变更'))
        ->assertJsonPath('errors.error_code', 'order_in_progress');
});

test('update active 携带 domains 为 0 不静默回落旧域名', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active', [
        'private_key' => "-----BEGIN PRIVATE KEY-----\nSERVER\n-----END PRIVATE KEY-----",
        'alternative_names' => 'old.example.com,www.old.example.com',
    ], [], [
        'validation_methods' => ['delegation', 'txt'],
    ]);

    // 旧口径下 domains='0' 被 empty() 当未携带，重签静默回落旧域名并返回 code=1——客户端以为改域名成功。
    // 不带 csr（服务端生成）才能让这条断言真正压在 domains 分支上：带畸形 CSR 会先在 CSR 解析处失败，
    // 新旧口径都返回 code=0，用例就锁不住任何东西
    $response = deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'domains' => '0',
        'validation_method' => 'delegation',
    ]);

    expect($response->json('code'))->not->toBe(1);
    expect(Cert::where('order_id', $order->id)->count())->toBe(1);
});

test('update pending 携带 domains 为 0 同样命中守卫（不走 empty 口径）', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'pending');

    // empty('0') 恒为 true：旧口径会把 domains='0' 当未携带放过，重签静默回落旧域名后返回成功
    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'domains' => '0',
    ])->assertOk()->assertJson(['code' => 0])
        ->assertJsonPath('errors.error_code', 'order_in_progress');
});

test('update processing 携带新 csr 显式报错（在途签发中，本次 CSR 未被接受）', function () {
    [$user, $token] = createDeployAuth();
    [$order, $cert] = createDeployOrder($user, 'processing');

    // 旧口径只拦 unpaid/pending：processing 既不会消费 csr 也不报错，末尾直接返回 code=1，
    // 客户端据此认为新 CSR 已被接受，此后永远等不到与本机私钥匹配的证书
    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'csr' => "-----BEGIN CERTIFICATE REQUEST-----\nNEW\n-----END CERTIFICATE REQUEST-----",
    ])->assertOk()->assertJson(['code' => 0])
        ->assertJsonPath('msg', fn ($msg) => str_contains((string) $msg, '无法变更'))
        ->assertJsonPath('errors.error_code', 'order_in_progress');

    expect($cert->fresh()->csr)->toBeNull();
});

test('update 终态订单携带新 csr 显式报错 order_not_active（不会自行回到 active）', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'cancelled');

    // 终态不会自行推进到 active，与在途分开下发 error_code：客户端据此「停」而不是「等」
    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'csr' => "-----BEGIN CERTIFICATE REQUEST-----\nNEW\n-----END CERTIFICATE REQUEST-----",
    ])->assertOk()->assertJson(['code' => 0])
        ->assertJsonPath('msg', fn ($msg) => str_contains((string) $msg, '无法变更'))
        ->assertJsonPath('errors.error_code', 'order_not_active');
});

test('update 过期证书携带新 domains 显式报错 order_not_active', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'expired');

    // 证书已过期时服务端策略是交人工处理（scheduler 侧同样以 expires_at >= now() 为下界），
    // 静默成功会让客户端以为改域名生效
    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'domains' => 'a.example.com,b.example.com',
    ])->assertOk()->assertJson(['code' => 0])
        ->assertJsonPath('errors.error_code', 'order_not_active');
});

test('update processing 仅传 order_id 不触发守卫（轮询形态不被破坏）', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'processing');

    // 守卫只针对携带 csr/domains 的提交；不带二者的 POST 仍按既有契约返回当前订单数据
    deployPost($token, '/api/deploy/', ['order_id' => $order->id])
        ->assertOk()->assertJson(['code' => 1])
        ->assertJsonPath('data.status', 'processing');
});

test('update unpaid 仅传 order_id 不触发守卫（推进自愈路径不被破坏）', function () {
    // 回归护栏：不带 csr/domains 的 unpaid 推进（pay）不应命中守卫
    [$user, $token] = createDeployAuth(
        User::factory()->create(['balance' => '0.00', 'credit_limit' => '0.00'])
    );
    [$order] = createDeployOrder($user, 'unpaid', [], ['amount' => '100.00']);

    $response = deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
    ])->assertOk()->assertJson(['code' => 0]);

    // 走 pay 分支（余额不足失败），而非被守卫拦截
    expect((string) $response->json('msg'))->not->toContain('无法变更');
});

test('update pending 仅传 order_id → commit 推进：上游超时被吞返 200 + status=pending（O3 resume 自愈形态）', function () {
    // O3 迁移后 resume 分支端到端护栏：卡在 pending 的在途单（已扣费、api_id 空），下游仅传 order_id 触达
    // update 的 pending 分支 → getData('commit')。上游 commit 返回 code=0（超时/失败）被 getData 吞 → 订单停
    // pending、下游可继续 poll 自愈，非报错。锁死「pending resume 入口的 commit 吞外溢」的 O3 迁移后形态。
    $api = Mockery::mock(Api::class);
    // 默认 pending cert 的 action='new' → commitLocked 调 $this->api->new()；令其返回 code=0 模拟上游超时/失败
    $api->shouldReceive('new')->andReturn(['code' => 0, 'msg' => '上游超时']);
    app()->instance(Api::class, $api);

    [$user, $token] = createDeployAuth();
    [$order, $cert] = createDeployOrder($user, 'pending');

    $response = deployPost($token, '/api/deploy/', ['order_id' => $order->id])
        ->assertOk()->assertJson(['code' => 1]);

    // commit 被吞 → 订单停 pending（下游 poll 自愈）、api_id 仍空（未推进上游）
    expect($response->json('data.status'))->toBe('pending')
        ->and($cert->fresh()->status)->toBe('pending')
        ->and($cert->fresh()->api_id)->toBeNull();
});

// ========================================
// update() — 正常流程（余额相关）
// ========================================

test('update unpaid 余额不足', function () {
    [$user, $token] = createDeployAuth(
        User::factory()->create(['balance' => '0.00', 'credit_limit' => '0.00'])
    );
    [$order] = createDeployOrder($user, 'unpaid', [], [
        'amount' => '100.00',
    ]);

    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
    ])->assertOk()->assertJson(['code' => 0]);
});

test('update active 续费通过 period 验证', function () {
    [$user, $token] = createDeployAuth(
        User::factory()->create([
            'balance' => '1000.00',
            'auto_settings' => ['auto_renew' => true, 'auto_reissue' => false],
        ])
    );

    // period_till 在 15 天内，触发续费逻辑
    [$order] = createDeployOrder($user, 'active', [
        'common_name' => 'renew.example.com',
        'alternative_names' => 'renew.example.com',
    ], [
        'period_till' => now()->addDays(5),
        'auto_renew' => null,
    ], [
        'source' => 'default',
        'validation_methods' => ['delegation', 'txt', 'http'],
    ]);

    // 控制器已继承原订单 period，不会因 period 缺失报错
    $response = deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
    ]);

    // 【O3 行为迁移认领】此前 pay(autoCommit=true) 的 commit 打不可达 gateway 失败 → 响应 code=0 错误；
    // O3 后 renew+pay(false) 原子落 pending、commit 移出被 getData 吞 → 响应 code=1 + status=pending
    // （对齐 V2 一条龙自愈哲学：卡单停 pending 靠 reconcile 自愈，非报错）。弱断言（无「有效期」参数错误）保留。
    $msg = $response->json('msg') ?? '';
    expect($msg)->not->toContain('有效期');
    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.status'))->toBe('pending');
});

// ========================================
// update() — 续费/重签 order 级互斥（F1-2）
// ========================================

test('update active 续费占锁时立即 503（抢锁早于建新单，Order 计数不变）', function () {
    // 占锁路径（Cache 层）：与 Action::commit/cancel 共用 order_mutate_{id} 键，
    // 预占该锁后 POST 续费 → withMutex 抢不到 → MutationBusyException → 503，
    // 且在进 DB / 建新单之前抛出（Order 计数不变）。array driver 进程内互斥。
    config(['cache.default' => 'array']);
    Cache::store('array')->flush();

    [$user, $token] = createDeployAuth(
        User::factory()->create([
            'balance' => '1000.00',
            'auto_settings' => ['auto_renew' => true, 'auto_reissue' => false],
        ])
    );
    [$order] = createDeployOrder($user, 'active', [
        'common_name' => 'lock.example.com',
        'alternative_names' => 'lock.example.com',
    ], [
        'period_till' => now()->addDays(5), // ≤15 天 → 续费
        'auto_renew' => null,
    ], [
        'source' => 'default',
        'validation_methods' => ['delegation', 'txt'],
    ]);

    // 预占互斥锁（模拟同订单 commit/cancel 正在执行 / 另一续费请求持锁）
    expect(Cache::lock("order_mutate_{$order->id}", 60)->get())->toBeTrue();

    $before = Order::count();

    deployPost($token, '/api/deploy/', ['order_id' => $order->id])
        ->assertStatus(503);

    // 抢锁失败早于建新单：无新订单
    expect(Order::count())->toBe($before);
});

test('update active 续费并发前驱翻 renewed：内部 O1 CAS 挡下 {code:0}，不双开', function () {
    // 移除 Deploy 显式预锁后（对齐 V2 一条龙，把 initParams 的 CSR/委托移出订单行锁），
    // 防并发双开改由 renew 内部 persistOrder 的「源订单行锁 + 前驱翻转 affected-rows CAS」承担。
    // 用 DB::listen 在锁内首条 orders FOR UPDATE 执行后把前驱证书翻 renewed，确定性复现
    // 「CAS 读到 status!='active' → affected=0 → 三态守卫 '订单已续费' code=0 回滚」（非真并发、预设态）。
    config(['cache.default' => 'array']);
    Cache::store('array')->flush();

    [$user, $token] = createDeployAuth(
        User::factory()->create([
            'balance' => '1000.00',
            'auto_settings' => ['auto_renew' => true, 'auto_reissue' => false],
        ])
    );
    [$order, $cert] = createDeployOrder($user, 'active', [
        'common_name' => 'guard.example.com',
        'alternative_names' => 'guard.example.com',
    ], [
        'period_till' => now()->addDays(5),
        'auto_renew' => null,
    ], [
        'source' => 'default',
        'validation_methods' => ['delegation', 'txt'],
    ]);

    $before = Order::count();
    $flipped = false;
    DB::listen(function ($query) use (&$flipped, $cert) {
        // 锁内首条 orders 行 FOR UPDATE（persistOrder）执行后翻转前驱证书状态
        if (! $flipped
            && str_contains(strtolower($query->sql), 'for update')
            && str_contains(strtolower($query->sql), 'orders')) {
            $flipped = true;
            DB::table('certs')->where('id', $cert->id)->update(['status' => 'renewed']);
        }
    });

    deployPost($token, '/api/deploy/', ['order_id' => $order->id])
        ->assertOk()->assertJson(['code' => 0])
        ->assertJsonPath('msg', fn ($msg) => str_contains((string) $msg, '订单已续费'));

    expect($flipped)->toBeTrue();          // 确认 CAS 路径确实被触发
    expect(Order::count())->toBe($before); // 无逃逸接替单（内部 CAS 挡下双开）
});

test('update active 重签不自死锁（pay 在锁外，commit 自锁与外层顺序获取）', function () {
    // reissue 复用同一 orderId：若把 pay 放互斥锁内，pay→commit 二次抢同键必 MutationBusyException 自伤。
    // pay 移出锁后是顺序获取（外层锁 finally 已释放），不应出现「正在处理中」自死锁文案。
    config(['cache.default' => 'array']);
    Cache::store('array')->flush();

    [$user, $token] = createDeployAuth();
    [$order, $cert] = createDeployOrder($user, 'active', [
        'common_name' => 'reissue.example.com',
        'alternative_names' => 'reissue.example.com',
    ], [
        'period_till' => now()->addMonths(6), // >15 天 → 重签
    ], [
        'source' => 'default',
        'reissue' => 1,
        'validation_methods' => ['delegation', 'txt'],
    ]);

    // 上游 CA 未配置（测试无 gateway）→ pay→commit 会失败于上游（既有 renew 用例同款容忍，不 assertOk）。
    // 关键断言：① 重签本地终态化在互斥锁内完成并提交（旧证书翻 reissued，证明锁工作且 finally 已释放）；
    // ② 无「正在处理中」自死锁文案（若 pay 在锁内则 commit 二次抢同键抛 MutationBusyException）。
    $response = deployPost($token, '/api/deploy/', ['order_id' => $order->id]);

    expect($cert->fresh()->status)->toBe('reissued')
        ->and((string) $response->json('msg'))->not->toContain('正在处理中');
});

// ========================================
// 认证测试
// ========================================

test('未认证拒绝访问', function () {
    test()->getJson('/api/deploy/')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('无效 token 拒绝访问', function () {
    test()->withHeaders(['Authorization' => 'Bearer invalid-token'])
        ->getJson('/api/deploy/')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

// ========================================
// errors.error_code —— 机器可读失败分类
// ========================================
//
// 错误响应固定 HTTP 200 + code=0（全站统一契约），客户端无法靠状态码区分"确定性失败"
// 与网络错误，只能一律当网络错误无限每日重试。errors.error_code 是唯一的分类依据，
// 取值一旦发布不得改动（下游按字符串判定），故逐个钉死。

test('认证 token 缺失返回 token_missing', function () {
    test()->getJson('/api/deploy/')
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('errors.error_code', 'token_missing');
});

test('认证 token 无效返回 token_invalid', function () {
    test()->withHeaders(['Authorization' => 'Bearer invalid-token'])
        ->getJson('/api/deploy/')
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('errors.error_code', 'token_invalid');
});

test('认证 token 被禁用返回 token_disabled', function () {
    $user = User::factory()->create();
    $token = DeployToken::factory()->disabled()->create(['user_id' => $user->id]);

    deployGet($token)
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('errors.error_code', 'token_disabled');
});

test('认证账号被禁用返回 account_disabled', function () {
    $user = User::factory()->create(['status' => 0]);
    $token = DeployToken::factory()->create(['user_id' => $user->id]);

    deployGet($token)
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('errors.error_code', 'account_disabled');
});

test('认证 IP 不在白名单返回 ip_not_allowed', function () {
    $user = User::factory()->create();
    $token = DeployToken::factory()->withAllowedIps(['10.0.0.1'])->create(['user_id' => $user->id]);

    test()->withHeaders(['Authorization' => "Bearer $token->token"])
        ->withServerVariables(['REMOTE_ADDR' => '192.168.9.9'])
        ->getJson('/api/deploy/')
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('errors.error_code', 'ip_not_allowed');
});

test('query 订单不存在返回 order_not_found', function () {
    [$user, $token] = createDeployAuth();

    deployGet($token, 'order=99999')
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('errors.error_code', 'order_not_found');
});

test('query 批量查询全部不存在返回空列表', function () {
    [$user, $token] = createDeployAuth();

    // 批量路径不报 order_not_found（部分命中是正常形态），只返回命中的那些
    $response = deployGet($token, 'order=99998,99999')
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.data'))->toBe([]);
});

test('update 订单不存在返回 order_not_found', function () {
    [$user, $token] = createDeployAuth();

    deployPost($token, '/api/deploy', ['order_id' => 99999])
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('errors.error_code', 'order_not_found');
});

test('callback 订单不存在返回 order_not_found', function () {
    [$user, $token] = createDeployAuth();

    deployPost($token, '/api/deploy/callback', ['order_id' => 99999, 'status' => 'success'])
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('errors.error_code', 'order_not_found');
});

test('callback 证书不存在返回 cert_not_found', function () {
    [$user, $token] = createDeployAuth();
    $product = Product::factory()->create();
    // 建一个没有 latestCert 的订单：callback 用 Order 直查（不带 whereHas），会走到证书判空分支
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);

    deployPost($token, '/api/deploy/callback', ['order_id' => $order->id, 'status' => 'success'])
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('errors.error_code', 'cert_not_found');
});

// 限流是最需要区分的一类：客户端认出 rate_limited 才知道"这次别重试"。
// 刻意不改 HTTP 429 —— 客户端把 429 当可重试，1s→2s→4s 退避全落在同一 60s 窗口内注定失败，
// 且 RateLimiter 的 Cache::increment 在阈值判断之前，重试反而把恢复时间往后拖。
test('限流返回 rate_limited 与 retry_after', function () {
    [$user, $token] = createDeployAuth();

    // 冻结在 60s 窗口内第 20 秒：既让 retry_after 可确定断言（跨过下一个整窗口 → 120-20=100），
    // 也避免请求恰好跨窗口边界时计数器落到新窗口而不触发限流（RateLimiter 用 now()->timestamp 正是为此）
    Carbon::setTestNow(Carbon::createFromTimestamp(1800000020));

    // 直接把 deploy token 的当前窗口计数器顶到限额之上
    $key = 'rate_limit_deploy:deploy_token_'.$token->id.':'.((int) floor(now()->timestamp / 60));
    Cache::put($key, $token->getEffectiveRateLimit(60) + 1, 120);

    deployGet($token)
        ->assertOk()
        ->assertJsonPath('code', 0)
        ->assertJsonPath('errors.error_code', 'rate_limited')
        ->assertJsonPath('errors.retry_after', 100);
});

// retry_after 的语义守卫：睡满它之后必须真的能过。
// 不能只断言常量——那与实现同源自洽，改错公式照样绿（反模式 15）。这里走两步实证：
// 先证「只睡到下一窗口起点」不够（滑动窗口把刚超限的计数按权重 1 全额计入，必再被拒），
// 再证「睡满 retry_after」够用。前者正是本字段改成 $window*2-$elapsed 之前的取值。
test('限流 retry_after 睡满后确实放行，睡到下一窗口起点则仍被拒', function () {
    [$user, $token] = createDeployAuth();

    Carbon::setTestNow(Carbon::createFromTimestamp(1800000020));
    $key = 'rate_limit_deploy:deploy_token_'.$token->id.':'.((int) floor(now()->timestamp / 60));
    Cache::put($key, $token->getEffectiveRateLimit(60) + 1, 120);

    // 刻意不在这里断言 retry_after 的具体值（上一个用例已锁 100）：常量断言放这儿会抢在
    // 两步实证之前红，让下面真正的语义守卫永远拿不到执行机会（等于白写）
    $retryAfter = deployGet($token)->json('errors.retry_after');

    // ① 只睡到下一窗口起点（旧取值 60-20=40）→ prevWeight=1，刚超限的计数全额计入 → 仍被拒
    Carbon::setTestNow(Carbon::createFromTimestamp(1800000020 + 40));
    deployGet($token)->assertJsonPath('errors.error_code', 'rate_limited');

    // ② 睡满 retry_after → prev 指向中间那个窗口（只剩 ① 那次徒劳重试的 1 次）→ 放行。
    //    放行的标志是被业务层参数校验拦下（invalid_order）而非 rate_limited
    Carbon::setTestNow(Carbon::createFromTimestamp(1800000020 + $retryAfter));
    deployGet($token)->assertJsonPath('errors.error_code', 'invalid_order');
});

// ========================================
// query() — GET query token 认证
// ========================================

test('query GET query token 认证通过', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active');

    $response = test()->getJson("/api/deploy?token=$token->token&order=$order->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    $response->assertJsonPath('data.data.0.order_id', $order->id);
});

test('query GET query token 落 api_logs 时 url 脱敏（F1-3）', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active');

    // 走 query token 认证：?token=<real> 明文串传，url 字段须脱敏
    test()->getJson("/api/deploy?token=$token->token&order=$order->id")->assertOk();

    // LogBuffer 在请求 terminating 时 flush；测试内 HTTP 调用后已 flush
    $log = ApiLog::query()->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->url)->not->toContain($token->token) // 明文 token 不落库
        ->and($log->url)->toContain('order='.$order->id); // 业务参数保留
});

// ========================================
// toggleAutoReissue() 测试
// ========================================

test('toggleAutoReissue 开启自动重签', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active', [], ['auto_reissue' => false]);

    $response = deployPost($token, '/api/deploy/auto-reissue', [
        'order_id' => $order->id,
        'auto_reissue' => true,
    ])->assertOk()->assertJson(['code' => 1]);

    expect($response->json('data'))
        ->order_id->toBe($order->id)
        ->auto_reissue->toBeTrue();

    expect($order->fresh()->auto_reissue)->toBeTrue();
});

test('toggleAutoReissue 关闭自动重签', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active', [], ['auto_reissue' => true]);

    $response = deployPost($token, '/api/deploy/auto-reissue', [
        'order_id' => $order->id,
        'auto_reissue' => false,
    ])->assertOk()->assertJson(['code' => 1]);

    expect($response->json('data'))
        ->order_id->toBe($order->id)
        ->auto_reissue->toBeFalse();

    expect($order->fresh()->auto_reissue)->toBeFalse();
});

test('toggleAutoReissue 订单不存在', function () {
    [, $token] = createDeployAuth();

    deployPost($token, '/api/deploy/auto-reissue', [
        'order_id' => 99999,
        'auto_reissue' => true,
    ])->assertOk()->assertJson(['code' => 0]);
});

test('toggleAutoReissue UserScope 隔离', function () {
    [, $token] = createDeployAuth();
    $otherUser = User::factory()->create();
    [$otherOrder] = createDeployOrder($otherUser, 'active');

    deployPost($token, '/api/deploy/auto-reissue', [
        'order_id' => $otherOrder->id,
        'auto_reissue' => true,
    ])->assertOk()->assertJson(['code' => 0]);
});

test('toggleAutoReissue 参数验证', function () {
    [, $token] = createDeployAuth();

    // 缺少必填字段
    deployPost($token, '/api/deploy/auto-reissue', [])
        ->assertOk()
        ->assertJson(['code' => 0]);

    // 缺少 auto_reissue
    deployPost($token, '/api/deploy/auto-reissue', ['order_id' => 1])
        ->assertOk()
        ->assertJson(['code' => 0]);
});

// ========================================
// 国密 (SM2) — Deploy 自动部署 gate
// ========================================

test('query field 拉取国密 SM2 证书被拒绝（防 certimate 单证书自动部署残缺）', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active', [
        'common_name' => 'gm.example.com',
        'encryption_alg' => 'SM2',
        'enc_cert' => "-----BEGIN CERTIFICATE-----\nENC\n-----END CERTIFICATE-----",
    ]);

    deployGet($token, "order={$order->id}&field=certificate")->assertStatus(400);
    deployGet($token, "order={$order->id}&field=private_key")->assertStatus(400);
});

test('query field 拉取非国密证书正常返回 PEM 文本', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active', ['encryption_alg' => 'RSA']);

    deployGet($token, "order={$order->id}&field=certificate")
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
});

test('query 国密 active 订单返回加密双证书字段 + 算法标记', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active', [
        'encryption_alg' => 'SM2',
        'enc_cert' => "-----BEGIN CERTIFICATE-----\nENC\n-----END CERTIFICATE-----",
        'enc_key' => 'ENC-KEY-0016',
        'enc_key2' => 'ENC-KEY-0009',
    ]);

    $response = deployGet($token, "order={$order->id}")->assertOk();
    $response->assertJsonPath('data.data.0.encryption_alg', 'sm2');
    $response->assertJsonPath('data.data.0.enc_certificate', "-----BEGIN CERTIFICATE-----\nENC\n-----END CERTIFICATE-----");
    $response->assertJsonPath('data.data.0.enc_private_key', 'ENC-KEY-0016');
});
