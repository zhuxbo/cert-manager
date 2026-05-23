<?php

use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use Tests\TestCase;

uses(TestCase::class);

test('BinaryNotFoundException 携带工具名、试过的路径和 diagnose 输出', function () {
    $exception = new BinaryNotFoundException(
        tool: 'openssl',
        triedPaths: ['/usr/bin/openssl', '/usr/local/bin/openssl'],
        diagnose: ['open_basedir: none', 'disable_functions: proc_open=ok']
    );

    expect($exception->getMessage())->toContain('openssl');
    expect($exception->getTool())->toBe('openssl');
    expect($exception->getTriedPaths())->toEqual(['/usr/bin/openssl', '/usr/local/bin/openssl']);
    expect($exception->diagnose())->toContain('open_basedir: none');
});

test('probeWith 通过真实 php -v 子进程校验 PHP_BINARY 可执行', function () {
    $locator = new BinaryLocator;

    // 反射调用 protected probeWith，避免污染 public API
    $reflect = new ReflectionMethod($locator, 'probeWith');
    $ok = $reflect->invoke($locator, [PHP_BINARY, '-v'], 'PHP ');

    expect($ok)->toBeTrue();
});

test('probeWith 在路径不存在时返回 false', function () {
    $locator = new BinaryLocator;
    $reflect = new ReflectionMethod($locator, 'probeWith');

    expect($reflect->invoke($locator, ['/nonexistent/bin/xxx', '--version'], 'XXX'))->toBeFalse();
});

test('probeWith 在输出特征不匹配时返回 false', function () {
    $locator = new BinaryLocator;
    $reflect = new ReflectionMethod($locator, 'probeWith');

    // php -v 输出含 "PHP "，不含 "Distrib"
    expect($reflect->invoke($locator, [PHP_BINARY, '-v'], 'Distrib'))->toBeFalse();
});

test('php() 在 CLI 进程内返回 PHP_BINARY 自身', function () {
    // 测试本身就跑在 CLI 进程内，PHP_BINARY 不含 fpm
    $locator = new BinaryLocator;
    expect($locator->php())->toBe(PHP_BINARY);
});

test('php() 第二次调用走 memoize，不重新探测', function () {
    $locator = new BinaryLocator;

    $first = $locator->php();
    $reflect = new ReflectionProperty($locator, 'resolved');
    $reflect->setAccessible(true);

    expect($reflect->getValue($locator))->toHaveKey('php');
    expect($locator->php())->toBe($first);
});

test('php() 模拟 FPM 进程时推断同目录 CLI', function () {
    // 用匿名子类把 currentPhpBinary() 覆盖成 fpm 路径
    $locator = new class extends BinaryLocator
    {
        public bool $inferCalled = false;

        protected function currentPhpBinary(): string
        {
            return PHP_BINARY; // 实际仍返回当前 CLI（确保推断后能找到真实文件）
        }

        protected function inferCliFromFpm(string $fpm): string
        {
            // 标记被调用，并返回当前 CLI 让 probe 通过
            $this->inferCalled = true;

            return PHP_BINARY;
        }

        protected function looksLikeFpm(string $path): bool
        {
            return true; // 强制走 fpm 推断分支
        }
    };

    expect($locator->php())->toBe(PHP_BINARY);
    expect($locator->inferCalled)->toBeTrue();
});

test('composer() 返回 {php} {phar} 完整命令串', function () {
    $locator = new BinaryLocator;

    try {
        $cmd = $locator->composer();
    } catch (BinaryNotFoundException $e) {
        $this->markTestSkipped('本机无 composer，跳过: '.$e->getMessage());
    }

    // 必须含 PHP 路径 + composer 路径，且 PHP 在前
    expect($cmd)->toStartWith(escapeshellarg($locator->php()));
    expect($cmd)->toContain('composer');
});

