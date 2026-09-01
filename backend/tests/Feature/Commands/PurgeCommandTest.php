<?php

use App\Exceptions\ApiResponseException;
use App\Models\Acme;
use App\Models\AdminLog;
use App\Models\AutoDeployReport;
use App\Models\ErrorLog;
use App\Models\Fund;
use App\Models\Notification;
use App\Models\OrderDocument;
use App\Models\Task;
use App\Models\User;
use App\Models\UserLog;
use App\Services\Order\Action;
use Illuminate\Support\Carbon;
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

test('config(logs.retention.full_days) 控制 admin 诊断日志全量窗口', function () {
    config(['logs.retention.full_days' => 30, 'logs.retention.audit_days' => 180]);

    AdminLog::insert([
        ['url' => 'https://test.local/old', 'method' => 'GET', 'module' => 'M', 'action' => 'index', 'created_at' => now()->subDays(31)],
        ['url' => 'https://test.local/new', 'method' => 'GET', 'module' => 'M', 'action' => 'index', 'created_at' => now()->subDays(10)],
    ]);

    $this->artisan('logs:purge')->assertSuccessful();

    expect(AdminLog::where('url', 'https://test.local/old')->exists())->toBeFalse();
    expect(AdminLog::where('url', 'https://test.local/new')->exists())->toBeTrue();
});

test('config(logs.retention.full_days) 控制 error 诊断日志全量窗口', function () {
    config(['logs.retention.full_days' => 7, 'logs.retention.audit_days' => 180]);

    ErrorLog::insert([
        ['module' => 'Order', 'action' => 'revalidate', 'url' => 'https://test.local/err-old', 'method' => 'POST', 'exception' => 'E', 'message' => 'm', 'created_at' => now()->subDays(8)],
        ['module' => 'Order', 'action' => 'revalidate', 'url' => 'https://test.local/err-new', 'method' => 'POST', 'exception' => 'E', 'message' => 'm', 'created_at' => now()->subDays(2)],
    ]);

    $this->artisan('logs:purge')->assertSuccessful();

    expect(ErrorLog::where('url', 'https://test.local/err-old')->exists())->toBeFalse();
    expect(ErrorLog::where('url', 'https://test.local/err-new')->exists())->toBeTrue();
});

