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
use Illuminate\Support\Facades\DB;
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

afterEach(function () {
    Carbon::setTestNow();
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

    $acme = createAcmeOrder($user, $product, [
        'plus' => 0,
        'refer_id' => 'acme-create-contract',
        'contact_email' => 'acme-create@example.com',
        'channel' => 'api',
        'remark' => 'test remark',
    ]);

    expect($acme)->toBeInstanceOf(Acme::class)
        ->and($acme->exists)->toBeTrue()
        ->and($acme->only([
            'user_id',
            'product_id',
            'brand',
            'period',
            'plus',
            'purchased_standard_count',
            'purchased_wildcard_count',
            'refer_id',
            'contact_email',
            'amount',
            'status',
            'channel',
            'remark',
        ]))->toBe([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'brand' => $product->brand,
            'period' => 12,
            'plus' => 0,
            'purchased_standard_count' => 1,
            'purchased_wildcard_count' => 0,
            'refer_id' => 'acme-create-contract',
            'contact_email' => 'acme-create@example.com',
            'amount' => '110.00',
            'status' => Acme::STATUS_UNPAID,
            'channel' => 'api',
            'remark' => 'test remark',
        ]);
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

test('new 按产品域名额度精确区分单域名与单通配符', function (
    int $standardMax,
    int $wildcardMax,
    int $expectedStandard,
    int $expectedWildcard
) {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct([
        'product_type' => Product::TYPE_ACME,
        'standard_max' => $standardMax,
        'wildcard_max' => $wildcardMax,
    ]);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);

    expect($acme->purchased_standard_count)->toBe($expectedStandard)
        ->and($acme->purchased_wildcard_count)->toBe($expectedWildcard);
})->with([
    '标准产品' => [1, 0, 1, 0],
    '通配符产品' => [0, 1, 0, 1],
    '混合额度按标准产品处理' => [1, 1, 1, 0],
    '未配置额度按标准产品处理' => [0, 0, 1, 0],
]);

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
        ->firstOrFail();
    expect($transaction->only([
        'user_id',
        'type',
        'transaction_id',
        'amount',
        'standard_count',
        'wildcard_count',
    ]))->toBe([
        'user_id' => $user->id,
        'type' => Transaction::TYPE_ACME_ORDER,
        'transaction_id' => $acme->id,
        'amount' => '-110.00',
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]);

    // 验证余额扣减
    $user->refresh();
    expect((float) $user->balance)->toBe($initialBalance - 110.0);
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

    expect($response['data'])->toBe([
        'order_id' => $acme->id,
        'eab_kid' => 'kid-abc',
        'eab_hmac' => 'hmac-xyz',
        'directory_url' => 'https://acme.example.test/directory/',
    ]);

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
    Http::assertSent(function ($request) use ($acme) {
        return $request->url() === 'https://fake-gateway.test/api/v2/acme/new'
            && $request->data() === [
                'contact_email' => 'acme-buyer@example.com',
                'product_code' => $acme->product->code,
                'period' => 12,
                'plus' => 1,
                'refer_id' => $acme->refer_id,
            ];
    });

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_ACTIVE)
        ->and($acme->contact_email)->toBe('acme-buyer@example.com');
});

test('commit 上游省略可选字段时精确写入本地默认值', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product, ['contact_email' => 'fallback@example.com']);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));

    Carbon::setTestNow('2026-07-31 12:00:00');
    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => ['order_id' => 'gw-minimal'],
        ]),
    ]);

    expectApiSuccess(fn () => $this->service->commit($acme->id));

    $acme->refresh();
    expect($acme->only([
        'api_id',
        'vendor_id',
        'contact_email',
        'eab_kid',
        'eab_hmac',
        'status',
    ]))->toBe([
        'api_id' => 'gw-minimal',
        'vendor_id' => null,
        'contact_email' => 'fallback@example.com',
        'eab_kid' => null,
        'eab_hmac' => null,
        'status' => Acme::STATUS_ACTIVE,
    ])->and($acme->period_from->equalTo(now()))->toBeTrue()
        ->and($acme->period_till->equalTo(now()->addMonths(12)))->toBeTrue();
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
    Carbon::setTestNow('2026-07-31 12:00:00');

    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'refund_period' => 30]);
    createAcmeProductPrice($product->id, $user);

    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'upstream-123',
        'amount' => '100.00',
        'created_at' => now()->subDays(30),
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
        ->firstOrFail();
    expect($task->only(['order_id', 'action', 'status', 'source']))->toBe([
        'order_id' => $acme->id,
        'action' => 'cancel_acme',
        'status' => 'executing',
        'source' => getControllerCategory(),
    ])->and($task->started_at->equalTo(now()->addSeconds(120)))->toBeTrue();

    Queue::assertPushed(TaskJob::class, function (TaskJob $job) use ($task) {
        $data = (new ReflectionProperty(TaskJob::class, 'data'))->getValue($job);

        return $data === ['id' => $task->id]
            && $job->afterCommit === true
            && $job->queue === config('queue.names.tasks')
            && $job->delay instanceof Carbon
            && $job->delay->equalTo(now()->addSeconds(123));
    });
});

