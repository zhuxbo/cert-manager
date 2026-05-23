<?php

namespace App\Services\Binary;

use App\Services\Binary\Exceptions\BinaryNotFoundException;
use Symfony\Component\Process\ExecutableFinder;

class BinaryLocator
{
    /**
     * BT 面板与常见发行版 php 候选路径，按版本/优先级排序。
     */
    private const PHP_CANDIDATE_PATHS = [
        '/www/server/php/84/bin/php',
        '/www/server/php/83/bin/php',
        '/usr/local/bin/php',
        '/usr/bin/php',
    ];

    /**
     * SOFT 档工具候选路径（覆盖 BT / Linux / macOS 常见安装位置）。
     */
    private const SOFT_CANDIDATES = [
        'openssl' => ['/usr/local/bin/openssl', '/usr/bin/openssl', '/opt/openssl/bin/openssl'],
        'java' => ['/usr/local/bin/java', '/usr/bin/java'],
        'keytool' => ['/usr/local/bin/keytool', '/usr/bin/keytool'],
        'mysqldump' => ['/www/server/mysql/bin/mysqldump', '/usr/local/mysql/bin/mysqldump', '/usr/bin/mysqldump'],
        'mysql' => ['/www/server/mysql/bin/mysql', '/usr/local/mysql/bin/mysql', '/usr/bin/mysql'],
        'curl' => ['/usr/bin/curl', '/usr/local/bin/curl'],
    ];

    /**
     * SOFT 档版本探测参数 [flag, expectedOutput]；expectedOutput 为空仅校验 exit code。
     */
    private const SOFT_VERSION_PROBES = [
        'openssl' => ['--version', 'OpenSSL'],
        'java' => ['-version', ''],          // java -version 输出到 stderr，仅校验 exit 0
        'keytool' => ['-help', ''],          // keytool 无 --version；中文 locale 输出不含 'keytool'，仅校验 exit 0
        'mysqldump' => ['--version', 'Ver '],
        'mysql' => ['--version', 'Ver '],
        'curl' => ['--version', 'curl '],
    ];

    /** @var array<string, string> tool name → 解析结果（路径或命令串） */
    protected array $resolved = [];

    public function php(): string
    {
        return $this->resolved['php'] ??= $this->doResolvePhp();
    }