test('未注入 config 时使用默认 180 天兜底（user_logs）', function () {
    // 不注入 config，依赖 config/logs.php 默认值
    UserLog::insert([
        ['url' => 'https://test.local/u-old', 'method' => 'POST', 'module' => 'M', 'action' => 'A', 'created_at' => now()->subDays(181)],
        ['url' => 'https://test.local/u-new', 'method' => 'POST', 'module' => 'M', 'action' => 'A', 'created_at' => now()->subDays(170)],
    ]);

    $this->artisan('logs:purge')->assertSuccessful();

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

// --- P3 包R：三表保留期清理（tasks / notifications 终态行）---

test('purge 清理超保留期的 successful/failed task', function () {
    config(['purge.retention.tasks_full_days' => 7, 'purge.retention.tasks_audit_days' => 180]);

    Task::insert([
        ['order_id' => 1001, 'action' => 'commit', 'status' => 'successful', 'created_at' => now()->subDays(181), 'updated_at' => now()->subDays(181)],
        ['order_id' => 1002, 'action' => 'sync', 'status' => 'failed', 'created_at' => now()->subDays(120), 'updated_at' => now()->subDays(120)],
        // 保留期内的终态行不清
        ['order_id' => 1003, 'action' => 'commit', 'status' => 'successful', 'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)],
    ]);

    $this->artisan('schedule:purge')->assertSuccessful();

    expect(Task::where('order_id', 1001)->exists())->toBeFalse();
    expect(Task::where('order_id', 1002)->exists())->toBeFalse();
    expect(Task::where('order_id', 1003)->exists())->toBeTrue();
});

test('purge 不清关联订单仍 pending（卡单未收尾）的终态 task —— 防到顶计数归零复活', function () {
    // ⑭：到顶判据的失败计数锚 last_execute_at >= latestCert.created_at 无上界；放置 90 天的 pending 卡单，
    // 其 failed commit task 全在计数集内。若被 purge 按 created_at>90d 删掉 → 计数归零 → 订单重回 actionable
    // → reconcile 重打上游 + 重发 auto_renew_failed（去重键 reconcile_user_alerted_at 随行删丢失）。
    // 保护：关联订单 latestCert.status=pending（卡单未收尾）的终态 task 不清。
    config(['purge.retention.tasks_full_days' => 7, 'purge.retention.tasks_audit_days' => 180]);

    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);
    $cert = $this->createTestCert($order, ['action' => 'renew', 'status' => 'pending', 'api_id' => null]); // 卡单未收尾
    $cert->forceFill(['created_at' => now()->subDays(200)])->saveQuietly();

    // 该卡单的失败 commit task（含 reconcile 用户去重键），已超保留期
    $task = Task::create([
        'order_id' => $order->id,
        'action' => 'commit',
        'status' => 'failed',
        'last_execute_at' => now()->subDays(190),
        'result' => ['code' => 0, 'msg' => 'timeout', 'reconcile_user_alerted_at' => now()->subDays(89)->toDateTimeString()],
    ]);
    $task->forceFill(['created_at' => now()->subDays(200), 'updated_at' => now()->subDays(190)])->save();

    $this->artisan('schedule:purge')->assertSuccessful();

    // 卡单未收尾：failed commit task 不被清（到顶计数保持 + 去重键保持，订单不复活）
    expect(Task::where('id', $task->id)->exists())->toBeTrue();
});

test('purge 清关联订单已收尾（非 pending）的终态 task', function () {
    // 对照：订单收尾成 cancelled/active 后 latestCert 非 pending，其历史 failed task 正常清理，不永久堆积。
    config(['purge.retention.tasks_full_days' => 7, 'purge.retention.tasks_audit_days' => 180]);

    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, ['action' => 'renew', 'status' => 'cancelled']); // 已收尾

    $task = Task::create([
        'order_id' => $order->id,
        'action' => 'commit',
        'status' => 'failed',
        'result' => ['code' => 0, 'msg' => 'timeout'],
    ]);
    $task->forceFill(['created_at' => now()->subDays(181), 'updated_at' => now()->subDays(181)])->save();

    $this->artisan('schedule:purge')->assertSuccessful();

    expect(Task::where('id', $task->id)->exists())->toBeFalse();
});

test('purge 不清 executing/stopped task（活动/转人工态永不被清）', function () {
    config(['purge.retention.tasks_full_days' => 7, 'purge.retention.tasks_audit_days' => 180]);

    Task::insert([
        ['order_id' => 2001, 'action' => 'commit', 'status' => 'executing', 'created_at' => now()->subDays(200), 'updated_at' => now()->subDays(200)],
        ['order_id' => 2002, 'action' => 'sync', 'status' => 'stopped', 'created_at' => now()->subDays(200), 'updated_at' => now()->subDays(200)],
    ]);

    $this->artisan('schedule:purge')->assertSuccessful();

    expect(Task::where('order_id', 2001)->exists())->toBeTrue();
    expect(Task::where('order_id', 2002)->exists())->toBeTrue();
});

test('purge tasks 按动作分层并使用 last_execute_at 作为年龄锚点', function () {
    config(['purge.retention.tasks_full_days' => 7, 'purge.retention.tasks_audit_days' => 180]);

    Task::insert([
        ['order_id' => 2101, 'action' => 'commit', 'status' => 'failed', 'last_execute_at' => null, 'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)],
        ['order_id' => 2102, 'action' => 'revalidate', 'status' => 'failed', 'last_execute_at' => null, 'created_at' => now()->subDays(8), 'updated_at' => now()->subDays(8)],
        ['order_id' => 2103, 'action' => 'future_action', 'status' => 'failed', 'last_execute_at' => null, 'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)],
        ['order_id' => 2104, 'action' => 'delegation', 'status' => 'successful', 'last_execute_at' => null, 'created_at' => now()->subDays(6), 'updated_at' => now()->subDays(6)],
        ['order_id' => 2105, 'action' => 'callback', 'status' => 'successful', 'last_execute_at' => null, 'created_at' => now()->subDays(181), 'updated_at' => now()->subDays(181)],
        ['order_id' => 2106, 'action' => 'commit', 'status' => 'failed', 'last_execute_at' => now(), 'created_at' => now()->subDays(181), 'updated_at' => now()],
    ]);

    $this->artisan('schedule:purge')->expectsOutputToContain('future_action')->assertSuccessful();

    expect(Task::where('order_id', 2101)->exists())->toBeTrue()
        ->and(Task::where('order_id', 2102)->exists())->toBeFalse()
        ->and(Task::where('order_id', 2103)->exists())->toBeTrue()
        ->and(Task::where('order_id', 2104)->exists())->toBeTrue()
        ->and(Task::where('order_id', 2105)->exists())->toBeFalse()
        ->and(Task::where('order_id', 2106)->exists())->toBeTrue();
});

test('pending 普通订单只保护当前证书周期的 failed commit', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);
    $cert = $this->createTestCert($order, ['status' => 'pending', 'api_id' => null]);
    $cert->forceFill(['created_at' => now()->subDays(200)])->saveQuietly();

    $currentCommit = Task::create(['order_id' => $order->id, 'action' => 'commit', 'status' => 'failed', 'last_execute_at' => now()->subDays(190)]);
    $oldCommit = Task::create(['order_id' => $order->id, 'action' => 'commit', 'status' => 'failed', 'last_execute_at' => now()->subDays(201)]);
    $oldSync = Task::create(['order_id' => $order->id, 'action' => 'sync', 'status' => 'failed', 'last_execute_at' => now()->subDays(8)]);

    $this->artisan('schedule:purge')->assertSuccessful();

    expect($currentCommit->fresh())->not->toBeNull()
        ->and($oldCommit->fresh())->toBeNull()
        ->and($oldSync->fresh())->toBeNull();
});

test('pending ACME 只保护当前周期的 failed commit_acme', function () {
    $acme = Acme::factory()->create(['status' => 'pending', 'api_id' => null]);
    $acme->forceFill(['created_at' => now()->subDays(200)])->saveQuietly();

    $currentCommit = Task::create(['order_id' => $acme->id, 'action' => 'commit_acme', 'status' => 'failed', 'last_execute_at' => now()->subDays(190)]);
    $oldCommit = Task::create(['order_id' => $acme->id, 'action' => 'commit_acme', 'status' => 'failed', 'last_execute_at' => now()->subDays(201)]);
    $oldSync = Task::create(['order_id' => $acme->id, 'action' => 'sync_acme', 'status' => 'failed', 'last_execute_at' => now()->subDays(8)]);

    $this->artisan('schedule:purge')->assertSuccessful();

    expect($currentCommit->fresh())->not->toBeNull()
        ->and($oldCommit->fresh())->toBeNull()
        ->and($oldSync->fresh())->toBeNull();
});

test('purge 清理超保留期的 sent/failed notification', function () {
    config(['purge.retention.notifications' => 90]);

    Notification::insert([
        ['notifiable_type' => User::class, 'notifiable_id' => 1, 'template_id' => 1, 'status' => 'sent', 'created_at' => now()->subDays(91), 'updated_at' => now()->subDays(91)],
        ['notifiable_type' => User::class, 'notifiable_id' => 1, 'template_id' => 1, 'status' => 'failed', 'created_at' => now()->subDays(120), 'updated_at' => now()->subDays(120)],
        // 保留期内的终态行不清
        ['notifiable_type' => User::class, 'notifiable_id' => 1, 'template_id' => 1, 'status' => 'sent', 'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)],
    ]);

    $this->artisan('schedule:purge')->assertSuccessful();

    // 3 条中 2 条超期终态删除，仅保留 30 天内的 1 条
    expect(Notification::count())->toBe(1);
    expect(Notification::where('created_at', '>', now()->subDays(60))->exists())->toBeTrue();
});

test('purge 不清 pending/sending notification（进行中永不被清）', function () {
    config(['purge.retention.notifications' => 90]);

    Notification::insert([
        ['notifiable_type' => User::class, 'notifiable_id' => 1, 'template_id' => 1, 'status' => 'pending', 'created_at' => now()->subDays(200), 'updated_at' => now()->subDays(200)],
        ['notifiable_type' => User::class, 'notifiable_id' => 1, 'template_id' => 1, 'status' => 'sending', 'created_at' => now()->subDays(200), 'updated_at' => now()->subDays(200)],
    ]);

    $this->artisan('schedule:purge')->assertSuccessful();

    expect(Notification::where('status', 'pending')->exists())->toBeTrue();
    expect(Notification::where('status', 'sending')->exists())->toBeTrue();
});

test('purge notification 清理不删近 1h 内可复用 failed 行（杀手场景②边界）', function () {
    config(['purge.retention.notifications' => 90]);

    Notification::insert([
        // 刚失败（可重发）、90 天内，必须保留
        ['notifiable_type' => User::class, 'notifiable_id' => 1, 'template_id' => 1, 'status' => 'failed', 'created_at' => now()->subMinutes(30), 'updated_at' => now()->subMinutes(30)],
    ]);

    $this->artisan('schedule:purge')->assertSuccessful();

    expect(Notification::where('status', 'failed')->count())->toBe(1);
});

test('purge tasks 分批删除大批量（chunk 循环正确性 + 护栏）', function () {
    config(['purge.retention.tasks_full_days' => 7, 'purge.retention.tasks_audit_days' => 180, 'purge.chunk' => 10]);

    $rows = [];
    for ($i = 0; $i < 25; $i++) {
        $rows[] = ['order_id' => 3000 + $i, 'action' => 'sync', 'status' => 'successful', 'created_at' => now()->subDays(100), 'updated_at' => now()->subDays(100)];
    }
    Task::insert($rows);

    // 25 行 / chunk 10 = 3 批（10+10+5），不触发 maxIterations 护栏
    $this->artisan('schedule:purge')
        ->expectsOutputToContain('Purged 25 terminal tasks')
        ->doesntExpectOutputToContain('aborted')
        ->assertSuccessful();

    expect(Task::whereIn('order_id', range(3000, 3024))->count())->toBe(0);
});

test('purge 清理不误伤刚被 batchStart 复活的旧 failed task（M3 边界）', function () {
    config(['purge.retention.tasks_full_days' => 7, 'purge.retention.tasks_audit_days' => 180]);

    // 90 天前的 failed task 被 admin batchStart 复活 → status=executing（非终态）
    Task::insert([
        ['order_id' => 4001, 'action' => 'commit', 'status' => 'executing', 'created_at' => now()->subDays(200), 'updated_at' => now()],
    ]);

    $this->artisan('schedule:purge')->assertSuccessful();

    // DELETE 谓词只含 {successful,failed}，复活为 executing 的行自动被排除
    expect(Task::where('order_id', 4001)->exists())->toBeTrue();
});

test('purge retention 读 config/purge.php（tasks 审计保留期 config 化）', function () {
    config(['purge.retention.tasks_full_days' => 7, 'purge.retention.tasks_audit_days' => 30]);

    Task::insert([
        ['order_id' => 5001, 'action' => 'commit', 'status' => 'successful', 'created_at' => now()->subDays(31), 'updated_at' => now()->subDays(31)],
        ['order_id' => 5002, 'action' => 'commit', 'status' => 'successful', 'created_at' => now()->subDays(20), 'updated_at' => now()->subDays(20)],
    ]);

    $this->artisan('schedule:purge')->assertSuccessful();

    expect(Task::where('order_id', 5001)->exists())->toBeFalse(); // 超 30 天删
    expect(Task::where('order_id', 5002)->exists())->toBeTrue();  // 30 天内留
});

// --- P3 包R：取消路径锁序对齐（commitCancel 替换裸三步）---

test('purge 成功取消计入 canceledCount 且输出不含 Failed to process（I1 反向验证锚点）', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product);
    $order->forceFill(['created_at' => now()->subDays(29)])->save();
    $this->createTestCert($order, ['status' => 'processing', 'action' => 'new']);

    // 只 mock sync（syncImmediately 锁外预检），commitCancel 走真实锁序逻辑
    $actionMock = Mockery::mock(Action::class)->makePartial();
    $actionMock->shouldReceive('sync')->andReturn(null);
    $this->app->bind(Action::class, fn () => $actionMock);

    // commitCancel 成功末尾抛 code=1（DB 副作用已提交）。若裸调，成功会被外层 catch 当失败打印、
    // canceledCount 恒 0——本用例断言「Set 1 orders」+「不含 Failed to process」即锚定该假绿。
    $this->artisan('schedule:purge')
        ->expectsOutputToContain('Set 1 orders to cancelling status')
        ->doesntExpectOutputToContain('Failed to process')
        ->assertSuccessful();

    expect($order->latestCert()->first()->status)->toBe('cancelling');
    expect(Task::where('order_id', $order->id)->where('action', 'cancel')->count())->toBe(1);
});

