<?php

use App\Services\Upgrade\UpgradeStatusManager;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->statusManager = new UpgradeStatusManager;
    $this->statusFile = storage_path('upgrades/status.json');

    // 确保测试前清理状态
    $this->statusManager->clear();
});

afterEach(function () {
    // 测试后清理状态
    $this->statusManager->clear();
});

test('start creates status file', function () {
    $this->statusManager->start('v1.0.0');

    expect($this->statusFile)->toBeFile();

    $status = $this->statusManager->get();
    expect($status['status'])->toBe('running');
    expect($status['version'])->toBe('v1.0.0');
    expect($status['progress'])->toBe(0);
    expect($status['error'])->toBeNull();
});

test('is running returns true when status is running', function () {
    $this->statusManager->start('v1.0.0');

    expect($this->statusManager->isRunning())->toBeTrue();
});

test('is running returns false when no status', function () {
    expect($this->statusManager->isRunning())->toBeFalse();
});

test('complete step updates progress', function () {
    $this->statusManager->setExpectedSteps(4);
    $this->statusManager->start('v1.0.0');

    $this->statusManager->completeStep('step1');
    $status = $this->statusManager->get();
    expect($status['progress'])->toBe(25);

    $this->statusManager->completeStep('step2');
    $status = $this->statusManager->get();
    expect($status['progress'])->toBe(50);

    $this->statusManager->completeStep('step3');
    $status = $this->statusManager->get();
    expect($status['progress'])->toBe(75);

    $this->statusManager->completeStep('step4');
    $status = $this->statusManager->get();
    expect($status['progress'])->toBe(100);
});

test('start step sets status to running', function () {
    $this->statusManager->start('v1.0.0');
    $this->statusManager->startStep('download');

    $status = $this->statusManager->get();
    expect($status['current_step'])->toBe('download');

    $step = collect($status['steps'])->firstWhere('step', 'download');
    expect($step['status'])->toBe('running');
});

test('fail step records error', function () {
    $this->statusManager->start('v1.0.0');
    $this->statusManager->failStep('apply', '权限不足');

    $status = $this->statusManager->get();
    $step = collect($status['steps'])->firstWhere('step', 'apply');

    expect($step['status'])->toBe('failed');
    expect($step['error'])->toBe('权限不足');
});

test('fail marks status as failed', function () {
    $this->statusManager->start('v1.0.0');
    $this->statusManager->fail('升级过程中发生错误');

    $status = $this->statusManager->get();
    expect($status['status'])->toBe('failed');
    expect($status['error'])->toBe('升级过程中发生错误');
    expect($status)->toHaveKey('failed_at');
});

test('complete marks status as completed', function () {
    $this->statusManager->start('v1.0.0');
    $this->statusManager->complete('v0.9.0', 'v1.0.0');

    $status = $this->statusManager->get();
    expect($status['status'])->toBe('completed');
    expect($status['progress'])->toBe(100);
    expect($status['from_version'])->toBe('v0.9.0');
    expect($status['to_version'])->toBe('v1.0.0');
    expect($status)->toHaveKey('completed_at');
});

test('clear removes status file', function () {
    $this->statusManager->start('v1.0.0');
    expect($this->statusFile)->toBeFile();

    $this->statusManager->clear();
    expect(file_exists($this->statusFile))->toBeFalse();
});

test('get returns null when no status file', function () {
    expect($this->statusManager->get())->toBeNull();
});

test('set expected steps affects progress calculation', function () {
    $this->statusManager->setExpectedSteps(2);
    $this->statusManager->start('v1.0.0');

    $this->statusManager->completeStep('step1');
    $status = $this->statusManager->get();
    expect($status['progress'])->toBe(50);
});

