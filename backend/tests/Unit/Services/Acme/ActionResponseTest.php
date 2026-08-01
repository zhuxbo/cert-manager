<?php

use App\Exceptions\ApiResponseException;
use App\Models\Acme;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Acme\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('database');

beforeEach(function () {
    Cache::flush();
    $this->acmeAction = app(Action::class);

    $group = SettingGroup::firstOrCreate(
        ['name' => 'ca'],
        ['title' => '证书接口', 'weight' => 2],
    );
    foreach (['url' => 'https://mutation-gateway.test/api/v2', 'token' => 'mutation-token'] as $key => $value) {
        Setting::updateOrCreate(
            ['group_id' => $group->id, 'key' => $key],
            ['type' => 'string', 'value' => $value, 'weight' => 0],
        );
    }
    Setting::clearGroupCache($group->id);
});

afterEach(function () {
    Carbon::setTestNow();
});

function acmeMutationSuccess(Closure $callback): array
{
    try {
        $callback();
        test()->fail('期望 Action 返回成功响应');
    } catch (ApiResponseException $e) {
        $response = $e->getApiResponse();
        expect($response['code'])->toBe(1);

        return $response;
    }

    return [];
}

function acmeMutationOrder(string $status = Acme::STATUS_PENDING, array $attributes = []): Acme
{
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
        'ca' => 'SeCTigo',
        'periods' => [12],
    ]);

    return Acme::factory()->create(array_merge([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'status' => $status,
        'api_id' => 'upstream-mutation-id',
        'contact_email' => 'local@example.test',
    ], $attributes));
}

test('new 精确持久化标准域名和通配符产品的完整订单字段', function (
    int $standardMax,
    int $wildcardMax,
    int $expectedStandard,
    int $expectedWildcard,
    int $plus,
    string $expectedAmount,
) {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
        'brand' => 'mutation-brand',
        'ca' => 'sectigo',
        'periods' => [12, 24],
        'standard_max' => $standardMax,
        'wildcard_max' => $wildcardMax,
    ]);
    ProductPrice::create([
        'product_id' => $product->id,
        'level_code' => $user->level_code,
        'period' => 12,
        'price' => '88.00',
        'alternative_standard_price' => '10.00',
        'alternative_wildcard_price' => '20.00',
    ]);

    $response = acmeMutationSuccess(fn () => $this->acmeAction->new([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'period' => 12,
        'plus' => $plus,
        'refer_id' => 'refer-new-'.$standardMax.'-'.$wildcardMax,
        'contact_email' => 'new-account@example.test',
        'channel' => 'api',
        'remark' => 'exact mutation order',
    ]));

    $acme = Acme::findOrFail($response['data']['order_id']);
    expect($acme->only([
        'user_id',
        'product_id',
        'brand',
        'period',
        'plus',
        'purchased_standard_count',
        'purchased_wildcard_count',
        'refer_id',
        'contact_email',
        'status',
        'channel',
        'remark',
    ]))->toBe([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'brand' => 'mutation-brand',
        'period' => 12,
        'plus' => $plus,
        'purchased_standard_count' => $expectedStandard,
        'purchased_wildcard_count' => $expectedWildcard,
        'refer_id' => 'refer-new-'.$standardMax.'-'.$wildcardMax,
        'contact_email' => 'new-account@example.test',
        'status' => Acme::STATUS_UNPAID,
        'channel' => 'api',
        'remark' => 'exact mutation order',
    ])->and((string) $acme->amount)->toBe($expectedAmount);
})->with([
    'standard account' => [1, 0, 1, 0, 0, '98.00'],
    'wildcard account' => [0, 1, 0, 1, 1, '108.00'],
]);

test('sync 接受的每个上游状态都会从非终态精确写回', function (string $upstreamStatus) {
    $acme = acmeMutationOrder();
    Http::fake([
        'mutation-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'status' => $upstreamStatus,
                'directory_url' => 'https://sync-status.example.test/directory',
            ],
        ]),
    ]);

    $this->acmeAction->sync($acme->id, true);

    expect($acme->fresh()->status)->toBe($upstreamStatus)
        ->and(Cache::get('acme_directory_url:sectigo'))->toBe('https://sync-status.example.test/directory');
})->with([
    Acme::STATUS_ACTIVE,
    Acme::STATUS_REVOKED,
    Acme::STATUS_EXPIRED,
    Acme::STATUS_CANCELLED,
]);

