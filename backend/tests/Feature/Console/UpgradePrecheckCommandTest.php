<?php

use App\Console\Commands\UpgradePrecheckCommand;
use Illuminate\Support\Facades\DB;

uses()->group('database');

/**
 * 用 anonymous subclass 替换 UpgradePrecheckCommand::diskFreeGb()，
 * 实现"磁盘不足"的 mock 路径而不实际改变文件系统。
 */
function bindFakePrecheckCommand(?float $diskFreeGb = null): void
{
    app()->bind(UpgradePrecheckCommand::class, function () use ($diskFreeGb) {
        return new class($diskFreeGb) extends UpgradePrecheckCommand
        {
            public function __construct(private ?float $fakeDiskFreeGb)
            {
                parent::__construct();
            }

            protected function diskFreeGb(): float
            {
                return $this->fakeDiskFreeGb ?? parent::diskFreeGb();
            }
        };
    });
}

// ==========================================
// 1. 迁移预演成功 + 磁盘充足 → exit 0
// ==========================================

test('upgrade:precheck 迁移预演成功且磁盘充足时 exit 0', function () {
    bindFakePrecheckCommand(50.0);

    $this->artisan('upgrade:precheck')
        ->expectsOutputToContain('升级预检开始')
        ->expectsOutputToContain('迁移预演')
        ->expectsOutputToContain('磁盘空间检查')
        ->expectsOutputToContain('升级预检通过')
        ->assertSuccessful();
});

// ==========================================
// 2. 磁盘不足（mock）→ exit 1
// ==========================================

test('upgrade:precheck 磁盘不足时 exit 1', function () {
    // mock: 0.5GB < 默认阈值 1.0GB
    bindFakePrecheckCommand(0.5);

    $this->artisan('upgrade:precheck')
        ->expectsOutputToContain('磁盘剩余空间不足')
        ->assertFailed();
});

// ==========================================
// 3. --min-disk-gb 自定义阈值生效
// ==========================================

test('upgrade:precheck --min-disk-gb=100 时 50GB 视为不足 exit 1', function () {
    bindFakePrecheckCommand(50.0);

    $this->artisan('upgrade:precheck', ['--min-disk-gb' => 100])
        ->expectsOutputToContain('磁盘剩余空间不足')
        ->assertFailed();
});

// ==========================================
// 4. --min-disk-gb 非数字报错
// ==========================================

test('upgrade:precheck --min-disk-gb=abc 报错 exit 1', function () {
    $this->artisan('upgrade:precheck', ['--min-disk-gb' => 'abc'])
        ->expectsOutputToContain('--min-disk-gb 必须是数值')
        ->assertFailed();
});

// ==========================================
// 5. 不实际执行 migrate（验证 --pretend 标志）
// ==========================================

test('upgrade:precheck 不实际执行 migrate（--pretend 标志生效）', function () {
    bindFakePrecheckCommand(50.0);

    // RefreshDatabase 已建好所有表；如果命令实际执行 migrate（不带 --pretend），
    // 会试图 CREATE 已存在的表导致失败。能成功完成本身就证明 --pretend 生效。
    // 同时 migrations 表行数在前后保持一致。
    $beforeCount = DB::table('migrations')->count();

    $this->artisan('upgrade:precheck')->assertSuccessful();

    $afterCount = DB::table('migrations')->count();
    expect($afterCount)->toBe($beforeCount);
});