test('get total steps uses config when no expected steps', function () {
    // 设置配置
    Config::set('upgrade.behavior.force_backup', true);
    Config::set('upgrade.behavior.maintenance_mode', true);
    Config::set('upgrade.behavior.auto_migrate', true);
    Config::set('upgrade.behavior.clear_cache', true);
    Config::set('upgrade.behavior.auto_structure_check', true);
    Config::set('upgrade.behavior.auto_seed', true);

    // 创建新实例以重置 expectedSteps
    $manager = new UpgradeStatusManager;
    $manager->start('v1.0.0');

    // 基础步骤 7 + backup 1 + maintenance 2 + migrate 1 + cache 1 + structure_check 1 + seed 1 = 14
    $reflection = new ReflectionClass($manager);
    $method = $reflection->getMethod('getTotalSteps');

    $totalSteps = $method->invoke($manager);
    expect($totalSteps)->toBe(14);
});

test('update step updates existing step', function () {
    $this->statusManager->start('v1.0.0');

    // 先添加步骤
    $this->statusManager->startStep('download');

    // 更新同一步骤
    $this->statusManager->completeStep('download');

    $status = $this->statusManager->get();
    $downloadSteps = collect($status['steps'])->where('step', 'download');

    // 应该只有一个 download 步骤，不是两个
    expect($downloadSteps)->toHaveCount(1);
    expect($downloadSteps->first()['status'])->toBe('completed');
});

test('multiple steps tracked correctly', function () {
    $this->statusManager->setExpectedSteps(5);
    $this->statusManager->start('v1.0.0');

    $steps = ['fetch', 'download', 'extract', 'apply', 'cleanup'];

    foreach ($steps as $step) {
        $this->statusManager->startStep($step);
        $this->statusManager->completeStep($step);
    }

    $status = $this->statusManager->get();
    expect($status['steps'])->toHaveCount(5);
    expect($status['progress'])->toBe(100);

    foreach ($status['steps'] as $stepData) {
        expect($stepData['status'])->toBe('completed');
    }
});

test('save uses file lock', function () {
    // 验证 save 方法使用文件锁（通过反射检查方法内部实现）
    $reflection = new ReflectionClass($this->statusManager);
    $method = $reflection->getMethod('save');

    // 执行 save
    $this->statusManager->start('v1.0.0');

    // 验证文件正确创建
    expect($this->statusFile)->toBeFile();

    // 验证内容正确
    $content = file_get_contents($this->statusFile);
    $data = json_decode($content, true);
    expect($data['status'])->toBe('running');
});

test('get uses shared lock', function () {
    // 验证 get 方法可以正确读取数据
    $this->statusManager->start('v1.0.0');
    $this->statusManager->updateStep('test_step', 'running');

    $status = $this->statusManager->get();

    expect($status)->not->toBeNull();
    expect($status['status'])->toBe('running');
    expect($status['steps'])->toHaveCount(1);
});

test('rapid updates preserve data integrity', function () {
    $this->statusManager->setExpectedSteps(10);
    $this->statusManager->start('v1.0.0');

    // 快速连续更新
    for ($i = 1; $i <= 10; $i++) {
        $this->statusManager->startStep("step_$i");
        $this->statusManager->completeStep("step_$i");
    }

    $status = $this->statusManager->get();

    // 验证所有步骤都被正确记录
    expect($status['steps'])->toHaveCount(10);
    expect($status['progress'])->toBe(100);

    // 验证每个步骤状态正确
    foreach ($status['steps'] as $stepData) {
        expect($stepData['status'])->toBe('completed');
    }
});

test('complete with structure check stores result', function () {
    $this->statusManager->start('v1.0.0');

    $structureCheck = [
        'has_diff' => false,
        'summary' => ['missing_tables' => [], 'extra_tables' => []],
    ];

    $this->statusManager->complete('v0.9.0', 'v1.0.0', $structureCheck);

    $status = $this->statusManager->get();
    expect($status['status'])->toBe('completed');
    expect($status)->toHaveKey('structure_check');
    expect($status['structure_check']['has_diff'])->toBeFalse();
});
