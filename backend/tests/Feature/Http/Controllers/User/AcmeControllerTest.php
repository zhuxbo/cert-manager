<?php

use App\Exceptions\ApiResponseException;
use App\Models\Acme;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\User;
use App\Services\Acme\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(Tests\Traits\ActsAsUser::class);
uses(RefreshDatabase::class);

/**
 * 创建 Gateway 系统设置
 */
function setupUserGatewaySettings(string $url = 'https://fake-gateway.test/api/v2', string $token = 'fake-key'): void
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
 * 创建 ACME 产品
 */
function createUserAcmeProduct(array $overrides = []): Product
{
    return Product::factory()->create(array_merge([
        'product_type' => Product::TYPE_ACME,
    ], $overrides));
}

/**
 * 创建产品价格
 */
function createUserProductPrice(Product $product, User $user, string $price = '100.00'): void
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
function createUserAcmeViaAction(User $user, Product $product, array $overrides = []): Acme
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

/**
 * 将 unpaid 订单支付为 pending 状态
 */
function payUserAcmeViaAction(Acme $acme): Acme
{
    $action = app(Action::class);

    try {
        // 测试场景仅扣费到 pending，避免同步 commit 触发上游调用
        $action->pay($acme->id, false);
    } catch (ApiResponseException) {
    }

    return $acme->refresh();
}

// ==================== index ====================

test('index 仅返回当前用户订单', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $product = createUserAcmeProduct();

    $acme = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    Acme::factory()->create(['user_id' => $otherUser->id, 'product_id' => $product->id]);

    $response = $this->actingAsUser($user)
        ->getJson('/api/acme/')
        ->assertOk()
        ->assertJson(['code' => 1])
        ->assertJsonStructure(['data' => ['items', 'total', 'pageSize', 'currentPage']]);

    expect($response->json('data.total'))
        ->toBe(1)
        ->and($response->json('data.items'))
        ->toHaveCount(1)
        ->and($response->json('data.items.0.id'))
        ->toBe($acme->id);
});

// ==================== show ====================

test('show 仅查看自己订单', function () {
    $user = User::factory()->create();
    $product = createUserAcmeProduct();

    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'eab_kid' => 'test-kid',
        'eab_hmac' => 'test-hmac-secret',
    ]);

    $response = $this->actingAsUser($user)
        ->getJson("/api/acme/$acme->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.id'))
        ->toBe($acme->id)
        ->and($response->json('data.eab_kid'))
        ->toBe('test-kid')
        ->and($response->json('data.eab_hmac'))
        ->not->toBeNull();
});

test('show 查看他人订单返回 null', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $product = createUserAcmeProduct();

    $acme = Acme::factory()->active()->create([
        'user_id' => $otherUser->id,
        'product_id' => $product->id,
    ]);

    $this->actingAsUser($user)
        ->getJson("/api/acme/$acme->id")
        ->assertOk()
        ->assertJson(['code' => 0]);
});

// ==================== new ====================

test('new 成功创建订单', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = createUserAcmeProduct();
    createUserProductPrice($product, $user);

    $response = $this->actingAsUser($user)
        ->postJson('/api/acme/new', [
            'product_id' => $product->id,
            'period' => 12,
            'contact_email' => 'buyer@example.com',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.order_id'))->toBeGreaterThan(0);

    $acme = Acme::find($response->json('data.order_id'));

    expect($acme)
        ->not->toBeNull()
        ->and($acme->status)
        ->toBe(Acme::STATUS_UNPAID)
        ->and($acme->user_id)
        ->toBe($user->id)
        ->and($acme->product_id)
        ->toBe($product->id)
        ->and($acme->contact_email)
        ->toBe('buyer@example.com')
        ->and($acme->channel)
        ->toBe('web');
});

test('new 缺少 contact_email 校验失败', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = createUserAcmeProduct();
    createUserProductPrice($product, $user);

    $this->actingAsUser($user)
        ->postJson('/api/acme/new', [
            'product_id' => $product->id,
            'period' => 12,
        ])
        ->assertOk()
        ->assertJson(['code' => 0])
        ->assertJsonPath('errors.contact_email.0', fn ($msg) => is_string($msg));
});

