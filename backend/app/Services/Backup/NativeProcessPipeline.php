<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Services\Binary\BinaryLocator;
use RuntimeException;

final class NativeProcessPipeline
{
    private const CHUNK_BYTES = 65536;

    private const MAX_PENDING_BYTES = 262144;

    private const MAX_STDERR_BYTES = 65536;

    public function __construct(private readonly BinaryLocator $binaries = new BinaryLocator) {}

    public function dumpToGzip(
        array $dumpCommand,
        array $gzipCommand,
        string $partPath,
        callable $progress,
        int $idleTimeoutSeconds,
    ): PipelineResult {
        if (! str_ends_with($partPath, '.sql.gz.part')) {
            throw new RuntimeException('备份管道只能写入 .sql.gz.part 临时文件');
        }

        $output = @fopen($partPath, 'xb');
        if ($output === false) {
            throw new RuntimeException("无法创建备份临时文件: $partPath");
        }

        try {
            $result = $this->transfer(
                sourceCommand: $dumpCommand,
                sinkCommand: $gzipCommand,
                sourceName: 'dump',
                sinkName: 'gzip',
                transform: static fn (string $chunk): string => $chunk,
                finish: static fn (): string => '',
                consumeSinkOutput: function (string $chunk) use ($output): void {
                    $this->writeAll($output, $chunk);
                },
                measureTransformedOutput: false,
                progress: $progress,
                idleTimeoutSeconds: $idleTimeoutSeconds,
            );
            if (! fflush($output)) {
                throw new RuntimeException('刷新备份临时文件失败');
            }
            if (fclose($output)) {
                $output = null;
            } else {
                throw new RuntimeException('关闭备份临时文件失败');
            }

            clearstatcache(true, $partPath);
            $finalSize = @filesize($partPath);
            $finalHash = @hash_file('sha256', $partPath);
            if ($finalSize === false || $finalHash === false
                || $finalSize !== $result->outputBytes || $finalHash !== $result->outputSha256) {
                throw new RuntimeException('备份临时文件落盘统计校验失败');
            }

            return $result;
        } finally {
            if (is_resource($output)) {
                @fclose($output);
            }
        }
    }

    public function restoreFromGzip(
        array $gzipCommand,
        array $mysqlCommand,
        SqlStreamTransformer $rewriter,
        callable $progress,
        int $idleTimeoutSeconds,
    ): PipelineResult {
        return $this->transfer(
            sourceCommand: $gzipCommand,
            sinkCommand: $mysqlCommand,
            sourceName: 'gzip',
            sinkName: 'mysql',
            transform: $rewriter->push(...),
            finish: $rewriter->finish(...),
            consumeSinkOutput: static function (): void {},
            measureTransformedOutput: true,
            progress: $progress,
            idleTimeoutSeconds: $idleTimeoutSeconds,
        );
    }