test('purge commitCancel 业务失败按跳过处理且不断整批', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['refund_period' => 30]);

    $orderFail = $this->createTestOrder($user, $product);
    $orderFail->forceFill(['created_at' => now()->subDays(29)])->save();
    $this->createTestCert($orderFail, ['status' => 'processing', 'action' => 'new']);

    $orderOk = $this->createTestOrder($user, $product);
    $orderOk->forceFill(['created_at' => now()->subDays(29)])->save();
    $this->createTestCert($orderOk, ['status' => 'processing', 'action' => 'new']);

    $actionMock = Mockery::mock(Action::class)->makePartial();
    $actionMock->shouldReceive('sync')->andReturn(null);
    // orderFail：业务失败 code=0（模拟锁内二次校验失败）→ 跳过、不计数、不断批
    $actionMock->shouldReceive('commitCancel')->with($orderFail->id)
        ->andThrow(new ApiResponseException('订单状态不是可取消状态', null, null, 0));
    // orderOk：成功 code=1
    $actionMock->shouldReceive('commitCancel')->with($orderOk->id)
        ->andThrow(new ApiResponseException('', null, null, 1));
    $this->app->bind(Action::class, fn () => $actionMock);

    // 失败订单走 info 跳过、成功订单计数；整批不中断（两单都被处理）
    $this->artisan('schedule:purge')
        ->expectsOutputToContain("Order $orderFail->id: cancel skipped")
        ->expectsOutputToContain('Set 1 orders to cancelling status')
        ->doesntExpectOutputToContain('Failed to process')
        ->assertSuccessful();
});

