<?php

use App\Services\Upgrade\SmokeChecker;
use Illuminate\Support\Facades\Artisan;

uses()->group('database');

/**
 * 用 anonymous subclass 替换 SmokeChecker 内部 check 方法。
 *
 * 命令通过参数注入拿到 SmokeChecker 实例，所以 app->bind 后会拿到替代实例。
 *
 * @param  array{
 *   db?: array{ok: bool, message?: string},
 *   jobs_table?: array{ok: bool, message?: string},
 *   critical_routes?: array{ok: bool, missing?: list<string>}
 * }  $overrides
 */
function bindFakeSmokeCheckerForCommand(array $overrides): void
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
// 1. 全部 check ok → exit 0
// ==========================================

test('upgrade:smoke 全部检查通过 exit 0', function () {
    bindFakeSmokeCheckerForCommand([
        'db' => ['ok' => true],
        'jobs_table' => ['ok' => true],
        'critical_routes' => ['ok' => true],
    ]);

    $exit = Artisan::call('upgrade:smoke');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('smoke test 通过');
    expect($output)->toContain('db              : ok');
    expect($output)->toContain('jobs_table      : ok');
    expect($output)->toContain('critical_routes : ok');
});

// ==========================================
// 2. DB 故障 → exit 1 + 输出失败原因
// ==========================================

test('upgrade:smoke DB 故障时 exit 1 + 输出 db fail', function () {
    bindFakeSmokeCheckerForCommand([
        'db' => ['ok' => false, 'message' => 'Connection refused'],
        'jobs_table' => ['ok' => true],
        'critical_routes' => ['ok' => true],
    ]);

    $exit = Artisan::call('upgrade:smoke');
    $output = Artisan::output();

    expect($exit)->toBe(1);
    expect($output)->toContain('smoke test 失败');
    expect($output)->toContain('db              : fail');
    expect($output)->toContain('Connection refused');
});

// ==========================================
// 3. jobs 表不可读 → exit 1
// ==========================================

test('upgrade:smoke jobs 表故障时 exit 1', function () {
    bindFakeSmokeCheckerForCommand([
        'db' => ['ok' => true],
        'jobs_table' => ['ok' => false, 'message' => 'Table jobs not found'],
        'critical_routes' => ['ok' => true],
    ]);

    $exit = Artisan::call('upgrade:smoke');
    $output = Artisan::output();

    expect($exit)->toBe(1);
    expect($output)->toContain('smoke test 失败');
    expect($output)->toContain('jobs_table      : fail');
    expect($output)->toContain('Table jobs not found');
});

// ==========================================
// 4. 关键路由缺失 → exit 1 + missing 列表
// ==========================================

test('upgrade:smoke 关键路由缺失时 exit 1 + missing 列表', function () {
    bindFakeSmokeCheckerForCommand([
        'db' => ['ok' => true],
        'jobs_table' => ['ok' => true],
        'critical_routes' => [
            'ok' => false,
            'missing' => ['GET api/health', 'POST api/admin/upgrade/freeze'],
        ],
    ]);

    $exit = Artisan::call('upgrade:smoke');
    $output = Artisan::output();

    expect($exit)->toBe(1);
    expect($output)->toContain('smoke test 失败');
    expect($output)->toContain('critical_routes : fail');
    expect($output)->toContain('missing: GET api/health');
    expect($output)->toContain('missing: POST api/admin/upgrade/freeze');
});

// ==========================================
// 5. 真实 SmokeChecker 全部走通（不 bind）
// ==========================================

test('upgrade:smoke 真实 SmokeChecker 走通时 exit 0', function () {
    // 默认环境 DB 已就绪、jobs 表存在、关键路由已注册
    $exit = Artisan::call('upgrade:smoke');
    $output = Artisan::output();

    expect($exit)->toBe(0);
    expect($output)->toContain('smoke test 通过');
});
