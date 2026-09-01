<?php

use App\Models\Admin;
use App\Models\AdminLog;
use App\Models\ApiLog;
use App\Services\Backup\BackupService;
use App\Utils\UpgradeFreezeLock;
use Illuminate\Support\Facades\Cache;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class)->group('database');

beforeEach(function () {
    UpgradeFreezeLock::unfreeze('restore');
});

afterEach(function () {
    UpgradeFreezeLock::unfreeze('restore');
});

// ==========================================
// 1. freeze 期间 HTTP 维护态：非白名单 503，白名单放行
// ==========================================

test('freeze 期间普通管理员路径返回 503 + Retry-After', function () {
    $admin = Admin::factory()->create();
    UpgradeFreezeLock::freeze();

    $response = $this->actingAsAdmin($admin)->getJson('/api/admin/order/index');

    $response->assertStatus(503);
    expect($response->headers->get('Retry-After'))->toBe('60');
    $response->assertJson([
        'status' => 'frozen',
        'message' => '系统升级中，请稍后重试',
        'retry_after' => 60,
    ]);
});

test('freeze 期间白名单路径 /api/health 不被维护态拦截', function () {
    UpgradeFreezeLock::freeze();

    // /api/health 由 1a-4 才注册，此处验证 MaintenanceMode 放行（非 503 即可）。
    // 1a-4 合并后该路径会返回 200。
    $response = $this->getJson('/api/health');

    expect($response->status())->not->toBe(503);
});

test('freeze 期间白名单路径 /api/admin/upgrade/status 返回 200', function () {
    $admin = Admin::factory()->create();
    UpgradeFreezeLock::freeze();

    $response = $this->actingAsAdmin($admin)->getJson('/api/admin/upgrade/status');

    $response->assertOk();
    expect($response->status())->not->toBe(503);
});

test('freeze 期间插件任务轮询 GET 放行但 fail-stale POST 仍拦截', function () {
    $admin = Admin::factory()->create();
    UpgradeFreezeLock::freeze();

    $this->actingAsAdmin($admin)
        ->getJson('/api/admin/plugin/operations')
        ->assertOk();

    $this->actingAsAdmin($admin)
        ->postJson('/api/admin/plugin/operations/example/fail-stale')
        ->assertStatus(503);
});

test('freeze 期间 admin 登录路径放行', function () {
    Admin::factory()->create([
        'username' => 'maintainer',
        'password' => 'password123',
    ]);
    UpgradeFreezeLock::freeze();

    $response = $this->postJson('/api/admin/login', [
        'account' => 'maintainer',
        'password' => 'password123',
    ]);

    // 关键：MaintenanceMode 放行 → 进入登录流程（业务 200/code=1）
    $response->assertOk()->assertJson(['code' => 1]);
});

test('unfreeze 后所有路径恢复', function () {
    $admin = Admin::factory()->create();

    UpgradeFreezeLock::freeze();
    $this->actingAsAdmin($admin)->getJson('/api/admin/order/index')->assertStatus(503);

    UpgradeFreezeLock::unfreeze();
    $response = $this->actingAsAdmin($admin)->getJson('/api/admin/order/index');

    expect($response->status())->not->toBe(503);
});

// ==========================================
// 2. freeze 期间 LogOperation 短路：非白名单短路、白名单正常写日志
// ==========================================

test('freeze 期间非白名单请求不写日志', function () {
    $admin = Admin::factory()->create();
    UpgradeFreezeLock::freeze();

    expect(AdminLog::count())->toBe(0);

    $this->actingAsAdmin($admin)->postJson('/api/admin/order/new', ['placeholder' => 'x'])
        ->assertStatus(503);

    expect(AdminLog::count())->toBe(0);
});

test('freeze 期间白名单请求正常写日志（保留升级流程审计追溯）', function () {
    $admin = Admin::factory()->create();
    UpgradeFreezeLock::freeze();

    expect(AdminLog::count())->toBe(0);
    expect(ApiLog::count())->toBe(0);

    // /api/admin/upgrade/status 命中白名单且不在 LogOperation excludedPaths 中，
    // 升级流程默认不备份/还原数据库（无回滚污染顾虑），freeze 期需保留审计追溯。
    $this->actingAsAdmin($admin)->getJson('/api/admin/upgrade/status')->assertOk();

    expect(AdminLog::where('url', 'like', '%/api/admin/upgrade/status%')->count())->toBe(1);
});

// ==========================================
// 3. /api/admin/upgrade/freeze 路由
// ==========================================

test('POST /api/admin/upgrade/freeze 写入锁文件且 info 返回字段正确', function () {
    $admin = Admin::factory()->create();

    $response = $this->actingAsAdmin($admin)->postJson('/api/admin/upgrade/freeze');

    $response->assertOk()->assertJson(['code' => 1]);
    expect(UpgradeFreezeLock::isFrozen())->toBeTrue();

    $info = UpgradeFreezeLock::info();
    expect($info)->not->toBeNull()
        ->and($info)->toHaveKeys(['frozen_at', 'version_from', 'version_to', 'ttl_seconds']);
    expect($info['version_from'])->toBeNull();
    expect($info['version_to'])->toBeNull();
    expect($info['ttl_seconds'])->toBe(7200);

    $response->assertJsonPath('data.ttl_seconds', 7200);
});