test('purge 临近退款期取消走 commitCancel：删 sync/revalidate、建 cancel、不删 commit', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['refund_period' => 30]);
    $order = $this->createTestOrder($user, $product);
    $order->forceFill(['created_at' => now()->subDays(29)])->save();
    $this->createTestCert($order, ['status' => 'processing', 'action' => 'new']);

    // 预置 commit/sync/revalidate 执行中任务（created_at=now，保留期内不被终态清理误删）
    Task::insert([
        ['order_id' => $order->id, 'action' => 'commit', 'status' => 'executing', 'created_at' => now(), 'updated_at' => now()],
        ['order_id' => $order->id, 'action' => 'sync', 'status' => 'executing', 'created_at' => now(), 'updated_at' => now()],
        ['order_id' => $order->id, 'action' => 'revalidate', 'status' => 'executing', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $actionMock = Mockery::mock(Action::class)->makePartial();
    $actionMock->shouldReceive('sync')->andReturn(null);
    $this->app->bind(Action::class, fn () => $actionMock);

    $this->artisan('schedule:purge')->assertSuccessful();

    expect($order->latestCert()->first()->status)->toBe('cancelling');
    // commitCancel 删 sync/revalidate（不含 commit）
    expect(Task::where('order_id', $order->id)->where('action', 'sync')->whereIn('status', ['executing', 'stopped'])->exists())->toBeFalse();
    expect(Task::where('order_id', $order->id)->where('action', 'revalidate')->whereIn('status', ['executing', 'stopped'])->exists())->toBeFalse();
    // commit 任务保留（知情差异：commitCancel 只删 sync,revalidate）
    expect(Task::where('order_id', $order->id)->where('action', 'commit')->where('status', 'executing')->exists())->toBeTrue();
    // 建 cancel 任务
    expect(Task::where('order_id', $order->id)->where('action', 'cancel')->count())->toBe(1);
});

// ===== 自动部署上报记录清理（订单终态后按 order_id 清理，保留期沿用现有机制）=====

/** 造一条指定 created_at 的自动部署上报记录 */
function makeReportRow(int $orderId, ?int $certId, string $status, Carbon $createdAt): AutoDeployReport
{
    $report = AutoDeployReport::create([
        'order_id' => $orderId,
        'cert_id' => $certId ?? $orderId,
        'status' => $status,
    ]);
    $report->forceFill(['created_at' => $createdAt])->save();

    return $report;
}

test('purge 清订单终态（证书 renewed/expired）且超保留期的自动部署上报', function () {
    config(['purge.retention.auto_deploy_reports' => 90]);

    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);
    $cert = $this->createTestCert($order, ['status' => 'renewed']); // 订单终态

    $report = makeReportRow($order->id, $cert->id, 'failure', now()->subDays(91));

    $this->artisan('schedule:purge')->assertSuccessful();

    expect(AutoDeployReport::where('id', $report->id)->exists())->toBeFalse();
});

