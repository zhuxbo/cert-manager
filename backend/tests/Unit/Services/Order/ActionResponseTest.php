<?php

use App\Exceptions\ApiResponseException;
use App\Models\Callback;
use App\Models\Cert;
use App\Models\CnameDelegation;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Action;
use App\Services\Order\Api\Api;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('database');

beforeEach(function () {
    Cache::flush();
    Queue::fake();
    $this->orderMutationAction = app(Action::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function orderMutationSuccess(Closure $callback): array
{
    try {
        $callback();
        test()->fail('期望 Action 返回成功响应');
    } catch (ApiResponseException $e) {
        $response = $e->getApiResponse();
        if ($response['code'] !== 1) {
            test()->fail(json_encode($response, JSON_UNESCAPED_UNICODE));
        }

        return $response;
    }

    return [];
}

function orderMutationError(Closure $callback, string $message): array
{
    try {
        $callback();
        test()->fail('期望 Action 返回错误响应');
    } catch (ApiResponseException $e) {
        $response = $e->getApiResponse();
        expect($response['code'])->toBe(0)
            ->and($response['msg'])->toBe($message);

        return $response;
    }

    return [];
}

function orderMutationFixture(
    string $status = 'processing',
    array $orderAttributes = [],
    array $certAttributes = [],
    array $productAttributes = [],
): array {
    $user = User::factory()->create();
    $product = Product::factory()->create(array_merge([
        'source' => 'default',
        'product_type' => Product::TYPE_SSL,
        'validation_type' => 'dv',
    ], $productAttributes));
    $order = Order::factory()->create(array_merge([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ], $orderAttributes));
    $cert = Cert::factory()->create(array_merge([
        'order_id' => $order->id,
        'status' => $status,
    ], $certAttributes));
    $order->update(['latest_cert_id' => $cert->id]);

    return [$order->fresh(), $cert, $product, $user];
}

function injectOrderMutationApi(Action $action, Api $api): void
{
    $reflection = new ReflectionClass($action);
    $property = $reflection->getProperty('api');
    $property->setAccessible(true);
    $property->setValue($action, $api);
}

function enableOrderMutationAutoRefund(): void
{
    $group = SettingGroup::firstOrCreate(
        ['name' => 'site'],
        ['title' => 'Site', 'weight' => 1],
    );
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'autoRefundOnSync'],
        ['type' => 'boolean', 'value' => true, 'weight' => 0],
    );
    Setting::setValue('site', 'autoRefundOnSync', true);
}

function invokeOrderMutationImportItem(Action $action, array $item, string $source, string $type): void
{
    $method = new ReflectionMethod($action, 'importProductItem');
    $method->setAccessible(true);
    $method->invoke($action, $item, $source, $type);
}

test('importProductItem update 精确过滤空值并保存可覆盖字段和成本', function () {
    $product = Product::factory()->create([
        'source' => 'mutation-import',
        'api_id' => 'UPDATE-1',
        'name' => '',
        'remark' => '',
        'brand' => 'local-brand',
        'weight' => 0,
        'periods' => [12],
        'alternative_name_types' => ['standard', 'wildcard'],
        'validation_methods' => ['dns', 'delegation'],
        'cost' => [
            'price' => ['12' => '50.00'],
            'alternative_standard_price' => ['12' => '5.00'],
            'alternative_wildcard_price' => ['12' => '10.00'],
        ],
    ]);

    invokeOrderMutationImportItem($this->orderMutationAction, [
        'code' => 'UPDATE-1',
        'name' => 'Upstream Exact Name',
        'remark' => 'Upstream exact remark',
        'brand' => null,
        'weight' => 9,
        'validation_methods' => ['email'],
        'cost' => [
            'price' => ['12' => '66.00'],
            'alternative_standard_price' => ['12' => '6.00'],
            'alternative_wildcard_price' => ['12' => '12.00'],
        ],
    ], 'mutation-import', 'update');

    $product->refresh();
    expect($product->name)->toBe('Upstream Exact Name')
        ->and($product->remark)->toBe('Upstream exact remark')
        ->and($product->brand)->toBe('local-brand')
        ->and($product->weight)->toBe(9)
        ->and($product->validation_methods)->toBe(['email', 'delegation'])
        ->and($product->cost)->toBe([
            'price' => ['12' => '66.00'],
            'alternative_standard_price' => ['12' => '6.00'],
            'alternative_wildcard_price' => ['12' => '12.00'],
        ]);
});

test('importProductItem new 精确准备必填默认值并持久化成本', function () {
    invokeOrderMutationImportItem($this->orderMutationAction, [
        'code' => 'CREATE-1',
        'brand' => 'sectigo',
        'ca' => 'Sectigo',
        'product_type' => Product::TYPE_ACME,
        'validation_type' => 'dv',
        'periods' => [12],
        'cost' => ['price' => ['12' => '77.00']],
    ], 'mutation-import', 'new');

    $product = Product::where('source', 'mutation-import')->where('api_id', 'CREATE-1')->sole();
    expect($product->only([
        'code',
        'api_id',
        'source',
        'name',
        'brand',
        'ca',
        'product_type',
        'validation_type',
        'periods',
        'encryption_alg',
        'signature_digest_alg',
        'common_name_types',
        'alternative_name_types',
        'validation_methods',
        'standard_min',
        'standard_max',
        'wildcard_min',
        'wildcard_max',
        'total_min',
        'total_max',
    ]))->toBe([
        'code' => 'CREATE-1',
        'api_id' => 'CREATE-1',
        'source' => 'mutation-import',
        'name' => 'Sectigo_CREATE-1',
        'brand' => 'sectigo',
        'ca' => 'Sectigo',
        'product_type' => Product::TYPE_ACME,
        'validation_type' => 'dv',
        'periods' => [12],
        'encryption_alg' => [],
        'signature_digest_alg' => [],
        'common_name_types' => [],
        'alternative_name_types' => [],
        'validation_methods' => [],
        'standard_min' => 0,
        'standard_max' => 0,
        'wildcard_min' => 0,
        'wildcard_max' => 0,
        'total_min' => 0,
        'total_max' => 0,
    ])->and($product->cost)->toBe(['price' => ['12' => '77.00']]);
});

test('importProduct 必须同时满足成功码和非空数据才导入', function () {
    $product = Product::factory()->create([
        'source' => 'mutation-import',
        'api_id' => 'IGNORED-CODE-ZERO',
        'weight' => 0,
    ]);
    $api = Mockery::mock(Api::class);
    $api->shouldReceive('getProducts')
        ->once()
        ->with('mutation-import', '', '')
        ->andReturn([
            'code' => 0,
            'data' => [['code' => 'IGNORED-CODE-ZERO', 'weight' => 9]],
        ]);
    injectOrderMutationApi($this->orderMutationAction, $api);

    orderMutationError(
        fn () => $this->orderMutationAction->importProduct('mutation-import', '', '', 'update'),
        '没有获取到产品',
    );

    expect($product->fresh()->weight)->toBe(0);
});

test('importProductItem update 不会为本地未启用的产品错误追加 delegation', function () {
    $product = Product::factory()->create([
        'source' => 'mutation-import',
        'api_id' => 'NO-DELEGATION',
        'validation_methods' => ['email'],
    ]);

    invokeOrderMutationImportItem($this->orderMutationAction, [
        'code' => 'NO-DELEGATION',
        'validation_methods' => ['email'],
    ], 'mutation-import', 'update');

    expect($product->fresh()->validation_methods)->toBe(['email']);
});

test('importProductItem update 依赖完整请求验证链拒绝非法周期', function () {
    $product = Product::factory()->create([
        'source' => 'mutation-import',
        'api_id' => 'INVALID-UPDATE',
        'product_type' => Product::TYPE_SSL,
        'periods' => [12],
    ]);

    $response = orderMutationError(
        fn () => invokeOrderMutationImportItem($this->orderMutationAction, [
            'code' => 'INVALID-UPDATE',
            'periods' => [2],
        ], 'mutation-import', 'update'),
        '产品数据验证失败',
    );

    expect($response['errors'])->toHaveKey('periods.0')
        ->and($product->fresh()->periods)->toBe([12]);
});

test('sync 锁内重读会保护每一种终态及全部国密敏感字段', function () {
    foreach (['cancelled', 'revoked', 'renewed', 'reissued', 'failed'] as $terminalStatus) {
        [$order, $cert] = orderMutationFixture('processing', [], [
            'enc_cert' => 'local-cert',
            'enc_key' => 'local-key',
            'enc_key2' => 'local-key2',
        ]);
        $api = Mockery::mock(Api::class);
        $api->shouldReceive('get')->once()->with($order->id)->andReturnUsing(function () use ($cert, $terminalStatus) {
            Cert::where('id', $cert->id)->update(['status' => $terminalStatus]);

            return [
                'code' => 1,
                'data' => [
                    'status' => 'active',
                    'enc_cert' => 'stale-cert',
                    'enc_key' => 'stale-key',
                    'enc_key2' => 'stale-key2',
                    'vendor_id' => 'metadata-still-syncs',
                ],
            ];
        });
        injectOrderMutationApi($this->orderMutationAction, $api);

        orderMutationSuccess(fn () => $this->orderMutationAction->sync($order->id));

        $fresh = $cert->fresh();
        expect($fresh->status)->toBe($terminalStatus)
            ->and($fresh->enc_cert)->toBe('local-cert')
            ->and($fresh->enc_key)->toBe('local-key')
            ->and($fresh->enc_key2)->toBe('local-key2')
            ->and($fresh->vendor_id)->toBe('metadata-still-syncs');
    }
});

test('sync 强制模式会在锁内保护每一种既有终态', function () {
    foreach (['cancelled', 'revoked', 'renewed', 'reissued', 'failed'] as $terminalStatus) {
        [$order, $cert] = orderMutationFixture($terminalStatus);
        $api = Mockery::mock(Api::class);
        $api->shouldReceive('get')->once()->with($order->id)->andReturn([
            'code' => 1,
            'data' => ['status' => 'active', 'vendor_id' => 'terminal-metadata'],
        ]);
        injectOrderMutationApi($this->orderMutationAction, $api);

        $this->orderMutationAction->sync($order->id, true);

        expect($cert->fresh()->status)->toBe($terminalStatus)
            ->and($cert->fresh()->vendor_id)->toBe('terminal-metadata');
    }
});

test('sync 精确合并订单资料并计算 SAN 数量和 12 月 plus 有效期', function () {
    Carbon::setTestNow('2026-07-31 12:00:00');
    $issuedAt = Carbon::parse('2026-08-01 00:00:00')->timestamp;
    $expiresAt = Carbon::parse('2027-07-27 23:59:59')->timestamp;
    [$order, $cert] = orderMutationFixture('processing', [
        'period' => 12,
        'plus' => 1,
        'period_from' => null,
        'period_till' => null,
        'purchased_standard_count' => 0,
        'purchased_wildcard_count' => 0,
        'contact' => ['first_name' => 'Local', 'email' => 'old@example.test'],
        'organization' => ['name' => 'Local Ltd', 'country' => 'CN'],
    ], [
        'dcv' => [['domain' => 'example.test', 'method' => 'delegation']],
        'validation' => [['domain' => 'old.example.test', 'method' => 'dns', 'verified' => 1]],
    ]);
    $api = Mockery::mock(Api::class);
    $api->shouldReceive('get')->once()->with($order->id)->andReturn([
        'code' => 1,
        'data' => [
            'status' => 'approving',
            'contact' => ['email' => 'new@example.test', 'phone' => '13800000000'],
            'organization' => ['name' => 'Upstream Ltd', 'city' => 'Shenzhen'],
            'alternative_names' => 'www.example.test,*.example.test,api.example.test',
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ],
    ]);
    injectOrderMutationApi($this->orderMutationAction, $api);

    $this->orderMutationAction->sync($order->id, true);

    $order->refresh();
    $cert->refresh();
    expect($order->contact)->toBe([
        'first_name' => 'Local',
        'email' => 'new@example.test',
        'phone' => '13800000000',
    ])->and($order->organization)->toBe([
        'name' => 'Upstream Ltd',
        'country' => 'CN',
        'city' => 'Shenzhen',
    ])->and($order->purchased_standard_count)->toBe(2)
        ->and($order->purchased_wildcard_count)->toBe(1)
        ->and($order->period_from->timestamp)->toBe($issuedAt)
        ->and($order->period_till->timestamp)->toBe($issuedAt + 395 * 86400 - 1)
        ->and($cert->status)->toBe('approving')
        ->and($cert->standard_count)->toBe(2)
        ->and($cert->wildcard_count)->toBe(1)
        ->and($cert->dcv)->toBe([['domain' => 'example.test', 'method' => 'delegation']])
        ->and($cert->validation)->toBe([['domain' => 'old.example.test', 'method' => 'dns', 'verified' => 1]]);
});

test('sync 只有 issued_at 和 expires_at 同时存在且订单未起期才设置有效期', function (
    bool $hasExistingPeriod,
    bool $hasIssuedAt,
    bool $hasExpiresAt,
    bool $shouldSet,
) {
    $issuedAt = Carbon::parse('2026-08-01 00:00:00')->timestamp;
    $expiresAt = Carbon::parse('2027-08-01 00:00:00')->timestamp;
    [$order] = orderMutationFixture('processing', [
        'period_from' => $hasExistingPeriod ? '2025-01-02 03:04:05' : null,
        'period_till' => $hasExistingPeriod ? '2026-01-02 03:04:05' : null,
    ]);
    $data = ['status' => 'processing'];
    $hasIssuedAt && $data['issued_at'] = $issuedAt;
    $hasExpiresAt && $data['expires_at'] = $expiresAt;
    $api = Mockery::mock(Api::class);
    $api->shouldReceive('get')->once()->andReturn(['code' => 1, 'data' => $data]);
    injectOrderMutationApi($this->orderMutationAction, $api);

    $this->orderMutationAction->sync($order->id, true);

    $fresh = $order->fresh();
    if ($shouldSet) {
        expect($fresh->period_from->timestamp)->toBe($issuedAt)
            ->and($fresh->period_till->timestamp)->toBe($expiresAt);
    } elseif ($hasExistingPeriod) {
        expect($fresh->period_from->toDateTimeString())->toBe('2025-01-02 03:04:05')
            ->and($fresh->period_till->toDateTimeString())->toBe('2026-01-02 03:04:05');
    } else {
        expect($fresh->period_from)->toBeNull()
            ->and($fresh->period_till)->toBeNull();
    }
})->with([
    'both values initialize' => [false, true, true, true],
    'missing issued_at does not initialize' => [false, false, true, false],
    'missing expires_at does not initialize' => [false, true, false, false],
    'existing period is authoritative' => [true, true, true, false],
]);

test('sync 防抖窗口在第十秒精确过期', function () {
    Carbon::setTestNow('2026-07-31 12:00:00');
    [$order, $cert] = orderMutationFixture('active');
    $api = Mockery::mock(Api::class);
    $api->shouldReceive('get')->twice()->andReturn(
        ['code' => 1, 'data' => ['status' => 'active', 'vendor_id' => 'first']],
        ['code' => 1, 'data' => ['status' => 'active', 'vendor_id' => 'second']],
    );
    injectOrderMutationApi($this->orderMutationAction, $api);

    $this->orderMutationAction->sync($order->id, true);
    $this->travel(9)->seconds();
    $this->orderMutationAction->sync($order->id, true);
    expect($cert->fresh()->vendor_id)->toBe('first');

    $this->travel(1)->second();
    $this->orderMutationAction->sync($order->id, true);
    expect($cert->fresh()->vendor_id)->toBe('second');
});

test('sync 吊销精确派发通知、回调并只清理指定任务', function (?string $expiresAt, string $expectedExpiry) {
    [$order, $cert, $product, $user] = orderMutationFixture('active', [], [
        'action' => 'renew',
        'common_name' => 'revoked.example.test',
        'expires_at' => $expiresAt,
        'last_cert_id' => null,
    ], [
        'product_type' => Product::TYPE_CODESIGN,
    ]);
    Callback::create([
        'user_id' => $user->id,
        'url' => 'https://callback.example.test/order',
        'token' => 'callback-token',
        'status' => 1,
    ]);
    foreach (['commit', 'sync', 'revalidate', 'cancel'] as $action) {
        Task::factory()->create(['order_id' => $order->id, 'action' => $action, 'status' => 'stopped']);
    }
    $captured = [];
    $notificationCenter = Mockery::mock(NotificationCenter::class);
    $notificationCenter->shouldReceive('dispatch')->once()->andReturnUsing(function ($intent) use (&$captured) {
        $captured[] = $intent;
    });
    app()->instance(NotificationCenter::class, $notificationCenter);
    $api = Mockery::mock(Api::class);
    $api->shouldReceive('get')->once()->with($order->id)->andReturn([
        'code' => 1,
        'data' => ['status' => 'revoked', 'vendor_id' => 'revoked-upstream'],
    ]);
    injectOrderMutationApi($this->orderMutationAction, $api);

    orderMutationSuccess(fn () => $this->orderMutationAction->sync($order->id));

    expect($cert->fresh()->status)->toBe('revoked')
        ->and($cert->fresh()->vendor_id)->toBe('revoked-upstream')
        ->and($captured)->toHaveCount(1)
        ->and($captured[0]->code)->toBe('cert_revoked')
        ->and($captured[0]->notifiableType)->toBe('user')
        ->and($captured[0]->notifiableId)->toBe($user->id)
        ->and($captured[0]->context)->toBe([
            'common_name' => 'revoked.example.test',
            'expires_at' => $expectedExpiry,
            'order_id' => $order->id,
            'is_successor' => false,
            'product_type' => Product::TYPE_CODESIGN,
        ])
        ->and(Task::where('order_id', $order->id)->orderBy('action')->pluck('action')->all())->toBe(['callback', 'cancel']);
})->with([
    '有到期日' => ['2027-02-03 12:34:56', '2027-02-03'],
    '无到期日' => [null, ''],
]);

test('sync 自动退款精确落账、回调并只清理指定任务', function () {
    Carbon::setTestNow('2026-07-31 14:15:16');
    enableOrderMutationAutoRefund();
    [$order, $cert, $product, $user] = orderMutationFixture('processing', [
        'amount' => '123.45',
    ], [
        'action' => 'new',
        'amount' => '123.45',
    ]);
    $user->update(['balance' => '123.45']);
    Transaction::create([
        'user_id' => $user->id,
        'type' => 'order',
        'transaction_id' => $order->id,
        'amount' => '-123.45',
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]);
    Callback::create([
        'user_id' => $user->id,
        'url' => 'https://callback.example.test/refund',
        'token' => 'refund-token',
        'status' => 1,
    ]);
    foreach (['commit', 'sync', 'revalidate', 'cancel'] as $action) {
        Task::factory()->create(['order_id' => $order->id, 'action' => $action, 'status' => 'stopped']);
    }
    $api = Mockery::mock(Api::class);
    $api->shouldReceive('get')->once()->with($order->id)->andReturn([
        'code' => 1,
        'data' => ['status' => 'cancelled', 'vendor_id' => 'cancelled-upstream'],
    ]);
    injectOrderMutationApi($this->orderMutationAction, $api);

    orderMutationSuccess(fn () => $this->orderMutationAction->sync($order->id));

    $refund = Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->sole();
    expect($cert->fresh()->status)->toBe('cancelled')
        ->and($cert->fresh()->vendor_id)->toBe('cancelled-upstream')
        ->and($order->fresh()->cancelled_at->toDateTimeString())->toBe('2026-07-31 14:15:16')
        ->and((string) $user->fresh()->balance)->toBe('123.45')
        ->and((string) $refund->amount)->toBe('123.45')
        ->and($refund->user_id)->toBe($user->id)
        ->and($refund->standard_count)->toBe(-1)
        ->and($refund->wildcard_count)->toBe(0)
        ->and(Task::where('order_id', $order->id)->orderBy('action')->pluck('action')->all())->toBe(['callback', 'cancel']);
});

test('updateDCV 重复提交返回包含精确剩余秒数的错误', function () {
    Carbon::setTestNow('2026-07-31 12:00:00');
    [$order] = orderMutationFixture('unpaid', [], [
        'alternative_names' => 'example.test',
        'csr' => 'unused-csr',
    ]);

    orderMutationSuccess(fn () => $this->orderMutationAction->updateDCV($order->id, 'txt'));
    orderMutationError(
        fn () => $this->orderMutationAction->updateDCV($order->id, 'txt'),
        '请在 60 秒后再提交修改',
    );
});

test('updateDCV 防抖键按订单隔离而不会阻塞其他订单', function () {
    Carbon::setTestNow('2026-07-31 12:00:00');
    [$first, $firstCert] = orderMutationFixture('unpaid', [], [
        'alternative_names' => 'first.example.test',
        'csr' => 'unused-csr',
    ]);
    [$second, $secondCert] = orderMutationFixture('unpaid', [], [
        'alternative_names' => 'second.example.test',
        'csr' => 'unused-csr',
    ]);

    orderMutationSuccess(fn () => $this->orderMutationAction->updateDCV($first->id, 'txt'));
    orderMutationSuccess(fn () => $this->orderMutationAction->updateDCV($second->id, 'txt'));

    expect($firstCert->fresh()->dcv)->toBe(['method' => 'txt'])
        ->and($secondCert->fresh()->dcv)->toBe(['method' => 'txt']);
});

test('updateDCV processing 精确发送方法并分别返回上游数据和保存合并数据', function () {
    [$order, $cert, , $user] = orderMutationFixture('processing', [], [
        'alternative_names' => 'example.test',
        'csr' => 'unused-csr',
        'dcv' => ['method' => 'file'],
        'validation' => [['domain' => 'example.test', 'method' => 'file', 'content' => 'old']],
    ], ['ca' => 'sectigo']);
    CnameDelegation::factory()->create([
        'user_id' => $user->id,
        'zone' => 'example.test',
        'prefix' => '_dnsauth',
        'valid' => true,
    ]);
    $apiDcv = [
        'method' => 'txt',
        'dns' => ['host' => '_dnsauth.example.test', 'value' => 'new-token'],
    ];
    $apiValidation = [[
        'domain' => 'example.test',
        'host' => '_dnsauth.example.test',
        'value' => 'new-token',
    ]];
    $api = Mockery::mock(Api::class);
    $api->shouldReceive('updateDCV')->once()->with($order->id, 'txt')->andReturn([
        'code' => 1,
        'data' => ['dcv' => $apiDcv, 'validation' => $apiValidation],
    ]);
    injectOrderMutationApi($this->orderMutationAction, $api);

    $response = orderMutationSuccess(fn () => $this->orderMutationAction->updateDCV($order->id, 'txt'));

    expect($response['data'])->toBe([
        'dcv' => $apiDcv,
        'validation' => $apiValidation,
    ])->and($cert->fresh()->dcv)->toBe($apiDcv)
        ->and($cert->fresh()->validation)->toBe([[
            'domain' => 'example.test',
            'host' => '_dnsauth.example.test',
            'value' => 'new-token',
            'method' => 'txt',
        ]])
        ->and(Task::where('order_id', $order->id)->where('action', 'delegation')->exists())->toBeFalse();
});

test('updateDCV processing 委托方法对上游转 txt 并创建委托任务', function () {
    [$order, $cert] = orderMutationFixture('processing', [], [
        'alternative_names' => 'example.test',
        'csr' => 'unused-csr',
    ], ['ca' => 'sectigo']);
    $api = Mockery::mock(Api::class);
    $api->shouldReceive('updateDCV')->once()->with($order->id, 'txt')->andReturn([
        'code' => 1,
        'data' => [
            'dcv' => [
                'method' => 'txt',
                'dns' => ['host' => '_dnsauth.example.test', 'value' => 'delegated-token'],
            ],
        ],
    ]);
    injectOrderMutationApi($this->orderMutationAction, $api);

    orderMutationSuccess(fn () => $this->orderMutationAction->updateDCV($order->id, 'delegation'));

    $fresh = $cert->fresh();
    expect($fresh->dcv['is_delegate'])->toBeTrue()
        ->and($fresh->dcv['ca'])->toBe('sectigo')
        ->and($fresh->validation[0]['is_delegate'])->toBeTrue()
        ->and(Task::where('order_id', $order->id)->where('action', 'delegation')->count())->toBe(1);
});

test('updateDCV unpaid 和 pending 都精确写入本地验证数据且不调上游', function (string $status) {
    [$order, $cert] = orderMutationFixture($status, [], [
        'alternative_names' => 'example.test',
        'csr' => 'unused-csr',
    ]);
    $api = Mockery::mock(Api::class);
    $api->shouldNotReceive('updateDCV');
    injectOrderMutationApi($this->orderMutationAction, $api);

    $response = orderMutationSuccess(fn () => $this->orderMutationAction->updateDCV($order->id, 'txt'));

    expect($response['data'])->toBe([
        'dcv' => ['method' => 'txt'],
        'validation' => [['domain' => 'example.test', 'method' => 'txt']],
    ])->and($cert->fresh()->dcv)->toBe(['method' => 'txt'])
        ->and($cert->fresh()->validation)->toBe([['domain' => 'example.test', 'method' => 'txt']]);
})->with(['unpaid', 'pending']);

test('updateDCV 不能跳过通配符与文件验证的兼容性检查', function () {
    [$order, $cert] = orderMutationFixture('unpaid', [], [
        'alternative_names' => '*.example.test',
        'csr' => 'unused-csr',
        'dcv' => null,
        'validation' => null,
    ]);

    orderMutationError(
        fn () => $this->orderMutationAction->updateDCV($order->id, 'file'),
        '通配符域名 *.example.test 不能使用文件验证方法',
    );
    expect($cert->fresh()->dcv)->toBeNull();
});

test('commit 为 SMIME OV 重签精确发送前驱和主体资料并返回完整结果', function () {
    [$order, $cert, $product] = orderMutationFixture('pending', [
        'period' => 24,
        'plus' => 1,
        'contact' => ['email' => 'contact@example.test'],
        'organization' => ['name' => 'Mutation Org', 'country' => 'CN'],
    ], [
        'action' => 'reissue',
        'email' => 'smime@example.test',
        'csr' => 'mutation-csr',
        'dcv' => ['method' => 'delegation', 'is_delegate' => true, 'ca' => 'sectigo'],
        'validation' => [[
            'domain' => 'new.example.test',
            'method' => 'dns',
            'verified' => 1,
            'delegation_id' => 123,
        ]],
    ], [
        'product_type' => Product::TYPE_SMIME,
        'validation_type' => 'ov',
        'api_id' => 'smime-product-code',
        'source' => 'mutation-source',
    ]);
    $lastCert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'reissued',
        'api_id' => 'last-upstream-id',
        'cert' => 'last-certificate',
        'alternative_names' => 'last.example.test',
    ]);
    $cert->update(['last_cert_id' => $lastCert->id]);
    $api = Mockery::mock(Api::class);
    $api->shouldReceive('reissue')->once()->with(Mockery::on(function (array $data) use ($order, $product) {
        expect($data['product_api_id'])->toBe($product->api_id)
            ->and($data['source'])->toBe('mutation-source')
            ->and($data['product_type'])->toBe(Product::TYPE_SMIME)
            ->and($data['period'])->toBe(24)
            ->and($data['plus'])->toBe(1)
            ->and($data['contact'])->toBe($order->contact)
            ->and($data['organization'])->toBe($order->organization)
            ->and($data['email'])->toBe('smime@example.test')
            ->and($data['csr'])->toBe('mutation-csr')
            ->and($data['last_api_id'])->toBe('last-upstream-id')
            ->and($data['last_cert'])->toBe('last-certificate')
            ->and($data['last_alternative_names'])->toBe('last.example.test');

        return true;
    }))->andReturn([
        'code' => 1,
        'data' => [
            'api_id' => 'new-upstream-id',
            'dcv' => ['method' => 'dns'],
            'validation' => [['domain' => 'new.example.test', 'status' => 'pending']],
        ],
    ]);
    injectOrderMutationApi($this->orderMutationAction, $api);

    $response = orderMutationSuccess(fn () => $this->orderMutationAction->commit($order->id));

    $cert->refresh();
    expect($response['data'])->toBe([
        'order_id' => $order->id,
        'cert_apply_status' => 0,
        'dcv' => ['method' => 'dns', 'is_delegate' => true, 'ca' => 'sectigo'],
        'validation' => [
            [
                'domain' => 'new.example.test',
                'status' => 'pending',
                'method' => 'dns',
                'verified' => 1,
                'delegation_id' => 123,
            ],
        ],
    ])->and($cert->api_id)->toBe('new-upstream-id')
        ->and($cert->cert_apply_status)->toBe(0)
        ->and($cert->status)->toBe('processing');
});

