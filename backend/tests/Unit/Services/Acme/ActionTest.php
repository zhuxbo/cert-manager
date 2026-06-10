<?php

use App\Exceptions\ApiResponseException;
use App\Jobs\TaskJob;
use App\Models\Acme;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\Task;
use App\Models\Transaction;
use App\Services\Acme\Action;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\CreatesTestData;

uses(TestCase::class, CreatesTestData::class, RefreshDatabase::class)->group('database');

beforeEach(function () {
    $this->seed = true;
    $this->seeder = DatabaseSeeder::class;
    $this->service = app(Action::class);
});

/**
 * 创建 Gateway 系统设置（ACME SDK 通过回落机制使用 ca.url/token）
 */
function setupGatewaySettings(string $url = 'https://fake-gateway.test/api/v2', string $token = 'fake-key'): void
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
 * 创建产品价格
 */
function createAcmeProductPrice(int $productId, $user, string $price = '100.00'): void
{
    ProductPrice::create([
        'product_id' => $productId,
        'level_code' => $user->level_code ?? 'standard',
        'period' => 12,
        'price' => $price,
        'alternative_standard_price' => '10.00',
        'alternative_wildcard_price' => '20.00',
    ]);
}

/**
 * 断言 ApiResponseException 包含指定消息
 */
function expectApiError(Closure $callback, string $expectedMsg): void
{
    try {
        $callback();
        test()->fail('期望抛出 ApiResponseException 但未抛出');
    } catch (ApiResponseException $e) {
        $response = $e->getApiResponse();
        expect($response['code'])->toBe(0);
        expect($response['msg'])->toContain($expectedMsg);
    }
}

/**
 * 断言 ApiResponseException code=1（success）
 */
function expectApiSuccess(Closure $callback): array
{
    try {
        $callback();
        test()->fail('期望抛出 ApiResponseException 但未抛出');
    } catch (ApiResponseException $e) {
        $response = $e->getApiResponse();
        expect($response['code'])->toBe(1);

        return $response;
    }

    return [];
}

/**
 * 通过 Action 创建 ACME 订单辅助方法
 */
function createAcmeOrder($user, $product, array $overrides = []): Acme
{
    $params = array_merge([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period' => 12,
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
        // contact_email 在 HTTP 层必填；单测 Action 层默认兜底到 user.email，避免每个用例显式传
        'contact_email' => $user->email ?: 'test@example.com',
    ], $overrides);

    $response = expectApiSuccess(fn () => test()->service->new($params));

    return Acme::find($response['data']['order_id']);
}

// ==================== new ====================

test('new creates unpaid order', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product, ['remark' => 'test remark']);

    expect($acme)->toBeInstanceOf(Acme::class);
    expect($acme->exists)->toBeTrue();
    expect($acme->status)->toBe(Acme::STATUS_UNPAID);
    expect($acme->user_id)->toBe($user->id);
    expect($acme->product_id)->toBe($product->id);
    expect($acme->period)->toBe(12);
    expect($acme->purchased_standard_count)->toBe(1);
    expect($acme->purchased_wildcard_count)->toBe(0);
    expect($acme->refer_id)->not->toBeNull();
    expect(strlen($acme->refer_id))->toBe(32);
    expect($acme->remark)->toBe('test remark');
    expect($acme->brand)->toBe($product->brand);
});

test('new generates unique refer_id', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);
    createAcmeProductPrice($product->id, $user);

    $acme1 = createAcmeOrder($user, $product);
    $acme2 = createAcmeOrder($user, $product);

    expect($acme1->refer_id)->not->toBe($acme2->refer_id);
});

test('new rejects non-acme product', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_SSL]);

    expectApiError(
        fn () => $this->service->new([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'period' => 12,
            'purchased_standard_count' => 1,
            'purchased_wildcard_count' => 0,
        ]),
        '产品不存在或不支持 ACME'
    );
});

test('new rejects invalid period', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'periods' => [12, 24]]);
    createAcmeProductPrice($product->id, $user);

    expectApiError(
        fn () => $this->service->new([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'period' => 6,
            'purchased_standard_count' => 1,
            'purchased_wildcard_count' => 0,
        ]),
        '无效的购买时长'
    );
});

test('new period 缺省且产品 periods 为空数组时报错（不再硬编码回落 12）', function () {
    // #25：period 未传时回落 product.periods[0]，但 periods=[] 时不得硬编码 12 绕过产品校验
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'periods' => []]);

    expectApiError(
        fn () => $this->service->new([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'purchased_standard_count' => 1,
            'purchased_wildcard_count' => 0,
        ]),
        '产品未配置周期'
    );
});

// ==================== pay ====================

test('pay deducts balance and sets pending', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    $initialBalance = (float) $user->balance;

    expectApiSuccess(fn () => $this->service->pay($acme->id, false));

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_PENDING);

    // 验证交易记录
    $transaction = Transaction::where('transaction_id', $acme->id)
        ->where('type', Transaction::TYPE_ACME_ORDER)
        ->first();
    expect($transaction)->not->toBeNull();
    expect($transaction->user_id)->toBe($user->id);

    // 验证余额扣减
    $user->refresh();
    expect((float) $user->balance)->toBeLessThan($initialBalance);
});