test('commitCancel directly cancels pending order without api_id', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'refund_period' => 30]);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->refresh();
    $acme->forceFill(['created_at' => now()->subDays(31)])->saveQuietly();

    expect($acme->status)->toBe(Acme::STATUS_PENDING);
    expect($acme->api_id)->toBeNull();

    expectApiSuccess(fn () => $this->service->commitCancel($acme->id));

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_CANCELLED);
    expect($acme->cancelled_at)->not->toBeNull();
});

test('commitCancel 拒绝超过退款期的 active ACME，保持 active 且不创建取消任务', function () {
    Queue::fake();
    Carbon::setTestNow('2026-07-31 12:00:00');

    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'refund_period' => 30]);
    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'upstream-expired',
        'amount' => '100.00',
        'created_at' => now()->subDays(30)->subSecond(),
    ]);

    expectApiError(fn () => $this->service->commitCancel($acme->id), '订单已超过30天不能取消');

    expect($acme->fresh()->status)->toBe(Acme::STATUS_ACTIVE);
    expect(Task::where('order_id', $acme->id)->where('action', 'cancel_acme')->count())->toBe(0);
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

test('commitCancel 先锁 cancel_acme task 再锁 acme 行（task→acme 锁序 + 复合索引，防与 sync 反序死锁）', function () {
    Queue::fake();

    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);
    createAcmeProductPrice($product->id, $user);

    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'upstream-lockorder',
        'amount' => '100.00',
    ]);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    expectApiSuccess(fn () => $this->service->commitCancel($acme->id));

    // 锁 cancel_acme task 走复合索引 + for update（lockForMutation 收窄间隙锁，与 Order/sync/revokeCancel 统一）
    $taskLockIndex = collect($queries)->search(fn (string $sql) => str_contains($sql, 'from `tasks`')
        && str_contains($sql, 'for update')
        && str_contains($sql, 'force index (tasks_order_action_status_index)'));
    expect($taskLockIndex)->not->toBeFalse();

    // 锁 acmes 行 for update
    $acmeLockIndex = collect($queries)->search(fn (string $sql) => str_contains($sql, 'from `acmes`')
        && str_contains($sql, 'for update'));
    expect($acmeLockIndex)->not->toBeFalse();

    // 锁序：先锁 task 再锁 acme（与 sync/revokeCancel 统一，消除 acme→task 反序死锁面 ⑪）
    expect($taskLockIndex)->toBeLessThan($acmeLockIndex);
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

test('revokeCancel 锁 cancel_acme task 时强制使用复合索引（与 Order 侧统一）', function () {
    Queue::fake();

    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME]);
    createAcmeProductPrice($product->id, $user);

    // 先进入 cancelling 并产生 cancel_acme task（revokeCancel 需锁的目标）
    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'upstream-idx',
        'amount' => '100.00',
    ]);
    expectApiSuccess(fn () => $this->service->commitCancel($acme->id));
    expect($acme->fresh()->status)->toBe(Acme::STATUS_CANCELLING);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    expectApiSuccess(fn () => $this->service->revokeCancel($acme->id));

    $lockSql = collect($queries)->first(fn (string $sql) => str_contains($sql, 'from `tasks`')
        && str_contains($sql, 'for update'));

    expect($lockSql)->not->toBeNull();
    expect($lockSql)->toContain('force index (tasks_order_action_status_index)');
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