test('new 非法 contact_email 校验失败', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = createUserAcmeProduct();
    createUserProductPrice($product, $user);

    $this->actingAsUser($user)
        ->postJson('/api/acme/new', [
            'product_id' => $product->id,
            'period' => 12,
            'contact_email' => 'not-an-email',
        ])
        ->assertOk()
        ->assertJson(['code' => 0])
        ->assertJsonPath('errors.contact_email.0', fn ($msg) => is_string($msg));
});

// ==================== pay ====================

test('pay 成功支付并同步提交至 active', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = createUserAcmeProduct(['source' => 'default']);
    createUserProductPrice($product, $user);

    setupUserGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'order_id' => 'gw-user-pay',
                'vendor_id' => 'v-user-pay',
                'eab_kid' => 'kid-user-pay',
                'eab_hmac' => 'hmac-user-pay',
                'directory_url' => 'https://acme.example.test/directory/',
            ],
        ]),
    ]);

    $acme = createUserAcmeViaAction($user, $product);
    expect($acme->status)->toBe(Acme::STATUS_UNPAID);

    $response = $this->actingAsUser($user)
        ->postJson("/api/acme/pay/$acme->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.eab_kid'))->toBe('kid-user-pay');

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_ACTIVE);
});

test('pay 他人订单返回 404', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $otherUser = User::factory()->withBalance('1000.00')->create();
    $product = createUserAcmeProduct();
    createUserProductPrice($product, $otherUser);

    $acme = createUserAcmeViaAction($otherUser, $product);

    $this->actingAsUser($user)
        ->postJson("/api/acme/pay/$acme->id")
        ->assertNotFound();
});

// ==================== commit ====================

test('commit 成功提交', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = createUserAcmeProduct(['source' => 'default']);
    createUserProductPrice($product, $user);

    $acme = createUserAcmeViaAction($user, $product);
    $acme = payUserAcmeViaAction($acme);
    expect($acme->status)->toBe(Acme::STATUS_PENDING);

    setupUserGatewaySettings();
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

    $response = $this->actingAsUser($user)
        ->postJson("/api/acme/commit/$acme->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data.eab_kid'))
        ->toBe('kid-abc')
        ->and($response->json('data.eab_hmac'))
        ->toBe('hmac-xyz');

    $acme->refresh();
    expect($acme->status)
        ->toBe(Acme::STATUS_ACTIVE)
        ->and($acme->api_id)
        ->toBe('gw-123');
});

// ==================== commitCancel ====================

test('commitCancel 成功取消', function () {
    Queue::fake();

    $user = User::factory()->create();
    $product = createUserAcmeProduct();

    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'upstream-123',
        'amount' => '100.00',
    ]);

    $response = $this->actingAsUser($user)
        ->postJson("/api/acme/commit-cancel/$acme->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    $acme->refresh();
    expect($acme->status)
        ->toBe(Acme::STATUS_CANCELLING)
        // 仅提交取消阶段不记录 cancelled_at（实际取消完成后由 cancel() 写入）
        ->and($acme->cancelled_at)
        ->toBeNull();
});

test('commitCancel 他人订单返回 404', function () {
    Queue::fake();

    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $product = createUserAcmeProduct();

    $acme = Acme::factory()->active()->create([
        'user_id' => $otherUser->id,
        'product_id' => $product->id,
        'api_id' => 'upstream-456',
        'amount' => '100.00',
    ]);

    $this->actingAsUser($user)
        ->postJson("/api/acme/commit-cancel/$acme->id")
        ->assertNotFound();
});

// ==================== revokeCancel ====================

test('revokeCancel 撤回取消恢复 active', function () {
    Queue::fake();

    $user = User::factory()->create();
    $product = createUserAcmeProduct();
    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'upstream-123',
        'amount' => '100.00',
    ]);

    $this->actingAsUser($user)
        ->postJson("/api/acme/commit-cancel/$acme->id")
        ->assertOk()->assertJson(['code' => 1]);

    $this->actingAsUser($user)
        ->postJson("/api/acme/revoke-cancel/$acme->id")
        ->assertOk()->assertJson(['code' => 1]);

    $acme->refresh();
    expect($acme->status)
        ->toBe(Acme::STATUS_ACTIVE)
        ->and($acme->cancelled_at)
        ->toBeNull();
    expect(\App\Models\Task::where('order_id', $acme->id)->where('action', 'cancel_acme')->count())->toBe(0);
});

