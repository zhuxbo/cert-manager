<?php

use App\Models\ApiLog;
use App\Models\Cert;
use App\Models\DeployToken;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
// query() — 空参数
// ========================================

test('query 空参数返回最新活跃订单', function () {
    [$user, $token] = createDeployAuth();
    [$order, $cert] = createDeployOrder($user, 'active');

    $response = deployGet($token)
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.data'))->toHaveCount(1);
    $response->assertJsonPath('data.data.0.order_id', $order->id);
    $response->assertJsonPath('data.data.0.status', 'active');
    $response->assertJsonPath('data.data.0.domains', $cert->alternative_names);
});

test('query 空参数仅返回 active 状态', function () {
    [$user, $token] = createDeployAuth();
    createDeployOrder($user, 'active');
    createDeployOrder($user, 'pending');
    createDeployOrder($user, 'processing');

    $response = deployGet($token)->assertOk()->assertJson(['code' => 1]);

    expect($response->json('data.data'))->toHaveCount(1);
    expect($response->json('data.total'))->toBe(1);
});

test('query 空参数分页', function () {
    [$user, $token] = createDeployAuth();
    for ($i = 0; $i < 5; $i++) {
        createDeployOrder($user, 'active');
    }

    $response = deployGet($token, 'page_size=2&page=1')
        ->assertOk()->assertJson(['code' => 1]);

    expect($response->json('data.data'))->toHaveCount(2);
    expect($response->json('data.total'))->toBe(5);
    expect($response->json('data.page_size'))->toBe(2);
    expect($response->json('data.page'))->toBe(1);

    $response2 = deployGet($token, 'page_size=2&page=3')
        ->assertOk()->assertJson(['code' => 1]);

    expect($response2->json('data.data'))->toHaveCount(1);
});

// query() 用 (int) $request->input('page', 1) 兜底默认值：客户端显式传 JSON null 时
// input() 返回 null（key 存在），(int) null = 0 → offset((0-1)*page_size) 负偏移，
// 响应体 page 字段也回显 0（错误）。正确写法应为 (int) ($request->input('page') ?? 1)。
test('query page 显式 null 回落默认值（非负 offset，page 回显 1）', function () {
    [$user, $token] = createDeployAuth();
    for ($i = 0; $i < 3; $i++) {
        createDeployOrder($user, 'active');
    }

    $response = test()->withHeaders(['Authorization' => "Bearer $token->token"])
        ->json('GET', '/api/deploy/', ['page' => null])
        ->assertOk()->assertJson(['code' => 1]);

    expect($response->json('data.page'))->toBe(1);
    expect($response->json('data.data'))->toHaveCount(3);
});

test('query 空参数 UserScope 隔离', function () {
    [$user, $token] = createDeployAuth();
    $otherUser = User::factory()->create();
    createDeployOrder($user, 'active');
    createDeployOrder($otherUser, 'active');

    $response = deployGet($token)->assertOk()->assertJson(['code' => 1]);

    expect($response->json('data.data'))->toHaveCount(1);
});

// ========================================
// query() — 按 ID 查询
// ========================================

test('query 按 ID 查询返回分页格式', function () {
    [$user, $token] = createDeployAuth();
    [$order, $cert] = createDeployOrder($user, 'active');

    $response = deployGet($token, "order=$order->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.total'))->toBe(1);
    expect($response->json('data.data'))->toHaveCount(1);
    $response->assertJsonPath('data.data.0.order_id', $order->id);
    $response->assertJsonPath('data.data.0.status', 'active');
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

// ========================================
// query() — 按域名查询
// ========================================

test('query 按域名查询返回分页格式', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active', [
        'common_name' => 'deploy.example.com',
        'alternative_names' => 'deploy.example.com',
    ]);

    $response = deployGet($token, 'order=deploy.example.com')
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.total'))->toBe(1);
    $response->assertJsonPath('data.data.0.order_id', $order->id);
    $response->assertJsonPath('data.data.0.domains', 'deploy.example.com');
});

test('query 按域名查询通配符域名', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active', [
        'common_name' => '*.example.com',
        'alternative_names' => '*.example.com',
    ]);

    // 直接查 *.example.com
    $response = deployGet($token, 'order=*.example.com')
        ->assertOk()
        ->assertJson(['code' => 1]);

    $response->assertJsonPath('data.data.0.order_id', $order->id);
});