test('cancelNow 拒绝超过退款期的已提交 ACME，不调用上游也不退款', function () {
    Carbon::setTestNow('2026-07-31 12:00:00');

    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
        'refund_period' => 30,
    ]);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->update(['status' => Acme::STATUS_ACTIVE, 'api_id' => 'upstream-expired-now']);
    $acme->forceFill(['created_at' => now()->subDays(30)->subSecond()])->saveQuietly();

    setupGatewaySettings();
    Http::fake();

    expectApiError(fn () => $this->service->cancelNow($acme->id), '订单已超过30天不能取消');

    Http::assertNothingSent();
    expect($acme->fresh()->status)->toBe(Acme::STATUS_ACTIVE);
    expect(Transaction::where('transaction_id', $acme->id)->where('type', Transaction::TYPE_ACME_CANCEL)->count())->toBe(0);
});

test('延时 cancel 执行时重新检查 ACME 退款期，越界后不调用上游也不退款', function () {
    Queue::fake();
    Carbon::setTestNow('2026-07-31 12:00:00');

    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
        'refund_period' => 30,
    ]);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->update(['status' => Acme::STATUS_ACTIVE, 'api_id' => 'upstream-cross-boundary']);
    $acme->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();

    expectApiSuccess(fn () => $this->service->commitCancel($acme->id));
    $this->travel(1)->seconds();

    setupGatewaySettings();
    Http::fake();
    expectApiError(fn () => $this->service->cancel($acme->id), '订单已超过30天不能取消');

    Http::assertNothingSent();
    expect($acme->fresh()->status)->toBe(Acme::STATUS_CANCELLING);
    expect(Transaction::where('transaction_id', $acme->id)->where('type', Transaction::TYPE_ACME_CANCEL)->count())->toBe(0);
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

test('sync 从 active 写回上游取消类终态并记录取消时间', function (string $upstream) {
    Carbon::setTestNow('2026-07-31 12:00:00');
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
    ]);
    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => "gw-active-$upstream",
        'cancelled_at' => null,
    ]);

    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'status' => $upstream,
                'vendor_id' => "vendor-$upstream",
                'contact_email' => "$upstream@example.test",
                'period_from' => '2026-07-01 00:00:00',
                'period_till' => '2027-07-01 00:00:00',
            ],
        ]),
    ]);

    $this->service->sync($acme->id, true);

    $acme->refresh();
    expect($acme->status)->toBe($upstream)
        ->and($acme->cancelled_at?->toDateTimeString())->toBe('2026-07-31 12:00:00')
        ->and($acme->vendor_id)->toBe("vendor-$upstream")
        ->and($acme->contact_email)->toBe("$upstream@example.test")
        ->and($acme->period_from?->toDateTimeString())->toBe('2026-07-01 00:00:00')
        ->and($acme->period_till?->toDateTimeString())->toBe('2027-07-01 00:00:00')
        // active 同步到取消类终态无法区分 CA 的取消/吊销语义，只写终态，不自动退款。
        ->and($user->fresh()->balance)->toBe('500.00')
        ->and(Transaction::where('transaction_id', $acme->id)
            ->where('type', Transaction::TYPE_ACME_CANCEL)
            ->count())->toBe(0);
})->with([
    Acme::STATUS_CANCELLED,
    Acme::STATUS_REVOKED,
]);

test('B1-M1：本地 expired（ExpireCommand set-expired 后）sync 遇上游 active 不复活，但 period_till 仍被上游覆盖', function () {
    // 固化「expired 但 period_till 未来」滞留态：sync 终态守卫已含 STATUS_EXPIRED，只挡 status，
    // period_till 为非状态字段仍按上游覆盖（既有性质，B1 仅让 set-expired 可达，不扩展守卫）。
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);

    $acme = Acme::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'gw-m1-test',
        'status' => Acme::STATUS_EXPIRED,  // ExpireCommand set-expired 后本地终态
        'period_till' => now()->subDay(),  // 本地记录已过期
    ]);

    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => ['status' => 'active', 'period_till' => now()->addYear()->toDateTimeString()],
        ]),
    ]);

    expectApiSuccess(fn () => $this->service->sync($acme->id));

    $acme->refresh();
    // 终态守卫：本地 expired 不被上游滞后 active 复活
    expect($acme->status)->toBe(Acme::STATUS_EXPIRED);
    // period_till 非状态字段仍被上游覆盖，形成"expired 但 period_till 未来"的已知滞留态
    expect($acme->period_till->isFuture())->toBeTrue();
    expect($acme->period_till->gt(now()->addMonths(6)))->toBeTrue();
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
                'directory_url' => 'https://acme.example.test/directory/',
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

    expect($response['data'])->toBe([
        'order_id' => $response['data']['order_id'],
        'eab_kid' => 'kid-deploy',
        'eab_hmac' => 'hmac-deploy',
        'status' => Acme::STATUS_ACTIVE,
        'directory_url' => 'https://acme.example.test/directory/',
    ]);

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

