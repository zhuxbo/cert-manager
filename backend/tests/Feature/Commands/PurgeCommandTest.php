<?php

use App\Models\AdminLog;
use App\Models\ErrorLog;
use App\Models\Fund;
use App\Models\OrderDocument;
use App\Models\Task;
use App\Models\User;
use App\Models\UserLog;
use App\Services\Order\Action;
use Tests\Traits\CreatesTestData;

test('签名为 schedule:purge', function () {
    $this->artisan('schedule:purge')->assertSuccessful();
});

test('清理超过24小时的未支付充值', function () {
    $user = User::factory()->create();

    // 超过24小时的未支付充值 - 直接插入避免触发模型事件
    Fund::unguard();
    $oldFund = Fund::create([
        'user_id' => $user->id,
        'amount' => '100.00',
        'type' => 'addfunds',
        'pay_method' => 'alipay',
        'ip' => '127.0.0.1',
        'status' => 0,
        'created_at' => now()->subHours(25),
    ]);
    Fund::reguard();

    // 新的未支付充值（不应被清理）
    $newFund = Fund::create([
        'user_id' => $user->id,
        'amount' => '200.00',
        'type' => 'addfunds',
        'pay_method' => 'alipay',
        'pay_sn' => 'PAY'.uniqid(),
        'ip' => '127.0.0.1',
        'status' => 0,
    ]);

    $this->artisan('schedule:purge')->assertSuccessful();

    expect(Fund::find($oldFund->id))->toBeNull();
    expect(Fund::find($newFund->id))->not->toBeNull();
});

test('命令输出包含清理统计', function () {
    $this->artisan('schedule:purge')
        ->expectsOutputToContain('Purged')
        ->assertSuccessful();
});

// --- 文档清理测试 ---

uses(CreatesTestData::class)->in(__DIR__);

test('清理已签发订单的上传文档和文件', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, ['status' => 'active']);

    // 创建测试文件
    $dir = storage_path("app/verification/$order->id");
    is_dir($dir) || mkdir($dir, 0755, true);
    $filePath = "verification/$order->id/test.pdf";
    file_put_contents(storage_path("app/$filePath"), 'test content');

    $doc = OrderDocument::create([
        'order_id' => $order->id,
        'user_id' => $user->id,
        'type' => 'APPLICANT',
        'file_name' => 'test.pdf',
        'file_path' => $filePath,
        'file_size' => 12,
        'uploaded_by' => 'user',
    ]);

    $this->artisan('schedule:purge')->assertSuccessful();

    expect(OrderDocument::find($doc->id))->toBeNull();
    expect(file_exists(storage_path("app/$filePath")))->toBeFalse();
    expect(is_dir($dir))->toBeFalse();
});

test('不清理 unpaid/pending/processing/approving 状态订单的文档', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();

    foreach (['pending', 'processing', 'approving', 'cancelling'] as $status) {
        $order = $this->createTestOrder($user, $product);
        $this->createTestCert($order, ['status' => $status]);

        $dir = storage_path("app/verification/$order->id");
        is_dir($dir) || mkdir($dir, 0755, true);
        $filePath = "verification/$order->id/test.pdf";
        file_put_contents(storage_path("app/$filePath"), 'test content');

        $doc = OrderDocument::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'type' => 'APPLICANT',
            'file_name' => 'test.pdf',
            'file_path' => $filePath,
            'file_size' => 12,
            'uploaded_by' => 'user',
        ]);

        $this->artisan('schedule:purge')->assertSuccessful();

        expect(OrderDocument::find($doc->id))->not->toBeNull();
        expect(file_exists(storage_path("app/$filePath")))->toBeTrue();

        // 清理测试文件
        unlink(storage_path("app/$filePath"));
        rmdir($dir);
    }
});

test('清理无记录的孤立 verification 目录', function () {
    // 创建一个没有对应 order_documents 记录的目录
    $fakeOrderId = '99999999999';
    $dir = storage_path("app/verification/$fakeOrderId");
    is_dir($dir) || mkdir($dir, 0755, true);
    file_put_contents("$dir/orphan.pdf", 'orphan content');

    $this->artisan('schedule:purge')->assertSuccessful();

    expect(is_dir($dir))->toBeFalse();
});

test('清理非进行中状态订单的文档', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();

    foreach (['active', 'reissued', 'expired', 'cancelled', 'revoked'] as $status) {
        $order = $this->createTestOrder($user, $product);
        $this->createTestCert($order, ['status' => $status]);

        $doc = OrderDocument::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'type' => 'ORGANIZATION',
            'file_name' => 'doc.pdf',
            'file_path' => "verification/$order->id/doc.pdf",
            'file_size' => 10,
            'uploaded_by' => 'admin',
        ]);

        $this->artisan('schedule:purge')->assertSuccessful();

        expect(OrderDocument::find($doc->id))->toBeNull()
            ->and("$status should be purged")->toBe("$status should be purged");
    }
});

// --- 日志保留期 config 化测试 ---

test('config(logs.retention.admin) 注入后清理超过保留期的 admin_logs', function () {
    config(['logs.retention.admin' => 30]);

    AdminLog::insert([
        ['url' => 'https://test.local/old', 'method' => 'POST', 'module' => 'M', 'action' => 'A', 'created_at' => now()->subDays(31)],
        ['url' => 'https://test.local/new', 'method' => 'POST', 'module' => 'M', 'action' => 'A', 'created_at' => now()->subDays(10)],
    ]);

    $this->artisan('schedule:purge')->assertSuccessful();

    expect(AdminLog::where('url', 'https://test.local/old')->exists())->toBeFalse();
    expect(AdminLog::where('url', 'https://test.local/new')->exists())->toBeTrue();
});