test('POST /api/admin/upgrade/freeze 带参数写入完整字段', function () {
    $admin = Admin::factory()->create();

    $response = $this->actingAsAdmin($admin)->postJson('/api/admin/upgrade/freeze', [
        'version_from' => '1.2.3',
        'version_to' => '1.3.0',
        'ttl_seconds' => 1800,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    $info = UpgradeFreezeLock::info();
    expect($info)->not->toBeNull();
    expect($info['version_from'])->toBe('1.2.3');
    expect($info['version_to'])->toBe('1.3.0');
    expect($info['ttl_seconds'])->toBe(1800);

    $response->assertJsonPath('data.version_from', '1.2.3');
    $response->assertJsonPath('data.version_to', '1.3.0');
    $response->assertJsonPath('data.ttl_seconds', 1800);
});

test('POST /api/admin/upgrade/unfreeze 删除锁文件', function () {
    $admin = Admin::factory()->create();
    UpgradeFreezeLock::freeze('1.0.0', '1.1.0');
    expect(UpgradeFreezeLock::isFrozen())->toBeTrue();

    $response = $this->actingAsAdmin($admin)->postJson('/api/admin/upgrade/unfreeze');

    $response->assertOk()->assertJson(['code' => 1]);
    expect(UpgradeFreezeLock::isFrozen())->toBeFalse();
    expect(file_exists(UpgradeFreezeLock::path()))->toBeFalse();
});

test('POST /api/admin/upgrade/unfreeze 不能冒充成功解除 restore 锁', function () {
    $admin = Admin::factory()->create();
    UpgradeFreezeLock::freezeRestore('database restore');

    $response = $this->actingAsAdmin($admin)->postJson('/api/admin/upgrade/unfreeze');

    $response->assertOk()
        ->assertJson(['code' => 0]);
    expect($response->json('msg'))->toContain('数据库恢复')
        ->and(UpgradeFreezeLock::isFrozen())->toBeTrue();
});

test('POST /api/admin/upgrade/freeze 拒绝非法 ttl_seconds（< 60）', function () {
    $admin = Admin::factory()->create();

    $response = $this->actingAsAdmin($admin)->postJson('/api/admin/upgrade/freeze', [
        'ttl_seconds' => 30,
    ]);

    // ApiExceptions 把 ValidationException 包成 status=200 / code=0 / errors，不是 422
    // ttl_seconds 字段必须出现在校验错误中
    $response->assertOk()->assertJson(['code' => 0]);
    $response->assertJsonStructure(['errors' => ['ttl_seconds']]);
    expect(UpgradeFreezeLock::isFrozen())->toBeFalse();
});

test('freeze / unfreeze 路由在 admin 中间件下，无 admin token 拒绝访问', function () {
    // 无 token 直接 POST，AdminAuthenticate 应 401
    $this->postJson('/api/admin/upgrade/freeze')->assertUnauthorized();
    $this->postJson('/api/admin/upgrade/unfreeze')->assertUnauthorized();

    // 有 admin token 即可访问，无须额外鉴权
    $admin = Admin::factory()->create();
    $this->actingAsAdmin($admin)->postJson('/api/admin/upgrade/freeze')->assertOk();
    $this->actingAsAdmin($admin)->postJson('/api/admin/upgrade/unfreeze')->assertOk();
});

test('restore 冻结期间仅精确 GET 状态路径和合法 token 放行', function () {
    $token = str_repeat('a', 32);
    Cache::put(BackupService::JOB_CACHE_PREFIX.$token, [
        'status' => 'running',
        'stage' => 'import',
        'message' => '正在恢复数据库',
    ], 600);
    UpgradeFreezeLock::freezeRestore('database restore');

    $this->getJson('/api/admin/database/jobs/'.$token)
        ->assertOk()
        ->assertJsonPath('data.progress.status', 'running');

    $this->postJson('/api/admin/database/jobs/'.$token)->assertStatus(503);
    $this->getJson('/api/admin/database/jobs/'.$token.'/extra')->assertStatus(503);
    $this->getJson('/api/admin/database/jobs/invalid-token')->assertStatus(503);
    $this->getJson('/api/admin/database/backups')->assertStatus(503);
});

test('restore 冻结期间未知但格式合法的状态 token 返回 404', function () {
    Cache::forget(BackupService::JOB_CACHE_PREFIX.str_repeat('b', 32));
    UpgradeFreezeLock::freezeRestore('database restore');

    $this->getJson('/api/admin/database/jobs/'.str_repeat('b', 32))->assertNotFound();
});

test('restore 冻结期间 HEAD 状态请求仍被维护模式阻断', function () {
    $token = str_repeat('d', 32);
    Cache::put(BackupService::JOB_CACHE_PREFIX.$token, ['status' => 'running'], 600);
    UpgradeFreezeLock::freezeRestore('database restore');

    $this->call('HEAD', '/api/admin/database/jobs/'.$token)->assertStatus(503);
});
