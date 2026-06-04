<?php

namespace App\Services\Binary;

use App\Services\Binary\Exceptions\BinaryNotFoundException;

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
        'openssl' => ['version', 'OpenSSL'], // 用子命令而非 --version：OpenSSL 3.0.x 不支持 --version 全局选项（3.2+ 才加），但 version 子命令 1.x/2.x/3.x 全系列支持
        'java' => ['-version', ''],          // java -version 输出到 stderr，仅校验 exit 0
        'keytool' => ['-help', ''],          // keytool 无 --version；中文 locale 输出不含 'keytool'，仅校验 exit 0
        'mysqldump' => ['--version', 'Ver '],
        'mysql' => ['--version', 'Ver '],
        'curl' => ['--version', 'curl '],
    ];

    /**
     * shell 兜底探测时显式注入的 PATH。
     *
     * 宝塔 PHP-FPM 默认 clear_env=yes、pool 配置 env[PATH] 默认注释掉，
     * worker 进程 getenv('PATH') 为空，子 sh 拿不到 PATH 必然找不到命令。
     * 这里显式给一个覆盖 Linux 标准位置 + macOS Homebrew 的 PATH，
     * 让 shell 兜底在生产/开发机都能工作（不依赖部署环境的 env[PATH] 配置）。
     *
     * 顺序：Homebrew → /usr/local → 系统目录。macOS `/usr/bin/openssl` 是 LibreSSL，
     * `openssl version` 输出 "LibreSSL ..." 不含 "OpenSSL"，会让探测假阳性失败；
     * 把 Homebrew 提前确保开发机命中真正的 OpenSSL。生产 Linux 无 /opt/homebrew/，无影响。
     */
    private const SHELL_FALLBACK_PATH = '/opt/homebrew/sbin:/opt/homebrew/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    /** @var array<string, string> tool name → 解析路径（或 composer 的完整命令串） */
    protected array $resolved = [];

    /** @var array{ini_path: ?string, disable_functions: string, disable_functions_ok: bool}|null */
    protected ?array $fpmIniCache = null;

    /** @var array{ini_path: ?string, disable_functions: ?string, disable_functions_ok: bool, error?: string}|null */
    protected ?array $cliIniCache = null;

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
     * 用 sh 解析 PATH 兜底，定位命令的绝对路径并校验探测输出。
     *
     * 候选路径全部 miss 时启用（如 binary 装在常规路径之外）。两步走：
     * 1) `sh -c 'command -v $tool'` 拿到 PATH 上命令的绝对路径
     * 2) 用该绝对路径走 probeWith 校验版本输出符合预期
     *
     * **返回绝对路径而非裸名**：调用方按绝对路径 exec/proc_open，
     * 不依赖调用方进程的 env PATH —— 宝塔 PHP-FPM 默认 clear_env=yes、
     * worker 进程 PATH 空，调用方启动的子 sh 拿不到 PATH 必然失败；
     * 探测阶段显式注入 SHELL_FALLBACK_PATH 给 sh，调用阶段不再需要。
     *
     * @return string|null 找到的绝对路径；null 表示找不到或版本校验失败
     */
    protected function probeViaShell(string $tool, string $flag, string $expectedOutput): ?string
    {
        // env 传 array 时是 execve 语义（完全替换、不合并），用 array_replace
        // 把父进程 env（HOME/LANG/JAVA_HOME 等）保留，只覆盖 PATH。
        // 父进程 PATH 空时（宝塔 FPM）也无害：PATH key 仍被 SHELL_FALLBACK_PATH 覆盖到。
        $env = array_replace(getenv() ?: [], ['PATH' => self::SHELL_FALLBACK_PATH]);
        $resolveCmd = 'command -v '.escapeshellarg($tool).' 2>/dev/null';
        $proc = @proc_open(
            $resolveCmd,
            [1 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        if (! is_resource($proc)) {
            return null;
        }
        $path = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        if (proc_close($proc) !== 0 || $path === '') {
            return null;
        }

        // command -v 找到了路径，但仍要走 probeWith 校验工具行为符合预期
        // （防 alias / wrapper script / 不同实现 like LibreSSL 等假阳性）
        return $this->probeWith([$path, $flag], $expectedOutput) ? $path : null;
    }

    /**
     * 解析 php 二进制：优先 PHP_BINARY（CLI），FPM 进程下推断同级 bin/php，
     * 再遍历候选路径，最后 shell PATH 兜底，全部失败抛 BinaryNotFoundException。
     *
     * 不再用 Symfony ExecutableFinder：open_basedir 非空时它强制只在
     * open_basedir 内目录找命令（参 Symfony 7.x ExecutableFinder 源码），
     * 宝塔站点的 open_basedir 必定不含 /usr/bin/，FPM 下永远 miss；
     * CLI 下又被候选路径覆盖。让 FPM 和 CLI 走完全一致的探测路径，
     * 避免"开发机能跑、生产挂"被 ExecutableFinder 这条路偷偷接住的差异。
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

        // 3) 遍历常见安装路径
        foreach (self::PHP_CANDIDATE_PATHS as $path) {
            $tried[] = $path;
            if ($this->probeWith([$path, '-v'], 'PHP ')) {
                return $path;
            }
        }

        // 4) shell 兜底：候选路径未覆盖时让 sh 解析 PATH 找绝对路径
        $tried[] = 'php (shell PATH)';
        if (($path = $this->probeViaShell('php', '-v', 'PHP ')) !== null) {
            return $path;
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
     *
     * **安全契约**：返回值两段路径已 escapeshellarg，可安全嵌入 `sprintf('cd %s && %s install', ...)`
     * 等 shell 命令。调用方在拼接命令时 **绝不允许把任何用户输入或非可信变量** 加到此命令串前后；
     * 如需追加可控参数（如 --no-dev / --optimize），直接字面量拼接即可（这些参数无变量插值，
     * 当前 UpgradeService 4 处调用均符合此模式）。
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
     * 解析 composer phar 路径。
     *
     * 候选路径全部 miss 时走 shell PATH 兜底。注意 composer 是 phar，
     * shell 兜底用 `composer --version` 探测会依赖 phar 自带 shebang
     * `#!/usr/bin/env php` —— 多版本 PHP 系统下可能选错 PHP；但只有
     * 候选路径都未覆盖时才走到这步，能跑通就是收益，可接受。
     *
     * @throws BinaryNotFoundException 候选路径与 shell PATH 都未找到 composer
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

        $tried[] = 'composer (shell PATH)';
        if (($path = $this->probeViaShell('composer', '--version', 'Composer')) !== null) {
            return $path;
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
     * SOFT 档通用解析：候选路径 → shell PATH 兜底，全部失败抛 BinaryNotFoundException。
     *
     * 不再走 ExecutableFinder：FPM 下 open_basedir 锁死 Symfony Finder、CLI 下被候选路径覆盖，
     * 留着只会让"开发机有/生产无"这种差异被偷偷接住。统一两条路径：候选明确路径 + shell 兜底。
     */
    protected function resolveSoft(string $tool): string
    {
        if (isset($this->resolved[$tool])) {
            return $this->resolved[$tool];
        }

        [$flag, $expected] = self::SOFT_VERSION_PROBES[$tool] ?? ['--version', ''];
        $tried = [];

        foreach ($this->candidatePathsFor($tool) as $candidate) {
            $tried[] = $candidate;
            if ($this->probeWith([$candidate, $flag], $expected)) {
                return $this->resolved[$tool] = $candidate;
            }
        }

        $tried[] = "$tool (shell PATH)";
        if (($path = $this->probeViaShell($tool, $flag, $expected)) !== null) {
            return $this->resolved[$tool] = $path;
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
        return $this->fpmIniCache ??= $this->buildIniInfo(
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
        if ($this->cliIniCache !== null) {
            return $this->cliIniCache;
        }

        $php = $this->php();
        $code = 'echo php_ini_loaded_file()."|".ini_get("disable_functions");';

        $proc = @proc_open(
            [$php, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (! is_resource($proc)) {
            return $this->cliIniCache = [
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

        return $this->cliIniCache = $this->buildIniInfo(
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