test('commit 为 SSL DV 新单不会泄露 SMIME 组织或前驱字段', function () {
    [$order] = orderMutationFixture('pending', [
        'contact' => ['email' => 'contact@example.test'],
        'organization' => ['name' => 'Should Not Be Sent'],
    ], [
        'action' => 'new',
        'email' => 'should-not-send@example.test',
    ]);
    $api = Mockery::mock(Api::class);
    $api->shouldReceive('new')->once()->with(Mockery::on(function (array $data) {
        expect($data['email'])->toBe('should-not-send@example.test')
            ->and(array_key_exists('organization', $data))->toBeFalse()
            ->and(array_key_exists('last_api_id', $data))->toBeFalse()
            ->and(array_key_exists('last_cert', $data))->toBeFalse()
            ->and(array_key_exists('last_alternative_names', $data))->toBeFalse();

        return true;
    }))->andReturn(['code' => 1, 'data' => ['api_id' => 'ssl-upstream-id']]);
    injectOrderMutationApi($this->orderMutationAction, $api);

    $response = orderMutationSuccess(fn () => $this->orderMutationAction->commit($order->id));

    expect($response['data'])->toBe([
        'order_id' => $order->id,
        'cert_apply_status' => 0,
        'dcv' => [['domain' => 'example.com', 'method' => 'txt']],
        'validation' => [],
    ]);
});

