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
        test()->markTestSkipped('本机无 composer，跳过: '.$e->getMessage());
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

        // 强制 shell 兜底也找不到（开发机如果 PATH 上有 composer 会真探到）
        protected function probeViaShell(string $tool, string $flag, string $expected): ?string
        {
            return null;
        }
    };

    expect(fn () => $locator->composer())->toThrow(BinaryNotFoundException::class);
});

test('openssl() 通过 PATH 解析（开发机/CI 必须装 openssl）', function () {
    // 不再 markTestSkipped 兜底：早期把异常吞掉，导致 OpenSSL 3.0.x 不识别 `--version`
    // 这种"探测命令选错"的回归在 CI 静默通过，线上才报"未找到可执行 openssl"。
    // 现在硬要求 openssl 必须能被解析；若环境真的没装 openssl，请在 CI 镜像层补齐。
    $path = (new BinaryLocator)->openssl();
    expect($path)->toBeString()->and(strlen($path))->toBeGreaterThan(0);
});

test('openssl 探测命令对 OpenSSL 3.0.x 也有效（不用 --version 全局选项）', function () {
    // 回归测试：OpenSSL 3.0.x 只支持 `openssl version` 子命令，不支持 `--version` 全局选项
    // （3.2+ 才加 --version）。Ubuntu 24.04 默认 OpenSSL 3.0.13 会因此探测失败。
    $reflect = new ReflectionClass(BinaryLocator::class);
    $probes = $reflect->getConstant('SOFT_VERSION_PROBES');
    expect($probes['openssl'][0])->toBe('version')
        ->and($probes['openssl'][1])->toBe('OpenSSL');
});

