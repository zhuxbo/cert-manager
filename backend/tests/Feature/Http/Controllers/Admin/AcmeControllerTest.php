<?php

use App\Exceptions\ApiResponseException;
use App\Jobs\TaskJob;
use App\Models\Acme;
use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\Task;
use App\Models\User;
use App\Services\Acme\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

/**
 * 创建 Gateway 系统设置
 */
function setupAdminGatewaySettings(string $url = 'https://fake-gateway.test/api/v2', string $token = 'fake-key'): void
{
    $group = SettingGroup::firstOrCreate(['name' => 'ca'], ['title' => '证书接口', 'weight' => 2]);

    foreach (['url' => $url, 'token' => $token, 'acme_url' => null, 'acme_token' => null] as $key => $value) {
        $setting = Setting::firstOrCreate(
            ['group_id' => $group->id, 'key' => $key],
            ['type' => 'string', 'value' => null, 'weight' => 0]
        );
        if ($value !== null) {
            $setting->value = $value;
            $setting->save();
        }
    }
}

/**
 * 创建 ACME 产品及价格
 */
function createAcmeProduct(array $productOverrides = []): Product
{
    return Product::factory()->create(array_merge([
        'product_type' => Product::TYPE_ACME,
    ], $productOverrides));
}

/**
 * 创建产品价格
 */
function createProductPrice(Product $product, User $user, string $price = '100.00'): void
{
    ProductPrice::create([
        'product_id' => $product->id,
        'level_code' => $user->level_code ?? 'standard',
        'period' => 12,
        'price' => $price,
        'alternative_standard_price' => '10.00',
        'alternative_wildcard_price' => '20.00',
    ]);
}

/**
 * 通过 Action 创建 unpaid 的 ACME 订单
 */
function createAcmeViaAction(User $user, Product $product, array $overrides = []): Acme
{
    $action = app(Action::class);

    $params = array_merge([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period' => 12,
        'purchased_standard_count' => 0,
        'purchased_wildcard_count' => 0,
        'contact_email' => $user->email ?: 'test@example.com',
    ], $overrides);

    try {
        $action->new($params);
        test()->fail('期望抛出 ApiResponseException 但未抛出');
    } catch (ApiResponseException $e) {
        $response = $e->getApiResponse();
        expect($response['code'])->toBe(1);

        return Acme::find($response['data']['order_id']);
    }
}

// ==================== index ====================

test('index 返回列表', function () {
    $acme1 = Acme::factory()->create();
    $acme2 = Acme::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/acme/');

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);
    expect($response->json('data.total'))->toBe(2);
    expect($response->json('data.items'))->toHaveCount(2);
});

test('index 按 user_id 过滤', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    Acme::factory()->create(['user_id' => $user1->id]);
    Acme::factory()->create(['user_id' => $user2->id]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/acme/?user_id=$user1->id");

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
    expect($response->json('data.items.0.user_id'))->toBe($user1->id);
});

test('index 按 status 过滤', function () {
    Acme::factory()->active()->create();
    Acme::factory()->cancelled()->create();

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/acme/?status=active');

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.total'))->toBe(1);
    expect($response->json('data.items.0.status'))->toBe('active');
});

// ==================== show ====================

test('show 返回详情含 eab_hmac', function () {
    $acme = Acme::factory()->active()->create([
        'eab_kid' => 'test-kid',
        'eab_hmac' => 'test-hmac-secret',
    ]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/acme/$acme->id");

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.id', $acme->id);
    $response->assertJsonPath('data.eab_kid', 'test-kid');
    // eab_hmac 通过 makeVisible 暴露，应存在于响应中
    expect($response->json('data.eab_hmac'))->not->toBeNull();
});

test('show 不存在返回错误', function () {
    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/acme/99999');

    $response->assertOk()->assertJson(['code' => 0]);
});

// ==================== new ====================

test('new 成功创建订单', function () {
    $user = User::factory()->create(['balance' => '500.00']);
    $product = createAcmeProduct();
    createProductPrice($product, $user);

    $response = $this->actingAsAdmin($this->admin)->postJson('/api/admin/acme/new', [
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period' => 12,
        'contact_email' => 'admin-buyer@example.com',
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.order_id'))->toBeGreaterThan(0);

    $acme = Acme::find($response->json('data.order_id'));
    expect($acme)->not->toBeNull();
    expect($acme->status)->toBe(Acme::STATUS_UNPAID);
    expect($acme->user_id)->toBe($user->id);
    expect($acme->product_id)->toBe($product->id);
    expect($acme->contact_email)->toBe('admin-buyer@example.com');
    expect($acme->channel)->toBe('admin');
});

test('new 缺少 contact_email 校验失败', function () {
    $user = User::factory()->create(['balance' => '500.00']);
    $product = createAcmeProduct();
    createProductPrice($product, $user);

    $this->actingAsAdmin($this->admin)->postJson('/api/admin/acme/new', [
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period' => 12,
    ])
        ->assertOk()
        ->assertJson(['code' => 0])
        ->assertJsonPath('errors.contact_email.0', fn ($msg) => is_string($msg));
});

// ==================== pay ====================

test('pay 成功支付并同步提交至 active', function () {
    $user = User::factory()->create(['balance' => '500.00']);
    $product = createAcmeProduct(['source' => 'default']);
    createProductPrice($product, $user);

    setupAdminGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'order_id' => 'gw-pay-123',
                'vendor_id' => 'v-pay',
                'eab_kid' => 'kid-pay',
                'eab_hmac' => 'hmac-pay',
                'directory_url' => 'https://acme.example.test/directory/',
            ],
        ]),
    ]);

    $acme = createAcmeViaAction($user, $product);
    expect($acme->status)->toBe(Acme::STATUS_UNPAID);

    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/acme/pay/$acme->id");

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.eab_kid'))->toBe('kid-pay');

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_ACTIVE);
});

