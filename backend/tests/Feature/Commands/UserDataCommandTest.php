<?php

use App\Models\Acme;
use App\Models\ApiToken;
use App\Models\Cert;
use App\Models\CnameDelegation;
use App\Models\Contact;
use App\Models\DeployToken;
use App\Models\Fund;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Services\UserData\UserDataTableRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    // 清理导出目录
    $dir = storage_path('app/private/exports/users');
    if (File::isDirectory($dir)) {
        File::cleanDirectory($dir);
    }
});

// ===================== export =====================

test('export 导出用户数据为 SQL 文件', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);
    Contact::factory()->create(['user_id' => $user->id]);

    $this->artisan("user:data export {$user->id} --force")
        ->assertSuccessful()
        ->expectsOutputToContain('导出完成');

    $files = glob(storage_path("app/private/exports/users/{$user->id}_*.sql"));
    expect($files)->not->toBeEmpty();

    $content = file_get_contents($files[0]);
    expect($content)
        ->toContain("-- user_id: $user->id")
        ->toContain('INSERT INTO `users`')
        ->toContain('INSERT INTO `orders`')
        ->toContain('INSERT INTO `contacts`');
});

test('export 不包含密码', function () {
    $user = User::factory()->create(['password' => 'secret123']);

    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();

    $files = glob(storage_path("app/private/exports/users/{$user->id}_*.sql"));
    $content = file_get_contents($files[0]);

    // password 列应为空字符串
    expect($content)->not->toContain('secret123');
});

test('export 不包含令牌和日志表', function () {
    $user = User::factory()->create();
    ApiToken::factory()->create(['user_id' => $user->id]);
    DeployToken::factory()->create(['user_id' => $user->id]);

    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();

    $files = glob(storage_path("app/private/exports/users/{$user->id}_*.sql"));
    $content = file_get_contents($files[0]);

    expect($content)
        ->not->toContain('INSERT INTO `api_tokens`')
        ->not->toContain('INSERT INTO `deploy_tokens`')
        ->not->toContain('INSERT INTO `user_logs`')
        ->not->toContain('INSERT INTO `api_logs`')
        ->not->toContain('INSERT INTO `user_refresh_tokens`');
});

test('export 包含 ACME 订单', function () {
    $user = User::factory()->create();
    Acme::factory()->create(['user_id' => $user->id]);

    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();

    $files = glob(storage_path("app/private/exports/users/{$user->id}_*.sql"));
    $content = file_get_contents($files[0]);

    expect($content)->toContain('INSERT INTO `acmes`');
});

test('export 用户不存在时失败', function () {
    $this->artisan('user:data export 999999999 --force')
        ->assertFailed()
        ->expectsOutputToContain('不存在');
});

// ===================== import =====================

test('import dry-run 无冲突时提示安全', function () {
    $user = User::factory()->create();
    Contact::factory()->create(['user_id' => $user->id]);

    // 先导出
    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();
    $files = glob(storage_path("app/private/exports/users/{$user->id}_*.sql"));
    $filePath = $files[0];

    // 删除数据
    Contact::where('user_id', $user->id)->delete();
    $user->delete();

    // 干跑检测
    $this->artisan("user:data import {$user->id} --dry-run --file=$filePath")
        ->assertSuccessful()
        ->expectsOutputToContain('无冲突');
});

test('import dry-run 有冲突时报告', function () {
    $user = User::factory()->create();

    // 导出
    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();
    $files = glob(storage_path("app/private/exports/users/{$user->id}_*.sql"));

    // 用户未删除，直接干跑 → 应有冲突
    $this->artisan("user:data import {$user->id} --dry-run --file={$files[0]}")
        ->assertSuccessful()
        ->expectsOutputToContain('冲突');
});

test('import 导入成功', function () {
    $user = User::factory()->create();
    $contact = Contact::factory()->create(['user_id' => $user->id]);

    // 导出
    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();
    $files = glob(storage_path("app/private/exports/users/{$user->id}_*.sql"));

    // 删除数据
    Contact::where('user_id', $user->id)->delete();
    $user->delete();

    // 导入
    $this->artisan("user:data import {$user->id} --force --file={$files[0]}")
        ->assertSuccessful()
        ->expectsOutputToContain('导入完成');

    // 验证数据恢复
    expect(User::find($user->id))->not->toBeNull();
    expect(Contact::where('user_id', $user->id)->count())->toBe(1);
});

