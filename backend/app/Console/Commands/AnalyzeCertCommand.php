<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 证书数据一致性分析命令
 *
 * 使用方法：
 *
 * 1. 快速分析（默认）：
 *    php artisan cert:analyze
 *    - 显示基本统计信息，包括错误数量和分类
 *    - 执行速度快，适合日常检查
 *
 * 2. 详情分析：
 *    php artisan cert:analyze --detail
 *    - 显示具体的错误记录详情
 *    - 默认显示前20条，可通过 --limit 调整
 *    - 例如：php artisan cert:analyze --detail --limit=50
 *
 * 3. 链条分析：
 *    php artisan cert:analyze --chain
 *    - 分析证书链条关系，识别循环引用等复杂问题
 *    - 适合深度问题排查
 *
 * 4. 综合分析：
 *    php artisan cert:analyze --detail --chain --limit=30
 *    - 同时进行详情和链条分析
 *
 * 5. 性能调优参数：
 *    php artisan cert:analyze --chunk=2000
 *    - 调整分块查询大小，默认1000，可根据服务器性能调整
 *
 * 问题分类说明：
 * - Latest Cert ID 错误：orders.latest_cert_id 与 certs.order_id 不匹配
 * - 孤儿证书：certs.order_id 指向不存在的订单
 * - 循环引用：证书通过 last_cert_id 形成循环链条
 * - 断裂链条：last_cert_id 指向不存在的证书
 */
class AnalyzeCertCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cert:analyze
                           {--detail : 显示详细错误信息}
                           {--chain : 分析证书链条关系}
                           {--limit=20 : 显示详细信息时的限制数量}
                           {--chunk=1000 : 分块查询大小}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '分析证书数据一致性问题（合并版）';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 开始分析证书数据一致性...');
        $this->newLine();

        $startTime = microtime(true);

        // 1. 快速分析 - 基本统计
        $stats = $this->performQuickAnalysis();
        $this->showQuickAnalysisResults($stats);

        // 2. 详情分析
        if ($this->option('detail')) {
            $this->newLine();
            $this->performDetailedAnalysis($stats);
        }

        // 3. 链条分析
        if ($this->option('chain')) {
            $this->newLine();
            $this->performChainAnalysis();
        }

        $this->newLine();
        $this->showExecutionSummary($startTime, $stats);

        return 0;
    }

    /**
     * 执行快速分析
     */
    private function performQuickAnalysis(): array
    {
        $this->info('📊 执行快速分析...');

        // 问题1: orders.latest_cert_id 与 certs.order_id 不匹配
        $latestCertErrors = DB::table('orders as o')
            ->join('certs as c', 'o.latest_cert_id', '=', 'c.id')
            ->whereColumn('c.order_id', '!=', 'o.id')
            ->whereNotNull('o.latest_cert_id')
            ->whereNotNull('c.order_id')
            ->count();

        // 问题2: 孤儿证书（order_id 指向不存在的订单）
        $orphanCerts = DB::table('certs as c')
            ->leftJoin('orders as o', 'c.order_id', '=', 'o.id')
            ->whereNotNull('c.order_id')
            ->whereNull('o.id')
            ->count();

        // 总的证书数和订单数
        $totalCerts = DB::table('certs')->count();
        $totalOrders = DB::table('orders')->count();

        // 正常的证书数（order_id 匹配现有订单）
        $validCerts = DB::table('certs as c')
            ->join('orders as o', 'c.order_id', '=', 'o.id')
            ->count();

        return [
            'total_certs' => $totalCerts,
            'total_orders' => $totalOrders,
            'valid_certs' => $validCerts,
            'latest_cert_errors' => $latestCertErrors,
            'orphan_certs' => $orphanCerts,
            'total_errors' => $latestCertErrors + $orphanCerts,
        ];
    }

    /**
     * 显示快速分析结果
     */
    private function showQuickAnalysisResults(array $stats): void
    {
        $this->info('📈 数据一致性分析结果：');
        $this->line('─────────────────────────────────────────');

        // 基本统计
        $this->line("总证书数: {$stats['total_certs']}");
        $this->line("总订单数: {$stats['total_orders']}");
        $this->line("正常证书: {$stats['valid_certs']} ({$this->percentage($stats['valid_certs'], $stats['total_certs'])}%)");

        $this->newLine();

        // 错误统计
        if ($stats['total_errors'] === 0) {
            $this->info('✅ 没有发现数据一致性问题！');
        } else {
            $this->warn("⚠️  发现 {$stats['total_errors']} 个数据一致性问题：");
            $this->line("├─ Latest Cert ID 错误: {$stats['latest_cert_errors']} ({$this->percentage($stats['latest_cert_errors'], $stats['total_errors'])}%)");
            $this->line("└─ 孤儿证书: {$stats['orphan_certs']} ({$this->percentage($stats['orphan_certs'], $stats['total_errors'])}%)");

            $this->newLine();
            $this->info('💡 修复建议：');
            if ($stats['latest_cert_errors'] > 0) {
                $this->line('   - 使用 php artisan cert:fix --type=basic 修复 Latest Cert ID 错误');
            }
            if ($stats['orphan_certs'] > 0) {
                $this->line('   - 使用 php artisan cert:fix --type=chain 修复孤儿证书');
            }
            if ($stats['total_errors'] > 0) {
                $this->line('   - 使用 php artisan cert:fix --type=all 一次性修复所有问题');
            }
        }
    }

    /**
     * 执行详情分析
     */
    private function performDetailedAnalysis(array $stats): void
    {
        $this->info('🔍 执行详情分析...');
        $limit = (int) $this->option('limit');

        if ($stats['latest_cert_errors'] > 0) {
            $this->showLatestCertErrorDetails($limit);
        }

        if ($stats['orphan_certs'] > 0) {
            $this->showOrphanCertDetails($limit);
        }
    }

    /**
     * 显示 Latest Cert ID 错误详情
     */
    private function showLatestCertErrorDetails(int $limit): void
    {
        $this->newLine();
        $this->info("📋 Latest Cert ID 错误详情（显示前 $limit 条）：");

        $errors = DB::table('orders as o')
            ->join('certs as c', 'o.latest_cert_id', '=', 'c.id')
            ->whereColumn('c.order_id', '!=', 'o.id')
            ->whereNotNull('o.latest_cert_id')
            ->whereNotNull('c.order_id')
            ->select(
                'c.id as cert_id',
                'c.order_id as cert_order_id',
                'o.id as order_id',
                'c.common_name',
                'c.status as cert_status',
                'c.created_at'
            )
            ->limit($limit)
            ->get();

        if ($errors->isNotEmpty()) {
            $headers = ['Cert ID', 'Cert Order ID', '正确 Order ID', 'Common Name', 'Status', 'Created At'];
            $rows = [];

            foreach ($errors as $error) {
                $rows[] = [
                    $error->cert_id,
                    $error->cert_order_id,
                    $error->order_id,
                    substr($error->common_name ?? 'N/A', 0, 30),
                    $error->cert_status,
                    $error->created_at ? substr($error->created_at, 0, 10) : 'N/A',
                ];
            }

            $this->table($headers, $rows);
        }
    }

    /**
     * 显示孤儿证书详情
     */
    private function showOrphanCertDetails(int $limit): void
    {
        $this->newLine();
        $this->info("📋 孤儿证书详情（显示前 $limit 条）：");

        $orphans = DB::table('certs as c')
            ->leftJoin('orders as o', 'c.order_id', '=', 'o.id')
            ->whereNotNull('c.order_id')
            ->whereNull('o.id')
            ->select(
                'c.id as cert_id',
                'c.order_id',
                'c.last_cert_id',
                'c.common_name',
                'c.status',
                'c.created_at'
            )
            ->limit($limit)
            ->get();

        if ($orphans->isNotEmpty()) {
            $headers = ['Cert ID', 'Order ID', 'Last Cert ID', 'Common Name', 'Status', 'Created At'];
            $rows = [];

            foreach ($orphans as $orphan) {
                $rows[] = [
                    $orphan->cert_id,
                    $orphan->order_id,
                    $orphan->last_cert_id ?? 'NULL',
                    substr($orphan->common_name ?? 'N/A', 0, 30),
                    $orphan->status,
                    $orphan->created_at ? substr($orphan->created_at, 0, 10) : 'N/A',
                ];
            }

            $this->table($headers, $rows);

            // 显示修复可能性分析
            $this->analyzeOrphanFixability($orphans);
        }
    }

    /**
     * 分析孤儿证书的修复可能性
     */
    private function analyzeOrphanFixability($orphans): void
    {
        $this->newLine();
        $this->info('🔧 修复可能性分析：');

        $fixableCount = 0;
        $unfixableReasons = [];

        foreach ($orphans as $orphan) {
            if ($orphan->last_cert_id) {
                $correctOrderId = $this->findCorrectOrderIdByChain($orphan->cert_id, $orphan->last_cert_id);
                if ($correctOrderId) {
                    $fixableCount++;
                } else {
                    $unfixableReasons[] = "证书 $orphan->cert_id: 链条追溯无效";
                }
            } else {
                $unfixableReasons[] = "证书 $orphan->cert_id: 无 last_cert_id";
            }
        }

        $this->line("可修复: $fixableCount 条");
        $this->line('需手动处理: '.(count($orphans) - $fixableCount).' 条');

        if (! empty($unfixableReasons) && count($unfixableReasons) <= 10) {
            $this->newLine();
            $this->warn('无法自动修复的原因：');
            foreach (array_slice($unfixableReasons, 0, 5) as $reason) {
                $this->line("  - $reason");
            }
            if (count($unfixableReasons) > 5) {
                $this->line('  - ... 还有 '.(count($unfixableReasons) - 5).' 条');
            }
        }
    }

    /**
     * 执行链条分析
     */
    private function performChainAnalysis(): void
    {
        $this->info('🔗 执行证书链条分析...');

        // 获取所有孤儿证书进行链条分析
        $orphanCerts = DB::table('certs as c')
            ->leftJoin('orders as o', 'c.order_id', '=', 'o.id')
            ->whereNotNull('c.order_id')
            ->whereNull('o.id')
            ->select('c.id', 'c.order_id', 'c.last_cert_id', 'c.common_name', 'c.status')
            ->get();

        if ($orphanCerts->isEmpty()) {
            $this->info('✅ 没有孤儿证书需要进行链条分析');

            return;
        }

        $chainStats = $this->analyzeChains($orphanCerts);
        $this->showChainAnalysisResults($chainStats);
    }

    /**
     * 分析证书链条
     */
    private function analyzeChains($orphanCerts): array
    {
        $stats = [
            'total' => $orphanCerts->count(),
            'can_fix_by_chain' => 0,
            'can_fix_by_reference' => 0,
            'circular_reference' => 0,
            'broken_chain' => 0,
            'no_last_cert' => 0,
            'truly_unfixable' => 0,
            'chains' => [],
        ];

        $processedCerts = [];

        foreach ($orphanCerts as $cert) {
            if (isset($processedCerts[$cert->id])) {
                continue;
            }

            $chainAnalysis = $this->analyzeCompleteChain($cert, $orphanCerts->keyBy('id'));

            // 标记链条中的所有证书为已处理
            foreach ($chainAnalysis['certs'] as $certInChain) {
                $processedCerts[$certInChain['id']] = true;
            }

            $stats['chains'][] = $chainAnalysis;

            // 统计分类
            switch ($chainAnalysis['status']) {
                case 'can_fix_by_chain':
                    $stats['can_fix_by_chain'] += count($chainAnalysis['certs']);
                    break;
                case 'can_fix_by_reference':
                    $stats['can_fix_by_reference'] += count($chainAnalysis['certs']);
                    break;
                case 'circular_reference':
                    $stats['circular_reference'] += count($chainAnalysis['certs']);
                    break;
                case 'broken_chain':
                    $stats['broken_chain'] += count($chainAnalysis['certs']);
                    break;
                case 'no_last_cert':
                    $stats['no_last_cert'] += count($chainAnalysis['certs']);
                    break;
                default:
                    $stats['truly_unfixable'] += count($chainAnalysis['certs']);
                    break;
            }
        }

        return $stats;
    }

    /**
     * 分析完整的证书链条
     */
    private function analyzeCompleteChain(object $cert, $allOrphanCerts): array
    {
        $chainCerts = [];
        $visited = [];

        // 收集整个链条中的证书
        $this->collectChainCerts($cert, $allOrphanCerts, $chainCerts, $visited);

        // 查找引用这个链条的证书
        $referencingCerts = $this->findReferencingCerts($chainCerts);

        // 分析整个链条的修复可能性
        return $this->analyzeChainFixability($chainCerts, $referencingCerts);
    }

    /**
     * 收集链条中的所有证书
     */
    private function collectChainCerts(object $cert, $allOrphanCerts, &$chainCerts, &$visited): void
    {
        if (in_array($cert->id, $visited)) {
            return; // 防止循环引用
        }

        $visited[] = $cert->id;
        $chainCerts[] = [
            'id' => $cert->id,
            'order_id' => $cert->order_id,
            'last_cert_id' => $cert->last_cert_id,
            'common_name' => $cert->common_name,
            'status' => $cert->status,
        ];

        // 继续向上查找
        if ($cert->last_cert_id && isset($allOrphanCerts[$cert->last_cert_id])) {
            $this->collectChainCerts($allOrphanCerts[$cert->last_cert_id], $allOrphanCerts, $chainCerts, $visited);
        }

        // 查找引用当前证书的证书
        $children = $allOrphanCerts->where('last_cert_id', $cert->id);
        foreach ($children as $child) {
            $this->collectChainCerts($child, $allOrphanCerts, $chainCerts, $visited);
        }
    }

    /**
     * 查找引用链条中证书的其他证书
     */
    private function findReferencingCerts(array $chainCerts): array
    {
        $chainCertIds = array_column($chainCerts, 'id');

        return DB::table('certs')
            ->whereIn('last_cert_id', $chainCertIds)
            ->whereNotIn('id', $chainCertIds)
            ->get(['id', 'order_id', 'last_cert_id'])
            ->toArray();
    }

    /**
     * 分析链条的修复可能性
     */
    private function analyzeChainFixability(array $chainCerts, array $referencingCerts): array
    {
        $analysis = [
            'certs' => $chainCerts,
            'referencing_certs' => $referencingCerts,
            'correct_order_id' => null,
        ];

        // 检查引用证书是否有有效的 order_id
        foreach ($referencingCerts as $refCert) {
            $orderExists = DB::table('orders')->where('id', $refCert->order_id)->exists();
            if ($orderExists) {
                $analysis['status'] = 'can_fix_by_reference';
                $analysis['correct_order_id'] = $refCert->order_id;
                $analysis['reason'] = "引用证书 $refCert->id 有有效的 order_id";

                return $analysis;
            }
        }

        // 检查链条本身是否有有效的 order_id
        foreach ($chainCerts as $cert) {
            $orderExists = DB::table('orders')->where('id', $cert['order_id'])->exists();
            if ($orderExists) {
                $analysis['status'] = 'can_fix_by_chain';
                $analysis['correct_order_id'] = $cert['order_id'];
                $analysis['reason'] = "链条中证书 {$cert['id']} 有有效的 order_id";

                return $analysis;
            }
        }

        // 检查是否能通过递归查找到有效的 order_id
        foreach ($chainCerts as $cert) {
            if ($cert['last_cert_id']) {
                $correctOrderId = $this->findCorrectOrderIdByChain($cert['id'], $cert['last_cert_id']);
                if ($correctOrderId) {
                    $analysis['status'] = 'can_fix_by_chain';
                    $analysis['correct_order_id'] = $correctOrderId;
                    $analysis['reason'] = '通过递归查找找到有效的 order_id';

                    return $analysis;
                }
            }
        }

        // 检查是否存在循环引用
        if ($this->hasCircularReference($chainCerts)) {
            $analysis['status'] = 'circular_reference';
            $analysis['reason'] = '存在循环引用';

            return $analysis;
        }

        // 检查是否没有 last_cert_id
        $hasLastCert = false;
        foreach ($chainCerts as $cert) {
            if ($cert['last_cert_id']) {
                $hasLastCert = true;
                break;
            }
        }

        if (! $hasLastCert) {
            $analysis['status'] = 'no_last_cert';
            $analysis['reason'] = '链条中所有证书都没有 last_cert_id';

            return $analysis;
        }

        // 默认为真正无法修复
        $analysis['status'] = 'truly_unfixable';
        $analysis['reason'] = '无法找到任何有效的 order_id';

        return $analysis;
    }

    /**
     * 检查链条是否存在循环引用
     */
    private function hasCircularReference(array $chainCerts): bool
    {
        $certMap = [];
        foreach ($chainCerts as $cert) {
            $certMap[$cert['id']] = $cert['last_cert_id'];
        }

        foreach ($chainCerts as $cert) {
            $visited = [];
            $current = $cert['id'];

            while ($current && isset($certMap[$current])) {
                if (in_array($current, $visited)) {
                    return true; // 发现循环
                }
                $visited[] = $current;
                $current = $certMap[$current];
            }
        }

        return false;
    }

    /**
     * 显示链条分析结果
     */
    private function showChainAnalysisResults(array $stats): void
    {
        $this->info('🔗 证书链条分析结果：');
        $this->line('─────────────────────────────────────────');

        $totalFixable = $stats['can_fix_by_chain'] + $stats['can_fix_by_reference'];

        $this->line("总数: {$stats['total']}");
        $this->line("├─ 可以修复: $totalFixable ({$this->percentage($totalFixable, $stats['total'])}%)");
        $this->line("│  ├─ 通过链条修复: {$stats['can_fix_by_chain']}");
        $this->line("│  └─ 通过引用修复: {$stats['can_fix_by_reference']}");
        $this->line("├─ 循环引用: {$stats['circular_reference']}");
        $this->line("├─ 断裂链条: {$stats['broken_chain']}");
        $this->line("├─ 无 last_cert_id: {$stats['no_last_cert']}");
        $this->line("└─ 无法修复: {$stats['truly_unfixable']}");

        if ($totalFixable > 0) {
            $this->newLine();
            $this->info("✅ 有 $totalFixable 条记录可以通过链条修复！");
        }
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
     * 显示执行总结
     */
    private function showExecutionSummary(float $startTime, array $stats): void
    {
        $executionTime = round(microtime(true) - $startTime, 2);
        $memoryUsage = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        $this->info('📊 分析完成！');
        $this->line('─────────────────────────────────────────');
        $this->info("执行时间: $executionTime 秒");
        $this->info("内存使用: $memoryUsage MB");

        if ($stats['total_errors'] > 0) {
            $this->newLine();
            $this->info('🔧 建议的后续操作：');
            $this->line('   1. 使用详情分析查看具体错误：--detail');
            $this->line('   2. 使用链条分析深度排查：--chain');
            $this->line('   3. 执行修复命令：php artisan cert:fix');
        }
    }

    /**
     * 计算百分比
     */
    private function percentage(int $part, int $total): int
    {
        return $total > 0 ? round(($part / $total) * 100) : 0;
    }
}