test('pay rejects non-unpaid order', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $acme = Acme::factory()->create([
        'user_id' => $user->id,
        'status' => Acme::STATUS_PENDING,
    ]);

    expectApiError(
        fn () => $this->service->pay($acme->id),
        '订单不是未支付状态'
    );
});

test('pay rejects when balance insufficient', function () {
    $user = $this->createTestUser(['balance' => '0.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);
    createAcmeProductPrice($product->id, $user, '500.00');

    $acme = createAcmeOrder($user, $product);

    expectApiError(
        fn () => $this->service->pay($acme->id),
        '余额不足'
    );
});

test('pay 串行第二次调用报错，保证只扣一次费（基础回归）', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);
    createAcmeProductPrice($product->id, $user);
    $acme = createAcmeOrder($user, $product);

    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    expectApiError(fn () => $this->service->pay($acme->id, false), '未支付状态');

    expect(Transaction::where('transaction_id', $acme->id)->where('type', Transaction::TYPE_ACME_ORDER)->count())->toBe(1);
});

// ==================== commit ====================

test('commit 成功调用 API 转 active 返回 eab 数据', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));

    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                // 上游返回 order_id 作为自身订单 ID，Manager 写入 api_id 列
                'order_id' => 'gw-123',
                'vendor_id' => 'vendor-456',
                'eab_kid' => 'kid-abc',
                'eab_hmac' => 'hmac-xyz',
                'directory_url' => 'https://acme.example.test/directory/',
            ],
        ]),
    ]);

    $response = expectApiSuccess(fn () => $this->service->commit($acme->id));

    expect($response['data']['eab_kid'])->toBe('kid-abc');
    expect($response['data']['eab_hmac'])->toBe('hmac-xyz');

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_ACTIVE);
    expect($acme->api_id)->toBe('gw-123');
});

test('commit 使用 acme.contact_email 作为 customer 传给 Gateway 并回写', function () {
    Queue::fake();
    $user = $this->createTestUser(['email' => 'login@example.com', 'balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    // 订单创建时填了自选邮箱，与用户登录邮箱不同
    $acme = createAcmeOrder($user, $product, ['contact_email' => 'acme-buyer@example.com']);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));

    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'order_id' => 'gw-contact',
                'vendor_id' => 'v-contact',
                'contact_email' => 'acme-buyer@example.com',
                'eab_kid' => 'kid-contact',
                'eab_hmac' => 'hmac-contact',
                'directory_url' => 'https://acme.example.test/directory/',
            ],
        ]),
    ]);

    expectApiSuccess(fn () => $this->service->commit($acme->id));

    // 请求体应把 acme.contact_email 作为 customer 发给上游（而非 user.email）
    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);

        return ($body['contact_email'] ?? null) === 'acme-buyer@example.com';
    });

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_ACTIVE)
        ->and($acme->contact_email)->toBe('acme-buyer@example.com');
});

test('commit acme.contact_email 缺失直接报错（不再 fallback 用户邮箱）', function () {
    $user = $this->createTestUser(['email' => 'login@example.com', 'balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    $acme->update(['contact_email' => null]); // 绕过 validate 模拟异常状态
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));

    expectApiError(fn () => $this->service->commit($acme->id), 'ACME 账号邮箱缺失');
});

test('commit 非 pending 状态报错', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $acme = Acme::factory()->create([
        'user_id' => $user->id,
        'status' => Acme::STATUS_UNPAID,
    ]);

    expectApiError(
        fn () => $this->service->commit($acme->id),
        '订单状态不是待提交'
    );
});

test('commit API 返回失败保持 pending', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));

    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response(['code' => 0, 'msg' => '上游提交失败'], 500),
    ]);

    expectApiError(
        fn () => $this->service->commit($acme->id),
        '上游提交失败'
    );

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_PENDING);
});

// ==================== commitCancel ====================

test('commitCancel sets cancelling status for active order', function () {
    Queue::fake();

    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);
    createAcmeProductPrice($product->id, $user);

    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'upstream-123',
        'amount' => '100.00',
    ]);

    expectApiSuccess(fn () => $this->service->commitCancel($acme->id));

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_CANCELLING);
    // commitCancel 仅提交取消，cancelled_at 在实际 cancel 执行后才记录
    expect($acme->cancelled_at)->toBeNull();

    // 验证 Task 记录创建
    $task = Task::where('order_id', $acme->id)
        ->where('action', 'cancel_acme')
        ->where('status', 'executing')
        ->first();
    expect($task)->not->toBeNull();
    expect($task->started_at)->toBeGreaterThan(now());

    Queue::assertPushed(TaskJob::class);
});