    private function transfer(
        array $sourceCommand,
        array $sinkCommand,
        string $sourceName,
        string $sinkName,
        callable $transform,
        callable $finish,
        callable $consumeSinkOutput,
        bool $measureTransformedOutput,
        callable $progress,
        int $idleTimeoutSeconds,
    ): PipelineResult {
        $this->assertCommand($sourceCommand);
        $this->assertCommand($sinkCommand);
        if ($idleTimeoutSeconds < 1) {
            throw new RuntimeException('进程管道 idle timeout 必须大于零');
        }

        $sensitiveTokens = $this->sensitiveTokens([$sourceCommand, $sinkCommand]);
        $source = null;
        $sink = null;
        $sourceGroupId = null;
        $sinkGroupId = null;
        $sourcePipes = [];
        $sinkPipes = [];

        try {
            $source = $this->openProcess($sourceCommand, $sourcePipes, $sourceGroupId);
            $sink = $this->openProcess($sinkCommand, $sinkPipes, $sinkGroupId);
            $this->closeStream($sourcePipes[0]);
            foreach ([$sourcePipes[1], $sourcePipes[2], $sinkPipes[0], $sinkPipes[1], $sinkPipes[2]] as $pipe) {
                stream_set_blocking($pipe, false);
            }

            $pending = '';
            $finishQueued = false;
            $inputBytes = 0;
            $outputBytes = 0;
            $stderrStates = [
                $sourceName => ['output' => '', 'pending' => ''],
                $sinkName => ['output' => '', 'pending' => ''],
            ];
            $hash = hash_init('sha256');
            $lastActivity = microtime(true);
            $lastProgress = null;
            $sourceStatus = proc_get_status($source);
            $sinkStatus = proc_get_status($sink);

            while (true) {
                if (microtime(true) - $lastActivity >= $idleTimeoutSeconds) {
                    throw new RuntimeException('进程管道空闲超时');
                }

                $read = [];
                $readMap = [];
                if (is_resource($sourcePipes[1]) && $pending === '') {
                    $read[] = $sourcePipes[1];
                    $readMap[(int) $sourcePipes[1]] = 'source_stdout';
                }
                foreach ([
                    'source_stderr' => $sourcePipes[2] ?? null,
                    'sink_stdout' => $sinkPipes[1] ?? null,
                    'sink_stderr' => $sinkPipes[2] ?? null,
                ] as $name => $pipe) {
                    if (is_resource($pipe)) {
                        $read[] = $pipe;
                        $readMap[(int) $pipe] = $name;
                    }
                }

                $write = [];
                if ($pending !== '' && is_resource($sinkPipes[0])) {
                    $write[] = $sinkPipes[0];
                }

                if ($read !== [] || $write !== []) {
                    $except = [];
                    if (@stream_select($read, $write, $except, 0, 100000) === false) {
                        throw new RuntimeException('进程管道多路 I/O 失败');
                    }
                } else {
                    usleep(10000);
                }

                foreach ($read as $stream) {
                    $name = $readMap[(int) $stream];
                    $chunk = fread($stream, self::CHUNK_BYTES);
                    if ($chunk === false) {
                        throw new RuntimeException('进程管道读取失败');
                    }
                    if ($chunk === '') {
                        if (feof($stream)) {
                            $this->closeStream($stream);
                        }

                        continue;
                    }

                    $lastActivity = microtime(true);
                    if ($name === 'source_stdout') {
                        $inputBytes += strlen($chunk);
                        $transformed = $transform($chunk);
                        $this->assertBoundedTransform($transformed);
                        $pending = $transformed;
                        if ($measureTransformedOutput && $transformed !== '') {
                            $outputBytes += strlen($transformed);
                            hash_update($hash, $transformed);
                        }
                    } elseif ($name === 'sink_stdout') {
                        $consumeSinkOutput($chunk);
                        if (! $measureTransformedOutput) {
                            $outputBytes += strlen($chunk);
                            hash_update($hash, $chunk);
                        }
                    } else {
                        $key = $name === 'source_stderr' ? $sourceName : $sinkName;
                        $this->appendRedacted($stderrStates[$key], $chunk, $sensitiveTokens);
                    }
                }

                if (! is_resource($sourcePipes[1]) && ! $finishQueued) {
                    $tail = $finish();
                    $this->assertBoundedTransform($tail);
                    $pending .= $tail;
                    if ($measureTransformedOutput && $tail !== '') {
                        $outputBytes += strlen($tail);
                        hash_update($hash, $tail);
                    }
                    $finishQueued = true;
                }

                if ($write !== []) {
                    $written = $this->writePipe($sinkPipes[0], substr($pending, 0, self::CHUNK_BYTES));
                    if ($written === false) {
                        $deadline = microtime(true) + 0.2;
                        do {
                            $sinkStatus = proc_get_status($sink);
                            if (is_resource($sinkPipes[2])) {
                                $errorChunk = fread($sinkPipes[2], self::CHUNK_BYTES);
                                if (is_string($errorChunk) && $errorChunk !== '') {
                                    $this->appendRedacted($stderrStates[$sinkName], $errorChunk, $sensitiveTokens);
                                }
                            }
                            if (! $sinkStatus['running']) {
                                break;
                            }
                            usleep(10000);
                        } while (microtime(true) < $deadline);
                        if (! $sinkStatus['running']) {
                            $stderr = $this->finalizeStderr($stderrStates, $sensitiveTokens);
                            throw new RuntimeException($this->failureMessage(
                                [$sinkName => (int) $sinkStatus['exitcode']],
                                $stderr,
                            ));
                        }
                        throw new RuntimeException('进程管道写入下游失败');
                    }
                    if ($written > 0) {
                        $pending = (string) substr($pending, $written);
                        $lastActivity = microtime(true);
                    }
                }

                if ($finishQueued && $pending === '' && is_resource($sinkPipes[0])) {
                    $this->closeStream($sinkPipes[0]);
                }

                $currentProgress = [$inputBytes, $outputBytes];
                if ($currentProgress !== $lastProgress) {
                    $progress($inputBytes, $outputBytes);
                    $lastProgress = $currentProgress;
                }

                $sourceStatus = proc_get_status($source);
                $sinkStatus = proc_get_status($sink);
                if (! $sinkStatus['running'] && (int) $sinkStatus['exitcode'] !== 0
                    && (is_resource($sourcePipes[1]) || $pending !== '')) {
                    $stderr = $this->finalizeStderr($stderrStates, $sensitiveTokens);
                    throw new RuntimeException($this->failureMessage(
                        [$sinkName => (int) $sinkStatus['exitcode']],
                        $stderr,
                    ));
                }

                $allPipesClosed = ! is_resource($sourcePipes[1])
                    && ! is_resource($sourcePipes[2])
                    && ! is_resource($sinkPipes[0])
                    && ! is_resource($sinkPipes[1])
                    && ! is_resource($sinkPipes[2]);
                if (! $sourceStatus['running'] && ! $sinkStatus['running'] && $allPipesClosed) {
                    break;
                }
            }

            $exitCodes = [
                $sourceName => $this->closeProcess($source, $sourceStatus),
                $sinkName => $this->closeProcess($sink, $sinkStatus),
            ];
            $source = null;
            $sink = null;
            $sourceGroupId = null;
            $sinkGroupId = null;
            $stderr = $this->finalizeStderr($stderrStates, $sensitiveTokens);
            if ($exitCodes[$sourceName] !== 0 || $exitCodes[$sinkName] !== 0) {
                throw new RuntimeException($this->failureMessage($exitCodes, $stderr));
            }

            return new PipelineResult(
                inputBytes: $inputBytes,
                outputBytes: $outputBytes,
                outputSha256: hash_final($hash),
                exitCodes: $exitCodes,
                stderr: $stderr,
            );
        } finally {
            foreach (array_merge($sourcePipes, $sinkPipes) as $pipe) {
                $this->closeStream($pipe);
            }
            if ($sourceGroupId !== null || is_resource($source)) {
                $this->terminateProcess($source, $sourceGroupId);
            }
            if ($sinkGroupId !== null || is_resource($sink)) {
                $this->terminateProcess($sink, $sinkGroupId);
            }
        }
    }