test('sync 不会复活任一终态但仍精确更新非状态字段', function (string $localStatus) {
    $acme = acmeMutationOrder($localStatus, [
        'vendor_id' => 'vendor-old',
        'contact_email' => 'keep@example.test',
        'period_from' => '2025-01-01 00:00:00',
        'period_till' => '2025-12-31 00:00:00',
    ]);
    Http::fake([
        'mutation-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'status' => Acme::STATUS_ACTIVE,
                'vendor_id' => 'vendor-new',
                'contact_email' => '',
                'period_from' => '2026-02-03 04:05:06',
                'period_till' => '2027-03-04 05:06:07',
            ],
        ]),
    ]);

    $this->acmeAction->sync($acme->id, true);

    $acme->refresh();
    expect($acme->status)->toBe($localStatus)
        ->and($acme->vendor_id)->toBe('vendor-new')
        ->and($acme->contact_email)->toBe('keep@example.test')
        ->and($acme->period_from->toDateTimeString())->toBe('2026-02-03 04:05:06')
        ->and($acme->period_till->toDateTimeString())->toBe('2027-03-04 05:06:07');
})->with([
    Acme::STATUS_CANCELLED,
    Acme::STATUS_REVOKED,
    Acme::STATUS_EXPIRED,
]);

test('sync 完成取消时删除执行中和停止任务并保留既有取消时间', function () {
    Carbon::setTestNow('2026-07-31 12:00:00');
    $acme = acmeMutationOrder(Acme::STATUS_CANCELLING, [
        'amount' => '100.00',
        'cancelled_at' => '2026-07-30 08:00:00',
    ]);
    DB::transaction(fn () => Transaction::create([
        'user_id' => $acme->user_id,
        'type' => Transaction::TYPE_ACME_ORDER,
        'transaction_id' => $acme->id,
        'amount' => '-100.00',
        'standard_count' => 1,
        'wildcard_count' => 0,
    ]));
    foreach (['executing', 'stopped', 'failed'] as $status) {
        Task::factory()->create([
            'order_id' => $acme->id,
            'action' => 'cancel_acme',
            'status' => $status,
        ]);
    }
    Http::fake([
        'mutation-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'status' => Acme::STATUS_CANCELLED,
                'directory_url' => 'https://sync-cancel.example.test/directory',
            ],
        ]),
    ]);

    $this->acmeAction->sync($acme->id, true);

    $acme->refresh();
    expect($acme->status)->toBe(Acme::STATUS_CANCELLED)
        ->and($acme->cancelled_at->toDateTimeString())->toBe('2026-07-30 08:00:00')
        ->and(Task::where('order_id', $acme->id)->where('action', 'cancel_acme')->pluck('status')->all())->toBe(['failed'])
        ->and(Transaction::where('type', Transaction::TYPE_ACME_CANCEL)->where('transaction_id', $acme->id)->count())->toBe(1)
        ->and((string) $acme->user->fresh()->balance)->toBe('0.00')
        ->and(Cache::get('acme_directory_url:sectigo'))->toBe('https://sync-cancel.example.test/directory');
});

test('sync 防抖窗口在第十秒精确过期', function () {
    Carbon::setTestNow('2026-07-31 12:00:00');
    $acme = acmeMutationOrder(Acme::STATUS_ACTIVE);
    Http::fake([
        'mutation-gateway.test/*' => Http::sequence()
            ->push(['code' => 1, 'data' => ['status' => 'active', 'vendor_id' => 'first']])
            ->push(['code' => 1, 'data' => ['status' => 'active', 'vendor_id' => 'second']]),
    ]);

    $this->acmeAction->sync($acme->id, true);
    $this->travel(9)->seconds();
    $this->acmeAction->sync($acme->id, true);

    expect($acme->fresh()->vendor_id)->toBe('first');
    Http::assertSentCount(1);

    $this->travel(1)->second();
    $this->acmeAction->sync($acme->id, true);

    expect($acme->fresh()->vendor_id)->toBe('second');
    Http::assertSentCount(2);
});