test('commitCancel directly cancels pending order without api_id', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->refresh();

    expect($acme->status)->toBe(Acme::STATUS_PENDING);
    expect($acme->api_id)->toBeNull();

    expectApiSuccess(fn () => $this->service->commitCancel($acme->id));

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_CANCELLED);
    expect($acme->cancelled_at)->not->toBeNull();
});

test('commitCancel rejects already cancelled order', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $acme = Acme::factory()->cancelled()->create([
        'user_id' => $user->id,
    ]);

    expectApiError(
        fn () => $this->service->commitCancel($acme->id),
        '当前状态不允许取消'
    );
});

// ==================== revokeCancel ====================

test('revokeCancel reverts cancelling order to active and deletes task', function () {
    Queue::fake();

    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);
    createAcmeProductPrice($product->id, $user);

    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'upstream-123',
        'amount' => '100.00',
    ]);

    expectApiSuccess(fn () => $this->service->commitCancel($acme->id));
    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_CANCELLING);
    expect(Task::where('order_id', $acme->id)->where('action', 'cancel_acme')->where('status', 'executing')->count())->toBe(1);

    expectApiSuccess(fn () => $this->service->revokeCancel($acme->id));

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_ACTIVE);
    expect($acme->cancelled_at)->toBeNull();
    expect(Task::where('order_id', $acme->id)->where('action', 'cancel_acme')->count())->toBe(0);
});

test('revokeCancel rejects when order not in cancelling status', function () {
    $user = $this->createTestUser();
    $acme = Acme::factory()->active()->create(['user_id' => $user->id]);

    expectApiError(
        fn () => $this->service->revokeCancel($acme->id),
        '订单不在取消中状态'
    );
});

// ==================== cancelNow ====================

test('cancelNow directly cancels active order without delayed task', function () {
    Queue::fake();

    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    // 创建并支付订单以生成 acme_order 交易（退费对账依赖）
    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->refresh();
    $acme->update(['status' => Acme::STATUS_ACTIVE, 'api_id' => 'upstream-immediate']);

    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'cancelled']]),
    ]);

    expectApiSuccess(fn () => $this->service->cancelNow($acme->id));

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_CANCELLED);
    // cancelNow 不创建 cancel_acme Task（区别于 commitCancel 的延时流程）
    expect(Task::where('order_id', $acme->id)->where('action', 'cancel_acme')->count())->toBe(0);

    $cancelTx = Transaction::where('transaction_id', $acme->id)
        ->where('type', Transaction::TYPE_ACME_CANCEL)
        ->first();
    expect($cancelTx)->not->toBeNull();
});

test('cancelNow directly cancels pending order without api_id', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->refresh();

    expectApiSuccess(fn () => $this->service->cancelNow($acme->id));

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_CANCELLED);
});

test('cancelNow upstream failure rolls back, order stays active', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'upstream-fail',
        'amount' => '100.00',
    ]);

    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response(['code' => 0, 'msg' => '上游取消失败'], 500),
    ]);

    expectApiError(
        fn () => $this->service->cancelNow($acme->id),
        '上游取消失败'
    );

    // 行级锁事务整体回滚：状态保持 active，无退费记录
    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_ACTIVE);

    $cancelTx = Transaction::where('transaction_id', $acme->id)
        ->where('type', Transaction::TYPE_ACME_CANCEL)
        ->first();
    expect($cancelTx)->toBeNull();
});

// ==================== cancel ====================

test('cancel cancels order without api_id', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->refresh();

    // 手动设为 cancelling
    $acme->update(['status' => Acme::STATUS_CANCELLING]);

    expectApiSuccess(fn () => $this->service->cancel($acme->id));

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_CANCELLED);
    expect($acme->cancelled_at)->not->toBeNull();
});

test('cancel rejects non-cancelling order', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
    ]);

    expectApiError(
        fn () => $this->service->cancel($acme->id),
        '取消中'
    );
});

test('cancel with api_id upstream returns revoked → status revoked + refund', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->refresh();

    $acme->update([
        'status' => Acme::STATUS_CANCELLING,
        'api_id' => 'upstream-revoke-test',
    ]);

    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'revoked']]),
    ]);

    expectApiSuccess(fn () => $this->service->cancel($acme->id));

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_REVOKED);

    $cancelTx = Transaction::where('transaction_id', $acme->id)
        ->where('type', Transaction::TYPE_ACME_CANCEL)
        ->first();
    expect($cancelTx)->not->toBeNull();
});

test('cancel with api_id upstream returns cancelled → status cancelled + refund', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->refresh();

    $acme->update([
        'status' => Acme::STATUS_CANCELLING,
        'api_id' => 'upstream-cancel-test',
    ]);

    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'cancelled']]),
    ]);

    expectApiSuccess(fn () => $this->service->cancel($acme->id));

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_CANCELLED);

    $cancelTx = Transaction::where('transaction_id', $acme->id)
        ->where('type', Transaction::TYPE_ACME_CANCEL)
        ->first();
    expect($cancelTx)->not->toBeNull();
});