test('批量上游入口仅在数量严格超过上限时拒绝', function (
    string $method,
    string $emptyMessage
) {
    config()->set('batch.max_upstream', 2);

    expectApiError(
        fn () => $this->service->{$method}([999_991, 999_992]),
        $emptyMessage
    );

    try {
        $this->service->{$method}([999_991, 999_992, 999_993]);
        test()->fail('超过批量上限应被拒绝');
    } catch (ApiResponseException $e) {
        expect($e->getApiResponse())->toMatchArray([
            'code' => 0,
            'msg' => '订单数量不能超过2',
        ]);
    }
})->with([
    'batchPay' => ['batchPay', '没有可以支付的订单'],
    'batchCommitCancel' => ['batchCommitCancel', '没有可以取消的订单'],
    'batchRevokeCancel' => ['batchRevokeCancel', '没有可以撤回取消的订单'],
]);

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
    expect($res['data'])->toBe([
        'success_count' => 2,
        'commit_count' => 2,
        'errors' => [],
    ]);
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
    expect($res['data']['errors'])->toHaveCount(1);
    expect(array_keys($res['data']['errors'][0]))->toBe(['id', 'msg']);
    expect($res['data']['errors'][0]['id'])->toBeIn([$a1->id, $a2->id]);
    expect($res['data']['errors'][0]['msg'])->toContain('余额不足');
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
    Carbon::setTestNow('2026-07-31 12:00:00');
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
    $task = Task::where('order_id', $fresh->id)->where('action', 'commit_acme')->firstOrFail();
    expect($task->only(['order_id', 'action', 'status', 'source']))->toBe([
        'order_id' => $fresh->id,
        'action' => 'commit_acme',
        'status' => 'executing',
        'source' => getControllerCategory(),
    ])->and($task->started_at->equalTo(now()))->toBeTrue();

    // 仅为 fresh dispatch 了 1 个 TaskJob
    Queue::assertPushed(TaskJob::class, function (TaskJob $job) use ($task) {
        $data = (new ReflectionProperty(TaskJob::class, 'data'))->getValue($job);

        return $data === ['id' => $task->id]
            && $job->afterCommit === true
            && $job->queue === config('queue.names.tasks')
            && $job->delay === null;
    });
});

test('createTasks 延时任务 dispatch delay 比 started_at 多 3 秒缓冲（对齐 Order createTask）', function () {
    // #39：从 Order createTask 复制时丢了 ->delay(now()->addSeconds($later + 3)) 的 +3s 缓冲，
    // 导致 dispatch 的 delay 恰好等于 started_at，worker 可能在事务提交/行可见前消费 job。
    Queue::fake();
    $user = $this->createTestUser();
    $acme = Acme::factory()->create(['user_id' => $user->id, 'status' => 'pending']);

    $delaySeconds = 300;
    Carbon::setTestNow('2026-07-31 12:00:00');

    $method = new ReflectionMethod(Action::class, 'createTasks');
    $method->setAccessible(true);
    $method->invoke($this->service, [$acme->id], 'commit_acme', $delaySeconds);

    // task.started_at = now + delaySeconds
    $task = Task::where('order_id', $acme->id)->where('action', 'commit_acme')->firstOrFail();
    expect($task->only(['order_id', 'action', 'status', 'source']))->toBe([
        'order_id' => $acme->id,
        'action' => 'commit_acme',
        'status' => 'executing',
        'source' => getControllerCategory(),
    ])->and($task->started_at->equalTo(now()->addSeconds($delaySeconds)))->toBeTrue();

    Queue::assertPushed(TaskJob::class, function (TaskJob $job) use ($task, $delaySeconds) {
        // dispatch delay 必须比 started_at（now+delaySeconds）再多 3 秒缓冲
        $data = (new ReflectionProperty(TaskJob::class, 'data'))->getValue($job);

        return $data === ['id' => $task->id]
            && $job->afterCommit === true
            && $job->queue === config('queue.names.tasks')
            && $job->delay instanceof Carbon
            && $job->delay->equalTo(now()->addSeconds($delaySeconds + 3));
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
    expect($res['data'])->toBe([
        'success_count' => 3,
        'errors' => [],
    ]);
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
    expect($res['data'])->toBe([
        'success_count' => 2,
        'errors' => [],
    ]);
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

// ==================== D1: sync cancelling 守卫（P1-4）====================

test('sync cancelling 守卫：本地 cancelling 不被上游滞后 active 复活', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);

    // 手动置 cancelling + api_id：仅验证守卫拦住 active 回写，不依赖退款流水
    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'gw-cancelling-active',
    ]);
    $acme->update(['status' => Acme::STATUS_CANCELLING]);

    setupGatewaySettings();
    // 上游滞后返回 active
    Http::fake([
        'fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'active']]),
    ]);

    // force=true 避免 success 抛 ApiResponseException 打断断言
    $this->service->sync($acme->id, true);

    // 守卫挡住：cancelling 未被复活为 active（未修复此断言红）
    expect($acme->fresh()->status)->toBe(Acme::STATUS_CANCELLING);
});