test('import user_id 不匹配时失败', function () {
    $user = User::factory()->create();

    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();
    $files = glob(storage_path("app/private/exports/users/{$user->id}_*.sql"));

    // 用另一个 user_id 导入
    $this->artisan("user:data import 999 --force --file={$files[0]}")
        ->assertFailed()
        ->expectsOutputToContain('不匹配');
});

test('import 无导出文件时提示', function () {
    $this->artisan('user:data import 123 --force')
        ->assertFailed()
        ->expectsOutputToContain('未找到');
});

// ===================== purge =====================

test('purge 用户未禁用时拒绝', function () {
    $user = User::factory()->create(['status' => 1]);

    $this->artisan("user:data purge {$user->id} --force")
        ->assertFailed()
        ->expectsOutputToContain('未禁用');
});

test('purge 未导出时拒绝', function () {
    $user = User::factory()->create(['status' => 0]);

    $this->artisan("user:data purge {$user->id} --force")
        ->assertFailed()
        ->expectsOutputToContain('导出');
});

test('purge 满足条件后清理用户数据', function () {
    $user = User::factory()->create(['status' => 0]);
    $order = Order::factory()->create(['user_id' => $user->id]);
    Contact::factory()->create(['user_id' => $user->id]);
    Organization::factory()->create(['user_id' => $user->id]);
    Fund::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create(['user_id' => $user->id, 'type' => 'order']);
    Acme::factory()->create(['user_id' => $user->id]);
    DeployToken::factory()->create(['user_id' => $user->id]);
    ApiToken::factory()->create(['user_id' => $user->id]);

    // 先导出
    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();

    // 清理
    $this->artisan("user:data purge {$user->id} --force")
        ->assertSuccessful()
        ->expectsOutputToContain('成功清理');

    // 验证数据已清除
    expect(User::find($user->id))->toBeNull();
    expect(Order::where('user_id', $user->id)->count())->toBe(0);
    expect(Contact::where('user_id', $user->id)->count())->toBe(0);
    expect(Organization::where('user_id', $user->id)->count())->toBe(0);
    expect(Fund::where('user_id', $user->id)->count())->toBe(0);
    expect(Transaction::where('user_id', $user->id)->count())->toBe(0);
    expect(Acme::where('user_id', $user->id)->count())->toBe(0);
    expect(DeployToken::where('user_id', $user->id)->count())->toBe(0);
    expect(ApiToken::where('user_id', $user->id)->count())->toBe(0);
});

// ===================== 导出范围与自增 ID =====================

test('export 只导出核心表，不含运营数据', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);
    Cert::factory()->create(['order_id' => $order->id]);
    Contact::factory()->create(['user_id' => $user->id]);
    Fund::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create(['user_id' => $user->id, 'type' => 'order']);
    CnameDelegation::factory()->create(['user_id' => $user->id]);
    Task::factory()->create(['order_id' => $order->id]);

    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();

    $files = glob(storage_path("app/private/exports/users/{$user->id}_*.sql"));
    $content = file_get_contents($files[0]);

    // 核心表应包含
    expect($content)
        ->toContain('INSERT INTO `users`')
        ->toContain('INSERT INTO `orders`')
        ->toContain('INSERT INTO `certs`')
        ->toContain('INSERT INTO `contacts`')
        ->toContain('INSERT INTO `funds`')
        ->toContain('INSERT INTO `transactions`');

    // 运营数据不导出
    expect($content)
        ->not->toContain('INSERT INTO `cname_delegations`')
        ->not->toContain('INSERT INTO `tasks`')
        ->not->toContain('INSERT INTO `notifications`');
});