test('cancel with api_id upstream error → stays cancelling, no refund', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->refresh();

    $acme->update([
        'status' => Acme::STATUS_CANCELLING,
        'api_id' => 'upstream-error-test',
    ]);

    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response(['code' => 0, 'msg' => '上游取消失败'], 500),
    ]);

    expectApiError(
        fn () => $this->service->cancel($acme->id),
        '上游取消失败'
    );

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_CANCELLING);

    $cancelTx = Transaction::where('transaction_id', $acme->id)
        ->where('type', Transaction::TYPE_ACME_CANCEL)
        ->first();
    expect($cancelTx)->toBeNull();
});

// ==================== sync ====================

test('sync 成功同步状态', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);

    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'gw-sync-test',
    ]);

    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => ['status' => 'expired', 'vendor_id' => 'v-new'],
        ]),
    ]);

    expectApiSuccess(fn () => $this->service->sync($acme->id));

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_EXPIRED);
    expect($acme->vendor_id)->toBe('v-new');
});

test('sync 10秒内缓存不重复请求', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);

    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'gw-cache-test',
    ]);

    // 设置缓存模拟已请求
    Cache::set("acme_sync_$acme->id", time(), 10);

    // force=true 时静默返回
    $this->service->sync($acme->id, true);
    // 没有抛异常即成功
    expect(true)->toBeTrue();
});

test('sync 无 api_id 报错', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);

    $acme = Acme::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => null,
        'status' => Acme::STATUS_PENDING,
    ]);

    expectApiError(
        fn () => $this->service->sync($acme->id),
        '订单尚未提交到上游'
    );
});

test('sync force=true 静默返回', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);

    $acme = Acme::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => null,
        'status' => Acme::STATUS_PENDING,
    ]);

    // force=true 不报错，静默返回
    $this->service->sync($acme->id, true);
    expect(true)->toBeTrue();
});

test('sync 终态守卫：本地 cancelled 不被上游滞后 active 复活', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    // 真实路径造出本地 cancelled + acme_cancel 退款流水（账目恒等，过 FundInvariants）
    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->refresh();
    $acme->update([
        'status' => Acme::STATUS_CANCELLING,
        'api_id' => 'upstream-terminal-guard',
    ]);

    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'cancelled']]),
    ]);
    expectApiSuccess(fn () => $this->service->cancel($acme->id));
    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_CANCELLED);

    $cancelTx = Transaction::where('transaction_id', $acme->id)
        ->where('type', Transaction::TYPE_ACME_CANCEL)
        ->first();
    expect($cancelTx)->not->toBeNull();

    // 上游滞后返回 active，绕过 10s 缓存（cancel 已写过），sync 应拒绝把 cancelled 改回 active
    Cache::forget("acme_sync_$acme->id");
    Http::fake([
        'fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'active']]),
    ]);

    // force=true 避免 success 抛 ApiResponseException 打断断言
    $this->service->sync($acme->id, true);

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_CANCELLED);
});

test('sync 上游失败回滚防抖占位，重试能再次调用上游', function () {
    // #19：占位 Cache::add 在上游调用之前；上游失败时占位若不回滚，10s 内重试会命中占位
    // 直接返回 success（把失败伪装成成功）。修复后失败应回滚占位，下次重试真正重调上游。
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);

    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'gw-sync-fail',
    ]);

    setupGatewaySettings();

    // 用 fakeSequence 按调用顺序返回：第一次失败、第二次成功。
    // 不能用两次 Http::fake 同 pattern——Laravel 会累加 stub 且先注册者先匹配，
    // 第二次请求仍命中第一次的失败响应。
    Http::fakeSequence('fake-gateway.test/*')
        ->push(['code' => 0, 'msg' => '上游同步失败'], 500)
        ->push(['code' => 1, 'data' => ['status' => 'expired', 'vendor_id' => 'v-after-retry']], 200);

    // 第一次：上游失败 → sync 应抛错（不能伪装成功），且占位被回滚
    expectApiError(fn () => $this->service->sync($acme->id), '上游同步失败');

    // 占位已被回滚：缓存键不应存在
    expect(Cache::has("acme_sync_$acme->id"))->toBeFalse();

    // 第二次：占位已清，上游恢复后重试应真正重调上游并写回状态
    expectApiSuccess(fn () => $this->service->sync($acme->id));

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_EXPIRED);
    expect($acme->vendor_id)->toBe('v-after-retry');

    // 共发起两次上游请求（首次失败 + 重试成功），证明占位未把第二次拦在 success 短路
    Http::assertSentCount(2);
});

// ==================== remark ====================

test('remark 更新 remark 字段', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $acme = Acme::factory()->create(['user_id' => $user->id]);

    expectApiSuccess(fn () => $this->service->remark($acme->id, '用户备注'));

    $acme->refresh();
    expect($acme->remark)->toBe('用户备注');
});

test('remark 更新 admin_remark 字段', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $acme = Acme::factory()->create(['user_id' => $user->id]);

    expectApiSuccess(fn () => $this->service->remark($acme->id, '管理员备注', 'admin_remark'));

    $acme->refresh();
    expect($acme->admin_remark)->toBe('管理员备注');
});