test('revokeCancel 他人订单返回 404', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $product = createUserAcmeProduct();
    $acme = Acme::factory()->create([
        'user_id' => $otherUser->id,
        'product_id' => $product->id,
        'status' => Acme::STATUS_CANCELLING,
    ]);

    $this->actingAsUser($user)
        ->postJson("/api/acme/revoke-cancel/$acme->id")
        ->assertNotFound();
});

// ==================== sync ====================

test('user sync 接口调用 Action::sync', function () {
    $user = User::factory()->create();
    $product = createUserAcmeProduct(['source' => 'default']);
    $acme = Acme::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'status' => 'active',
        'api_id' => 'order-123',
    ]);

    setupUserGatewaySettings();
    Http::fake([
        '*' => Http::response(['code' => 1, 'data' => ['status' => 'active']]),
    ]);

    $res = $this->actingAsUser($user)
        ->postJson("/api/acme/sync/$acme->id");

    $res->assertJsonPath('code', 1);
});

test('user sync 他人订单应失败（UserScope 隔离）', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $product = createUserAcmeProduct();
    $other = Acme::factory()->create([
        'user_id' => $otherUser->id,
        'product_id' => $product->id,
        'api_id' => 'x',
    ]);

    $res = $this->actingAsUser($user)
        ->postJson("/api/acme/sync/$other->id");

    $res->assertJsonPath('code', 0);
});

// ==================== batch-pay ====================

test('user batch-pay 成功支付自己的 unpaid', function () {
    $user = User::factory()->withBalance('500.00')->create();
    $product = createUserAcmeProduct();
    createUserProductPrice($product, $user);
    setupUserGatewaySettings();

    $a = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'status' => 'unpaid', 'amount' => '100.00']);

    $res = $this->actingAsUser($user)->postJson('/api/acme/batch-pay', ['ids' => [$a->id]]);
    $res->assertJsonPath('code', 1);
});

test('user batch-pay 不能支付他人订单（UserScope 隔离）', function () {
    $user = User::factory()->create();
    $product = createUserAcmeProduct();
    $other = Acme::factory()->create(['user_id' => 99999, 'product_id' => $product->id, 'status' => 'unpaid', 'amount' => '100.00']);

    $res = $this->actingAsUser($user)->postJson('/api/acme/batch-pay', ['ids' => [$other->id]]);
    $res->assertJsonPath('code', 0);
    $res->assertJsonPath('msg', '没有可以支付的订单');
});

// ==================== batch-commit ====================

test('user batch-commit 仅处理 pending 并入队 commit_acme', function () {
    Queue::fake();

    $user = User::factory()->create();
    $product = createUserAcmeProduct();
    $pending = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'status' => 'pending']);
    $unpaid = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'status' => 'unpaid']);

    $res = $this->actingAsUser($user)->postJson('/api/acme/batch-commit', ['ids' => [$pending->id, $unpaid->id]]);
    $res->assertJsonPath('code', 1);
    Queue::assertPushed(\App\Jobs\TaskJob::class, 1);
});

// ==================== batch-sync ====================

test('user batch-sync 仅处理 active 并入队 sync_acme', function () {
    Queue::fake();

    $user = User::factory()->create();
    $product = createUserAcmeProduct();
    $active = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'status' => 'active', 'api_id' => 'x1']);
    $pending = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'status' => 'pending']);

    $res = $this->actingAsUser($user)->postJson('/api/acme/batch-sync', ['ids' => [$active->id, $pending->id]]);
    $res->assertJsonPath('code', 1);
    Queue::assertPushed(\App\Jobs\TaskJob::class, 1);
});

// ==================== batch-copy-eab ====================

test('user batch-copy-eab 返回含 eab_kid 的文本', function () {
    $user = User::factory()->create();
    $product = createUserAcmeProduct(['ca' => 'google']);
    setupUserGatewaySettings();

    $a = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'eab_kid' => 'KID1', 'eab_hmac' => 'HMAC1']);

    $res = $this->actingAsUser($user)->postJson('/api/acme/batch-copy-eab', ['ids' => [$a->id]]);
    $res->assertJsonPath('code', 1);
    expect($res->json('data.text'))->toContain('eab_kid=KID1');
});