test('export 导出顺序满足外键依赖', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);
    Cert::factory()->create(['order_id' => $order->id]);

    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();

    $files = glob(storage_path("app/private/exports/users/{$user->id}_*.sql"));
    $content = file_get_contents($files[0]);

    // users 在 orders 之前，orders 在 certs 之前
    $usersPos = strpos($content, 'INSERT INTO `users`');
    $ordersPos = strpos($content, 'INSERT INTO `orders`');
    $certsPos = strpos($content, 'INSERT INTO `certs`');

    expect($usersPos)->toBeLessThan($ordersPos);
    expect($ordersPos)->toBeLessThan($certsPos);
});

test('export 导出后导入可恢复数据', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);
    Cert::factory()->create(['order_id' => $order->id]);
    Fund::factory()->create(['user_id' => $user->id]);

    // 导出
    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();
    $files = glob(storage_path("app/private/exports/users/{$user->id}_*.sql"));
    $filePath = $files[0];

    // 删除数据（子表先删）
    Cert::where('order_id', $order->id)->delete();
    Fund::where('user_id', $user->id)->delete();
    Order::where('user_id', $user->id)->delete();
    $user->delete();

    // 导入
    $this->artisan("user:data import {$user->id} --force --file=$filePath")
        ->assertSuccessful()
        ->expectsOutputToContain('导入完成');

    expect(User::find($user->id))->not->toBeNull();
    expect(Order::where('user_id', $user->id)->count())->toBe(1);
    expect(Cert::where('order_id', $order->id)->count())->toBe(1);
    expect(Fund::where('user_id', $user->id)->count())->toBe(1);
});

test('exportTables 白名单含 callbacks 不含运营数据', function () {
    $tables = array_column(UserDataTableRegistry::exportTables(), 'table');

    expect($tables)
        ->toContain('users')
        ->toContain('orders')
        ->toContain('certs')
        ->toContain('funds')
        ->toContain('transactions')
        ->toContain('callbacks')
        ->not->toContain('cname_delegations')
        ->not->toContain('tasks')
        ->not->toContain('domain_validation_records')
        ->not->toContain('notifications')
        ->not->toContain('order_documents');
});

test('export 雪花 ID 表保留 id 列', function () {
    $user = User::factory()->create();
    Transaction::factory()->create(['user_id' => $user->id, 'type' => 'order']);

    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();

    $files = glob(storage_path("app/private/exports/users/{$user->id}_*.sql"));
    $content = file_get_contents($files[0]);

    // transactions（雪花）保留 `id` 列
    preg_match('/INSERT INTO `transactions` \(([^)]+)\)/', $content, $matches);
    expect($matches)->not->toBeEmpty();
    expect($matches[1])->toContain('`id`');

    // orders（雪花）保留 `id` 列
    preg_match('/INSERT INTO `orders` \(([^)]+)\)/', $content, $matches);
    if (! empty($matches)) {
        expect($matches[1])->toContain('`id`');
    }
});

test('export 雪花 ID 表导入跳过重复', function () {
    $user = User::factory()->create();
    Transaction::factory()->create(['user_id' => $user->id, 'type' => 'order']);

    // 导出
    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();
    $files = glob(storage_path("app/private/exports/users/{$user->id}_*.sql"));
    $filePath = $files[0];

    // 不删除原数据，直接导入 — 雪花 ID 重复应跳过
    $this->artisan("user:data import {$user->id} --force --file=$filePath")
        ->assertSuccessful();

    // transactions 雪花 ID 重复，跳过导入，数量不变
    expect(Transaction::where('user_id', $user->id)->count())->toBe(1);
});

// ===================== 参数验证 =====================

test('无效 action 报错', function () {
    $this->artisan('user:data invalid 1 --force')
        ->assertFailed()
        ->expectsOutputToContain('export');
});

test('签名为 user:data', function () {
    $user = User::factory()->create();

    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();
});

// ===================== parseStatements：引号内分号 =====================