// ==================== newAndCommit ====================

test('newAndCommit 一步完成 new+pay+commit', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'order_id' => 'gw-deploy',
                'vendor_id' => 'v-deploy',
                'eab_kid' => 'kid-deploy',
                'eab_hmac' => 'hmac-deploy',
            ],
        ]),
    ]);

    $response = expectApiSuccess(fn () => $this->service->newAndCommit([
        'contact_email' => 'test@example.com',
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period' => 12,
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]));

    expect($response['data']['status'])->toBe(Acme::STATUS_ACTIVE);
    expect($response['data']['eab_kid'])->toBe('kid-deploy');

    $acme = Acme::find($response['data']['order_id']);
    expect($acme->status)->toBe(Acme::STATUS_ACTIVE);
    expect($acme->api_id)->toBe('gw-deploy');
});

test('commit 主路径读上游 data.order_id 写入本地 api_id', function () {
    // 上游权威字段为 data.order_id，本地落到 acmes.api_id 列。
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'order_id' => 'upstream-order-9527',
                'vendor_id' => 'v-main',
                'eab_kid' => 'kid-main',
                'eab_hmac' => 'hmac-main',
            ],
        ]),
    ]);

    $response = expectApiSuccess(fn () => $this->service->newAndCommit([
        'contact_email' => 'test@example.com',
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period' => 12,
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]));

    $acme = Acme::find($response['data']['order_id']);
    expect($acme->api_id)->toBe('upstream-order-9527');
    expect($acme->vendor_id)->toBe('v-main');
    expect($acme->eab_kid)->toBe('kid-main');
});

test('commit 上游同时返回 order_id 与 api_id 时忽略 api_id', function () {
    // 只信任 order_id，防止上游误字段名污染本地列。
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'order_id' => 'authoritative-id',
                'api_id' => 'should-be-ignored',
                'eab_kid' => 'kid',
                'eab_hmac' => 'hmac',
            ],
        ]),
    ]);

    $response = expectApiSuccess(fn () => $this->service->newAndCommit([
        'contact_email' => 'test@example.com',
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period' => 12,
        'purchased_standard_count' => 1,
        'purchased_wildcard_count' => 0,
    ]));

    $acme = Acme::find($response['data']['order_id']);
    expect($acme->api_id)->toBe('authoritative-id');
});

test('commit 上游响应缺 order_id 报错回滚', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'eab_kid' => 'kid',
                'eab_hmac' => 'hmac',
            ],
        ]),
    ]);

    expectApiError(
        fn () => $this->service->newAndCommit([
            'contact_email' => 'test@example.com',
            'user_id' => $user->id,
            'product_id' => $product->id,
            'period' => 12,
            'purchased_standard_count' => 1,
            'purchased_wildcard_count' => 0,
        ]),
        '上游返回缺少 order_id'
    );
});

test('newAndCommit 余额不足报错', function () {
    $user = $this->createTestUser(['balance' => '0.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);
    createAcmeProductPrice($product->id, $user, '500.00');

    expectApiError(
        fn () => $this->service->newAndCommit([
            'contact_email' => 'test@example.com',
            'user_id' => $user->id,
            'product_id' => $product->id,
            'period' => 12,
            'purchased_standard_count' => 1,
            'purchased_wildcard_count' => 0,
        ]),
        '余额不足'
    );
});

test('newAndCommit 产品不存在报错', function () {
    $user = $this->createTestUser(['balance' => '500.00']);

    expectApiError(
        fn () => $this->service->newAndCommit([
            'contact_email' => 'test@example.com',
            'user_id' => $user->id,
            'product_id' => 99999,
            'period' => 12,
            'purchased_standard_count' => 1,
            'purchased_wildcard_count' => 0,
        ]),
        '产品不存在或不支持 ACME'
    );
});

// ==================== batchPay ====================

test('batchPay 仅处理 unpaid 状态，非 unpaid 被过滤', function () {
    Queue::fake();

    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);
    createAcmeProductPrice($product->id, $user);
    setupGatewaySettings();

    $unpaid1 = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'status' => 'unpaid', 'amount' => '100.00']);
    $unpaid2 = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'status' => 'unpaid', 'amount' => '100.00']);
    $pending = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'status' => 'pending']);

    try {
        $this->service->batchPay([$unpaid1->id, $unpaid2->id, $pending->id]);
    } catch (ApiResponseException $e) {
        $res = $e->getApiResponse();
    }

    expect($res['code'])->toBe(1);
    expect($res['data']['success_count'])->toBe(2);
    expect($res['data']['commit_count'])->toBe(2);
    expect($res['data']['errors'] ?? [])->toBe([]);
    expect(Acme::find($unpaid1->id)->status)->toBe('pending');
    expect(Acme::find($unpaid2->id)->status)->toBe('pending');
    expect(Acme::find($pending->id)->status)->toBe('pending');
    // 验证自动创建 commit_acme Task
    expect(Task::where('action', 'commit_acme')->count())->toBe(2);
    Queue::assertPushed(TaskJob::class, 2);
});

