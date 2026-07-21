<?php

use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\User;
use App\Services\Order\Action;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

// 辅助函数：设置回调端点配置
function setupCallbackEndpoint(string $endpoint, array $config): void
{
    $group = SettingGroup::firstOrCreate(
        ['name' => 'callback'],
        ['title' => '回调设置']
    );
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => $endpoint],
        ['type' => 'array', 'value' => $config]
    );
}

// 辅助函数：创建订单及证书数据
function createCallbackTestOrder(array $productOverrides = [], array $certOverrides = []): array
{
    $user = User::factory()->create();
    $product = Product::factory()->create($productOverrides);
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $cert = Cert::factory()->create(array_merge(['order_id' => $order->id], $certOverrides));
    $order->update(['latest_cert_id' => $cert->id]);

    return ['user' => $user, 'product' => $product, 'order' => $order, 'cert' => $cert];
}

function createCallbackDatabaseException(int $errorCode, string $message): QueryException
{
    $pdo = new PDOException("SQLSTATE[HY000]: $errorCode $message");
    $pdo->errorInfo = ['HY000', $errorCode, $message];

    return new QueryException('mysql', 'insert into `tasks` ...', [], $pdo);
}

// ==========================================
// 鉴权前置门槛（A3）
//
// 业务规则：回调端点要求至少配置 token 或 allowed_ips 之一，否则直接拒绝（防出厂双空裸奔
// 被刷 sync / 探测 api_id）。因此下方多数用例配 allowed_ips='127.0.0.1'（测试默认 IP 即此值，
// 通过 IP 白名单），以聚焦各自待测逻辑（token 校验 / sources / 状态过滤等）。
// ==========================================

test('token 与 allowed_ips 均未配置时拒绝回调', function () {
    setupCallbackEndpoint('certum', [
        'sources' => '',
        'token' => '',
        'id_field' => 'id',
        'allowed_ips' => '',
    ]);

    // 双空配置：不调用上游、直接拒绝
    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->never();
    app()->instance(Action::class, $mockAction);

    $this->postJson('/callback/certum', [
        'id' => 'any-api-id',
    ])->assertOk()
        ->assertJson(['code' => 0, 'msg' => '回调未配置鉴权']);
});