// ==================== commit ====================

test('commit 成功提交', function () {
    $user = User::factory()->create(['balance' => '500.00']);
    $product = createAcmeProduct(['source' => 'default']);
    createProductPrice($product, $user);

    $acme = createAcmeViaAction($user, $product);

    // 先支付（仅扣费，单独测试 commit）
    $action = app(Action::class);
    try {
        $action->pay($acme->id, false);
    } catch (ApiResponseException) {
    }

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_PENDING);

    // Mock Gateway HTTP
    setupAdminGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'order_id' => 'gw-123',
                'vendor_id' => 'v-456',
                'eab_kid' => 'kid-abc',
                'eab_hmac' => 'hmac-xyz',
            ],
        ]),
    ]);

    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/acme/commit/$acme->id");

    $response->assertOk()->assertJson(['code' => 1]);
    expect($response->json('data.eab_kid'))->toBe('kid-abc');
    expect($response->json('data.eab_hmac'))->toBe('hmac-xyz');

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_ACTIVE);
    expect($acme->api_id)->toBe('gw-123');
});

// ==================== sync ====================

test('sync 成功同步', function () {
    $product = createAcmeProduct(['source' => 'default']);
    $acme = Acme::factory()->active()->create([
        'product_id' => $product->id,
        'api_id' => 'gw-sync-test',
    ]);

    setupAdminGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => ['status' => 'expired', 'vendor_id' => 'v-new'],
        ]),
    ]);

    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/acme/sync/$acme->id");

    $response->assertOk()->assertJson(['code' => 1]);

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_EXPIRED);
    expect($acme->vendor_id)->toBe('v-new');
});

// ==================== commitCancel ====================

test('commitCancel 成功取消', function () {
    Queue::fake();

    $product = createAcmeProduct();
    $acme = Acme::factory()->active()->create([
        'product_id' => $product->id,
        'api_id' => 'upstream-123',
        'amount' => '100.00',
    ]);

    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/acme/commit-cancel/$acme->id");

    $response->assertOk()->assertJson(['code' => 1]);

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_CANCELLING);
    // 仅提交取消阶段不记录 cancelled_at（实际取消完成后由 cancel() 写入）
    expect($acme->cancelled_at)->toBeNull();
});

// ==================== revokeCancel ====================

test('revokeCancel 撤回取消恢复 active 并清理 Task', function () {
    Queue::fake();

    $product = createAcmeProduct();
    $acme = Acme::factory()->active()->create([
        'product_id' => $product->id,
        'api_id' => 'upstream-123',
        'amount' => '100.00',
    ]);

    $this->actingAsAdmin($this->admin)
        ->postJson("/api/admin/acme/commit-cancel/$acme->id")
        ->assertOk()->assertJson(['code' => 1]);

    $response = $this->actingAsAdmin($this->admin)
        ->postJson("/api/admin/acme/revoke-cancel/$acme->id");

    $response->assertOk()->assertJson(['code' => 1]);

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_ACTIVE);
    expect($acme->cancelled_at)->toBeNull();
    expect(Task::where('order_id', $acme->id)->where('action', 'cancel_acme')->count())->toBe(0);
});

test('revokeCancel 非 cancelling 状态拒绝', function () {
    $acme = Acme::factory()->active()->create();

    $response = $this->actingAsAdmin($this->admin)
        ->postJson("/api/admin/acme/revoke-cancel/$acme->id");

    $response->assertOk()->assertJson(['code' => 0, 'msg' => '订单不在取消中状态']);
});

// ==================== remark ====================