    private function assertCommand(array $command): void
    {
        if ($command === []) {
            throw new RuntimeException('进程命令不能为空');
        }
        foreach ($command as $argument) {
            if (! is_string($argument) || $argument === '') {
                throw new RuntimeException('进程命令参数必须是非空字符串');
            }
        }
    }

    /**
     * @param  array<int, resource>  $pipes
     *
     * @param-out int $groupId
     */
    private function openProcess(array $command, array &$pipes, ?int &$groupId)
    {
        if (! function_exists('posix_kill') || ! function_exists('posix_getpgrp')) {
            throw new RuntimeException('无法创建独立进程组：posix 不可用');
        }
        $launchCommand = [$this->binaries->setsid(), '--', ...$command];
        $process = @proc_open($launchCommand, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('无法启动原生进程');
        }
        $status = proc_get_status($process);
        $leaderPid = (int) $status['pid'];
        if ($leaderPid <= 1 || $leaderPid === getmypid() || $leaderPid === posix_getpgrp()) {
            @proc_terminate($process, SIGKILL);
            @proc_close($process);
            throw new RuntimeException('无法获取安全的独立进程组');
        }
        $groupId = $leaderPid;

        return $process;
    }

    private function closeProcess($process, array $lastStatus): int
    {
        $exitCode = (int) ($lastStatus['exitcode'] ?? -1);
        $closed = proc_close($process);

        return $exitCode >= 0 ? $exitCode : $closed;
    }

    private function terminateProcess(mixed $process, ?int $groupId): void
    {
        $safeGroupId = $groupId !== null
            && $groupId > 1
            && $groupId !== getmypid()
            && (! function_exists('posix_getpgrp') || $groupId !== posix_getpgrp());
        if ($safeGroupId && function_exists('posix_kill')) {
            @posix_kill(-$groupId, SIGTERM);
            $deadline = microtime(true) + 0.2;
            do {
                usleep(10000);
            } while (@posix_kill(-$groupId, 0) && microtime(true) < $deadline);
            if (@posix_kill(-$groupId, 0)) {
                @posix_kill(-$groupId, SIGKILL);
            }
        } elseif (is_resource($process)) {
            @proc_terminate($process, SIGKILL);
        }
        if (is_resource($process)) {
            @proc_close($process);
        }
    }

    private function closeStream(mixed &$stream): void
    {
        if (is_resource($stream)) {
            @fclose($stream);
        }
        $stream = null;
    }

