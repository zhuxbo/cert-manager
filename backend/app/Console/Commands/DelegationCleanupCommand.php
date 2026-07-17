<?php

namespace App\Console\Commands;

use App\Models\CnameDelegation;
use App\Models\Order;
use App\Services\Delegation\DelegationDnsService;
use App\Services\Delegation\ProxyDNS;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class DelegationCleanupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'delegation:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清理不是processing状态订单的委托DNS记录';

    protected DelegationDnsService $dnsService;

    protected ProxyDNS $proxyDNS;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->dnsService = app(DelegationDnsService::class);
        $this->proxyDNS = app(ProxyDNS::class);

        $this->info('开始清理委托DNS记录...');

        try {
            // 1. 获取委托域名配置
            $delegation = get_system_setting('site', 'delegation');
            $proxyZone = $delegation['proxyZone'] ?? null;

            if (empty($proxyZone)) {
                $this->error('代理域名未设置，无法执行清理');

                return;
            }

            $this->info("委托域名: $proxyZone");

            // 2. 调用腾讯云 API 查询委托域名的所有 TXT 记录
            $this->info('正在查询腾讯云 TXT 记录...');
            $allTxtRecords = $this->proxyDNS->getAllTxtRecords($proxyZone);
            $this->info('腾讯云 TXT 记录总数: '.count($allTxtRecords));

            // 3. 查询所有 processing/approving 订单的委托记录（F2-3 缺陷二：approving 单已过 DCV、
            //    等 CA 审批，其委托 TXT 不可删，否则 CA 复查 DCV 即失败）
            $this->info('正在查询处理中/待批准订单的委托记录...');
            $processingOrders = Order::with(['latestCert'])
                ->whereHas('latestCert', function ($query) {
                    $query->whereIn('status', ['processing', 'approving']);
                })
                ->get();

            // 4. 收集所有应该保留的 label（记录名）
            $keepLabels = collect();
            foreach ($processingOrders as $order) {
                $cert = $order->latestCert;
                $validations = $cert->validation;

                if (empty($validations)) {
                    continue;
                }

                foreach ($validations as $validation) {
                    if (isset($validation['delegation_id'])) {
                        $delegation = CnameDelegation::find($validation['delegation_id']);
                        if ($delegation) {
                            $keepLabels->push($delegation->label);
                        }
                    }
                }
            }

            $keepLabels = $keepLabels->unique()->values();
            $this->info('应该保留的记录数: '.$keepLabels->count());

            // 5. 比对差值，找出需要删除的记录
            //    删除判据在 keepLabels 白名单之上，前置「委托格式收敛」：仅删 label 形如 32 或
            //    64 位 hex 的记录，护住 proxyZone 下用户自放的 SPF/DKIM/_dmarc/站点验证等非委托 TXT
            //    （apex `@`、含点/下划线的名字均不匹配 → 永不进删除集）。委托 label 由
            //    substr(hash('sha256', ...), 0, 32) 生成（当代恒 32-hex）；迁移列注释与前身仓历史
            //    可能存在 64-hex 形态，故判据兼容两者（大小写不敏感防漂移）。孤儿委托（表内已删、
            //    DNS 残留）label 仍为 hex 格式、不在 keepLabels → 仍被清理，格式过滤两全不漏清。
            $recordsToDelete = collect($allTxtRecords)->filter(function ($record) use ($keepLabels) {
                if (preg_match('/^([0-9a-f]{32}|[0-9a-f]{64})$/i', (string) $record['name']) !== 1) {
                    return false;
                }

                return ! $keepLabels->contains($record['name']);
            });

            $this->info('需要删除的记录数: '.$recordsToDelete->count());

            if ($recordsToDelete->isEmpty()) {
                $this->info('没有需要清理的记录');

                return;
            }

            // 6. 批量删除记录（一次最多 2000 条）
            $recordIds = $recordsToDelete->pluck('id')->toArray();
            $this->info('正在批量删除记录...');
            $this->proxyDNS->batchDeleteRecords($recordIds);

            Log::info('批量清理委托DNS记录成功', [
                'proxy_zone' => $proxyZone,
                'deleted_count' => count($recordIds),
                'deleted_labels' => $recordsToDelete->pluck('name')->toArray(),
            ]);

            // 7. 清理数据库中的 auto_txt_written 标记
            $this->info('正在清理数据库中的标记...');
            $cleanedMarkCount = $this->cleanDatabaseMarks($recordsToDelete->pluck('name')->toArray());

            $this->info('清理完成！');
            $this->info('- 删除了 '.count($recordIds).' 条 DNS 记录');
            $this->info("- 清理了 $cleanedMarkCount 个数据库标记");
        } catch (Throwable $e) {
            $this->error('清理失败: '.$e->getMessage());
            Log::error('委托DNS记录清理失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 清理数据库中的 auto_txt_written 标记
     *
     * @param  array  $deletedLabels  已删除的 label 数组
     * @return int 清理的标记数量
     */
    protected function cleanDatabaseMarks(array $deletedLabels): int
    {
        $cleanedCount = 0;
        $deletedLabelSet = array_flip($deletedLabels); // O(1) 命中判定，替代逐条 in_array

        // F2-3 缺陷一：去掉 30 天窗口，分块扫所有带 auto_txt_written 标记的证书。
        // 原只扫 30 天内订单 → >30 天订单 label 被删后标记不清、TXT 永不重写。
        // 全扫性能收敛（每日 06:00 冷路径，chunkById 控内存）：
        //  - SQL 侧 validation LIKE '%auto_txt_written%' 粗筛（json_encode 不转义该 ASCII 键，是
        //    带标记记录的严格超集、无漏；误匹配由 PHP 侧 isset && === true 复核吸收），避免
        //    whereNotNull 近乎全表（validation 除 unpaid 外几乎非空、无选择性）；
        //  - latestCert 只 select id/validation，不水合 csr/private_key/enc_* 宽列（mediumtext）；
        //  - 每 chunk 先收集 delegation_id 再 whereIn 批量取 label 映射，消除逐条 find 的 N+1。
        Order::with(['latestCert' => fn ($query) => $query->select('id', 'validation')])
            ->whereHas('latestCert', fn ($query) => $query->where('validation', 'like', '%auto_txt_written%'))
            ->chunkById(200, function ($orders) use ($deletedLabelSet, &$cleanedCount) {
                // 先收集本 chunk 全部带标记的 delegation_id，一次 whereIn 批量取 label（消除 N+1）
                $delegationIds = [];
                foreach ($orders as $order) {
                    foreach ($order->latestCert->validation ?? [] as $validation) {
                        if (($validation['auto_txt_written'] ?? false) === true && ! empty($validation['delegation_id'])) {
                            $delegationIds[$validation['delegation_id']] = true;
                        }
                    }
                }

                $labelById = empty($delegationIds)
                    ? collect()
                    : CnameDelegation::whereIn('id', array_keys($delegationIds))->pluck('label', 'id');

                foreach ($orders as $order) {
                    $cert = $order->latestCert;
                    $validations = $cert->validation;

                    if (empty($validations)) {
                        continue;
                    }

                    $hasChanges = false;
                    $updatedValidations = [];

                    foreach ($validations as $index => $validation) {
                        // 检查是否有 auto_txt_written 标记
                        if (! isset($validation['auto_txt_written']) || $validation['auto_txt_written'] !== true) {
                            $updatedValidations[$index] = $validation;

                            continue;
                        }

                        $delegationId = $validation['delegation_id'] ?? null;

                        if (! $delegationId) {
                            $updatedValidations[$index] = $validation;

                            continue;
                        }

                        $label = $labelById->get($delegationId);

                        // 委托记录不存在（孤儿标记），或 label 已被删除 → 清理标记
                        if ($label === null || isset($deletedLabelSet[$label])) {
                            unset($validation['auto_txt_written'], $validation['auto_txt_written_at'], $validation['delegation_id']);
                            $updatedValidations[$index] = $validation;
                            $hasChanges = true;
                            $cleanedCount++;
                        } else {
                            $updatedValidations[$index] = $validation;
                        }
                    }

                    // 保存更新后的 validation
                    if ($hasChanges) {
                        $cert->validation = $updatedValidations;
                        $cert->save();
                    }
                }
            });

        return $cleanedCount;
    }
}
