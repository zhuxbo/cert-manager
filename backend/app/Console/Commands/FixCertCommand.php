<?php

/** @noinspection DuplicatedCode */

/** @noinspection PhpUnhandledExceptionInspection */

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 证书数据一致性修复命令
 *
 * 使用方法：
 *
 * 1. 普通修复（修复 Latest Cert ID 错误）：
 *    php artisan cert:fix --type=basic
 *    - 修复 orders.latest_cert_id 与 certs.order_id 不匹配的问题
 *    - 这类问题通常修复成功率很高
 *    - 推荐优先执行此类修复
 *
 * 2. 链条修复（修复孤儿证书）：
 *    php artisan cert:fix --type=chain
 *    - 通过递归查找 last_cert_id 链条修复孤儿证书
 *    - 适合处理复杂的证书关联关系问题
 *    - 需要更多的计算时间
 *
 * 3. 全面修复（推荐）：
 *    php artisan cert:fix --type=all
 *    - 依次执行普通修复和链条修复
 *    - 一次性解决所有可修复的问题
 *
 * 4. 干跑模式（安全预览）：
 *    php artisan cert:fix --dry-run
 *    - 只显示将要修复的记录，不实际修改数据
 *    - 建议在正式修复前先使用此模式确认
 *    - 例如：php artisan cert:fix --type=all --dry-run
 *
 * 5. 性能调优参数：
 *    php artisan cert:fix --chunk=2000 --memory-limit=256M
 *    - --chunk: 分块处理大小，默认1000，可根据数据量调整
 *    - --memory-limit: 内存限制，默认128M，大数据量时可增加
 *
 * 完整使用示例：
 *    # 安全预览所有修复
 *    php artisan cert:fix --type=all --dry-run
 *
 *    # 正式执行修复
 *    php artisan cert:fix --type=all
 *
 *    # 只修复基础问题
 *    php artisan cert:fix --type=basic
 *
 *    # 高性能修复大数据量
 *    php artisan cert:fix --type=all --chunk=5000 --memory-limit=512M
 *
 * 注意事项：
 * - 修复前建议先备份数据库
 * - 大数据量修复时建议在低峰期执行
 * - 使用 --dry-run 预览修复内容
 * - 修复完成后使用 cert:analyze 验证结果
 */
class FixCertCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cert:fix
                           {--type=all : 修复类型 (basic|chain|all)}
                           {--dry-run : 仅显示将要修复的记录，不实际执行修复}
                           {--chunk=1000 : 分块查询大小}
                           {--memory-limit=128M : 内存限制}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '修复证书数据一致性问题（合并版）';

    /**
     * 修复统计
     */
    private int $basicFixedCount = 0;

    private int $chainFixedCount = 0;

    private array $fixDetails = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // 设置内存限制
        ini_set('memory_limit', $this->option('memory-limit'));

        $isDryRun = $this->option('dry-run');
        $type = $this->option('type');

        $this->info('🔧 开始修复证书数据一致性问题...');
        $this->info('模式: '.($isDryRun ? '干跑模式（不会实际修改数据）' : '实际修复模式'));
        $this->info("修复类型: $type");
        $this->info('分块大小: '.$this->option('chunk'));
        $this->newLine();

        if ($isDryRun) {
            $this->warn('⚠️  当前为干跑模式，只会显示将要修复的记录，不会实际修改数据');
            $this->newLine();
        }

        $startTime = microtime(true);

        // 执行修复
        if (in_array($type, ['basic', 'all'])) {
            $this->performBasicFix($isDryRun);
            $this->newLine();
        }

        if (in_array($type, ['chain', 'all'])) {
            $this->performChainFix($isDryRun);
            $this->newLine();
        }

        // 显示总结
        $this->showFixSummary($startTime, $isDryRun);

        return 0;
    }

    /**
     * 执行普通修复（Latest Cert ID 错误）
     */
    private function performBasicFix(bool $isDryRun): void
    {
        $this->info('🔄 普通修复：修复 Latest Cert ID 错误');
        $this->line('─────────────────────────────────────────────────────');

        if ($isDryRun) {
            $this->performBasicFixDryRun();
        } else {
            $this->performBasicFixExecution();
        }
    }

    /**
     * 普通修复干跑模式
     */
    private function performBasicFixDryRun(): void
    {
        // 快速统计需要修复的数量
        $totalCount = DB::table('orders as o')
            ->join('certs as c', 'o.latest_cert_id', '=', 'c.id')
            ->whereColumn('c.order_id', '!=', 'o.id')
            ->whereNotNull('o.latest_cert_id')
            ->whereNotNull('c.order_id')
            ->count();

        if ($totalCount === 0) {
            $this->info('✅ 没有需要普通修复的记录');

            return;
        }

        // 获取前20条记录用于预览
        $recordsToFix = DB::table('orders as o')
            ->join('certs as c', 'o.latest_cert_id', '=', 'c.id')
            ->whereColumn('c.order_id', '!=', 'o.id')
            ->whereNotNull('o.latest_cert_id')
            ->whereNotNull('c.order_id')
            ->select(
                'c.id as cert_id',
                'c.order_id as old_order_id',
                'o.id as new_order_id',
                'c.common_name'
            )
            ->limit(20)
            ->get();

        // 保存详细信息
        foreach ($recordsToFix as $record) {
            $this->fixDetails[] = [
                'type' => 'basic',
                'cert_id' => $record->cert_id,
                'old_order_id' => $record->old_order_id,
                'new_order_id' => $record->new_order_id,
                'common_name' => $record->common_name ?? 'N/A',
            ];
        }

        $this->basicFixedCount = $totalCount;
        $this->warn("🔍 普通修复将处理 $totalCount 条记录");
    }

    /**
     * 普通修复实际执行
     */
    private function performBasicFixExecution(): void
    {
        // 统计总数
        $totalCount = DB::table('orders as o')
            ->join('certs as c', 'o.latest_cert_id', '=', 'c.id')
            ->whereColumn('c.order_id', '!=', 'o.id')
            ->whereNotNull('o.latest_cert_id')
            ->whereNotNull('c.order_id')
            ->count();

        if ($totalCount === 0) {
            $this->info('✅ 没有需要普通修复的记录');

            return;
        }

        $this->info("发现 $totalCount 条需要修复的记录，开始批量处理...");

        // 确认用户意图
        if (! $this->confirm("确定要修复这 $totalCount 条记录吗？")) {
            $this->warn('❌ 用户取消操作');

            return;
        }

        $chunkSize = (int) $this->option('chunk');
        $progressBar = $this->output->createProgressBar($totalCount);
        $progressBar->setFormat('修复进度: %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

        $failedCount = 0;

        // 分块批量更新
        DB::table('orders as o')
            ->join('certs as c', 'o.latest_cert_id', '=', 'c.id')
            ->whereColumn('c.order_id', '!=', 'o.id')
            ->whereNotNull('o.latest_cert_id')
            ->whereNotNull('c.order_id')
            ->select('c.id as cert_id', 'o.id as order_id', 'c.order_id as old_order_id')
            ->orderBy('c.id')
            ->chunk($chunkSize, function ($records) use ($progressBar, &$failedCount) {
                try {
                    DB::transaction(function () use ($records, &$failedCount) {
                        foreach ($records as $record) {
                            // 验证订单存在
                            $orderExists = DB::table('orders')->where('id', $record->order_id)->exists();
                            if (! $orderExists) {
                                $failedCount++;

                                continue;
                            }

                            // 执行更新
                            $updated = DB::table('certs')
                                ->where('id', $record->cert_id)
                                ->where('order_id', $record->old_order_id)
                                ->update(['order_id' => $record->order_id]);

                            if ($updated === 0) {
                                $failedCount++;
                            }
                        }
                    });
                } catch (Exception $e) {
                    $this->error('❌ 批次更新失败: '.$e->getMessage());
                    $failedCount += count($records);
                }

                $this->basicFixedCount += (count($records) - $failedCount);
                $progressBar->advance(count($records));
            });

        $progressBar->finish();
        $this->newLine();

        $actualFixed = $totalCount - $failedCount;
        $this->basicFixedCount = $actualFixed;

        if ($failedCount > 0) {
            $this->warn("⚠️  $failedCount 条记录修复失败");
        }
        $this->info("✅ 普通修复完成，成功修复 $actualFixed 条记录");
    }

    /**
     * 执行链条修复（孤儿证书）
     */
    private function performChainFix(bool $isDryRun): void
    {
        $this->info('🔗 链条修复：通过证书链修复孤儿证书');
        $this->line('─────────────────────────────────────────────────────');

        if ($isDryRun) {
            $this->performChainFixDryRun();
        } else {
            $this->performChainFixExecution();
        }
    }

    /**
     * 链条修复干跑模式
     */
    private function performChainFixDryRun(): void
    {
        // 快速统计孤儿证书数量
        $orphanCount = DB::table('certs as c')
            ->leftJoin('orders as o', 'c.order_id', '=', 'o.id')
            ->whereNotNull('c.order_id')
            ->whereNull('o.id')
            ->count();

        if ($orphanCount === 0) {
            $this->info('✅ 没有需要链条修复的记录');

            return;
        }

        $this->info("发现 $orphanCount 条孤儿证书，分析前20条的修复可能性...");

        // 分析前20条
        $orphanCerts = DB::table('certs as c')
            ->leftJoin('orders as o', 'c.order_id', '=', 'o.id')
            ->whereNotNull('c.order_id')
            ->whereNull('o.id')
            ->select('c.id', 'c.order_id', 'c.last_cert_id', 'c.common_name')
            ->limit(20)
            ->get();

        $fixableCount = 0;
        foreach ($orphanCerts as $orphanCert) {
            $correctOrderId = $this->findCorrectOrderIdByChain($orphanCert->id, $orphanCert->last_cert_id);

            if ($correctOrderId) {
                $this->fixDetails[] = [
                    'type' => 'chain',
                    'cert_id' => $orphanCert->id,
                    'old_order_id' => $orphanCert->order_id,
                    'new_order_id' => $correctOrderId,
                    'common_name' => $orphanCert->common_name ?? 'N/A',
                ];
                $fixableCount++;
            }
        }

        $this->chainFixedCount = $fixableCount;
        $this->warn("🔍 链条修复预计可处理约 $fixableCount 条记录（基于前20条样本）");
        $this->warn('注意：实际可修复数量需要完整扫描确定');
    }

    /**
     * 链条修复实际执行
     */
    private function performChainFixExecution(): void
    {
        // 获取所有孤儿证书
        $orphanCerts = DB::table('certs as c')
            ->leftJoin('orders as o', 'c.order_id', '=', 'o.id')
            ->whereNotNull('c.order_id')
            ->whereNull('o.id')
            ->select('c.id', 'c.order_id', 'c.last_cert_id', 'c.common_name')
            ->get();

        if ($orphanCerts->isEmpty()) {
            $this->info('✅ 没有需要链条修复的记录');

            return;
        }

        $this->info("发现 {$orphanCerts->count()} 条孤儿证书，开始递归查找修复方案...");

        $progressBar = $this->output->createProgressBar($orphanCerts->count());
        $progressBar->setFormat('链条修复: %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');

        $batchUpdates = [];
        $batchSize = 100;
        $unfixableCerts = [];

        foreach ($orphanCerts as $orphanCert) {
            $correctOrderId = $this->findCorrectOrderIdByChain($orphanCert->id, $orphanCert->last_cert_id);

            if ($correctOrderId && $this->validateOrderId($correctOrderId)) {
                $batchUpdates[] = [
                    'cert_id' => $orphanCert->id,
                    'order_id' => $correctOrderId,
                ];

                // 批量更新
                if (count($batchUpdates) >= $batchSize) {
                    $successCount = $this->executeBatchUpdate($batchUpdates);
                    $this->chainFixedCount += $successCount;
                    $batchUpdates = [];
                }
            } else {
                $unfixableCerts[] = $orphanCert;
            }

            $progressBar->advance();
        }

        // 处理剩余更新
        if (! empty($batchUpdates)) {
            $successCount = $this->executeBatchUpdate($batchUpdates);
            $this->chainFixedCount += $successCount;
        }

        $progressBar->finish();
        $this->newLine();

        $this->info("✅ 链条修复完成，成功修复 $this->chainFixedCount 条记录");

        if (! empty($unfixableCerts)) {
            $this->newLine();
            $this->warn('⚠️  '.count($unfixableCerts).' 条记录无法自动修复，需要手动处理');

            if (count($unfixableCerts) <= 10) {
                $this->showUnfixableCerts($unfixableCerts);
            }
        }
    }

    /**
     * 执行批量更新
     */
    private function executeBatchUpdate(array $updates): int
    {
        if (empty($updates)) {
            return 0;
        }

        $successCount = 0;

        DB::transaction(function () use ($updates, &$successCount) {
            foreach ($updates as $update) {
                $updated = DB::table('certs')
                    ->where('id', $update['cert_id'])
                    ->update(['order_id' => $update['order_id']]);

                if ($updated > 0) {
                    $successCount++;
                }
            }
        });

        return $successCount;
    }

    /**
     * 递归查找正确的订单ID
     */
    private function findCorrectOrderIdByChain(int $certId, ?int $lastCertId, int $depth = 0): ?int
    {
        if ($depth > 10 || ! $lastCertId) {
            return null;
        }

        $result = DB::table('certs as c')
            ->leftJoin('orders as o', 'c.order_id', '=', 'o.id')
            ->where('c.id', $lastCertId)
            ->select('c.order_id', 'c.last_cert_id', 'o.id as order_exists')
            ->first();

        if (! $result) {
            return null;
        }

        if ($result->order_exists) {
            return $result->order_id;
        }

        return $this->findCorrectOrderIdByChain($certId, $result->last_cert_id, $depth + 1);
    }

    /**
     * 验证订单ID
     */
    private function validateOrderId(int $orderId): bool
    {
        return DB::table('orders')->where('id', $orderId)->exists();
    }

    /**
     * 显示无法修复的证书
     */
    private function showUnfixableCerts($unfixableCerts): void
    {
        $headers = ['Cert ID', 'Order ID', 'Last Cert ID', 'Common Name'];
        $rows = [];

        foreach (array_slice($unfixableCerts, 0, 10) as $cert) {
            $rows[] = [
                $cert->id,
                $cert->order_id,
                $cert->last_cert_id ?? 'NULL',
                substr($cert->common_name ?? 'N/A', 0, 30),
            ];
        }

        $this->table($headers, $rows);

        if (count($unfixableCerts) > 10) {
            $this->line('... 还有 '.(count($unfixableCerts) - 10).' 条记录');
        }
    }

    /**
     * 显示修复总结
     */
    private function showFixSummary(float $startTime, bool $isDryRun): void
    {
        $endTime = microtime(true);
        $executionTime = round($endTime - $startTime, 2);
        $memoryUsage = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        $totalFixed = $this->basicFixedCount + $this->chainFixedCount;

        $this->info($isDryRun ? '🎯 干跑完成！' : '🎯 修复完成！');
        $this->line('─────────────────────────────────────────────────────');
        $this->info("普通修复记录数: $this->basicFixedCount");
        $this->info("链条修复记录数: $this->chainFixedCount");
        $this->info("总计修复记录数: $totalFixed");
        $this->info("执行时间: $executionTime 秒");
        $this->info("内存使用: $memoryUsage MB");

        // 修复后验证
        if (! $isDryRun && $totalFixed > 0) {
            $this->newLine();
            $this->performPostFixValidation();
        }

        // 显示修复详情
        if ($isDryRun && ! empty($this->fixDetails)) {
            $this->newLine();
            $this->showFixDetails();
            $this->newLine();
            $this->warn('💡 要实际执行修复，请移除 --dry-run 参数重新运行');
        }

        if (! $isDryRun && $totalFixed > 0) {
            $this->newLine();
            $this->info('💡 建议执行以下命令验证修复结果：');
            $this->line('   php artisan cert:analyze');
        }
    }

    /**
     * 修复后验证
     */
    private function performPostFixValidation(): void
    {
        $this->info('🔍 验证修复结果...');

        // 检查剩余的 Latest Cert ID 错误
        $remainingBasicErrors = DB::table('orders as o')
            ->join('certs as c', 'o.latest_cert_id', '=', 'c.id')
            ->whereColumn('c.order_id', '!=', 'o.id')
            ->whereNotNull('o.latest_cert_id')
            ->whereNotNull('c.order_id')
            ->count();

        // 检查剩余的孤儿证书
        $remainingOrphans = DB::table('certs as c')
            ->leftJoin('orders as o', 'c.order_id', '=', 'o.id')
            ->whereNotNull('c.order_id')
            ->whereNull('o.id')
            ->count();

        if ($remainingBasicErrors === 0 && $remainingOrphans === 0) {
            $this->info('✅ 验证通过：所有可修复的错误已修复！');
        } else {
            $this->warn('⚠️  仍有部分错误未修复：');
            if ($remainingBasicErrors > 0) {
                $this->warn("  - Latest Cert ID 错误: $remainingBasicErrors");
            }
            if ($remainingOrphans > 0) {
                $this->warn("  - 孤儿证书: $remainingOrphans");
            }
            $this->line('  这些可能需要手动处理或使用其他修复策略');
        }
    }

    /**
     * 显示修复详情
     */
    private function showFixDetails(): void
    {
        $this->warn('📋 修复详情预览（显示前20条）：');

        $basicDetails = array_filter($this->fixDetails, fn ($item) => $item['type'] === 'basic');
        $chainDetails = array_filter($this->fixDetails, fn ($item) => $item['type'] === 'chain');

        if (! empty($basicDetails)) {
            $this->info('普通修复详情:');
            $headers = ['Cert ID', '原 Order ID', '新 Order ID', 'Common Name'];
            $rows = [];

            foreach (array_slice($basicDetails, 0, 10) as $detail) {
                $rows[] = [
                    $detail['cert_id'],
                    $detail['old_order_id'],
                    $detail['new_order_id'],
                    substr($detail['common_name'], 0, 25),
                ];
            }

            $this->table($headers, $rows);

            if (count($basicDetails) > 10) {
                $this->line('... 还有 '.(count($basicDetails) - 10).' 条记录');
            }
        }

        if (! empty($chainDetails)) {
            $this->newLine();
            $this->info('链条修复详情:');
            $headers = ['Cert ID', '原 Order ID', '新 Order ID', 'Common Name'];
            $rows = [];

            foreach (array_slice($chainDetails, 0, 10) as $detail) {
                $rows[] = [
                    $detail['cert_id'],
                    $detail['old_order_id'],
                    $detail['new_order_id'],
                    substr($detail['common_name'], 0, 25),
                ];
            }

            $this->table($headers, $rows);

            if (count($chainDetails) > 10) {
                $this->line('... 还有 '.(count($chainDetails) - 10).' 条记录');
            }
        }
    }
}