test('commit 精确发送并保存完整上游响应', function () {
    Carbon::setTestNow('2026-07-31 12:00:00');
    $acme = acmeMutationOrder(Acme::STATUS_PENDING, [
        'period' => 12,
        'plus' => 0,
        'refer_id' => 'refer-mutation-123',
        'contact_email' => 'request@example.test',
    ]);
    Http::fake([
        'mutation-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'order_id' => 'gateway-order-123',
                'vendor_id' => 'vendor-123',
                'contact_email' => 'authoritative@example.test',
                'eab_kid' => 'kid-123',
                'eab_hmac' => 'hmac-123',
                'period_from' => '2026-08-01 01:02:03',
                'period_till' => '2027-08-01 01:02:03',
                'directory_url' => 'https://acme.example.test/directory',
            ],
        ]),
    ]);

    $response = acmeMutationSuccess(fn () => $this->acmeAction->commit($acme->id));

    Http::assertSent(fn ($request) => $request->data() === [
        'contact_email' => 'request@example.test',
        'product_code' => $acme->product->code,
        'period' => 12,
        'plus' => 0,
        'refer_id' => 'refer-mutation-123',
    ]);
    Http::assertSentCount(1);
    $acme->refresh();
    expect($response['data'])->toBe([
        'order_id' => $acme->id,
        'eab_kid' => 'kid-123',
        'eab_hmac' => 'hmac-123',
        'directory_url' => 'https://acme.example.test/directory',
    ])->and($acme->only([
        'api_id',
        'vendor_id',
        'contact_email',
        'eab_kid',
        'eab_hmac',
        'status',
    ]))->toBe([
        'api_id' => 'gateway-order-123',
        'vendor_id' => 'vendor-123',
        'contact_email' => 'authoritative@example.test',
        'eab_kid' => 'kid-123',
        'eab_hmac' => 'hmac-123',
        'status' => Acme::STATUS_ACTIVE,
    ])->and($acme->period_from->toDateTimeString())->toBe('2026-08-01 01:02:03')
        ->and($acme->period_till->toDateTimeString())->toBe('2027-08-01 01:02:03')
        ->and(Cache::get('acme_directory_url:sectigo'))->toBe('https://acme.example.test/directory');
});

test('pay 自动提交精确返回账号凭据并记录扣费明细', function () {
    Queue::fake();
    $acme = acmeMutationOrder(Acme::STATUS_UNPAID, [
        'amount' => '12.34',
        'purchased_standard_count' => 7,
        'purchased_wildcard_count' => 3,
        'eab_kid' => null,
        'eab_hmac' => null,
    ]);
    $acme->user->update(['balance' => '100.00']);
    Http::fake([
        'mutation-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'order_id' => 'pay-upstream-id',
                'eab_kid' => 'pay-kid',
                'eab_hmac' => 'pay-hmac',
                'directory_url' => 'https://pay.example.test/directory',
            ],
        ]),
    ]);

    $response = acmeMutationSuccess(fn () => $this->acmeAction->pay($acme->id));

    $transaction = Transaction::where('type', Transaction::TYPE_ACME_ORDER)
        ->where('transaction_id', $acme->id)
        ->sole();
    expect($response['data'])->toBe([
        'order_id' => $acme->id,
        'eab_kid' => 'pay-kid',
        'eab_hmac' => 'pay-hmac',
        'directory_url' => 'https://pay.example.test/directory',
    ])->and($transaction->only(['user_id', 'type', 'transaction_id', 'standard_count', 'wildcard_count']))->toBe([
        'user_id' => $acme->user_id,
        'type' => Transaction::TYPE_ACME_ORDER,
        'transaction_id' => $acme->id,
        'standard_count' => 7,
        'wildcard_count' => 3,
    ])->and((string) $transaction->amount)->toBe('-12.34')
        ->and((string) $acme->user->fresh()->balance)->toBe('87.66')
        ->and($acme->fresh()->status)->toBe(Acme::STATUS_ACTIVE);
});

test('pay 在分位信用额度边界精确拒绝且不产生交易', function () {
    $acme = acmeMutationOrder(Acme::STATUS_UNPAID, ['amount' => '0.02']);
    $acme->user->update(['balance' => '0.00', 'credit_limit' => '0.01']);

    try {
        $this->acmeAction->pay($acme->id, false);
        test()->fail('应在信用额度以下拒绝支付');
    } catch (ApiResponseException $e) {
        expect($e->getApiResponse())->toMatchArray(['code' => 0, 'msg' => '余额不足']);
    }

    expect($acme->fresh()->status)->toBe(Acme::STATUS_UNPAID)
        ->and(Transaction::where('transaction_id', $acme->id)->exists())->toBeFalse();
});