    /**
     * 通过 proc_open 子进程探测命令是否可执行。
     *
     * array 形式调用 proc_open（execve），不走 shell，天然防注入 + 避开 open_basedir。
     *
     * @param  string  $expectedOutput  非空时校验 stdout 含此字符串；空字符串仅校验 exit code 0
     *                                  （用于 java/keytool 等输出到 stderr 或本地化的工具）
     */
    protected function probeWith(array $command, string $expectedOutput): bool
    {
        $proc = @proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (! is_resource($proc)) {
            return false;
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        // $expectedOutput 为空：仅校验 exit code（兼容 java -version 输出到 stderr 的情况）
        return $exit === 0 && ($expectedOutput === '' || str_contains($stdout, $expectedOutput));
    }

    /**
     * 解析 php 二进制：优先 PHP_BINARY（CLI），FPM 进程下推断同级 bin/php，
     * 再退到 ExecutableFinder，最后遍历候选路径，全部失败抛 BinaryNotFoundException。
     *
     * @return string 可执行的 php 绝对路径
     */
    protected function doResolvePhp(): string
    {
        $tried = [];
        $current = $this->currentPhpBinary();

        if (! $this->looksLikeFpm($current)) {
            // 1) 当前进程是 CLI，直接复用
            $tried[] = $current;
            if ($this->probeWith([$current, '-v'], 'PHP ')) {
                return $current;
            }
        } else {
            // 2) FPM 进程下推断同级 CLI
            $candidate = $this->inferCliFromFpm($current);
            $tried[] = $candidate;
            if ($this->probeWith([$candidate, '-v'], 'PHP ')) {
                return $candidate;
            }
        }

        // 3) PATH 查找
        $found = (new ExecutableFinder)->find('php');
        if ($found !== null) {
            $tried[] = $found;
            if ($this->probeWith([$found, '-v'], 'PHP ')) {
                return $found;
            }
        }

        // 4) 遍历常见安装路径
        foreach (self::PHP_CANDIDATE_PATHS as $path) {
            $tried[] = $path;
            if ($this->probeWith([$path, '-v'], 'PHP ')) {
                return $path;
            }
        }

        throw new BinaryNotFoundException(
            tool: 'php',
            triedPaths: $tried,
            diagnose: $this->diagnose('php'),
        );
    }

    /**
     * 当前进程对应的 php 二进制路径，独立成方法便于测试覆盖。
     */
    protected function currentPhpBinary(): string
    {
        return PHP_BINARY;
    }

    /**
     * 判定路径是否为 FPM 进程，独立成方法便于测试强制走 FPM 分支。
     */
    protected function looksLikeFpm(string $path): bool
    {
        return str_contains($path, 'fpm');
    }

    /**
     * 由 FPM 路径推断同目录 CLI：php-fpm → php、sbin → bin。
     */
    protected function inferCliFromFpm(string $fpm): string
    {
        return str_replace(['php-fpm', 'sbin'], ['php', 'bin'], $fpm);
    }

    /**
     * 返回 composer 完整命令串：escapeshellarg($php).' '.escapeshellarg($phar)。
     * 始终以本进程解析出的 PHP 为前缀，避开多版本 PHP 系统下 phar 自带
     * `#!/usr/bin/env php` shebang 找错版本（spec § 4）。
     */
    public function composer(): string
    {
        return $this->resolved['composer'] ??= sprintf(
            '%s %s',
            escapeshellarg($this->php()),
            escapeshellarg($this->resolveComposerPhar())
        );
    }

    /**
     * composer 候选 phar 路径，按优先级排序，可被子类覆盖供测试。
     *
     * @return string[]
     */
    protected function composerCandidatePaths(): array
    {
        return [
            '/usr/local/bin/composer',
            '/usr/bin/composer',
            base_path('composer.phar'),
        ];
    }

    /**
     * 走 PATH 找 $tool，独立成方法便于子类强制返回 null 覆盖兜底分支。
     */
    protected function pathFinderResult(string $tool): ?string
    {
        return (new ExecutableFinder)->find($tool);
    }

    /**
     * 解析 composer phar 路径。
     *
     * 顺序与 SOFT 档相反（候选 → PATH 而非 PATH → 候选）：宝塔 / Linux 站点的
     * /usr/local/bin/composer 通常是站点 composer，优先选择能避开 PATH 上的旧版本副本。
     *
     * @throws BinaryNotFoundException 候选路径与 PATH 都未找到可用 composer
     */
    protected function resolveComposerPhar(): string
    {
        $tried = [];
        foreach ($this->composerCandidatePaths() as $candidate) {
            $tried[] = $candidate;
            if ($this->probeComposerPhar($candidate)) {
                return $candidate;
            }
        }

        $found = $this->pathFinderResult('composer');
        if ($found !== null) {
            $tried[] = $found;
            if ($this->probeComposerPhar($found)) {
                return $found;
            }
        }

        throw new BinaryNotFoundException(
            tool: 'composer',
            triedPaths: $tried,
            diagnose: $this->diagnose('composer'),
        );
    }

    /**
     * 通过 `{php} {phar} --version` 探测 composer phar，校验输出含 "Composer"。
     * 绕过 phar 自身 shebang，避免多版本 PHP 选错。
     */
    private function probeComposerPhar(string $path): bool
    {
        return $this->probeWith([$this->php(), $path, '--version'], 'Composer');
    }

    public function openssl(): string
    {
        return $this->resolveSoft('openssl');
    }

    public function java(): string
    {
        return $this->resolveSoft('java');
    }

    public function keytool(): string
    {
        return $this->resolveSoft('keytool');
    }

    public function mysqldump(): string
    {
        return $this->resolveSoft('mysqldump');
    }

    public function mysql(): string
    {
        return $this->resolveSoft('mysql');
    }

    public function curl(): string
    {
        return $this->resolveSoft('curl');
    }

    /**
     * SOFT 档候选路径，独立成方法便于子类覆盖供测试。
     *
     * @return string[]
     */
    protected function candidatePathsFor(string $tool): array
    {
        return self::SOFT_CANDIDATES[$tool] ?? [];
    }

    /**
     * SOFT 档通用解析：PATH → 候选路径（与 spec § 4 一致），全部失败抛 BinaryNotFoundException。
     */
    protected function resolveSoft(string $tool): string
    {
        if (isset($this->resolved[$tool])) {
            return $this->resolved[$tool];
        }

        [$flag, $expected] = self::SOFT_VERSION_PROBES[$tool] ?? ['--version', ''];
        $tried = [];

        if (($path = $this->pathFinderResult($tool)) !== null) {
            $tried[] = $path;
            if ($this->probeWith([$path, $flag], $expected)) {
                return $this->resolved[$tool] = $path;
            }
        }

        foreach ($this->candidatePathsFor($tool) as $candidate) {
            $tried[] = $candidate;
            if ($this->probeWith([$candidate, $flag], $expected)) {
                return $this->resolved[$tool] = $candidate;
            }
        }

        throw new BinaryNotFoundException(
            tool: $tool,
            triedPaths: $tried,
            diagnose: $this->diagnose($tool),
        );
    }

    /**
     * 读取当前进程（FPM 或 CLI 直跑）的 ini 路径与 disable_functions。
     *
     * @return array{ini_path: ?string, disable_functions: string, disable_functions_ok: bool}
     */
    public function inspectFpmIni(): array
    {
        return $this->resolved['__fpm_ini'] ??= $this->buildIniInfo(
            php_ini_loaded_file() ?: null,
            (string) ini_get('disable_functions'),
        );
    }

    /**
     * 通过 CLI 子进程读 ini，宝塔 CLI 与 FPM ini 互相独立，preflight 必须两边都看。
     *
     * @return array{ini_path: ?string, disable_functions: ?string, disable_functions_ok: bool, error?: string}
     */
    public function inspectCliIni(): array
    {
        if (isset($this->resolved['__cli_ini'])) {
            return $this->resolved['__cli_ini'];
        }

        $php = $this->php();
        $code = 'echo php_ini_loaded_file()."|".ini_get("disable_functions");';

        $proc = @proc_open(
            [$php, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (! is_resource($proc)) {
            return $this->resolved['__cli_ini'] = [
                'ini_path' => null,
                'disable_functions' => null,
                'disable_functions_ok' => false,
                'error' => 'proc_open_failed',
            ];
        }

        $out = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        [$iniPath, $disableFunctions] = array_pad(explode('|', $out, 2), 2, null);

        return $this->resolved['__cli_ini'] = $this->buildIniInfo(
            $iniPath !== '' ? $iniPath : null,
            $disableFunctions ?? '',
        );
    }

    /**
     * 构造 ini 信息字典，CLI/FPM 共用。disable_functions_ok 要求 proc_open、exec 都未被禁。
     *
     * @return array{ini_path: ?string, disable_functions: string, disable_functions_ok: bool}
     */
    private function buildIniInfo(?string $iniPath, string $disableFunctions): array
    {
        $disabled = array_map('trim', explode(',', $disableFunctions));
        $ok = ! in_array('proc_open', $disabled, true) && ! in_array('exec', $disabled, true);

        return [
            'ini_path' => $iniPath,
            'disable_functions' => $disableFunctions,
            'disable_functions_ok' => $ok,
        ];
    }

    /**
     * 失败诊断：聚合 open_basedir / disable_functions / SAPI / 候选路径 + 安装提示。
     *
     * public 以便 BinaryNotFoundException 构造时复用，同时供 preflight 阶段直接调用展示给前端。
     *
     * @return string[]
     */
    public function diagnose(string $tool): array
    {
        $lines = [];

        $openBasedir = (string) ini_get('open_basedir');
        $lines[] = $openBasedir !== ''
            ? "当前 open_basedir: $openBasedir"
            : 'open_basedir: 无限制';

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $criticals = array_values(array_intersect(['proc_open', 'exec', 'shell_exec'], $disabled));
        $lines[] = $criticals !== []
            ? '已禁用关键函数: '.implode(', ', $criticals)
            : 'disable_functions: 进程控制函数可用';

        $lines[] = '当前进程类型: '.(PHP_SAPI === 'cli' ? 'CLI' : PHP_SAPI);
        $lines[] = "试过的 $tool 候选路径: ".implode(', ', $this->candidatePathsFor($tool) ?: ['(无)']);

        return array_merge($lines, $this->installHintFor($tool));
    }

    /**
     * 按工具返回 OS 分支安装提示（spec § 4），未识别工具返回空数组。
     *
     * @return string[]
     */
    protected function installHintFor(string $tool): array
    {
        $hint = match ($tool) {
            'openssl' => ['Debian: apt install openssl', 'RHEL: yum install openssl', 'macOS: brew install openssl'],
            'java', 'keytool' => ['Debian: apt install default-jdk', 'RHEL: yum install java', 'macOS: brew install openjdk'],
            'mysqldump', 'mysql' => ['Debian: apt install default-mysql-client', 'RHEL: yum install mysql', 'macOS: brew install mysql-client'],
            'curl' => ['Debian: apt install curl', 'RHEL: yum install curl', 'macOS: brew install curl'],
            'composer' => ['curl -sS https://getcomposer.org/installer | php', 'mv composer.phar /usr/local/bin/composer'],
            'php' => ['请使用 upgrade.sh 重新部署，确保站点 PHP 可被探测到'],
            default => [],
        };

        return $hint !== [] ? array_merge(['推荐安装命令:'], array_map(fn ($l) => "  $l", $hint)) : [];
    }
}