test('mysqldump() 找不到时抛 BinaryNotFoundException', function () {
    $locator = new class extends BinaryLocator
    {
        protected function candidatePathsFor(string $tool): array
        {
            return ['/nonexistent/bin/mysqldump'];
        }

        protected function probeViaShell(string $tool, string $flag, string $expected): ?string
        {
            return null; // 模拟 shell 兜底也找不到
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
        test()->markTestSkipped('本机无 curl，跳过');
    }
});

test('BinaryNotFoundException 抛出时包含 diagnose 多行', function () {
    $locator = new class extends BinaryLocator
    {
        protected function candidatePathsFor(string $tool): array
        {
            return ['/nonexistent/keytool'];
        }

        protected function probeViaShell(string $tool, string $flag, string $expected): ?string
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

test('resolveSoft 在候选路径全 miss 时走 shell 兜底，返回 shell 找到的绝对路径', function () {
    $locator = new class extends BinaryLocator
    {
        public bool $shellProbed = false;

        protected function candidatePathsFor(string $tool): array
        {
            return ['/nonexistent/openssl']; // 候选必败
        }

        protected function probeViaShell(string $tool, string $flag, string $expected): ?string
        {
            $this->shellProbed = true;

            return '/opt/custom/bin/openssl'; // 模拟 shell 找到非常规路径
        }
    };

    expect($locator->openssl())->toBe('/opt/custom/bin/openssl');
    expect($locator->shellProbed)->toBeTrue();
});

test('probeViaShell 实测在标准环境下能找到 openssl 绝对路径', function () {
    // 真实探测：本机 PATH 上必有 openssl（CI/开发机标准依赖）
    $locator = new BinaryLocator;
    $reflect = new ReflectionMethod($locator, 'probeViaShell');
    $path = $reflect->invoke($locator, 'openssl', 'version', 'OpenSSL');

    expect($path)->toBeString();
    expect($path)->toStartWith('/'); // 绝对路径
    expect(file_exists($path))->toBeTrue();
});

test('probeViaShell 找不到命令时返回 null', function () {
    $locator = new BinaryLocator;
    $reflect = new ReflectionMethod($locator, 'probeViaShell');
    $path = $reflect->invoke($locator, '__definitely_not_a_real_binary__', '--version', '');

    expect($path)->toBeNull();
});

test('probeViaShell 即便父进程 PATH 为空也能靠 SHELL_FALLBACK_PATH 找到 openssl', function () {
    // 模拟宝塔 PHP-FPM clear_env=yes 场景（父进程 getenv("PATH") 为空）。
    // 验证 array_replace(getenv() ?: [], ['PATH' => SHELL_FALLBACK_PATH]) 注入
    // 真的让子 sh 能 PATH-resolve 命令，而不是靠父进程残留 PATH 偶然命中。
    $originalPath = getenv('PATH');
    try {
        putenv('PATH=');
        $locator = new BinaryLocator;
        $reflect = new ReflectionMethod($locator, 'probeViaShell');
        $path = $reflect->invoke($locator, 'openssl', 'version', 'OpenSSL');

        expect($path)->toBeString();
        expect($path)->toStartWith('/');
        expect(file_exists($path))->toBeTrue();
    } finally {
        putenv('PATH='.$originalPath);
    }
});

test('BinaryLocator 不再依赖 Symfony ExecutableFinder', function () {
    // 回归：删除 ExecutableFinder 是为了让 FPM/CLI 走完全一致的探测路径，
    // 避免"开发机能跑、生产挂"被 Symfony Finder 偷偷接住的差异
    $reflect = new ReflectionClass(BinaryLocator::class);
    expect($reflect->hasMethod('pathFinderResult'))->toBeFalse();

    // 检查实际 import 和实例化，不看注释（注释里描述删除原因会假阳性）
    $source = file_get_contents($reflect->getFileName());
    expect($source)->not->toContain('use Symfony\Component\Process\ExecutableFinder');
    expect($source)->not->toContain('new ExecutableFinder');
});

test('gmOpenssl 命中第一个支持 SM2 的系统 openssl 候选', function () {
    $locator = new class extends BinaryLocator
    {
        protected function candidatePathsFor(string $tool): array
        {
            return $tool === 'openssl' ? ['/fake/openssl-sm2'] : parent::candidatePathsFor($tool);
        }

        protected function probeSm2(string $path): bool
        {
            return $path === '/fake/openssl-sm2';
        }
    };

    expect($locator->gmOpenssl())->toBe('/fake/openssl-sm2');
});

test('gmOpenssl 跳过不支持 SM2 的候选选下一个（防普通 openssl 假阳性）', function () {
    $locator = new class extends BinaryLocator
    {
        protected function candidatePathsFor(string $tool): array
        {
            return $tool === 'openssl' ? ['/fake/openssl-libre', '/fake/openssl-sm2'] : parent::candidatePathsFor($tool);
        }

        protected function probeSm2(string $path): bool
        {
            // 模拟第一个系统 openssl 不支持 SM2（LibreSSL/编译 no-sm2），第二个才支持
            return $path === '/fake/openssl-sm2';
        }
    };

    expect($locator->gmOpenssl())->toBe('/fake/openssl-sm2');
});

test('gmOpenssl 全部候选不支持 SM2 时抛 BinaryNotFoundException', function () {
    $locator = new class extends BinaryLocator
    {
        protected function candidatePathsFor(string $tool): array
        {
            return $tool === 'openssl' ? ['/nonexistent/openssl'] : parent::candidatePathsFor($tool);
        }

        protected function probeSm2(string $path): bool
        {
            return false; // 候选 + shell 兜底全部不支持 SM2
        }
    };

    expect(fn () => $locator->gmOpenssl())->toThrow(BinaryNotFoundException::class, 'gmopenssl');
});

test('gmOpenssl 第二次调用走 memoize，不重复探测', function () {
    $locator = new class extends BinaryLocator
    {
        public int $probeCount = 0;

        protected function candidatePathsFor(string $tool): array
        {
            return $tool === 'openssl' ? ['/fake/openssl-sm2'] : parent::candidatePathsFor($tool);
        }

        protected function probeSm2(string $path): bool
        {
            $this->probeCount++;

            return true;
        }
    };

    $first = $locator->gmOpenssl();
    expect($locator->gmOpenssl())->toBe($first);
    expect($locator->probeCount)->toBe(1);
});

test('probeSm2 对不存在的路径返回 false（探测命令不恒真）', function () {
    // 回归：探测 SM2 必须真验曲线（ecparam -name SM2 -genkey），不能因命令拼写错而恒真，
    // 否则 version 通过但签不了 SM2 的 LibreSSL/老版会假阳性被选中。
    $locator = new BinaryLocator;
    $reflect = new ReflectionMethod($locator, 'probeSm2');

    expect($reflect->invoke($locator, '/nonexistent/openssl'))->toBeFalse();
});

test('csrUsesStandardEcPublicKey 区分 id-ecPublicKey 标准编码与 dual-sm2（拒 OpenSSL 3.0~3.2.0/GmSSL）', function () {
    $locator = new BinaryLocator;
    $method = new ReflectionMethod($locator, 'csrUsesStandardEcPublicKey');

    // 标准 id-ecPublicKey 编码（系统 OpenSSL 3.2.1+ / Debian backport 3.0.20 产出）→ true
    $standard = <<<'PEM'
        -----BEGIN CERTIFICATE REQUEST-----
        MIIBAzCBqwIBADBJMRQwEgYDVQQDDAt0ZXN0LjhraS5jbjELMAkGA1UEBhMCQ04x
        ETAPBgNVBAgMCFNoYW5naGFpMREwDwYDVQQHDAhTaGFuZ2hhaTBZMBMGByqGSM49
        AgEGCCqBHM9VAYItA0IABJIFxhnYYJluRd6iXY0aMMmAyMCY1TSJ0ZX/UfyJ314b
        WJ+y/2MI5LjmYH4LEQcpEySJfFaxa57ZD9rsUdO89X+gADAKBggqgRzPVQGDdQNH
        ADBEAiBFKOlNXa0g8SXyC83aSNXNXWUOGltiQ0SlTZ277P4xlQIgKHx7RUmpB58G
        +gA987jeMbtRSeQ9Eq+z5pjwJFKeLMY=
        -----END CERTIFICATE REQUEST-----
        PEM;

    // dual-sm2 编码（OpenSSL 3.0.0~3.2.0 / GmSSL，algorithm 填 sm2 曲线 OID）→ false
    $dualSm2 = <<<'PEM'
        -----BEGIN CERTIFICATE REQUEST-----
        MIIBBTCBrAIBADBJMRQwEgYDVQQDDAt0ZXN0LjhraS5jbjELMAkGA1UEBhMCQ04x
        ETAPBgNVBAgMCFNoYW5naGFpMREwDwYDVQQHDAhTaGFuZ2hhaTBaMBQGCCqBHM9V
        AYItBggqgRzPVQGCLQNCAAQ5HDNZmmPj7ZPVR1MVSY25DIA4r1GPnn2Fgd4TTstD
        R/ziR6hS3Nx2a5Bc3u7qXKup7y8pRJX7VwNY/Yl/gE/XoAAwCgYIKoEcz1UBg3UD
        SAAwRQIhAOsIdAdGBt383N1PMtzLiFIL7JZCH2O6KAtEDs6i5HPFAiA5UpZmqUML
        XqNAPA4a7LstKb6nXIPSLIS1o/gMeAKJ+A==
        -----END CERTIFICATE REQUEST-----
        PEM;

    expect($method->invoke($locator, $standard))->toBeTrue();
    expect($method->invoke($locator, $dualSm2))->toBeFalse();
});

test('gmOpenssl 在容器内真实探测到支持 SM2 的 openssl（不 mock、不 skip）', function () {
    // 国密 CSR 生成是关键能力，必须真探到支持 SM2 的 openssl（gmOpenssl 的 probeSm2 已保证返回的二进制
    // 通过 `ecparam -name SM2 -genkey` 验真）。dev 容器与 CI runner 一致，统一靠系统 OpenSSL 3.0+ 原生 SM2，
    // gmOpenssl 复用系统 openssl 候选 + shell 兜底命中。
    // 遵反模式 15 不 markTestSkipped 兜底（否则关键能力探测在 CI 静默跳过、生产才炸）。
    // 裸机（如 macOS LibreSSL）无 SM2-capable openssl 会失败，提示按 docker/README 用容器。
    $path = (new BinaryLocator)->gmOpenssl();

    expect($path)->toBeString()
        ->and(file_exists($path))->toBeTrue();
});