test('purge 不清仍 active（部署中）订单的自动部署上报（保住审计视图）', function () {
    config(['purge.retention.auto_deploy_reports' => 90]);

    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);
    $cert = $this->createTestCert($order, ['status' => 'active']); // 仍活跃

    $report = makeReportRow($order->id, $cert->id, 'failure', now()->subDays(120));

    $this->artisan('schedule:purge')->assertSuccessful();

    expect(AutoDeployReport::where('id', $report->id)->exists())->toBeTrue();
});

test('purge 不清订单终态但保留期内的自动部署上报', function () {
    config(['purge.retention.auto_deploy_reports' => 90]);

    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);
    $cert = $this->createTestCert($order, ['status' => 'expired']); // 终态

    $report = makeReportRow($order->id, $cert->id, 'failure', now()->subDays(30)); // 保留期内

    $this->artisan('schedule:purge')->assertSuccessful();

    expect(AutoDeployReport::where('id', $report->id)->exists())->toBeTrue();
});

test('purge 清超保留期的孤儿自动部署上报（订单已不存在）', function () {
    config(['purge.retention.auto_deploy_reports' => 90]);

    $report = makeReportRow(999999, 888888, 'failure', now()->subDays(91)); // order 不存在

    $this->artisan('schedule:purge')->assertSuccessful();

    expect(AutoDeployReport::where('id', $report->id)->exists())->toBeFalse();
});