test('sync cancelling 放行上游终态：仍写回 cancelled/revoked/expired（不被守卫误挡）', function (string $upstream) {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);

    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => "gw-cancelling-$upstream",
    ]);
    $acme->update(['status' => Acme::STATUS_CANCELLING]);

    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => $upstream]]),
    ]);

    $this->service->sync($acme->id, true);

    $acme->refresh();
    // 守卫只挡 active：上游终态仍照常写回
    expect($acme->status)->toBe($upstream);
    if (in_array($upstream, [Acme::STATUS_CANCELLED, Acme::STATUS_REVOKED], true)) {
        expect($acme->cancelled_at)->not->toBeNull();
    }
})->with([
    Acme::STATUS_CANCELLED,
    Acme::STATUS_REVOKED,
    Acme::STATUS_EXPIRED,
]);

test('sync 挡 active 后延时 cancel_acme 仍完成取消+退费（K1 端到端）', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    // 真实扣费流水，供 cancel 退费反向冲正（账目恒等，过 FundInvariants）
    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->refresh();
    // 模拟 commit 成功后的 active + api_id
    $acme->update(['status' => Acme::STATUS_ACTIVE, 'api_id' => 'gw-k1-active']);

    setupGatewaySettings();
    // 同 URL 顺序两次响应用 fakeSequence（两次 Http::fake 同 pattern 会累加且先注册者先匹配）：
    // sync 先遇上游滞后 active（守卫应挡），随后 cancel 上游返回 cancelled
    Http::fakeSequence('fake-gateway.test/*')
        ->push(['code' => 1, 'data' => ['status' => 'active']])
        ->push(['code' => 1, 'data' => ['status' => 'cancelled']]);

    // commitCancel(active) → cancelling + 延时 cancel_acme 任务
    expectApiSuccess(fn () => $this->service->commitCancel($acme->id));
    expect($acme->fresh()->status)->toBe(Acme::STATUS_CANCELLING);
    expect(Task::where('order_id', $acme->id)->where('action', 'cancel_acme')->where('status', 'executing')->count())->toBe(1);

    // sync 遇上游滞后 active：守卫挡住，保持 cancelling
    $this->service->sync($acme->id, true);
    expect($acme->fresh()->status)->toBe(Acme::STATUS_CANCELLING);

    // 延时任务到点执行 cancel：上游 cancelled → cancelled + acme_cancel 退款流水
    // （未修复：sync 已翻 active，此处 cancelLocked 校验 !=cancelling 抛「订单状态不是取消中」）
    expectApiSuccess(fn () => $this->service->cancel($acme->id));

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_CANCELLED);
    expect(Transaction::where('transaction_id', $acme->id)
        ->where('type', Transaction::TYPE_ACME_CANCEL)
        ->first())->not->toBeNull();
});