test('commit 上游失败精确保留消息和结构化错误', function () {
    [$order, $cert] = orderMutationFixture('pending', [], ['action' => 'new']);
    $api = Mockery::mock(Api::class);
    $api->shouldReceive('new')->once()->andReturn([
        'code' => 0,
        'msg' => '上游拒绝提交',
        'errors' => ['domain' => ['域名未通过校验']],
    ]);
    injectOrderMutationApi($this->orderMutationAction, $api);

    $response = orderMutationError(
        fn () => $this->orderMutationAction->commit($order->id),
        '上游拒绝提交',
    );

    expect($response['errors'])->toBe(['domain' => ['域名未通过校验']])
        ->and($cert->fresh()->status)->toBe('pending');
});

test('pay 批量上限使用严格大于语义', function () {
    config()->set('batch.max_upstream', 1);

    orderMutationError(
        fn () => $this->orderMutationAction->pay([PHP_INT_MAX], false),
        '订单或相关数据不存在',
    );
    orderMutationError(
        fn () => $this->orderMutationAction->pay([PHP_INT_MAX - 1, PHP_INT_MAX], false),
        '订单数量不能超过1',
    );
});

test('markRenewed 只允许精确的到期前三十天窗口', function (bool $insideWindow) {
    Carbon::setTestNow('2026-07-31 12:00:00');
    [$order, $cert] = orderMutationFixture('active', [
        'period_till' => now()->addDays(30)->addSecond($insideWindow ? 0 : 1),
    ]);

    if ($insideWindow) {
        orderMutationSuccess(fn () => $this->orderMutationAction->markRenewed($order->id));
        expect($cert->fresh()->status)->toBe('renewed');
    } else {
        orderMutationError(
            fn () => $this->orderMutationAction->markRenewed($order->id),
            '仅订单到期前 30 天内且未过期可标记为已续费',
        );
        expect($cert->fresh()->status)->toBe('active');
    }
})->with([
    'exact boundary' => [true],
    'one second outside' => [false],
]);