test('import 正确处理 VALUES 中包含分号的数据', function () {
    $user = User::factory()->create();
    // organization 和 contact 是 text 列，可能包含分号
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'organization' => json_encode(['name' => 'Test; Corp', 'addr' => 'No.1; Street']),
        'contact' => json_encode(['email' => 'a@b.com; c@d.com']),
    ]);

    // 导出
    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();
    $files = glob(storage_path("app/private/exports/users/{$user->id}_*.sql"));
    $filePath = $files[0];

    // 确认导出文件确实包含分号数据
    $content = file_get_contents($filePath);
    expect($content)->toContain('Test; Corp');

    // 删除原数据
    Cert::where('order_id', $order->id)->delete();
    Order::where('id', $order->id)->delete();
    $user->delete();

    // 导入应成功
    $this->artisan("user:data import {$user->id} --force --file=$filePath")
        ->assertSuccessful()
        ->expectsOutputToContain('导入完成');

    // 验证订单恢复且数据正确
    $restored = Order::find($order->id);
    expect($restored)->not->toBeNull();
    expect(json_decode($restored->organization, true)['name'])->toBe('Test; Corp');
});

// ===================== adaptColumns：列差异适配 =====================

test('import 自动跳过目标表不存在的列', function () {
    $user = User::factory()->create();
    Order::factory()->create(['user_id' => $user->id]);

    // 导出
    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();
    $files = glob(storage_path("app/private/exports/users/{$user->id}_*.sql"));
    $filePath = $files[0];

    // 手动给 orders INSERT 加一个不存在的列
    $content = file_get_contents($filePath);
    $content = str_replace(
        'INSERT INTO `orders` (`id`,',
        'INSERT INTO `orders` (`id`, `fake_column`,',
        $content
    );
    // 只在 orders 的 VALUES 行插入假值（匹配 orders INSERT 块内的 VALUES）
    $content = preg_replace(
        '/(INSERT INTO `orders`[^;]+VALUES)\n\((\d+),/s',
        "$1\n($2, 'fake_value',",
        $content
    );
    file_put_contents($filePath, $content);

    // 删除原数据
    Order::where('user_id', $user->id)->delete();
    $user->delete();

    // 导入应成功，自动跳过 fake_column
    Artisan::call('user:data', [
        'action' => 'import',
        'user_id' => $user->id,
        '--force' => true,
        '--file' => $filePath,
    ]);
    $output = Artisan::output();

    expect($output)->toContain('跳过列')->toContain('fake_column');
    expect(User::find($user->id))->not->toBeNull();
    expect(Order::where('user_id', $user->id)->count())->toBe(1);
});

// ===================== purge：孤立间接记录清理 =====================

test('purge 清理孤立的 certs（orders 缺失时）', function () {
    $user = User::factory()->create(['status' => 0]);
    $order = Order::factory()->create(['user_id' => $user->id]);
    $cert = Cert::factory()->create(['order_id' => $order->id]);

    // 导出（包含 order 和 cert）
    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();

    // 模拟导入失败场景：删掉 order 但保留 cert（孤立）
    Order::where('id', $order->id)->delete();

    // cert 通过 orders 子查询已经查不到了
    expect(Cert::whereIn('order_id', fn ($q) => $q->select('id')->from('orders')->where('user_id', $user->id))->count())->toBe(0);
    // 但 cert 实际还在
    expect(Cert::find($cert->id))->not->toBeNull();

    // purge 应该能通过导出文件 ID 清理孤立 cert
    $this->artisan("user:data purge {$user->id} --force")
        ->assertSuccessful()
        ->expectsOutputToContain('清理孤立');

    expect(Cert::find($cert->id))->toBeNull();
    expect(User::find($user->id))->toBeNull();
});

// ===================== purge：tasks 表 order/acme 双归属 =====================

test('purge 同时删除 order 任务和 acme 任务', function () {
    $user = User::factory()->create(['status' => 0]);
    $order = Order::factory()->create(['user_id' => $user->id]);
    $acme = Acme::factory()->create(['user_id' => $user->id]);

    // order 任务：order_id 指向 orders.id
    $orderTask = Task::factory()->create(['order_id' => $order->id, 'action' => 'sync']);
    // acme 任务：order_id 指向 acmes.id（ACME 任务复用 tasks 表）
    $acmeTask = Task::factory()->create(['order_id' => $acme->id, 'action' => 'sync_acme']);

    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();
    $this->artisan("user:data purge {$user->id} --force")->assertSuccessful();

    expect(User::find($user->id))->toBeNull();
    expect(Task::find($orderTask->id))->toBeNull();
    expect(Task::find($acmeTask->id))->toBeNull();
});

