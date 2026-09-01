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
        'gzip' => ['/usr/bin/gzip', '/bin/gzip', '/usr/local/bin/gzip'],
        'setsid' => ['/usr/bin/setsid', '/bin/setsid', '/usr/local/bin/setsid'],
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
        'gzip' => ['--version', 'gzip '],
        'setsid' => ['--version', 'setsid '],
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

        // 并发排空 stdout + stderr：子进程把 stderr 写满管道缓冲区时也不与父进程读 stdout 互锁
        $stdout = $this->drainPipes($pipes[1], $pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        // $expectedOutput 为空：仅校验 exit code（兼容 java -version 输出到 stderr 的情况）
        return $exit === 0 && ($expectedOutput === '' || str_contains($stdout, $expectedOutput));
    }

    /**
     * 并发排空子进程的 stdout + stderr 两个管道，返回 stdout 全文（stderr 读出即丢弃）。
     *
     * proc_open 声明两个 'pipe','w' 时，若父进程只读 stdout 不读 stderr，子进程一旦向
     * stderr 写满管道缓冲区（Linux 默认 ~64KB）就阻塞在写，父进程又阻塞在读 stdout 等 EOF
     * —— 双方互等成经典死锁。这里用 stream_select 轮询两管道、哪个就绪读哪个直到双双 EOF，
     * 杜绝任一方向写满阻塞。仅 stdout 文本返回参与上层判定，stderr 只为防死锁而排空、内容丢弃。
     * 调用方负责 fclose 两个管道与 proc_close。
     *
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    private function drainPipes($stdout, $stderr): string
    {
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        $out = '';
        $open = [1 => $stdout, 2 => $stderr];

        while ($open !== []) {
            $read = $open;
            $write = $except = [];

            // null 超时：阻塞等任一管道就绪（不忙等）。被信号打断返回 false 时退出循环，
            // 由调用方 fclose + proc_close 收尾（关读端令子进程写 stderr 得 EPIPE 自终，不残留死锁）。
            if (@stream_select($read, $write, $except, null) === false) {
                break;
            }

            foreach ($read as $fd => $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk === '' || $chunk === false) {
                    // 非阻塞下「就绪却空读」即 EOF：移出待读集；非 EOF 的偶发空读留待下轮
                    if (feof($stream)) {
                        unset($open[$fd]);
                    }

                    continue;
                }
                if ($fd === 1) {
                    $out .= $chunk;
                }
            }
        }

        return $out;
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

    /**
     * 解析 bash 绝对路径（后台升级经 proc_open 调 nginx/render.sh 用）。
     */
    public function bash(): string
    {
        if (isset($this->resolved['bash'])) {
            return $this->resolved['bash'];
        }

        $candidates = ['/bin/bash', '/usr/bin/bash', '/usr/local/bin/bash'];
        foreach ($candidates as $candidate) {
            if ($this->probeWith([$candidate, '--version'], 'GNU bash')) {
                return $this->resolved['bash'] = $candidate;
            }
        }

        if (($path = $this->probeViaShell('bash', '--version', 'GNU bash')) !== null) {
            return $this->resolved['bash'] = $path;
        }

        throw new BinaryNotFoundException(
            tool: 'bash',
            triedPaths: array_merge($candidates, ['bash (shell PATH)']),
            diagnose: $this->diagnose('bash'),
        );
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

    public function gzip(): string
    {
        return $this->resolveSoft('gzip');
    }

    public function setsid(): string
    {
        return $this->resolveSoft('setsid');
    }

    public function curl(): string
    {
        return $this->resolveSoft('curl');
    }

    /**
     * 支持 SM2 的 openssl —— 仅国密（SM2）相关命令用（生成 SM2 签名 CSR）。
     *
     * 与系统 openssl() 同源（共用同一批系统 openssl 候选路径），但探测条件更严：不只验 version，
     * 还验 SM2 曲线真可用（`ecparam -name SM2 -genkey -noout` exit 0），防 LibreSSL / 编译 no-sm2 /
     * FIPS-only 等假阳性（version 过但签不了 SM2）。OpenSSL 3.0+ 的 default provider 原生支持 SM2，
     * 本系统统一依赖系统 OpenSSL 3 签发、不接独立国密二进制；PHP openssl 扩展不支持 SM2，故仍走命令行。
     *
     * 候选路径 miss 后 shell PATH 兜底，全失败抛 BinaryNotFoundException，调用方
     * （CsrUtil/guardSm2Capable）catch 后 fail-closed（拒国密入口，绝不静默降级签 RSA）。
     */
    public function gmOpenssl(): string
    {
        if (isset($this->resolved['gmopenssl'])) {
            return $this->resolved['gmopenssl'];
        }

        $tried = [];

        // 与 openssl() 共用系统 openssl 候选，但要求「真支持 SM2」（probeSm2）而非仅 version 通过
        foreach ($this->candidatePathsFor('openssl') as $candidate) {
            $tried[] = $candidate;
            if ($this->probeSm2($candidate)) {
                return $this->resolved['gmopenssl'] = $candidate;
            }
        }

        // shell PATH 兜底：command -v openssl 拿绝对路径，再验 SM2
        $tried[] = 'openssl (shell PATH, SM2)';
        $env = array_replace(getenv() ?: [], ['PATH' => self::SHELL_FALLBACK_PATH]);
        $proc = @proc_open('command -v openssl 2>/dev/null', [1 => ['pipe', 'w']], $pipes, null, $env);
        if (is_resource($proc)) {
            $path = trim((string) stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            if (proc_close($proc) === 0 && $path !== '' && $this->probeSm2($path)) {
                return $this->resolved['gmopenssl'] = $path;
            }
        }

        throw new BinaryNotFoundException(
            tool: 'gmopenssl',
            triedPaths: $tried,
            diagnose: $this->diagnose('openssl'),
        );
    }

    /**
     * 探测 openssl 是否真支持 SM2 **且签出 id-ecPublicKey 标准编码**。
     *
     * 不能只验「能生成 SM2 key」——OpenSSL 3.0.0~3.0.12 / 3.1.x~3.2.0 能签 SM2，却把公钥
     * SubjectPublicKeyInfo 的 algorithm 写成 SM2 曲线 OID（dual-sm2），被国密 CA（如 Keeptrust）拒为
     * 「csr 解析失败」；官方 3.0.13（3.0 LTS backport）与 3.2.1 起 restore 回 id-ecPublicKey。故必须实际
     * 签一张 SM2 CSR、校验 SPKI 是 id-ecPublicKey，才能 fail-closed 拒掉这类「能签但编码错」的 openssl，绝不签出 CA 不收的 CSR。
     *
     * array proc_open（execve，防注入 + 避 open_basedir）；临时私钥写系统临时目录、finally 强清不留盘。
     */
    protected function probeSm2(string $path): bool
    {
        // 临时目录用 sys_get_temp_dir() 而非 storage_path：storage 不可写（权限/只读挂载）时
        // 写 storage 会让 mkdir 失败被误判为"不支持 SM2"，文案误导排障。系统临时目录始终可写，
        // 让探测只反映「openssl 是否真支持 SM2 标准编码」这一能力判定本身。
        $dir = $this->sm2ProbeDir();
        if (! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            return false;
        }
        $keyFile = $dir.'/k.pem';
        $csrFile = $dir.'/c.csr';

        try {
            // 1. 生成 SM2 key + 2. 签一张 SM2 CSR（distid 不影响 SPKI 编码，从略；-subj 免交互）
            if (! $this->probeWith([$path, 'ecparam', '-name', 'SM2', '-genkey', '-out', $keyFile], '')
                || ! $this->probeWith([$path, 'req', '-new', '-key', $keyFile, '-sm3', '-subj', '/CN=probe', '-out', $csrFile], '')) {
                return false;
            }

            // 3. 校验 CSR 的 SPKI 是 id-ecPublicKey 标准编码（拒 dual-sm2）
            $csr = @file_get_contents($csrFile);

            return $csr !== false && $this->csrUsesStandardEcPublicKey($csr);
        } finally {
            @unlink($keyFile);
            @unlink($csrFile);
            @rmdir($dir);
        }
    }

    /**
     * SM2 探测的临时目录（唯一随机名，独立成方法便于单测断言基路径）。
     *
     * 用 sys_get_temp_dir() 而非 storage_path：避免 storage 不可写时 mkdir 失败被误判为
     * "openssl 不支持 SM2"。返回随机子目录名防多进程争抢。
     */
    protected function sm2ProbeDir(): string
    {
        return sys_get_temp_dir().'/sm2-probe-'.bin2hex(random_bytes(8));
    }

    /**
     * 校验 CSR 的 SubjectPublicKeyInfo 是否用标准 id-ecPublicKey 编码（RFC 5480）。
     *
     * id-ecPublicKey OID 1.2.840.10045.2.1 的 DER 内容字节为 2a8648ce3d0201；OpenSSL 3.0.0~3.0.12 /
     * 3.1.x~3.2.0 与 GmSSL 的 dual-sm2 编码把 algorithm 填成 sm2 曲线 OID（2a811ccf5501822d）、不含此串，以此区分。
     * 独立成 protected 便于单测覆盖（dual-sm2 fixture → false / 标准 fixture → true）。
     */
    protected function csrUsesStandardEcPublicKey(string $csrPem): bool
    {
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $csrPem));

        return $der !== '' && str_contains($der, hex2bin('2a8648ce3d0201'));
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

        // 并发排空两管道（同 probeWith）：防 php -r 子进程 stderr 写满缓冲区与父进程读 stdout 互锁
        $out = trim($this->drainPipes($pipes[1], $pipes[2]));
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
            'mysqldump', 'mysql' => [
                '安装 Oracle MySQL 官方 mysql-client，版本系列必须与目标服务端一致（5.7、8.0 或 8.4）',
                '不要使用可能实际提供 MariaDB 的 default-mysql-client',
                '宝塔面板优先检查 /www/server/mysql/bin',
            ],
            'curl' => ['Debian: apt install curl', 'RHEL: yum install curl', 'macOS: brew install curl'],
            'composer' => ['curl -sS https://getcomposer.org/installer | php', 'mv composer.phar /usr/local/bin/composer'],
            'php' => ['请使用 upgrade.sh 重新部署，确保站点 PHP 可被探测到'],
            default => [],
        };

        return $hint !== [] ? array_merge(['推荐安装命令:'], array_map(fn ($l) => "  $l", $hint)) : [];
    }
}