test('composer() 在找不到 phar 时抛 BinaryNotFoundException', function () {
    $locator = new class extends BinaryLocator
    {
        protected function composerCandidatePaths(): array
        {
            return ['/nonexistent/composer1', '/nonexistent/composer2'];
        }

        protected function pathFinderResult(string $tool): ?string
        {
            // 强制 PATH 也找不到
            return null;
        }
    };

    expect(fn () => $locator->composer())->toThrow(BinaryNotFoundException::class);
});

test('openssl() 通过 PATH 解析（开发机要求装了 openssl）', function () {
    $locator = new BinaryLocator;

    try {
        $path = $locator->openssl();
        expect($path)->toBeString()->and(strlen($path))->toBeGreaterThan(0);
    } catch (BinaryNotFoundException $e) {
        $this->markTestSkipped('本机无 openssl，跳过');
    }
});

test('mysqldump() 找不到时抛 BinaryNotFoundException', function () {
    $locator = new class extends BinaryLocator
    {
        protected function candidatePathsFor(string $tool): array
        {
            return ['/nonexistent/bin/mysqldump'];
        }

        protected function pathFinderResult(string $tool): ?string
        {
            return null; // 模拟 PATH 也找不到
        }
    };

    expect(fn () => $locator->mysqldump())->toThrow(BinaryNotFoundException::class, 'mysqldump');
});

test('curl() 第二次调用复用 memoize', function () {
    $locator = new BinaryLocator;
    try {
        $first = $locator->curl();
        expect($locator->curl())->toBe($first);
    } catch (BinaryNotFoundException $e) {
        $this->markTestSkipped('本机无 curl，跳过');
    }
});

test('BinaryNotFoundException 抛出时包含 diagnose 多行', function () {
    $locator = new class extends BinaryLocator
    {
        protected function candidatePathsFor(string $tool): array
        {
            return ['/nonexistent/keytool'];
        }

        protected function pathFinderResult(string $tool): ?string
        {
            return null;
        }
    };

    try {
        $locator->keytool();
        $this->fail('应该抛异常');
    } catch (BinaryNotFoundException $e) {
        $lines = $e->diagnose();
        expect($lines)->toBeArray();
        expect(implode("\n", $lines))->toContain('keytool');
    }
});

test('diagnose 包含 open_basedir 和 disable_functions 信息', function () {
    $locator = new BinaryLocator;
    $lines = $locator->diagnose('mysqldump');

    $joined = implode("\n", $lines);
    expect($joined)->toContain('open_basedir');
    expect($joined)->toContain('disable_functions');
});

test('inferCliFromFpm 把 fpm 路径替换为同目录 CLI', function () {
    $locator = new BinaryLocator;
    $reflect = new ReflectionMethod($locator, 'inferCliFromFpm');

    expect($reflect->invoke($locator, '/www/server/php/84/sbin/php-fpm'))
        ->toBe('/www/server/php/84/bin/php');
});

test('inspectFpmIni 直接读当前进程 ini', function () {
    $locator = new BinaryLocator;
    $info = $locator->inspectFpmIni();

    expect($info)->toHaveKeys(['ini_path', 'disable_functions', 'disable_functions_ok']);
    expect($info['ini_path'])->toBe(php_ini_loaded_file() ?: null);
    expect($info['disable_functions_ok'])->toBeBool();
});

test('inspectCliIni 通过 CLI 子进程读 ini_get', function () {
    $locator = new BinaryLocator;
    $info = $locator->inspectCliIni();

    expect($info)->toHaveKeys(['ini_path', 'disable_functions', 'disable_functions_ok']);
    // 测试本身就是 CLI 进程，CLI 子进程必然能跑通
    expect($info['ini_path'])->toBeString();
});

test('inspectCliIni 在 proc_open 失败时返回 error', function () {
    $locator = new class extends BinaryLocator
    {
        public function php(): string
        {
            return '/nonexistent/php';
        }
    };
    $info = $locator->inspectCliIni();

    expect($info)->toHaveKey('error');
});

test('容器 app(BinaryLocator::class) 返回单例', function () {
    $a = app(BinaryLocator::class);
    $b = app(BinaryLocator::class);

    expect($a)->toBe($b);
});
