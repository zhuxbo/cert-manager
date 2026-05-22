<?php

use App\Services\Upgrade\EnvironmentChecker;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->checker = new EnvironmentChecker;
    $GLOBALS['__env_checker_temp_files'] = [];
});

afterEach(function () {
    foreach ($GLOBALS['__env_checker_temp_files'] ?? [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    $GLOBALS['__env_checker_temp_files'] = [];
});

function makeRequirements(array $data): string
{
    $path = tempnam(sys_get_temp_dir(), 'phpreq');
    file_put_contents($path, json_encode($data));
    $GLOBALS['__env_checker_temp_files'][] = $path;

    return $path;
}

test('清单缺失时跳过校验，ok=true', function () {
    $report = $this->checker->check('/nonexistent/path/req.json');

    expect($report['ok'])->toBeTrue();
    expect($report['skipped'])->toBeTrue();
    expect($report['reason'])->toBe('requirements_file_missing');
});

test('清单非法 JSON 时跳过校验', function () {
    $path = tempnam(sys_get_temp_dir(), 'phpreq');
    file_put_contents($path, 'not json at all');
    $GLOBALS['__env_checker_temp_files'][] = $path;

    $report = $this->checker->check($path);

    expect($report['ok'])->toBeTrue();
    expect($report['skipped'])->toBeTrue();
    expect($report['reason'])->toBe('requirements_file_invalid');
});

test('PHP 版本满足时通过', function () {
    $path = makeRequirements(['php_min' => '7.0.0']);

    $report = $this->checker->check($path);

    expect($report['ok'])->toBeTrue();
    expect($report['php']['ok'])->toBeTrue();
    expect($report['php']['current'])->toBe(PHP_VERSION);
    expect($report['php']['min'])->toBe('7.0.0');
});

test('PHP 版本不足时 ok=false', function () {
    $path = makeRequirements(['php_min' => '99.0.0']);

    $report = $this->checker->check($path);

    expect($report['ok'])->toBeFalse();
    expect($report['php']['ok'])->toBeFalse();
});

test('缺失 required 扩展时 ok=false', function () {
    $path = makeRequirements([
        'php_min' => '7.0.0',
        'extensions' => ['required' => ['ext_definitely_not_exist_xyz']],
    ]);

    $report = $this->checker->check($path);

    expect($report['ok'])->toBeFalse();
    expect($report['extensions']['missing_required'])->toContain('ext_definitely_not_exist_xyz');
});

test('缺失 recommended 扩展不影响 ok', function () {
    $path = makeRequirements([
        'php_min' => '7.0.0',
        'extensions' => ['recommended' => ['ext_definitely_not_exist_xyz']],
    ]);

    $report = $this->checker->check($path);

    expect($report['ok'])->toBeTrue();
    expect($report['extensions']['missing_recommended'])->toContain('ext_definitely_not_exist_xyz');
});

test('已加载扩展不出现在 missing 列表', function () {
    $path = makeRequirements([
        'php_min' => '7.0.0',
        'extensions' => ['required' => ['json', 'mbstring']],
    ]);

    $report = $this->checker->check($path);

    expect($report['ok'])->toBeTrue();
    expect($report['extensions']['missing_required'])->toBeEmpty();
});

test('被禁用的 required 函数让 ok=false', function () {
    $path = makeRequirements([
        'php_min' => '7.0.0',
        'functions' => ['required' => ['fn_definitely_not_exist_xyz']],
    ]);

    $report = $this->checker->check($path);

    expect($report['ok'])->toBeFalse();
    expect($report['functions']['disabled_required'])->toContain('fn_definitely_not_exist_xyz');
});

test('summarize 含 remediation 提示用 upgrade.sh', function () {
    $path = makeRequirements([
        'php_min' => '99.0.0',
        'extensions' => ['required' => ['ext_not_exist_xyz']],
    ]);

    $report = $this->checker->check($path);
    $details = $this->checker->summarize($report);

    expect($details['type'])->toBe('php_environment');
    expect($details['remediation'])->toContain('upgrade.sh');
    expect($details['missing_extensions'])->toContain('ext_not_exist_xyz');
    expect($details['message'])->toContain('PHP 版本过低');
    expect($details['message'])->toContain('缺失必需扩展');
});

test('summarize 在所有条件满足时 message 表示符合要求', function () {
    $path = makeRequirements([
        'php_min' => '7.0.0',
        'extensions' => ['required' => ['json']],
        'functions' => ['required' => ['strlen']],
    ]);

    $report = $this->checker->check($path);
    $details = $this->checker->summarize($report);

    expect($details['message'])->toBe('PHP 环境符合要求');
});