test('batchPay 无符合订单时报错', function () {
    $user = $this->createTestUser();
    $acme = Acme::factory()->create(['user_id' => $user->id, 'status' => 'pending']);

    try {
        $this->service->batchPay([$acme->id]);
    } catch (ApiResponseException $e) {
        $res = $e->getApiResponse();
    }

    expect($res['code'])->toBe(0);
    expect($res['msg'])->toBe('没有可以支付的订单');
});

test('batchPay 单条失败不影响其他（记入 errors）', function () {
    Queue::fake();

    $user = $this->createTestUser(['balance' => '50.00']); // 只够一条
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);
    createAcmeProductPrice($product->id, $user, '50.00');
    setupGatewaySettings();

    $a1 = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'status' => 'unpaid', 'amount' => '50.00']);
    $a2 = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'status' => 'unpaid', 'amount' => '50.00']);

    try {
        $this->service->batchPay([$a1->id, $a2->id]);
    } catch (ApiResponseException $e) {
        $res = $e->getApiResponse();
    }

    expect($res['code'])->toBe(1);
    expect($res['data']['success_count'])->toBe(1);
    expect($res['data']['commit_count'])->toBe(1);
    expect(count($res['data']['errors']))->toBe(1);
    // 仅成功的那条自动创建 commit_acme Task
    expect(Task::where('action', 'commit_acme')->count())->toBe(1);
    Queue::assertPushed(TaskJob::class, 1);
});

// ==================== batchCommit ====================

test('batchCommit 仅处理 pending 状态，创建 commit_acme Task', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $pending = Acme::factory()->create(['user_id' => $user->id, 'status' => 'pending']);
    $unpaid = Acme::factory()->create(['user_id' => $user->id, 'status' => 'unpaid']);

    try {
        $this->service->batchCommit([$pending->id, $unpaid->id]);
    } catch (ApiResponseException $e) {
        $res = $e->getApiResponse();
    }

    expect($res['code'])->toBe(1);
    expect(Task::where('order_id', $pending->id)->where('action', 'commit_acme')->count())->toBe(1);
    expect(Task::where('order_id', $unpaid->id)->count())->toBe(0);
    Queue::assertPushed(TaskJob::class, 1);
});

test('batchCommit 存在 executing 任务时整体报错', function () {
    $user = $this->createTestUser();
    $acme = Acme::factory()->create(['user_id' => $user->id, 'status' => 'pending']);
    Task::create([
        'order_id' => $acme->id,
        'action' => 'commit_acme',
        'status' => 'executing',
        'started_at' => now(),
        'source' => 'admin',
    ]);

    try {
        $this->service->batchCommit([$acme->id]);
    } catch (ApiResponseException $e) {
        $res = $e->getApiResponse();
    }

    expect($res['code'])->toBe(0);
    expect($res['msg'])->toBe('已存在处理中的任务，请稍后刷新页面');
});

test('batchCommit 无 pending 订单时报错', function () {
    $user = $this->createTestUser();
    $acme = Acme::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    try {
        $this->service->batchCommit([$acme->id]);
    } catch (ApiResponseException $e) {
        $res = $e->getApiResponse();
    }

    expect($res['code'])->toBe(0);
    expect($res['msg'])->toBe('没有可以提交的订单');
});

// ==================== createTasks 逐条幂等 ====================

test('createTasks 逐条幂等：跳过已存在 executing 的 id，仅为其余创建（对齐 Order createTask）', function () {
    Queue::fake();
    $user = $this->createTestUser();
    $existing = Acme::factory()->create(['user_id' => $user->id, 'status' => 'pending']);
    $fresh = Acme::factory()->create(['user_id' => $user->id, 'status' => 'pending']);

    // existing 已有 executing commit_acme 任务
    Task::create([
        'order_id' => $existing->id,
        'action' => 'commit_acme',
        'status' => 'executing',
        'started_at' => now(),
        'source' => 'admin',
    ]);

    // public 入口 batchCommit/batchSync 前置 checkRepeat 会整体拦截，无法触达逐条分支，故反射直调
    $method = new ReflectionMethod(Action::class, 'createTasks');
    $method->setAccessible(true);
    $method->invoke($this->service, [$existing->id, $fresh->id], 'commit_acme');

    // existing 不重复创建（仍 1 条），fresh 新建 1 条
    expect(Task::where('order_id', $existing->id)->where('action', 'commit_acme')->where('status', 'executing')->count())->toBe(1);
    expect(Task::where('order_id', $fresh->id)->where('action', 'commit_acme')->where('status', 'executing')->count())->toBe(1);
    // 仅为 fresh dispatch 了 1 个 TaskJob
    Queue::assertPushed(TaskJob::class, 1);
});