    private function writeAll($stream, string $chunk): void
    {
        $offset = 0;
        $length = strlen($chunk);
        while ($offset < $length) {
            $written = fwrite($stream, substr($chunk, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('写入备份临时文件失败');
            }
            $offset += $written;
        }
    }

    private function writePipe($stream, string $chunk): int|false
    {
        set_error_handler(static fn (): bool => true);
        try {
            return fwrite($stream, $chunk);
        } finally {
            restore_error_handler();
        }
    }

    private function assertBoundedTransform(string $chunk): void
    {
        if (strlen($chunk) > self::MAX_PENDING_BYTES) {
            throw new RuntimeException('SQL 流转换单次输出超过固定缓冲上限');
        }
    }

    /**
     * @param  array{output:string, pending:string}  $state
     * @param  list<string>  $tokens
     */
    private function appendRedacted(array &$state, string $chunk, array $tokens, bool $final = false): void
    {
        $state['pending'] .= $chunk;
        $bufferLength = strlen($state['pending']);
        $offset = 0;
        while ($offset < $bufferLength) {
            $remainingLength = $bufferLength - $offset;
            $matched = false;
            $partial = false;
            foreach ($tokens as $token) {
                $tokenLength = strlen($token);
                if ($remainingLength >= $tokenLength
                    && substr_compare($state['pending'], $token, $offset, $tokenLength) === 0) {
                    $this->appendStderrOutput($state, '[REDACTED]');
                    $offset += $tokenLength;
                    $matched = true;

                    break;
                }
                if ($remainingLength < $tokenLength
                    && strncmp(substr($state['pending'], $offset), $token, $remainingLength) === 0) {
                    $partial = true;

                    break;
                }
            }
            if ($matched) {
                continue;
            }
            if ($partial) {
                if ($final) {
                    $this->appendStderrOutput($state, '[REDACTED]');
                    $offset = $bufferLength;
                }

                break;
            }
            $this->appendStderrOutput($state, $state['pending'][$offset]);
            $offset++;
        }
        $state['pending'] = (string) substr($state['pending'], $offset);
    }

    /** @param array{output:string, pending:string} $state */
    private function appendStderrOutput(array &$state, string $text): void
    {
        $remaining = self::MAX_STDERR_BYTES - strlen($state['output']);
        if ($remaining > 0) {
            $state['output'] .= substr($text, 0, $remaining);
        }
    }

    /**
     * @param  array<string, array{output:string, pending:string}>  $states
     * @param  list<string>  $tokens
     * @return array<string, string>
     */
    private function finalizeStderr(array &$states, array $tokens): array
    {
        $stderr = [];
        foreach ($states as $name => &$state) {
            $this->appendRedacted($state, '', $tokens, true);
            $stderr[$name] = $state['output'];
        }
        unset($state);

        return $stderr;
    }

    /**
     * @param  array<int, array<int, string>>  $commands
     * @return list<string>
     */
    private function sensitiveTokens(array $commands): array
    {
        $tokens = [];
        foreach ($commands as $command) {
            $nextIsSensitive = false;
            foreach ($command as $argument) {
                if ($nextIsSensitive) {
                    $tokens[] = $argument;
                    $nextIsSensitive = false;

                    continue;
                }
                if (preg_match('/^(?:--?(?:password|passwd|pass|token|secret|credential)|-p|--defaults-extra-file)$/i', $argument) === 1) {
                    $tokens[] = $argument;
                    $nextIsSensitive = true;

                    continue;
                }
                if (preg_match('/^(--(?:password|passwd|pass|token|secret|credential|defaults-extra-file)=)(.+)$/i', $argument, $matches) === 1) {
                    $tokens[] = $argument;
                    $tokens[] = $matches[1];
                    $tokens[] = $matches[2];

                    continue;
                }
                if (preg_match('/^(-p)(.+)$/i', $argument, $matches) === 1) {
                    $tokens[] = $argument;
                    $tokens[] = $matches[1];
                    $tokens[] = $matches[2];
                }
            }
        }

        usort($tokens, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return array_values(array_unique(array_filter($tokens, fn (string $token): bool => $token !== '')));
    }

    /** @param array<string, int> $exitCodes */
    private function failureMessage(array $exitCodes, array $stderr): string
    {
        $details = [];
        foreach ($exitCodes as $name => $exitCode) {
            if ($exitCode !== 0) {
                $details[] = "$name=$exitCode";
            }
        }
        foreach ($stderr as $name => $text) {
            $text = trim($text);
            if ($text !== '') {
                $details[] = "$name stderr: $text";
            }
        }

        return '原生进程管道失败（'.implode('；', $details).'）';
    }
}
