<?php

use App\Models\Admin;
use App\Services\Upgrade\SmokeChecker;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class)->group('database');

/**
 * 用 anonymous subclass 替换 SmokeChecker 的内部 check 方法。
 *
 * 路由 dispatch 通过参数注入拿到 SmokeChecker 实例，所以 app->bind 后会拿到替代实例。
 *
 * @param  array{
 *   db?: array{ok: bool, message?: string},
 *   jobs_table?: array{ok: bool, message?: string},
 *   critical_routes?: array{ok: bool, missing?: list<string>}
 * }  $overrides
 */
function bindFakeSmokeChecker(array $overrides): void
{
    app()->bind(SmokeChecker::class, function () use ($overrides) {
        return new class($overrides) extends SmokeChecker
        {
            public function __construct(private array $overrides) {}

            public function dbCheck(): array
            {
                return $this->overrides['db'] ?? parent::dbCheck();
            }

            public function jobsTableCheck(): array
            {
                return $this->overrides['jobs_table'] ?? parent::jobsTableCheck();
            }

            public function criticalRoutesCheck(): array
            {
                return $this->overrides['critical_routes'] ?? parent::criticalRoutesCheck();
            }
        };
    });
}

// ==========================================
// 1. 全部 check ok → 200 / status=ok
// ==========================================

test('smoke 全部检查通过返回 200 / status=ok', function () {
    bindFakeSmokeChecker([
        'db' => ['ok' => true],
        'jobs_table' => ['ok' => true],
        'critical_routes' => ['ok' => true],
    ]);

    $admin = Admin::factory()->create();

    $response = $this->actingAsAdmin($admin)->postJson('/api/admin/upgrade/smoke');

    $response->assertOk()->assertJson([
        'code' => 1,
        'data' => [
            'status' => 'ok',
            'checks' => [
                'db' => ['ok' => true],
                'jobs_table' => ['ok' => true],
                'critical_routes' => ['ok' => true],
            ],
        ],
    ]);
});

// ==========================================
// 2. DB 故障 → 503 / status=failed
// ==========================================

test('DB ping 失败返回 503 / status=failed / checks.db.ok=false', function () {
    bindFakeSmokeChecker([
        'db' => ['ok' => false, 'message' => 'Connection refused'],
        'jobs_table' => ['ok' => true],
        'critical_routes' => ['ok' => true],
    ]);

    $admin = Admin::factory()->create();

    $response = $this->actingAsAdmin($admin)->postJson('/api/admin/upgrade/smoke');

    $response->assertStatus(503);
    $response->assertJson([
        'code' => 0,
        'data' => [
            'status' => 'failed',
            'checks' => [
                'db' => ['ok' => false],
            ],
        ],
    ]);
});

// ==========================================
// 3. jobs 表不可读 → 503
// ==========================================

test('jobs 表不可读返回 503', function () {
    bindFakeSmokeChecker([
        'db' => ['ok' => true],
        'jobs_table' => ['ok' => false, 'message' => 'Table jobs not found'],
        'critical_routes' => ['ok' => true],
    ]);

    $admin = Admin::factory()->create();

    $response = $this->actingAsAdmin($admin)->postJson('/api/admin/upgrade/smoke');

    $response->assertStatus(503);
    expect($response->json('data.status'))->toBe('failed');
    expect($response->json('data.checks.jobs_table.ok'))->toBeFalse();
});

// ==========================================
// 4. 关键路由未注册 → 503
// ==========================================

test('关键路由缺失返回 503 + missing 列表', function () {
    bindFakeSmokeChecker([
        'db' => ['ok' => true],
        'jobs_table' => ['ok' => true],
        'critical_routes' => [
            'ok' => false,
            'missing' => ['GET api/health', 'POST api/admin/upgrade/freeze'],
        ],
    ]);

    $admin = Admin::factory()->create();

    $response = $this->actingAsAdmin($admin)->postJson('/api/admin/upgrade/smoke');

    $response->assertStatus(503);
    expect($response->json('data.status'))->toBe('failed');
    expect($response->json('data.checks.critical_routes.missing'))->toBe([
        'GET api/health',
        'POST api/admin/upgrade/freeze',
    ]);
});

// ==========================================
// 5. 真实 SmokeChecker：不 bind，全部走真实实现
// ==========================================

test('真实 SmokeChecker 全部检查通过（不 bind）', function () {
    // 不 bind：默认 DB 已就绪、jobs 表存在、关键路由已注册
    $admin = Admin::factory()->create();

    $response = $this->actingAsAdmin($admin)->postJson('/api/admin/upgrade/smoke');

    $response->assertOk();
    expect($response->json('data.status'))->toBe('ok');
    expect($response->json('data.checks.db.ok'))->toBeTrue();
    expect($response->json('data.checks.jobs_table.ok'))->toBeTrue();
    expect($response->json('data.checks.critical_routes.ok'))->toBeTrue();
});

// ==========================================
// 6. 仅 admin 可调
// ==========================================

test('未登录时 POST /api/admin/upgrade/smoke 返回 401', function () {
    $this->postJson('/api/admin/upgrade/smoke')->assertUnauthorized();
});
