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

// ============================================================================
// isRedisRequiredFromEnv — 与 deploy/upgrade.sh::_redis_required_from_env 对称
// ============================================================================

function makeEnvFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'envtest');
    file_put_contents($path, $content);
    $GLOBALS['__env_checker_temp_files'][] = $path;

    return $path;
}

test('isRedisRequiredFromEnv: CACHE_DRIVER=redis 命中', function () {
    $env = makeEnvFile("CACHE_DRIVER=redis\nQUEUE_CONNECTION=database\n");
    expect($this->checker->isRedisRequiredFromEnv($env))->toBeTrue();
});

test('isRedisRequiredFromEnv: QUEUE_CONNECTION=redis 命中', function () {
    $env = makeEnvFile("CACHE_DRIVER=file\nQUEUE_CONNECTION=redis\n");
    expect($this->checker->isRedisRequiredFromEnv($env))->toBeTrue();
});

test('isRedisRequiredFromEnv: CACHE_STORE=redis 命中（L11+ 别名）', function () {
    $env = makeEnvFile("CACHE_STORE=redis\nQUEUE_CONNECTION=database\n");
    expect($this->checker->isRedisRequiredFromEnv($env))->toBeTrue();
});

test('isRedisRequiredFromEnv: 双引号包裹 redis 命中', function () {
    $env = makeEnvFile("CACHE_DRIVER=\"redis\"\n");
    expect($this->checker->isRedisRequiredFromEnv($env))->toBeTrue();
});

test('isRedisRequiredFromEnv: 单引号包裹 redis 命中', function () {
    $env = makeEnvFile("CACHE_DRIVER='redis'\n");
    expect($this->checker->isRedisRequiredFromEnv($env))->toBeTrue();
});

test('isRedisRequiredFromEnv: 行内注释剥离正确', function () {
    $env = makeEnvFile("CACHE_DRIVER=redis # use redis\n");
    expect($this->checker->isRedisRequiredFromEnv($env))->toBeTrue();
});

test('isRedisRequiredFromEnv: 大小写不敏感', function () {
    $env = makeEnvFile("CACHE_DRIVER=REDIS\n");
    expect($this->checker->isRedisRequiredFromEnv($env))->toBeTrue();
});

test('isRedisRequiredFromEnv: CRLF 行尾 OK', function () {
    $env = makeEnvFile("CACHE_DRIVER=redis\r\nQUEUE_CONNECTION=db\r\n");
    expect($this->checker->isRedisRequiredFromEnv($env))->toBeTrue();
});

test('isRedisRequiredFromEnv: 整行注释不命中', function () {
    $env = makeEnvFile("#CACHE_DRIVER=redis\nCACHE_DRIVER=file\n");
    expect($this->checker->isRedisRequiredFromEnv($env))->toBeFalse();
});

test('isRedisRequiredFromEnv: 都用非 redis 时不命中', function () {
    $env = makeEnvFile("CACHE_DRIVER=file\nQUEUE_CONNECTION=database\n");
    expect($this->checker->isRedisRequiredFromEnv($env))->toBeFalse();
});

test('isRedisRequiredFromEnv: REDIS_HOST 这种含 redis 的 key 不误命中', function () {
    $env = makeEnvFile("REDIS_HOST=127.0.0.1\nCACHE_DRIVER=file\n");
    expect($this->checker->isRedisRequiredFromEnv($env))->toBeFalse();
});

test('isRedisRequiredFromEnv: .env 缺失返回 false（fail-safe）', function () {
    expect($this->checker->isRedisRequiredFromEnv('/nonexistent/path/.env'))->toBeFalse();
});

// ============================================================================
// check() 集成：.env 动态升级 redis 为必装
// ============================================================================

test('check: .env 启用 redis 时 redis_dyn_required=true', function () {
    $req = makeRequirements([
        'php_min' => '7.0.0',
        'extensions' => ['recommended' => ['redis']],
    ]);
    $env = makeEnvFile("CACHE_DRIVER=redis\n");

    $report = $this->checker->check($req, $env);

    expect($report['extensions']['redis_dyn_required'])->toBeTrue();
});