test('batchPay 排除已有提交任务并精确汇总新建任务', function () {
    Queue::fake();
    $first = acmeMutationOrder(Acme::STATUS_UNPAID, ['amount' => '10.00']);
    $second = acmeMutationOrder(Acme::STATUS_UNPAID, ['amount' => '20.00']);
    $first->user->update(['balance' => '100.00']);
    $second->user->update(['balance' => '100.00']);
    Task::factory()->create([
        'order_id' => $first->id,
        'action' => 'commit_acme',
        'status' => 'executing',
        'started_at' => now(),
    ]);

    $response = acmeMutationSuccess(fn () => $this->acmeAction->batchPay([$first->id, $second->id]));

    expect($response['data'])->toBe([
        'success_count' => 2,
        'commit_count' => 1,
        'errors' => [],
    ])->and(Task::where('order_id', $first->id)->where('action', 'commit_acme')->count())->toBe(1)
        ->and(Task::where('order_id', $second->id)->where('action', 'commit_acme')->count())->toBe(1)
        ->and(Task::where('order_id', $second->id)->where('action', 'commit_acme')->sole()->only([
            'order_id',
            'action',
            'status',
        ]))->toBe([
            'order_id' => $second->id,
            'action' => 'commit_acme',
            'status' => 'executing',
        ]);
});

test('batchPay 精确汇总单条余额不足错误且继续提交成功订单', function () {
    Queue::fake();
    $successful = acmeMutationOrder(Acme::STATUS_UNPAID, ['amount' => '10.00']);
    $failed = acmeMutationOrder(Acme::STATUS_UNPAID, ['amount' => '20.00']);
    $successful->user->update(['balance' => '100.00']);
    $failed->user->update(['balance' => '0.00', 'credit_limit' => '0.00']);

    $response = acmeMutationSuccess(fn () => $this->acmeAction->batchPay([
        $successful->id,
        $failed->id,
    ]));

    expect($response['data'])->toBe([
        'success_count' => 1,
        'commit_count' => 1,
        'errors' => [['id' => $failed->id, 'msg' => '余额不足']],
    ])->and($successful->fresh()->status)->toBe(Acme::STATUS_PENDING)
        ->and($failed->fresh()->status)->toBe(Acme::STATUS_UNPAID)
        ->and(Task::where('order_id', $successful->id)->where('action', 'commit_acme')->count())->toBe(1)
        ->and(Task::where('order_id', $failed->id)->exists())->toBeFalse();
});

test('batchCommitCancel 精确汇总直接取消和延时取消', function () {
    Queue::fake();
    Carbon::setTestNow('2026-07-31 16:00:00');
    $unpaid = acmeMutationOrder(Acme::STATUS_UNPAID);
    $active = acmeMutationOrder(Acme::STATUS_ACTIVE);

    $response = acmeMutationSuccess(fn () => $this->acmeAction->batchCommitCancel([
        $unpaid->id,
        $active->id,
    ]));

    expect($response['data'])->toBe(['success_count' => 2, 'errors' => []])
        ->and($unpaid->fresh()->status)->toBe(Acme::STATUS_CANCELLED)
        ->and($unpaid->fresh()->cancelled_at->toDateTimeString())->toBe('2026-07-31 16:00:00')
        ->and($active->fresh()->status)->toBe(Acme::STATUS_CANCELLING)
        ->and(Task::where('order_id', $active->id)->where('action', 'cancel_acme')->sole()->only([
            'order_id',
            'action',
            'status',
        ]))->toBe([
            'order_id' => $active->id,
            'action' => 'cancel_acme',
            'status' => 'executing',
        ]);
});

test('batchRevokeCancel 精确汇总并清理每个取消任务', function () {
    $first = acmeMutationOrder(Acme::STATUS_CANCELLING);
    $second = acmeMutationOrder(Acme::STATUS_CANCELLING);
    foreach ([$first, $second] as $acme) {
        foreach (['executing', 'stopped', 'failed'] as $status) {
            Task::factory()->create([
                'order_id' => $acme->id,
                'action' => 'cancel_acme',
                'status' => $status,
            ]);
        }
    }

    $response = acmeMutationSuccess(fn () => $this->acmeAction->batchRevokeCancel([
        $first->id,
        $second->id,
    ]));

    expect($response['data'])->toBe(['success_count' => 2, 'errors' => []])
        ->and($first->fresh()->status)->toBe(Acme::STATUS_ACTIVE)
        ->and($second->fresh()->status)->toBe(Acme::STATUS_ACTIVE)
        ->and(Task::whereIn('order_id', [$first->id, $second->id])->orderBy('order_id')->pluck('status')->all())
        ->toBe(['failed', 'failed']);
});