test('config(logs.retention.error) 注入后清理超过保留期的 error_logs', function () {
    config(['logs.retention.error' => 7]);

    ErrorLog::insert([
        ['url' => 'https://test.local/err-old', 'method' => 'POST', 'exception' => 'E', 'message' => 'm', 'created_at' => now()->subDays(8)],
        ['url' => 'https://test.local/err-new', 'method' => 'POST', 'exception' => 'E', 'message' => 'm', 'created_at' => now()->subDays(2)],
    ]);

    $this->artisan('schedule:purge')->assertSuccessful();

    expect(ErrorLog::where('url', 'https://test.local/err-old')->exists())->toBeFalse();
    expect(ErrorLog::where('url', 'https://test.local/err-new')->exists())->toBeTrue();
});

test('未注入 config 时使用默认 180 天兜底（user_logs）', function () {
    // 不注入 config，依赖 config/logs.php 默认值
    UserLog::insert([
        ['url' => 'https://test.local/u-old', 'method' => 'POST', 'module' => 'M', 'action' => 'A', 'created_at' => now()->subDays(181)],
        ['url' => 'https://test.local/u-new', 'method' => 'POST', 'module' => 'M', 'action' => 'A', 'created_at' => now()->subDays(170)],
    ]);

    $this->artisan('schedule:purge')->assertSuccessful();

    expect(UserLog::where('url', 'https://test.local/u-old')->exists())->toBeFalse();
    expect(UserLog::where('url', 'https://test.local/u-new')->exists())->toBeTrue();
});

// --- B5: temp-certs 残留清理（私钥泄漏兜底）---

test('purge 清理 temp-certs 下超过 1 小时的残留目录（含私钥）', function () {
    $base = storage_path('temp-certs');
    is_dir($base) || mkdir($base, 0755, true);

    // 唯一命名 fixture，只断言自建项生命周期（避免 paratest 跨 worker 误判，反模式 14）
    $oldDir = "$base/b5old".uniqid();
    mkdir($oldDir, 0755, true);
    file_put_contents("$oldDir/private.key", 'SECRET PRIVATE KEY');
    // mtime 设为 2 小时前（须在写入内容之后，写文件会刷新目录 mtime）
    touch($oldDir, time() - 7200);

    $this->artisan('schedule:purge')->assertSuccessful();

    // 超 1h 的残留目录被删（含私钥）
    expect(is_dir($oldDir))->toBeFalse();
});

test('purge 不误删 temp-certs 下 1 小时内的进行中下载目录', function () {
    $base = storage_path('temp-certs');
    is_dir($base) || mkdir($base, 0755, true);

    $freshDir = "$base/b5fresh".uniqid();
    mkdir($freshDir, 0755, true);
    file_put_contents("$freshDir/inflight.zip", 'downloading');
    // mtime = now（进行中下载），阈值 1h ≫ 下载时长，永不命中 mtime>1h

    $this->artisan('schedule:purge')->assertSuccessful();

    // 进行中目录不被误删
    expect(is_dir($freshDir))->toBeTrue();

    // 清理自建 fixture
    unlink("$freshDir/inflight.zip");
    rmdir($freshDir);
});

// --- 自动取消：action 过滤测试 ---

test('PurgeCommand 取消 reissue 处理中订单（B3：退款期兜底扩展覆盖 reissue）', function () {
    // 行为变更（审计 P1-12）：reissue 原被 whereIn('action',['new','renew']) 排除致退款期到期时
    // 卡 processing、无兜底取消；B3 扩展覆盖 reissue，退款/恢复由 cancelLocked reissue 分支直测。
    // 本文件只做编排断言（cert 置 cancelling + cancel task 建），不跑到资金路径。
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product);
    // Eloquent timestamps 会覆盖 create 中的 created_at，需要在 create 后单独更新
    $order->forceFill(['created_at' => now()->subDays(29)])->save();
    $this->createTestCert($order, ['status' => 'processing', 'action' => 'reissue']);

    // mock sync 避免真实 API 调用；createTask/deleteTask 走真实逻辑以便断言 Task 记录
    $actionMock = Mockery::mock(Action::class)->makePartial();
    $actionMock->shouldReceive('sync')->andReturn(null);
    $this->app->bind(Action::class, fn () => $actionMock);

    $this->artisan('schedule:purge')->assertSuccessful();

    expect($order->latestCert()->first()->status)->toBe('cancelling');
    expect(Task::where('order_id', $order->id)->where('action', 'cancel')->count())->toBe(1);
});

test('PurgeCommand 仍取消 new 处理中订单', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product);
    // Eloquent timestamps 会覆盖 create 中的 created_at，需要在 create 后单独更新
    $order->forceFill(['created_at' => now()->subDays(29)])->save();
    $this->createTestCert($order, ['status' => 'processing', 'action' => 'new']);

    // mock sync 避免真实 API 调用；createTask/deleteTask 走真实逻辑以便断言 Task 记录
    $actionMock = Mockery::mock(Action::class)->makePartial();
    $actionMock->shouldReceive('sync')->andReturn(null);
    $this->app->bind(Action::class, fn () => $actionMock);

    $this->artisan('schedule:purge')->assertSuccessful();

    expect($order->latestCert()->first()->status)->toBe('cancelling');
    expect(Task::where('order_id', $order->id)->where('action', 'cancel')->count())->toBe(1);
});