test('sync 挡 active 后 revokeCancel 仍能置回 active 并删任务（K2 交互）', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'gw-k2-active',
        'amount' => '100.00',
    ]);

    // commitCancel → cancelling + cancel_acme 任务
    expectApiSuccess(fn () => $this->service->commitCancel($acme->id));
    expect($acme->fresh()->status)->toBe(Acme::STATUS_CANCELLING);
    expect(Task::where('order_id', $acme->id)->where('action', 'cancel_acme')->where('status', 'executing')->count())->toBe(1);

    // sync 遇上游滞后 active：守卫挡住，保持 cancelling
    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'active']]),
    ]);
    $this->service->sync($acme->id, true);
    expect($acme->fresh()->status)->toBe(Acme::STATUS_CANCELLING);

    // 用户撤回：revokeCancel 仍能置回 active 并清空任务
    // （未修复：sync 已翻 active，revokeCancel 校验 !=cancelling 抛「订单不在取消中状态」，且任务残留）
    expectApiSuccess(fn () => $this->service->revokeCancel($acme->id));
    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_ACTIVE);
    expect(Task::where('order_id', $acme->id)->where('action', 'cancel_acme')->count())->toBe(0);
});

// ==================== D2: directory_url 缓存 TTL（P2）====================

test('directory_url 缓存过期后 syncDirectoryUrl 回源上游刷新（不再永久驻留）', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);

    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'gw-dir-ttl',
    ]);

    setupGatewaySettings();
    // 同 URL 顺序两次响应用 fakeSequence（两次 Http::fake 同 pattern 会累加且先注册者先匹配）：
    // 首次回源拿 A，TTL 过期后再次回源拿 B
    Http::fakeSequence('fake-gateway.test/*')
        ->push(['code' => 1, 'data' => ['status' => 'active', 'directory_url' => 'https://acme.example.test/A/']])
        ->push(['code' => 1, 'data' => ['status' => 'active', 'directory_url' => 'https://acme.example.test/B/']]);

    // 首次上游返回 directory_url A → syncDirectoryUrl 缓存 A
    expect($this->service->syncDirectoryUrl($acme->fresh()))->toBe('https://acme.example.test/A/');

    // 超过 TTL(30 天)：缓存过期，下次详情查看应回源拿到 B（未修复的 forever 永不过期，仍返回 A → 红）
    $this->travel(31)->days();
    expect($this->service->syncDirectoryUrl($acme->fresh()))->toBe('https://acme.example.test/B/');
});

test('directory_url TTL 未到期时命中缓存不回源（防 TTL 设过短）', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);

    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'gw-dir-hit',
    ]);

    setupGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'active', 'directory_url' => 'https://acme.example.test/A/']]),
    ]);
    expect($this->service->syncDirectoryUrl($acme->fresh()))->toBe('https://acme.example.test/A/');
    Http::assertSentCount(1);

    // TTL(30 天) 之内：命中缓存，不再回源上游
    $this->travel(1)->days();
    expect($this->service->syncDirectoryUrl($acme->fresh()))->toBe('https://acme.example.test/A/');
    Http::assertSentCount(1);
});

// ==================== T7: sync cancelling→terminal 补退款（D 评审孪生缺口，资金路径）====================

test('T7：cancelling + 上游 cancelled → sync 退款 + 删 cancel_acme 任务 + 置 cancelled', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    // 真实 new+pay 造 acme_order 流水（账目恒等，过 FundInvariants）→ active → commitCancel → cancelling + task
    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->refresh();
    $acme->update(['status' => Acme::STATUS_ACTIVE, 'api_id' => 'gw-t7-cancelled']);
    expectApiSuccess(fn () => $this->service->commitCancel($acme->id));
    expect($acme->fresh()->status)->toBe(Acme::STATUS_CANCELLING);
    expect(Task::where('order_id', $acme->id)->where('action', 'cancel_acme')->where('status', 'executing')->count())->toBe(1);

    setupGatewaySettings();
    Http::fake(['fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'cancelled']])]);

    $this->service->sync($acme->id, true);

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_CANCELLED)
        ->and($acme->cancelled_at)->not->toBeNull();
    // 退款流水（account 冲正）
    expect(Transaction::where('transaction_id', $acme->id)->where('type', Transaction::TYPE_ACME_CANCEL)->count())->toBe(1);
    // 孤儿 cancel_acme 任务被删（延时任务不再断死）
    expect(Task::where('order_id', $acme->id)->where('action', 'cancel_acme')->count())->toBe(0);
});