test('query 按域名查询仅匹配 active 证书', function () {
    [$user, $token] = createDeployAuth();
    createDeployOrder($user, 'pending', [
        'common_name' => 'pending.example.com',
        'alternative_names' => 'pending.example.com',
    ]);

    deployGet($token, 'order=pending.example.com')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('query 按域名查询不存在', function () {
    [$user, $token] = createDeployAuth();

    deployGet($token, 'order=nonexistent.example.com')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('query 按域名查询 UserScope 隔离', function () {
    [$user, $token] = createDeployAuth();
    $otherUser = User::factory()->create();
    createDeployOrder($otherUser, 'active', [
        'common_name' => 'other.example.com',
        'alternative_names' => 'other.example.com',
    ]);

    deployGet($token, 'order=other.example.com')
        ->assertOk()
        ->assertJson(['code' => 0]);
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

test('query 批量查询按域名', function () {
    [$user, $token] = createDeployAuth();
    createDeployOrder($user, 'active', [
        'common_name' => 'a.example.com',
        'alternative_names' => 'a.example.com',
    ]);
    createDeployOrder($user, 'active', [
        'common_name' => 'b.example.com',
        'alternative_names' => 'b.example.com',
    ]);

    $response = deployGet($token, 'order=a.example.com,b.example.com')
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.data'))->toHaveCount(2);
});

test('query 批量查询混合 ID 和域名', function () {
    [$user, $token] = createDeployAuth();
    [$order1] = createDeployOrder($user, 'active');
    createDeployOrder($user, 'active', [
        'common_name' => 'mix.example.com',
        'alternative_names' => 'mix.example.com',
    ]);

    $response = deployGet($token, "order=$order1->id,mix.example.com")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.data'))->toHaveCount(2);
});

test('query 批量查询去重', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active', [
        'common_name' => 'dup.example.com',
        'alternative_names' => 'dup.example.com',
    ]);

    // 同时用 ID 和域名查同一条
    $response = deployGet($token, "order=$order->id,dup.example.com")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.data'))->toHaveCount(1);
});

test('query 批量查询分页', function () {
    [$user, $token] = createDeployAuth();
    for ($i = 0; $i < 5; $i++) {
        createDeployOrder($user, 'active');
    }

    $allIds = Order::withoutGlobalScopes()->pluck('id')->implode(',');

    $response = deployGet($token, "order=$allIds&page_size=2&page=1")
        ->assertOk()->assertJson(['code' => 1]);

    expect($response->json('data.data'))->toHaveCount(2);
    expect($response->json('data.total'))->toBe(5);
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

    expect($cert->fresh()->auto_deploy_at)->not->toBeNull();
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
        ->recorded->toBeFalse();

    expect($cert->fresh()->auto_deploy_at)->toBeNull();
});

test('callback 使用自定义 deployed_at', function () {
    [$user, $token] = createDeployAuth();
    [$order, $cert] = createDeployOrder($user, 'active', ['auto_deploy_at' => null]);

    deployPost($token, '/api/deploy/callback', [
        'order_id' => $order->id,
        'status' => 'success',
        'deployed_at' => '2026-01-15 08:30:00',
    ])->assertOk()->assertJson(['code' => 1]);

    expect($cert->fresh()->auto_deploy_at->format('Y-m-d H:i:s'))
        ->toBe('2026-01-15 08:30:00');
});

test('callback deployed_at 格式错误使用当前时间', function () {
    [$user, $token] = createDeployAuth();
    [$order, $cert] = createDeployOrder($user, 'active', ['auto_deploy_at' => null]);

    $this->travelTo(now()->startOfMinute());

    deployPost($token, '/api/deploy/callback', [
        'order_id' => $order->id,
        'status' => 'success',
        'deployed_at' => 'not-a-date',
    ])->assertOk()->assertJson(['code' => 1]);

    expect($cert->fresh()->auto_deploy_at)->not->toBeNull();
});

test('callback 订单不存在', function () {
    [$user, $token] = createDeployAuth();

    deployPost($token, '/api/deploy/callback', [
        'order_id' => 99999,
        'status' => 'success',
    ])->assertOk()->assertJson(['code' => 0]);
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
    ])->assertOk()->assertJson(['code' => 0, 'msg' => '该产品不支持委托验证']);
});

test('update active 产品不支持文件验证', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active', [], [], [
        'validation_methods' => ['txt', 'email'],  // 无 file/http/https
    ]);

    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'validation_method' => 'file',
    ])->assertOk()->assertJson(['code' => 0, 'msg' => '该产品不支持文件验证']);
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
    ])->assertOk()->assertJson(['code' => 0, 'msg' => '该订单未开启自动续费']);
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
    // 后续会因 gateway 不可用而失败，但不应是参数验证错误
    $response = deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
    ]);

    $msg = $response->json('msg') ?? '';
    expect($msg)->not->toContain('有效期');
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