test('purge 不误删其他用户的 order/acme 任务', function () {
    $victim = User::factory()->create(['status' => 0]);
    $victimOrder = Order::factory()->create(['user_id' => $victim->id]);
    $victimAcme = Acme::factory()->create(['user_id' => $victim->id]);
    $victimOrderTask = Task::factory()->create(['order_id' => $victimOrder->id, 'action' => 'sync']);
    $victimAcmeTask = Task::factory()->create(['order_id' => $victimAcme->id, 'action' => 'sync_acme']);

    $other = User::factory()->create(['status' => 0]);
    $otherOrder = Order::factory()->create(['user_id' => $other->id]);
    $otherAcme = Acme::factory()->create(['user_id' => $other->id]);
    $otherOrderTask = Task::factory()->create(['order_id' => $otherOrder->id, 'action' => 'sync']);
    $otherAcmeTask = Task::factory()->create(['order_id' => $otherAcme->id, 'action' => 'sync_acme']);

    $this->artisan("user:data export {$victim->id} --force")->assertSuccessful();
    $this->artisan("user:data purge {$victim->id} --force")->assertSuccessful();

    // victim 两类任务都删
    expect(Task::find($victimOrderTask->id))->toBeNull();
    expect(Task::find($victimAcmeTask->id))->toBeNull();
    // other 两类任务都保留
    expect(Task::find($otherOrderTask->id))->not->toBeNull();
    expect(Task::find($otherAcmeTask->id))->not->toBeNull();
});

test('getStatistics 统计 tasks 含 ACME 任务', function () {
    $user = User::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id]);
    $acme = Acme::factory()->create(['user_id' => $user->id]);
    Task::factory()->create(['order_id' => $order->id, 'action' => 'sync']);
    Task::factory()->create(['order_id' => $acme->id, 'action' => 'sync_acme']);

    $stats = UserDataTableRegistry::getStatistics($user);
    $taskRow = collect($stats)->first(fn ($r) => $r[0] === '任务');

    expect($taskRow)->not->toBeNull();
    expect($taskRow[1])->toBe(2);
});

// ===================== dryRun：自增表显示修复 =====================

test('dry-run 雪花表显示正确的记录数和冲突检测', function () {
    $user = User::factory()->create();
    Transaction::factory()->count(3)->create(['user_id' => $user->id, 'type' => 'order']);

    // 导出
    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();
    $files = glob(storage_path("app/private/exports/users/{$user->id}_*.sql"));

    // 删除数据
    Transaction::where('user_id', $user->id)->delete();
    $user->delete();

    // dry-run 应显示正确行数和冲突检测结果
    Artisan::call('user:data', [
        'action' => 'import',
        'user_id' => $user->id,
        '--dry-run' => true,
        '--file' => $files[0],
    ]);
    $output = Artisan::output();

    expect($output)->toContain('3条')->toContain('0 条冲突');
});

// ===================== 生成列 dedup_key：导出端不写值 =====================

test('export 不导出生成列 dedup_key', function () {
    $user = User::factory()->create();
    Transaction::factory()->create(['user_id' => $user->id, 'type' => 'order']);

    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();

    $files = glob(storage_path("app/private/exports/users/{$user->id}_*.sql"));
    $content = file_get_contents($files[0]);

    preg_match('/INSERT INTO `transactions` \(([^)]+)\)/', $content, $m);
    expect($m)->not->toBeEmpty();
    expect($m[1])->not->toContain('dedup_key');
});

test('export 导出后 transactions 可导入恢复（生成列不阻断）', function () {
    $user = User::factory()->create();
    Transaction::factory()->count(3)->create(['user_id' => $user->id, 'type' => 'order']);

    $this->artisan("user:data export {$user->id} --force")->assertSuccessful();
    $files = glob(storage_path("app/private/exports/users/{$user->id}_*.sql"));
    $filePath = $files[0];

    Transaction::where('user_id', $user->id)->delete();
    $user->delete();

    $this->artisan("user:data import {$user->id} --force --file=$filePath")
        ->assertSuccessful()
        ->expectsOutputToContain('导入完成');

    expect(Transaction::where('user_id', $user->id)->count())->toBe(3);
});