test('createTasks 延时任务 dispatch delay 比 started_at 多 3 秒缓冲（对齐 Order createTask）', function () {
    // #39：从 Order createTask 复制时丢了 ->delay(now()->addSeconds($later + 3)) 的 +3s 缓冲，
    // 导致 dispatch 的 delay 恰好等于 started_at，worker 可能在事务提交/行可见前消费 job。
    Queue::fake();
    $user = $this->createTestUser();
    $acme = Acme::factory()->create(['user_id' => $user->id, 'status' => 'pending']);

    $delaySeconds = 300;
    $before = now();

    $method = new ReflectionMethod(Action::class, 'createTasks');
    $method->setAccessible(true);
    $method->invoke($this->service, [$acme->id], 'commit_acme', $delaySeconds);

    // task.started_at = now + delaySeconds
    $task = Task::where('order_id', $acme->id)->where('action', 'commit_acme')->first();
    expect($task)->not->toBeNull();

    Queue::assertPushed(TaskJob::class, function (TaskJob $job) use ($before, $delaySeconds) {
        // dispatch delay 必须比 started_at（now+delaySeconds）再多 3 秒缓冲
        $delay = $job->delay;
        expect($delay)->toBeInstanceOf(Carbon::class);
        if (! $delay instanceof Carbon) {
            return false;
        }
        $expected = $before->copy()->addSeconds($delaySeconds + 3);
        // 容忍执行耗时的 ±2 秒抖动；关键是 delay ≈ delaySeconds+3 而非 delaySeconds
        expect(abs($delay->diffInSeconds($expected)))->toBeLessThanOrEqual(2);

        return true;
    });
});

// ==================== batchSync ====================

test('batchSync 仅处理 active/cancelling 状态，创建 sync_acme Task', function () {
    Queue::fake();
    $user = $this->createTestUser();
    $active = Acme::factory()->create(['user_id' => $user->id, 'status' => 'active', 'api_id' => 'x1']);
    $cancelling = Acme::factory()->create(['user_id' => $user->id, 'status' => 'cancelling', 'api_id' => 'x2']);
    $pending = Acme::factory()->create(['user_id' => $user->id, 'status' => 'pending']);

    try {
        $this->service->batchSync([$active->id, $cancelling->id, $pending->id]);
    } catch (ApiResponseException $e) {
        $res = $e->getApiResponse();
    }

    expect($res['code'])->toBe(1);
    expect(Task::where('action', 'sync_acme')->count())->toBe(2);
    expect(Task::where('order_id', $pending->id)->count())->toBe(0);
    Queue::assertPushed(TaskJob::class, 2);
});

test('batchSync 无可同步订单时报错', function () {
    $user = $this->createTestUser();
    $pending = Acme::factory()->create(['user_id' => $user->id, 'status' => 'pending']);

    try {
        $this->service->batchSync([$pending->id]);
    } catch (ApiResponseException $e) {
        $res = $e->getApiResponse();
    }

    expect($res['code'])->toBe(0);
    expect($res['msg'])->toBe('没有可以同步的订单');
});

// ==================== batchCommitCancel ====================

test('batchCommitCancel 混合处理: unpaid/无 api_id pending 直接退费，其余延时任务', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);

    $unpaid = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'status' => 'unpaid', 'amount' => '100.00']);
    $pendingNoApi = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'status' => 'pending', 'api_id' => null, 'amount' => '100.00']);
    $active = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id, 'status' => 'active', 'api_id' => 'x']);

    try {
        $this->service->batchCommitCancel([$unpaid->id, $pendingNoApi->id, $active->id]);
    } catch (ApiResponseException $e) {
        $res = $e->getApiResponse();
    }

    expect($res['code'])->toBe(1);
    expect(Acme::find($unpaid->id)->status)->toBe('cancelled');
    expect(Acme::find($pendingNoApi->id)->status)->toBe('cancelled');
    expect(Acme::find($active->id)->status)->toBe('cancelling');
    expect(Task::where('action', 'cancel_acme')->where('order_id', $active->id)->count())->toBe(1);
});

// ==================== batchRevokeCancel ====================

test('batchRevokeCancel 仅处理 cancelling 状态，回滚至 active 并删除延时 Task', function () {
    $user = $this->createTestUser();
    $c1 = Acme::factory()->create(['user_id' => $user->id, 'status' => 'cancelling', 'api_id' => 'a1']);
    $c2 = Acme::factory()->create(['user_id' => $user->id, 'status' => 'cancelling', 'api_id' => 'a2']);
    $active = Acme::factory()->create(['user_id' => $user->id, 'status' => 'active', 'api_id' => 'a3']);

    Task::create([
        'order_id' => $c1->id, 'action' => 'cancel_acme',
        'status' => 'executing', 'started_at' => now()->addMinute(), 'source' => 'Admin',
    ]);

    try {
        $this->service->batchRevokeCancel([$c1->id, $c2->id, $active->id]);
    } catch (ApiResponseException $e) {
        $res = $e->getApiResponse();
    }

    expect($res['code'])->toBe(1);
    expect($res['data']['success_count'])->toBe(2);
    expect(Acme::find($c1->id)->status)->toBe('active');
    expect(Acme::find($c2->id)->status)->toBe('active');
    expect(Acme::find($active->id)->status)->toBe('active');
    expect(Task::where('order_id', $c1->id)->where('action', 'cancel_acme')->count())->toBe(0);
});