test('仅配置 token（allowed_ips 空）时按 token 校验放行', function () {
    setupCallbackEndpoint('certum', [
        'sources' => '',
        'token' => 'only-token',
        'id_field' => 'id',
        'allowed_ips' => '',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->once();
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        [],
        ['api_id' => 'only-token-api', 'status' => 'processing'],
    );

    $this->postJson('/callback/certum', [
        'id' => 'only-token-api',
        'token' => 'only-token',
    ])->assertOk()
        ->assertJson(['code' => 1]);
});

test('仅配置 allowed_ips（token 空）时按 IP 白名单放行', function () {
    setupCallbackEndpoint('certum', [
        'sources' => '',
        'token' => '',
        'id_field' => 'id',
        'allowed_ips' => '127.0.0.1',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->once();
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        [],
        ['api_id' => 'only-ip-api', 'status' => 'processing'],
    );

    $this->postJson('/callback/certum', [
        'id' => 'only-ip-api',
    ])->assertOk()
        ->assertJson(['code' => 1]);
});

// ==========================================
// 基础流程
// ==========================================

test('端点配置存在时正常流程', function () {
    setupCallbackEndpoint('certum', [
        'sources' => 'certum',
        'token' => 'test-token',
        'id_field' => 'orderId',
        'allowed_ips' => '',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->once();
    app()->instance(Action::class, $mockAction);

    $data = createCallbackTestOrder(
        ['source' => 'certum'],
        ['api_id' => 'certum-api-123', 'status' => 'processing'],
    );

    $this->postJson('/callback/certum', [
        'orderId' => 'certum-api-123',
        'token' => 'test-token',
    ])->assertOk()
        ->assertJson(['code' => 1]);
});

test('端点不存在时回落到 default', function () {
    setupCallbackEndpoint('default', [
        'sources' => '',
        'token' => 'default-token',
        'id_field' => 'id',
        'allowed_ips' => '',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->once();
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        [],
        ['api_id' => 'fallback-api-123', 'status' => 'processing'],
    );

    $this->postJson('/callback/unknown', [
        'id' => 'fallback-api-123',
        'token' => 'default-token',
    ])->assertOk()
        ->assertJson(['code' => 1]);
});

test('端点不存在且无 default 返回错误', function () {
    $this->postJson('/callback/nonexistent', [
        'id' => 'test',
    ])->assertOk()
        ->assertJson(['code' => 0]);
});

test('endpoint 为空时使用 default', function () {
    setupCallbackEndpoint('default', [
        'sources' => '',
        'token' => '',
        'id_field' => 'id',
        'allowed_ips' => '127.0.0.1',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->once();
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        [],
        ['api_id' => 'default-api-123', 'status' => 'processing'],
    );

    $this->postJson('/callback', [
        'id' => 'default-api-123',
    ])->assertOk()
        ->assertJson(['code' => 1]);
});

// ==========================================
// Token 校验
// ==========================================

test('token 非空但缺少 token 参数返回错误', function () {
    setupCallbackEndpoint('certum', [
        'sources' => 'certum',
        'token' => 'required-token',
        'id_field' => 'id',
        'allowed_ips' => '',
    ]);

    $this->postJson('/callback/certum', [
        'id' => 'test-api-id',
    ])->assertOk()
        ->assertJson(['code' => 0]);
});

test('token 非空但传入错误 token 返回错误', function () {
    setupCallbackEndpoint('certum', [
        'sources' => 'certum',
        'token' => 'correct-token',
        'id_field' => 'id',
        'allowed_ips' => '',
    ]);

    // 断言 msg 区分 token 校验拒绝（非 A3 双空拒绝）——token 非空 → A3 必放行，code:0 只能来自 token 校验
    $this->postJson('/callback/certum', [
        'id' => 'test-api-id',
        'token' => 'wrong-token',
    ])->assertOk()
        ->assertJson(['code' => 0, 'msg' => 'Invalid token']);
});

test('token 为空时跳过 token 校验（配 IP 白名单放行）', function () {
    // 配 IP 过鉴权门槛；token 为空 → 跳过 token 校验仍放行
    setupCallbackEndpoint('certum', [
        'sources' => '',
        'token' => '',
        'id_field' => 'id',
        'allowed_ips' => '127.0.0.1',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->once();
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        [],
        ['api_id' => 'no-token-api', 'status' => 'processing'],
    );

    $this->postJson('/callback/certum', [
        'id' => 'no-token-api',
    ])->assertOk()
        ->assertJson(['code' => 1]);
});

test('支持 password 参数作为 token', function () {
    setupCallbackEndpoint('certum', [
        'sources' => '',
        'token' => 'pw-token',
        'id_field' => 'id',
        'allowed_ips' => '',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->once();
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        [],
        ['api_id' => 'pw-api-123', 'status' => 'processing'],
    );

    $this->postJson('/callback/certum', [
        'id' => 'pw-api-123',
        'password' => 'pw-token',
    ])->assertOk()
        ->assertJson(['code' => 1]);
});

test('支持 TOKEN 服务器变量作为 token', function () {
    setupCallbackEndpoint('certum', [
        'sources' => '',
        'token' => 'server-token',
        'id_field' => 'id',
        'allowed_ips' => '',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->once();
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        [],
        ['api_id' => 'server-token-api', 'status' => 'processing'],
    );

    $this->withServerVariables(['TOKEN' => 'server-token'])
        ->postJson('/callback/certum', [
            'id' => 'server-token-api',
        ])->assertOk()
        ->assertJson(['code' => 1]);
});

// ==========================================
// IP 白名单
// ==========================================

test('allowed_ips 非空且 IP 不在列表中返回错误', function () {
    setupCallbackEndpoint('certum', [
        'sources' => '',
        'token' => '',
        'id_field' => 'id',
        'allowed_ips' => '10.0.0.1,10.0.0.2',
    ]);

    // 断言 msg 区分 IP 校验拒绝（非 A3 双空拒绝）——allowed_ips 非空 → A3 必放行，code:0 只能来自 IP 校验
    $this->postJson('/callback/certum', [
        'id' => 'test',
    ])->assertOk()
        ->assertJson(['code' => 0, 'msg' => 'IP not allowed']);
});

test('多 IP 逗号分隔且 IP 在列表中通过', function () {
    setupCallbackEndpoint('certum', [
        'sources' => '',
        'token' => '',
        'id_field' => 'id',
        'allowed_ips' => '1.2.3.4, 127.0.0.1',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->once();
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        [],
        ['api_id' => 'ip-ok-api', 'status' => 'processing'],
    );

    $this->postJson('/callback/certum', [
        'id' => 'ip-ok-api',
    ])->assertOk()
        ->assertJson(['code' => 1]);
});

test('allowed_ips 为空时跳过 IP 校验（配 token 放行）', function () {
    // 配 token 过鉴权门槛；allowed_ips 为空 → 跳过 IP 校验仍放行
    setupCallbackEndpoint('certum', [
        'sources' => '',
        'token' => 'ip-skip-token',
        'id_field' => 'id',
        'allowed_ips' => '',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->once();
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        [],
        ['api_id' => 'no-ip-check-api', 'status' => 'processing'],
    );

    $this->postJson('/callback/certum', [
        'id' => 'no-ip-check-api',
        'token' => 'ip-skip-token',
    ])->assertOk()
        ->assertJson(['code' => 1]);
});

// ==========================================
// id_field
// ==========================================

test('配置 id_field 从指定参数取值', function () {
    setupCallbackEndpoint('certum', [
        'sources' => '',
        'token' => '',
        'id_field' => 'orderId',
        'allowed_ips' => '127.0.0.1',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->once();
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        [],
        ['api_id' => 'custom-field-api', 'status' => 'processing'],
    );

    $this->postJson('/callback/certum', [
        'orderId' => 'custom-field-api',
    ])->assertOk()
        ->assertJson(['code' => 1]);
});

test('id_field 为空时默认取 id 参数', function () {
    setupCallbackEndpoint('certum', [
        'sources' => '',
        'token' => '',
        'id_field' => '',
        'allowed_ips' => '127.0.0.1',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->once();
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        [],
        ['api_id' => 'default-id-api', 'status' => 'processing'],
    );

    $this->postJson('/callback/certum', [
        'id' => 'default-id-api',
    ])->assertOk()
        ->assertJson(['code' => 1]);
});

test('ID 值为空返回错误', function () {
    setupCallbackEndpoint('certum', [
        'sources' => '',
        'token' => '',
        'id_field' => 'id',
        'allowed_ips' => '127.0.0.1',
    ]);

    $this->postJson('/callback/certum', [])
        ->assertOk()
        ->assertJson(['code' => 0]);
});

// ==========================================
// sources 限定
// ==========================================

test('sources 限定只查指定来源的订单', function () {
    setupCallbackEndpoint('certum', [
        'sources' => 'certum,certumcnssl',
        'token' => '',
        'id_field' => 'id',
        'allowed_ips' => '127.0.0.1',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->once();
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        ['source' => 'certum'],
        ['api_id' => 'source-match-api', 'status' => 'processing'],
    );

    $this->postJson('/callback/certum', [
        'id' => 'source-match-api',
    ])->assertOk()
        ->assertJson(['code' => 1]);
});

test('其他来源的同 api_id 订单不匹配', function () {
    setupCallbackEndpoint('certum', [
        'sources' => 'certum,certumcnssl',
        'token' => '',
        'id_field' => 'id',
        'allowed_ips' => '127.0.0.1',
    ]);

    createCallbackTestOrder(
        ['source' => 'gogetssl'],
        ['api_id' => 'wrong-source-api', 'status' => 'processing'],
    );

    $this->postJson('/callback/certum', [
        'id' => 'wrong-source-api',
    ])->assertOk()
        ->assertJson(['code' => 0]);
});

test('sources 为空时不限定来源全局查找', function () {
    setupCallbackEndpoint('default', [
        'sources' => '',
        'token' => '',
        'id_field' => 'id',
        'allowed_ips' => '127.0.0.1',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->once();
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        ['source' => 'gogetssl'],
        ['api_id' => 'any-source-api', 'status' => 'processing'],
    );

    $this->postJson('/callback/default', [
        'id' => 'any-source-api',
    ])->assertOk()
        ->assertJson(['code' => 1]);
});

// ==========================================
// 状态过滤
// ==========================================

test('processing 状态创建 sync 任务', function () {
    setupCallbackEndpoint('default', [
        'sources' => '',
        'token' => '',
        'id_field' => 'id',
        'allowed_ips' => '127.0.0.1',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->with(Mockery::any(), 'sync')->once();
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        [],
        ['api_id' => 'processing-api', 'status' => 'processing'],
    );

    $this->postJson('/callback/default', [
        'id' => 'processing-api',
    ])->assertOk()
        ->assertJson(['code' => 1]);
});

test('processing 状态创建 sync 任务遇 1213 时短暂退避后重试成功', function () {
    setupCallbackEndpoint('default', [
        'sources' => '',
        'token' => '',
        'id_field' => 'id',
        'allowed_ips' => '127.0.0.1',
    ]);

    $attempts = 0;
    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')
        ->with(Mockery::any(), 'sync')
        ->times(3)
        ->andReturnUsing(function () use (&$attempts) {
            if (++$attempts < 3) {
                throw createCallbackDatabaseException(1213, 'Deadlock found when trying to get lock; try restarting transaction');
            }
        });
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        [],
        ['api_id' => 'deadlock-retry-api', 'status' => 'processing'],
    );

    Sleep::fake();
    try {
        $this->postJson('/callback/default', [
            'id' => 'deadlock-retry-api',
        ])->assertOk()
            ->assertJson(['code' => 1]);

        Sleep::assertSequence([
            Sleep::for(25)->milliseconds(),
            Sleep::for(75)->milliseconds(),
        ]);
    } finally {
        Sleep::fake(false);
    }
});

test('processing 状态创建 sync 任务遇 1205 时不延长上游回调等待', function () {
    setupCallbackEndpoint('default', [
        'sources' => '',
        'token' => '',
        'id_field' => 'id',
        'allowed_ips' => '127.0.0.1',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')
        ->with(Mockery::any(), 'sync')
        ->once()
        ->andThrow(createCallbackDatabaseException(1205, 'Lock wait timeout exceeded; try restarting transaction'));
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        [],
        ['api_id' => 'lock-wait-no-retry-api', 'status' => 'processing'],
    );

    Sleep::fake();
    try {
        $this->postJson('/callback/default', [
            'id' => 'lock-wait-no-retry-api',
        ])->assertStatus(503)
            ->assertJson(['code' => 0, 'msg' => '系统繁忙，请稍后重试']);

        Sleep::assertNeverSlept();
    } finally {
        Sleep::fake(false);
    }
});

test('active 状态创建 sync 任务', function () {
    setupCallbackEndpoint('default', [
        'sources' => '',
        'token' => '',
        'id_field' => 'id',
        'allowed_ips' => '127.0.0.1',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->with(Mockery::any(), 'sync')->once();
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        [],
        ['api_id' => 'active-api', 'status' => 'active'],
    );

    $this->postJson('/callback/default', [
        'id' => 'active-api',
    ])->assertOk()
        ->assertJson(['code' => 1]);
});

test('approving 状态创建 sync 任务', function () {
    setupCallbackEndpoint('default', [
        'sources' => '',
        'token' => '',
        'id_field' => 'id',
        'allowed_ips' => '127.0.0.1',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->with(Mockery::any(), 'sync')->once();
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        [],
        ['api_id' => 'approving-api', 'status' => 'approving'],
    );

    $this->postJson('/callback/default', [
        'id' => 'approving-api',
    ])->assertOk()
        ->assertJson(['code' => 1]);
});

test('cancelled 状态不创建 sync 任务', function () {
    setupCallbackEndpoint('default', [
        'sources' => '',
        'token' => '',
        'id_field' => 'id',
        'allowed_ips' => '127.0.0.1',
    ]);

    $mockAction = Mockery::mock(Action::class);
    $mockAction->shouldReceive('createTask')->never();
    app()->instance(Action::class, $mockAction);

    createCallbackTestOrder(
        [],
        ['api_id' => 'cancelled-api', 'status' => 'cancelled'],
    );

    $this->postJson('/callback/default', [
        'id' => 'cancelled-api',
    ])->assertOk()
        ->assertJson(['code' => 1]);
});
