#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Services\Backup\SqlDumpRewritePlan;
use App\Services\Backup\SqlDumpRewriter;

require dirname(__DIR__).'/vendor/autoload.php';

if ($argc !== 3) {
    fwrite(STDERR, "用法: php scripts/atomic-restore-rewrite.php <schema.json> <12位恢复token>\n");
    exit(2);
}

$schemaPath = $argv[1];
$token = $argv[2];
if (preg_match('/^[a-f0-9]{12}$/D', $token) !== 1) {
    fwrite(STDERR, "恢复 token 必须是 12 位小写十六进制字符\n");
    exit(2);
}

$encoded = @file_get_contents($schemaPath);
if (! is_string($encoded)) {
    fwrite(STDERR, "无法读取 Schema: {$schemaPath}\n");
    exit(2);
}

try {
    $schema = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, "Schema JSON 无效: {$e->getMessage()}\n");
    exit(2);
}

$definitions = is_array($schema['tables'] ?? null) ? $schema['tables'] : null;
if ($definitions === null || $definitions === []) {
    fwrite(STDERR, "Schema 缺少 tables\n");
    exit(2);
}

// 旧备份曾把 Laravel migrations 写进 SQL、却未写进 schema.json；
// 与 RestorePreflight 的固定 adjunct 定义保持一致，避免基准脚本产生不同恢复契约。
if (! isset($schema['backup_meta']) && ! array_key_exists('migrations', $definitions)) {
    $definitions['migrations'] = [
        'columns' => [
            'id' => ['extra' => 'auto_increment', 'generation_expression' => ''],
            'migration' => ['extra' => '', 'generation_expression' => ''],
            'batch' => ['extra' => '', 'generation_expression' => ''],
        ],
    ];
}

$included = $schema['backup_meta']['included_tables'] ?? array_keys($definitions);
if (! is_array($included) || $included === []) {
    fwrite(STDERR, "Schema included_tables 无效\n");
    exit(2);
}

$tableMap = [];
$columnOrder = [];
$generatedColumns = [];
foreach ($included as $table) {
    if (! is_string($table) || preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
        fwrite(STDERR, "Schema 包含无效表名\n");
        exit(2);
    }
    $definition = $definitions[$table] ?? null;
    $columns = is_array($definition['columns'] ?? null) ? $definition['columns'] : null;
    if ($columns === null || $columns === []) {
        fwrite(STDERR, "Schema 表缺少列定义: {$table}\n");
        exit(2);
    }
    $tableMap[$table] = "__rst_{$token}_{$table}";
    $columnOrder[$table] = array_keys($columns);
    $generatedColumns[$table] = [];
    foreach ($columns as $column => $facts) {
        if (! is_string($column) || ! is_array($facts)) {
            fwrite(STDERR, "Schema 列定义无效: {$table}\n");
            exit(2);
        }
        $extra = strtoupper((string) ($facts['extra'] ?? ''));
        $expression = trim((string) ($facts['generation_expression'] ?? ''));
        if (str_contains($extra, 'GENERATED') || $expression !== '') {
            $generatedColumns[$table][] = $column;
        }
    }
}

$rewriter = new SqlDumpRewriter(new SqlDumpRewritePlan(
    $tableMap,
    $columnOrder,
    $generatedColumns,
));
$inputBytes = 0;
$outputBytes = 0;

try {
    $buffer = '';
    while (! feof(STDIN)) {
        $chunk = fread(STDIN, 1048576);
        if ($chunk === false) {
            throw new RuntimeException('读取标准输入失败');
        }
        if ($chunk === '') {
            continue;
        }
        $buffer .= $chunk;
        if (strlen($buffer) < 65536 && ! feof(STDIN)) {
            continue;
        }
        $inputBytes += strlen($buffer);
        $output = $rewriter->push($buffer);
        $buffer = '';
        $outputBytes += strlen($output);
        if ($output !== '' && fwrite(STDOUT, $output) === false) {
            throw new RuntimeException('写入标准输出失败');
        }
    }
    if ($buffer !== '') {
        $inputBytes += strlen($buffer);
        $output = $rewriter->push($buffer);
        $outputBytes += strlen($output);
        if ($output !== '' && fwrite(STDOUT, $output) === false) {
            throw new RuntimeException('写入标准输出失败');
        }
    }
    $output = $rewriter->finish();
    $outputBytes += strlen($output);
    if ($output !== '' && fwrite(STDOUT, $output) === false) {
        throw new RuntimeException('写入标准输出失败');
    }
} catch (Throwable $e) {
    fwrite(STDERR, "SQL 改写失败: {$e->getMessage()}\n");
    exit(1);
}

$metricsPath = getenv('ATOMIC_RESTORE_METRICS_FILE');
if (is_string($metricsPath) && $metricsPath !== '') {
    $metrics = json_encode([
        'input_bytes' => $inputBytes,
        'output_bytes' => $outputBytes,
        'peak_memory_bytes' => memory_get_peak_usage(true),
    ], JSON_THROW_ON_ERROR);
    if (file_put_contents($metricsPath, $metrics, LOCK_EX) === false) {
        fwrite(STDERR, "无法写入改写指标文件\n");
        exit(1);
    }
}