test('cancel 退款期在精确边界内允许而早一秒拒绝', function (bool $insideWindow) {
    Carbon::setTestNow('2026-07-31 12:00:00');
    [$order, $cert, $product, $user] = orderMutationFixture('cancelling', [], [
        'amount' => '123.45',
        'action' => 'new',
    ], [
        'refund_period' => 30,
    ]);
    Transaction::create([
        'user_id' => $user->id,
        'type' => 'order',
        'transaction_id' => $order->id,
        'amount' => '-123.45',
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]);
    $api = Mockery::mock(Api::class);
    if ($insideWindow) {
        $api->shouldReceive('cancel')->once()->with($order->id)->andReturn(['code' => 1]);
    } else {
        $api->shouldNotReceive('cancel');
    }
    injectOrderMutationApi($this->orderMutationAction, $api);
    Order::where('id', $order->id)->update([
        'created_at' => now()->subDays(30)->subSecond($insideWindow ? 0 : 1),
    ]);

    if ($insideWindow) {
        orderMutationSuccess(fn () => $this->orderMutationAction->cancel($order->id));
        expect($cert->fresh()->status)->toBe('cancelled')
            ->and(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->count())->toBe(1)
            ->and((string) $user->fresh()->balance)->toBe('0.00');
    } else {
        orderMutationError(
            fn () => $this->orderMutationAction->cancel($order->id),
            '订单已超过'.$product->refund_period.'天',
        );
        expect($cert->fresh()->status)->toBe('cancelling')
            ->and(Transaction::where('type', 'cancel')->where('transaction_id', $order->id)->exists())->toBeFalse();
    }
})->with([
    'exact boundary' => [true],
    'one second outside' => [false],
]);

test('commitCancel 退款期在精确边界内创建取消任务而早一秒拒绝', function (bool $insideWindow) {
    Carbon::setTestNow('2026-07-31 12:00:00');
    [$order, $cert, $product] = orderMutationFixture('active', [], [], [
        'refund_period' => 30,
    ]);
    Order::where('id', $order->id)->update([
        'created_at' => now()->subDays(30)->subSecond($insideWindow ? 0 : 1),
    ]);

    if ($insideWindow) {
        orderMutationSuccess(fn () => $this->orderMutationAction->commitCancel($order->id));
        expect($cert->fresh()->status)->toBe('cancelling')
            ->and(Task::where('order_id', $order->id)->where('action', 'cancel')->sole()->status)->toBe('executing');
    } else {
        orderMutationError(
            fn () => $this->orderMutationAction->commitCancel($order->id),
            '订单已超过 '.$product->refund_period.' 天不能取消',
        );
        expect($cert->fresh()->status)->toBe('active')
            ->and(Task::where('order_id', $order->id)->exists())->toBeFalse();
    }
})->with([
    'exact boundary' => [true],
    'one second outside' => [false],
]);
