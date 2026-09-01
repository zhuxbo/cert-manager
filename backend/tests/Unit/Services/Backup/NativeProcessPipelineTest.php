<?php

use App\Services\Backup\NativeProcessPipeline;
use App\Services\Backup\SqlStreamTransformer;

class PipelineFlushFailureStreamWrapper
{
    public mixed $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        return strlen($data);
    }

    public function stream_flush(): bool
    {
        return false;
    }

    public function stream_close(): void {}

    public function stream_stat(): array
    {
        return [];
    }
}

beforeEach(function () {
    $this->pipelineDirectory = sys_get_temp_dir().'/native pipeline '.bin2hex(random_bytes(6));
    mkdir($this->pipelineDirectory, 0700, true);
});

afterEach(function () {
    foreach (glob($this->pipelineDirectory.'/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($this->pipelineDirectory);
});

test('dump 同时排空超过一 MiB 的 stdout stderr 并流式生成统计', function () {
    $chunkBytes = 65536;
    $chunkCount = 20;
    $expected = str_repeat('D', $chunkBytes * $chunkCount);
    $partPath = $this->pipelineDirectory.'/backup with spaces.sql.gz.part';
    $progress = [];

    $result = (new NativeProcessPipeline)->dumpToGzip(
        [
            PHP_BINARY,
            '-r',
            '$chunk = str_repeat("D", '.$chunkBytes.');'
                .'$err = str_repeat("E", '.$chunkBytes.');'
                .'for ($i = 0; $i < '.$chunkCount.'; $i++) {'
                .'fwrite(STDOUT, $chunk); fwrite(STDERR, $err);}',
        ],
        ['/usr/bin/gzip', '-1', '-c'],
        $partPath,
        function (int $inputBytes, int $outputBytes) use (&$progress): void {
            $progress[] = [$inputBytes, $outputBytes];
        },
        5,
    );

    expect(gzdecode((string) file_get_contents($partPath)))->toBe($expected)
        ->and($result->inputBytes)->toBe(strlen($expected))
        ->and($result->outputBytes)->toBe(filesize($partPath))
        ->and($result->outputSha256)->toBe(hash_file('sha256', $partPath))
        ->and($result->exitCodes)->toBe(['dump' => 0, 'gzip' => 0])
        ->and(strlen($result->stderr['dump']))->toBeLessThanOrEqual(65536)
        ->and($progress)->not->toBeEmpty()
        ->and($progress[array_key_last($progress)])->toBe([$result->inputBytes, $result->outputBytes]);
});

test('进程非零退出保留退出码但异常不泄露命令凭据', function () {
    $secret = 'pipeline-secret-'.bin2hex(random_bytes(8));
    $partPath = $this->pipelineDirectory.'/credential.sql.gz.part';

    expect(fn () => (new NativeProcessPipeline)->dumpToGzip(
        [
            PHP_BINARY,
            '-r',
            'fwrite(STDERR, $argv[1]); exit(7);',
            '--',
            "--password=$secret",
        ],
        ['/usr/bin/gzip', '-1', '-c'],
        $partPath,
        static function (): void {},
        5,
    ))->toThrow(function (RuntimeException $e) use ($secret): void {
        expect($e->getMessage())->toContain('dump=7')
            ->not->toContain($secret)
            ->not->toContain('--password=');
    });
});

test('stderr 跨越 64 KiB 边界时结果与异常都不泄露敏感选项或部分值', function (
    array $sensitiveArguments,
    string $secret,
    string $optionPrefix,
) {
    $script = 'fwrite(STDERR, str_repeat("N", 65530).implode("|", array_slice($argv, 1)));'
        .'fwrite(STDOUT, "ok");';
    $pipeline = new NativeProcessPipeline;
    $failurePath = $this->pipelineDirectory.'/redaction failure '.bin2hex(random_bytes(3)).'.sql.gz.part';

    try {
        $pipeline->dumpToGzip(
            [PHP_BINARY, '-r', $script.'exit(7);', '--', ...$sensitiveArguments],
            ['/usr/bin/gzip', '-1', '-c'],
            $failurePath,
            static function (): void {},
            5,
        );
        test()->fail('预期源进程非零退出');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain($secret)
            ->not->toContain(substr($secret, 0, 6))
            ->not->toContain($optionPrefix)
            ->not->toContain(substr($optionPrefix, 0, min(6, strlen($optionPrefix))));
    }

    $successPath = $this->pipelineDirectory.'/redaction success '.bin2hex(random_bytes(3)).'.sql.gz.part';
    $result = $pipeline->dumpToGzip(
        [PHP_BINARY, '-r', $script, '--', ...$sensitiveArguments],
        ['/usr/bin/gzip', '-1', '-c'],
        $successPath,
        static function (): void {},
        5,
    );

    expect(strlen($result->stderr['dump']))->toBeLessThanOrEqual(65536)
        ->and($result->stderr['dump'])->not->toContain($secret)
        ->not->toContain(substr($secret, 0, 6))
        ->not->toContain($optionPrefix)
        ->not->toContain(substr($optionPrefix, 0, min(6, strlen($optionPrefix))));
})->with([
    '--password=value' => [['--password=redaction-secret-attached'], 'redaction-secret-attached', '--password='],
    '--password value' => [['--password', 'redaction-secret-separated'], 'redaction-secret-separated', '--password'],
    '-pvalue' => [['-predaction-secret-short-attached'], 'redaction-secret-short-attached', '-p'],
    '-p value' => [['-p', 'redaction-secret-short-separated'], 'redaction-secret-short-separated', '-p'],
    '--defaults-extra-file=value' => [['--defaults-extra-file=/tmp/redaction-client.cnf'], '/tmp/redaction-client.cnf', '--defaults-extra-file='],
    '--defaults-extra-file value' => [['--defaults-extra-file', '/tmp/redaction-separated.cnf'], '/tmp/redaction-separated.cnf', '--defaults-extra-file'],
]);

test('dump 在慢下游背压下保持完整输出', function () {
    $bytes = 2 * 1024 * 1024;
    $partPath = $this->pipelineDirectory.'/slow sink.sql.gz.part';

    $result = (new NativeProcessPipeline)->dumpToGzip(
        [PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("B", '.$bytes.'));'],
        [
            PHP_BINARY,
            '-r',
            'while (!feof(STDIN)) {'
                .'$chunk = fread(STDIN, 4096);'
                .'if ($chunk !== false && $chunk !== "") { usleep(1000); fwrite(STDOUT, $chunk); }}',
        ],
        $partPath,
        static function (): void {},
        5,
    );

    expect(filesize($partPath))->toBe($bytes)
        ->and(hash_file('sha256', $partPath))->toBe(hash('sha256', str_repeat('B', $bytes)))
        ->and($result->inputBytes)->toBe($bytes)
        ->and($result->outputBytes)->toBe($bytes);
});

test('dump output fflush 失败时不能返回成功', function () {
    $scheme = 'pipelineflushfailure';
    stream_wrapper_register($scheme, PipelineFlushFailureStreamWrapper::class);

    try {
        expect(fn () => (new NativeProcessPipeline)->dumpToGzip(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, "payload");'],
            [PHP_BINARY, '-r', 'stream_copy_to_stream(STDIN, STDOUT);'],
            $scheme.'://backup.sql.gz.part',
            static function (): void {},
            5,
        ))->toThrow(RuntimeException::class, '刷新备份临时文件失败');
    } finally {
        stream_wrapper_unregister($scheme);
    }
});

test('dump 报告下游非零退出码', function () {
    $partPath = $this->pipelineDirectory.'/downstream failure.sql.gz.part';

    expect(fn () => (new NativeProcessPipeline)->dumpToGzip(
        [PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("X", 1048576));'],
        [PHP_BINARY, '-r', 'fwrite(STDERR, "gzip failed"); exit(9);'],
        $partPath,
        static function (): void {},
        5,
    ))->toThrow(function (RuntimeException $e): void {
        expect($e->getMessage())->toContain('gzip=9')->toContain('gzip failed');
    });
});

test('idle timeout 会向整个进程组发信号并清理父子进程', function () {
    if (! function_exists('pcntl_signal') || ! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl 扩展或 fork 不可用');
    }

    $marker = $this->pipelineDirectory.'/terminated';
    $partPath = $this->pipelineDirectory.'/idle.sql.gz.part';
    $startedAt = microtime(true);

    expect(fn () => (new NativeProcessPipeline)->dumpToGzip(
        [
            PHP_BINARY,
            '-r',
            'pcntl_async_signals(true);'
                .'$pid = pcntl_fork(); $role = $pid === 0 ? "child" : "parent";'
                .'pcntl_signal(SIGTERM, function () use ($role) {'
                .'file_put_contents('.var_export($marker, true).', $role."\n", FILE_APPEND | LOCK_EX); exit(143);});'
                .'while (true) { usleep(100000); }',
        ],
        [PHP_BINARY, '-r', 'stream_copy_to_stream(STDIN, STDOUT);'],
        $partPath,
        static function (): void {},
        1,
    ))->toThrow(RuntimeException::class, '进程管道空闲超时');

    expect(microtime(true) - $startedAt)->toBeLessThan(4.0)
        ->and(file_get_contents($marker))->toContain("parent\n")->toContain("child\n");
});

test('setsid leader 已退出时 finally 仍清理持有 pipe 的 child', function () {
    if (! function_exists('pcntl_signal') || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        test()->markTestSkipped('pcntl/posix 扩展不可用');
    }

    $pidPath = $this->pipelineDirectory.'/orphan pid';
    $marker = $this->pipelineDirectory.'/orphan terminated';
    $partPath = $this->pipelineDirectory.'/orphan.sql.gz.part';
    $childPid = 0;

    try {
        expect(fn () => (new NativeProcessPipeline)->dumpToGzip(
            [
                PHP_BINARY,
                '-r',
                'pcntl_async_signals(true); $pid = pcntl_fork();'
                    .'if ($pid > 0) { file_put_contents('.var_export($pidPath, true).', (string) $pid); exit(0); }'
                    .'pcntl_signal(SIGTERM, function () {'
                    .'file_put_contents('.var_export($marker, true).', "terminated"); exit(0);});'
                    .'while (true) { usleep(100000); }',
            ],
            [PHP_BINARY, '-r', 'stream_copy_to_stream(STDIN, STDOUT);'],
            $partPath,
            static function (): void {},
            1,
        ))->toThrow(RuntimeException::class, '进程管道空闲超时');

        $childPid = (int) file_get_contents($pidPath);
        $deadline = microtime(true) + 1;
        do {
            $stat = @file_get_contents("/proc/$childPid/stat");
            preg_match('/^\d+ \(.*\) ([A-Z]) /', is_string($stat) ? $stat : '', $stateMatch);
            $state = $stat === false ? 'gone' : ($stateMatch[1] ?? 'unknown');
            if ($state === 'gone' || $state === 'Z') {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        expect(is_file($marker))->toBeTrue()
            ->and($state)->toBeIn(['gone', 'Z']);
    } finally {
        if ($childPid > 0 && @posix_kill($childPid, 0)) {
            @posix_kill($childPid, SIGKILL);
        }
    }
});

test('持续有 I O 活动时运行时间可超过 idle timeout', function () {
    $partPath = $this->pipelineDirectory.'/active.sql.gz.part';
    $startedAt = microtime(true);

    $result = (new NativeProcessPipeline)->dumpToGzip(
        [
            PHP_BINARY,
            '-r',
            'for ($i = 0; $i < 7; $i++) { fwrite(STDOUT, "A"); usleep(200000); }',
        ],
        [PHP_BINARY, '-r', 'stream_copy_to_stream(STDIN, STDOUT);'],
        $partPath,
        static function (): void {},
        1,
    );

    expect(microtime(true) - $startedAt)->toBeGreaterThan(1.0)
        ->and(file_get_contents($partPath))->toBe('AAAAAAA')
        ->and($result->exitCodes)->toBe(['dump' => 0, 'gzip' => 0]);
});

test('restore 流式改写解压内容并在 finish 后关闭 mysql 输入', function () {
    $gzipPath = $this->pipelineDirectory.'/restore input.sql.gz';
    $receivedPath = $this->pipelineDirectory.'/mysql received.sql';
    $input = str_repeat("insert into t values (1);\n", 50000);
    file_put_contents($gzipPath, gzencode($input, 1));
    $rewriter = new class implements SqlStreamTransformer
    {
        public function push(string $chunk): string
        {
            return strtoupper($chunk);
        }

        public function finish(): string
        {
            return "-- FINISHED\n";
        }
    };

    $result = (new NativeProcessPipeline)->restoreFromGzip(
        ['/usr/bin/gzip', '-dc', $gzipPath],
        [
            PHP_BINARY,
            '-r',
            'file_put_contents($argv[1], stream_get_contents(STDIN));',
            '--',
            $receivedPath,
        ],
        $rewriter,
        static function (): void {},
        5,
    );
    $expected = strtoupper($input)."-- FINISHED\n";

    expect(file_get_contents($receivedPath))->toBe($expected)
        ->and($result->inputBytes)->toBe(strlen($input))
        ->and($result->outputBytes)->toBe(strlen($expected))
        ->and($result->outputSha256)->toBe(hash('sha256', $expected))
        ->and($result->exitCodes)->toBe(['gzip' => 0, 'mysql' => 0]);
});