test('remark 更新 admin_remark', function () {
    $acme = Acme::factory()->create();

    $response = $this->actingAsAdmin($this->admin)->postJson("/api/admin/acme/remark/$acme->id", [
        'remark' => '管理员测试备注',
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    $acme->refresh();
    expect($acme->admin_remark)->toBe('管理员测试备注');
});

// ==================== batch-pay ====================

test('admin batch-pay 成功支付多个 unpaid', function () {
    $user = User::factory()->create(['balance' => '500.00']);
    $product = createAcmeProduct();
    createProductPrice($product, $user);
    setupAdminGatewaySettings();

    $a1 = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'status' => 'unpaid', 'amount' => '100.00']);
    $a2 = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'status' => 'unpaid', 'amount' => '100.00']);

    $res = $this->actingAsAdmin($this->admin)->postJson('/api/admin/acme/batch-pay', ['ids' => [$a1->id, $a2->id]]);
    $res->assertJsonPath('code', 1);
    $res->assertJsonPath('data.success_count', 2);
});

// ==================== batch-commit ====================

test('admin batch-commit 仅处理 pending 并入队 commit_acme', function () {
    Queue::fake();
    $user = User::factory()->create();
    $a1 = Acme::factory()->create(['user_id' => $user->id, 'status' => 'pending']);
    $a2 = Acme::factory()->create(['user_id' => $user->id, 'status' => 'unpaid']);

    $res = $this->actingAsAdmin($this->admin)->postJson('/api/admin/acme/batch-commit', ['ids' => [$a1->id, $a2->id]]);
    $res->assertJsonPath('code', 1);
    Queue::assertPushed(TaskJob::class, 1);
});

// ==================== batch-sync ====================

test('admin batch-sync 仅处理 active/cancelling 并入队 sync_acme', function () {
    Queue::fake();
    $user = User::factory()->create();
    $active = Acme::factory()->create(['user_id' => $user->id, 'status' => 'active', 'api_id' => 'x1']);
    $pending = Acme::factory()->create(['user_id' => $user->id, 'status' => 'pending']);

    $res = $this->actingAsAdmin($this->admin)->postJson('/api/admin/acme/batch-sync', ['ids' => [$active->id, $pending->id]]);
    $res->assertJsonPath('code', 1);
    Queue::assertPushed(TaskJob::class, 1);
});

// ==================== batch-commit-cancel ====================

test('admin batch-commit-cancel 混合处理', function () {
    Queue::fake();
    $user = User::factory()->create(['balance' => '500.00']);
    $product = createAcmeProduct();

    $unpaid = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'status' => 'unpaid', 'amount' => '100.00']);
    $active = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'status' => 'active', 'api_id' => 'x1']);

    $res = $this->actingAsAdmin($this->admin)->postJson('/api/admin/acme/batch-commit-cancel', ['ids' => [$unpaid->id, $active->id]]);
    $res->assertJsonPath('code', 1);
    expect(Acme::find($unpaid->id)->status)->toBe('cancelled');
    expect(Acme::find($active->id)->status)->toBe('cancelling');
});

// ==================== batch-revoke-cancel ====================

test('admin batch-revoke-cancel 回滚 cancelling 至 active', function () {
    $user = User::factory()->create();
    $c = Acme::factory()->create(['user_id' => $user->id, 'status' => 'cancelling', 'api_id' => 'a1']);

    $res = $this->actingAsAdmin($this->admin)->postJson('/api/admin/acme/batch-revoke-cancel', ['ids' => [$c->id]]);
    $res->assertJsonPath('code', 1);
    expect(Acme::find($c->id)->status)->toBe('active');
});

// ==================== batch-copy-eab ====================

test('admin batch-copy-eab 同用户正常返回文本', function () {
    $user = User::factory()->create();
    $product = createAcmeProduct(['ca' => 'google']);
    setupAdminGatewaySettings();

    $a1 = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'eab_kid' => 'KID1', 'eab_hmac' => 'HMAC1']);
    $a2 = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'eab_kid' => 'KID2', 'eab_hmac' => 'HMAC2']);

    $res = $this->actingAsAdmin($this->admin)->postJson('/api/admin/acme/batch-copy-eab', ['ids' => [$a1->id, $a2->id]]);
    $res->assertJsonPath('code', 1);
    $res->assertJsonPath('data.count', 2);
    expect($res->json('data.text'))->toContain('eab_kid=KID1');
    expect($res->json('data.text'))->toContain('eab_kid=KID2');
});

test('admin batch-copy-eab 跨用户拒绝', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();
    $a1 = Acme::factory()->create(['user_id' => $u1->id, 'eab_kid' => 'K1', 'eab_hmac' => 'H1']);
    $a2 = Acme::factory()->create(['user_id' => $u2->id, 'eab_kid' => 'K2', 'eab_hmac' => 'H2']);

    $res = $this->actingAsAdmin($this->admin)->postJson('/api/admin/acme/batch-copy-eab', ['ids' => [$a1->id, $a2->id]]);
    $res->assertJsonPath('code', 0);
    $res->assertJsonPath('msg', '仅能复制同一用户的 EAB');
});
