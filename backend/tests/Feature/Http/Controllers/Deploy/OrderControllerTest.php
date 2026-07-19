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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
    ])->assertOk()->assertJson(['code' => 0, 'msg' => '该产品不支持委托验证']);
});

test('update 本地 CSR 前置校验失败不写签发失败记录', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'active', [], [], [
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
    [$order, $cert] = createDeployOrder($user, 'active', [], [], [
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
// update() — 在途订单 CSR/域名守卫（F1-1）
// ========================================

test('update unpaid 携带新 csr 显式报错（在途订单 CSR 已定型）', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'unpaid');

    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'csr' => "-----BEGIN CERTIFICATE REQUEST-----\nNEW\n-----END CERTIFICATE REQUEST-----",
    ])->assertOk()->assertJson(['code' => 0])
        ->assertJsonPath('msg', fn ($msg) => str_contains((string) $msg, '无法变更'));
});

test('update pending 携带新 domains 显式报错（在途订单域名已定型）', function () {
    [$user, $token] = createDeployAuth();
    [$order] = createDeployOrder($user, 'pending');

    deployPost($token, '/api/deploy/', [
        'order_id' => $order->id,
        'domains' => 'a.example.com,b.example.com',
    ])->assertOk()->assertJson(['code' => 0])
        ->assertJsonPath('msg', fn ($msg) => str_contains((string) $msg, '无法变更'));
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