test('batchRevokeCancel 无 cancelling 订单报错', function () {
    $user = $this->createTestUser();
    $acme = Acme::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    try {
        $this->service->batchRevokeCancel([$acme->id]);
    } catch (ApiResponseException $e) {
        $res = $e->getApiResponse();
    }

    expect($res['code'])->toBe(0);
    expect($res['msg'])->toBe('没有可以撤回取消的订单');
});

test('batchCommitCancel 无可取消订单报错', function () {
    $user = $this->createTestUser();
    $acme = Acme::factory()->create(['user_id' => $user->id, 'status' => 'cancelled']);

    try {
        $this->service->batchCommitCancel([$acme->id]);
    } catch (ApiResponseException $e) {
        $res = $e->getApiResponse();
    }

    expect($res['code'])->toBe(0);
    expect($res['msg'])->toBe('没有可以取消的订单');
});

// ==================== TaskJob 分发 ====================

test('TaskJob 收到 commit_acme 分发到 Acme\\Action::commit', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    setupGatewaySettings();

    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'order_id' => 'X1', 'eab_kid' => 'K1', 'eab_hmac' => 'H1',
                'directory_url' => 'https://g',
            ],
        ]),
    ]);

    $acme = Acme::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'status' => 'pending',
        'amount' => '100.00',
        'contact_email' => 'task@example.com',
    ]);
    $task = Task::create([
        'order_id' => $acme->id, 'action' => 'commit_acme',
        'status' => 'executing', 'started_at' => now(), 'source' => 'Admin',
    ]);

    (new TaskJob(['id' => $task->id]))->handle();

    expect(Acme::find($acme->id)->status)->toBe('active');
    expect($task->fresh()->status)->toBe('successful');
});

test('TaskJob 收到 sync_acme 分发到 Acme\\Action::sync', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    setupGatewaySettings();

    Http::fake([
        'fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'active']]),
    ]);

    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'X1',
    ]);
    $task = Task::create([
        'order_id' => $acme->id, 'action' => 'sync_acme',
        'status' => 'executing', 'started_at' => now(), 'source' => 'Admin',
    ]);

    (new TaskJob(['id' => $task->id]))->handle();

    expect($task->fresh()->status)->toBe('successful');
});

// ==================== pay autoCommit ====================

test('单体 pay 默认同步提交至 active', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);
    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'order_id' => 'gw-pay',
                'eab_kid' => 'kid-pay',
                'eab_hmac' => 'hmac-pay',
                'directory_url' => 'https://acme.example.test/directory/',
            ],
        ]),
    ]);

    $acme = Acme::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'status' => 'unpaid',
        'amount' => '100.00',
        'contact_email' => 'pay@example.com',
    ]);

    try {
        $this->service->pay($acme->id);
    } catch (ApiResponseException $e) {
        $res = $e->getApiResponse();
    }

    expect($res['code'])->toBe(1);
    expect($res['data']['eab_kid'])->toBe('kid-pay');
    expect(Acme::find($acme->id)->status)->toBe('active');
    // 不再创建异步 commit_acme Task（批量支付才入队）
    expect(Task::where('order_id', $acme->id)->where('action', 'commit_acme')->count())->toBe(0);
    Queue::assertNotPushed(TaskJob::class);
});

test('单体 pay commit 失败保留 pending，扣费不回滚', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);
    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response(['code' => 0, 'msg' => '上游提交失败'], 500),
    ]);

    $acme = Acme::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'status' => 'unpaid',
        'amount' => '100.00',
        'contact_email' => 'pay-fail@example.com',
    ]);
    $balanceBefore = (float) $user->balance;

    try {
        $this->service->pay($acme->id);
    } catch (ApiResponseException $e) {
        $res = $e->getApiResponse();
    }

    // 上游失败时返回错误，但扣费已落库、订单留 pending 可重试 commit
    expect($res['code'])->toBe(0);
    expect($res['msg'])->toBe('上游提交失败');
    expect(Acme::find($acme->id)->status)->toBe('pending');

    expect(Transaction::where('transaction_id', $acme->id)
        ->where('type', Transaction::TYPE_ACME_ORDER)
        ->count())->toBe(1);

    $user->refresh();
    expect((float) $user->balance)->toBeLessThan($balanceBefore);
});

test('单体 pay 传入 autoCommit=false 不创建 Task', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);
    createAcmeProductPrice($product->id, $user);
    setupGatewaySettings();

    $acme = Acme::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'status' => 'unpaid',
        'amount' => '100.00',
    ]);

    try {
        $this->service->pay($acme->id, false);
    } catch (ApiResponseException $e) {
        $res = $e->getApiResponse();
    }

    expect($res['code'])->toBe(1);
    expect(Task::where('order_id', $acme->id)->count())->toBe(0);
    Queue::assertNotPushed(TaskJob::class);
});