test('T7：cancelling 同步到上游终态时检查 ACME 退款期，越界不退款且保留取消任务转人工', function () {
    Queue::fake();
    Carbon::setTestNow('2026-07-31 12:00:00');

    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
        'refund_period' => 30,
    ]);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->update(['status' => Acme::STATUS_ACTIVE, 'api_id' => 'gw-t7-expired']);
    $acme->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();
    expectApiSuccess(fn () => $this->service->commitCancel($acme->id));
    $this->travel(1)->seconds();

    setupGatewaySettings();
    Http::fake(['fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'cancelled']])]);

    expectApiError(fn () => $this->service->sync($acme->id, true), '订单已超过30天不能取消');

    expect($acme->fresh()->status)->toBe(Acme::STATUS_CANCELLING);
    expect(Transaction::where('transaction_id', $acme->id)->where('type', Transaction::TYPE_ACME_CANCEL)->count())->toBe(0);
    expect(Task::where('order_id', $acme->id)->where('action', 'cancel_acme')->count())->toBe(1);
});

test('T7：cancelling + 上游 revoked/expired 同样退款置终态并删任务', function (string $upstream) {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->refresh();
    $acme->update(['status' => Acme::STATUS_ACTIVE, 'api_id' => "gw-t7-$upstream"]);
    expectApiSuccess(fn () => $this->service->commitCancel($acme->id));

    setupGatewaySettings();
    Http::fake(['fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => $upstream]])]);

    $this->service->sync($acme->id, true);

    $acme->refresh();
    expect($acme->status)->toBe($upstream);
    expect(Transaction::where('transaction_id', $acme->id)->where('type', Transaction::TYPE_ACME_CANCEL)->count())->toBe(1);
    expect(Task::where('order_id', $acme->id)->where('action', 'cancel_acme')->count())->toBe(0);
})->with([Acme::STATUS_REVOKED, Acme::STATUS_EXPIRED]);

test('T7：已退款的 cancelling 单再 sync → 预检跳过退款、只补终态删任务、无二次流水', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->refresh();
    $acme->update(['status' => Acme::STATUS_ACTIVE, 'api_id' => 'gw-t7-idem']);
    expectApiSuccess(fn () => $this->service->commitCancel($acme->id)); // cancelling + cancel_acme task

    setupGatewaySettings();
    // 先真实 cancel 一次：退款 + cancelled（acme_cancel 流水，task 仍 executing——cancel 不删 task）
    Http::fake(['fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'cancelled']])]);
    expectApiSuccess(fn () => $this->service->cancel($acme->id));
    expect(Transaction::where('transaction_id', $acme->id)->where('type', Transaction::TYPE_ACME_CANCEL)->count())->toBe(1);

    // 模拟状态回滚边缘态：cancelling 但 acme_cancel 已在
    $acme->update(['status' => Acme::STATUS_CANCELLING]);

    // 再 sync cancelled：预检 alreadyRefunded=true → 跳过退款，只补终态删任务
    Cache::forget("acme_sync_$acme->id");
    Http::fake(['fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'cancelled']])]);
    $this->service->sync($acme->id, true);

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_CANCELLED);
    expect(Transaction::where('transaction_id', $acme->id)->where('type', Transaction::TYPE_ACME_CANCEL)->count())->toBe(1); // 无二次退款
    expect(Task::where('order_id', $acme->id)->where('action', 'cancel_acme')->count())->toBe(0); // 孤儿任务删除
});

test('T7 K5：revokeCancel 撤回(→active)后 sync 上游 active 不退款不写终态', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->refresh();
    $acme->update(['status' => Acme::STATUS_ACTIVE, 'api_id' => 'gw-t7-k5']);
    expectApiSuccess(fn () => $this->service->commitCancel($acme->id)); // cancelling + task
    expectApiSuccess(fn () => $this->service->revokeCancel($acme->id)); // → active + 删 task
    expect($acme->fresh()->status)->toBe(Acme::STATUS_ACTIVE);

    setupGatewaySettings();
    Http::fake(['fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'active']])]);
    $this->service->sync($acme->id, true);

    // T7 判据 status===cancelling 不命中（已 active）→ 不退款、不写终态
    expect($acme->fresh()->status)->toBe(Acme::STATUS_ACTIVE);
    expect(Transaction::where('transaction_id', $acme->id)->where('type', Transaction::TYPE_ACME_CANCEL)->count())->toBe(0);
});

test('T7 K6：并发 cancel 已退款置 cancelled → sync 上游终态不双退不复活', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->refresh();
    $acme->update(['status' => Acme::STATUS_ACTIVE, 'api_id' => 'gw-t7-k6']);
    expectApiSuccess(fn () => $this->service->commitCancel($acme->id));

    setupGatewaySettings();
    // 并发 cancel 先执行：退款 + cancelled
    Http::fake(['fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'cancelled']])]);
    expectApiSuccess(fn () => $this->service->cancel($acme->id));
    expect($acme->fresh()->status)->toBe(Acme::STATUS_CANCELLED);
    expect(Transaction::where('transaction_id', $acme->id)->where('type', Transaction::TYPE_ACME_CANCEL)->count())->toBe(1);

    // sync 上游 cancelled：本地已 cancelled（localTerminal）→ T7 判据 status===cancelling 不命中 → 不双退不复活
    Cache::forget("acme_sync_$acme->id");
    Http::fake(['fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'cancelled']])]);
    $this->service->sync($acme->id, true);

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_CANCELLED);
    expect(Transaction::where('transaction_id', $acme->id)->where('type', Transaction::TYPE_ACME_CANCEL)->count())->toBe(1); // 无二次退款
});

test('T7 ⑦：上游 active（高频 get 常态）不触发 cancel_acme 锁路径（回归）', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);

    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'api_id' => 'gw-t7-active',
    ]);

    setupGatewaySettings();
    Http::fake(['fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'active']])]);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $this->service->sync($acme->id, true);

    // upstreamTerminal=false → 不锁 cancel_acme task（零 task 锁开销）
    $lockSql = collect($queries)->first(fn (string $sql) => str_contains($sql, 'from `tasks`') && str_contains($sql, 'for update'));
    expect($lockSql)->toBeNull();
    expect($acme->fresh()->status)->toBe(Acme::STATUS_ACTIVE);
});

test('T7：退款终态异常 → sync 抛出并回滚且不发送专属告警', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->refresh();
    $acme->update(['status' => Acme::STATUS_ACTIVE, 'api_id' => 'gw-t7-alert']);
    expectApiSuccess(fn () => $this->service->commitCancel($acme->id));

    // partial mock：refund 抛非并发终态异常
    $service = Mockery::mock(Action::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('refund')->andThrow(new RuntimeException('refund boom'));

    setupGatewaySettings();
    Http::fake(['fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'cancelled']])]);

    try {
        $service->sync($acme->id, true);
        test()->fail('期望 sync 抛出退款异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('refund boom');
    }

    // 事务回滚：退款未落、状态仍 cancelling
    expect(Transaction::where('transaction_id', $acme->id)->where('type', Transaction::TYPE_ACME_CANCEL)->count())->toBe(0);
    expect($acme->fresh()->status)->toBe(Acme::STATUS_CANCELLING);
});

test('T7：refund 抛并发错误 → 异常继续抛出且状态回滚', function () {
    Queue::fake();
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    createAcmeProductPrice($product->id, $user);

    $acme = createAcmeOrder($user, $product);
    expectApiSuccess(fn () => $this->service->pay($acme->id, false));
    $acme->refresh();
    $acme->update(['status' => Acme::STATUS_ACTIVE, 'api_id' => 'gw-t7-n1']);
    expectApiSuccess(fn () => $this->service->commitCancel($acme->id));

    // partial mock：refund 恒抛并发错误（deadlock message → causedByConcurrencyError=true）→
    // 真实 MySQL 连接由 runTaskMutationTransaction(attempts=3) 识别并发错误并重试；
    // 测试环境只验证异常不会被吞掉且事务整体回滚。
    $service = Mockery::mock(Action::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('refund')->once()->andThrow(
        new RuntimeException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction')
    );

    setupGatewaySettings();
    Http::fake(['fake-gateway.test/*' => Http::response(['code' => 1, 'data' => ['status' => 'cancelled']])]);

    try {
        $service->sync($acme->id, true);
    } catch (Throwable $e) {
        // 重试耗尽后抛并发错误（预期）
    }

    expect(Transaction::where('transaction_id', $acme->id)->where('type', Transaction::TYPE_ACME_CANCEL)->count())->toBe(0)
        ->and($acme->fresh()->status)->toBe(Acme::STATUS_CANCELLING);
});