test('check: .env 未启用 redis 时 redis_dyn_required=false', function () {
    $req = makeRequirements([
        'php_min' => '7.0.0',
        'extensions' => ['recommended' => ['redis']],
    ]);
    $env = makeEnvFile("CACHE_DRIVER=file\nQUEUE_CONNECTION=database\n");

    $report = $this->checker->check($req, $env);

    expect($report['extensions']['redis_dyn_required'])->toBeFalse();
});

test('check: redis 已升级为必装时不出现在 missing_recommended', function () {
    // 强制场景：redis 同时出现在 recommended 列表，但 dyn_required=true 后必须从 recommended 路径跳过
    // 不依赖 redis 是否实际加载 — 即使加载了，跳过逻辑也保证 missing_recommended 不含 redis
    $req = makeRequirements([
        'php_min' => '7.0.0',
        'extensions' => ['recommended' => ['redis']],
    ]);
    $env = makeEnvFile("CACHE_DRIVER=redis\n");

    $report = $this->checker->check($req, $env);

    expect($report['extensions']['missing_recommended'])->not->toContain('redis');
});

test('check: redis 未升级为必装时仍走推荐路径（不影响 ok）', function () {
    // CI 环境 redis 已加载，无法直接断言"未加载时进 missing_recommended"
    // 退而验证：dyn_required=false 时不会从 recommended 跳过（即逻辑路径正确）
    $req = makeRequirements([
        'php_min' => '7.0.0',
        'extensions' => ['recommended' => ['redis']],
    ]);
    $env = makeEnvFile("CACHE_DRIVER=file\n");

    $report = $this->checker->check($req, $env);

    expect($report['extensions']['redis_dyn_required'])->toBeFalse();
    // redis 加载时 missing_recommended 为空；未加载时含 redis — 两种情况均合法
    if (! extension_loaded('redis')) {
        expect($report['extensions']['missing_recommended'])->toContain('redis');
    }
    expect($report['ok'])->toBeTrue();
});

test('check: 缺省 envPath 回落到 base_path(.env) 不抛异常', function () {
    // 不指定 envPath 时，函数内部会调 base_path('.env')
    // 该路径在测试环境可能存在也可能不存在，但都不能让 check() 抛异常
    $req = makeRequirements(['php_min' => '7.0.0']);

    $report = $this->checker->check($req);

    expect($report)->toHaveKey('extensions');
    expect($report['extensions'])->toHaveKey('redis_dyn_required');
});

test('summarize: redis 因 dyn_required 进 missing 时 message 附加来源说明', function () {
    // 构造 report 直接喂 summarize，规避 extension_loaded('redis') 的环境依赖
    $reflection = new ReflectionClass($this->checker);
    $summarize = $reflection->getMethod('summarize');

    $report = [
        'ok' => false,
        'skipped' => false,
        'php' => ['current' => PHP_VERSION, 'min' => '7.0.0', 'recommended' => '8.4', 'ok' => true],
        'extensions' => [
            'missing_required' => ['redis'],
            'missing_recommended' => [],
            'redis_dyn_required' => true,
        ],
        'functions' => ['disabled_required' => [], 'disabled_recommended' => []],
    ];

    $details = $summarize->invoke($this->checker, $report);

    expect($details['redis_dyn_required'])->toBeTrue();
    expect($details['missing_extensions'])->toContain('redis');
    expect($details['message'])->toContain('缺失必需扩展: redis');
    expect($details['message'])->toContain('redis 因 backend/.env 中 cache/queue 启用 redis 列为必装');
});

test('summarize: redis 非 dyn_required 时 message 不附加来源说明', function () {
    $reflection = new ReflectionClass($this->checker);
    $summarize = $reflection->getMethod('summarize');

    // redis 因为别的原因（比如 php-requirements.json 直接列在 required）出现在 missing
    $report = [
        'ok' => false,
        'skipped' => false,
        'php' => ['current' => PHP_VERSION, 'min' => '7.0.0', 'recommended' => '8.4', 'ok' => true],
        'extensions' => [
            'missing_required' => ['redis'],
            'missing_recommended' => [],
            'redis_dyn_required' => false,
        ],
        'functions' => ['disabled_required' => [], 'disabled_recommended' => []],
    ];

    $details = $summarize->invoke($this->checker, $report);

    expect($details['message'])->toContain('缺失必需扩展: redis');
    expect($details['message'])->not->toContain('backend/.env');
});
