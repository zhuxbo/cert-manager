<?php

namespace App\Console\Commands;

use App\Models\Cert;
use App\Models\CnameDelegation;
use App\Services\Delegation\CnameDelegationService;
use App\Services\Order\Utils\DomainUtil;
use Illuminate\Console\Command;
use Throwable;

/**
 * CNAME 委托健康检查命令（周巡检 + 手工运行）。
 *
 * 无有效证书引用的记录直接清理；仍有引用的记录才查询全部可用委托域，
 * 并以实际命中的域校正 proxy_domain。
 */
class DelegationCheckCommand extends Command
{
    protected $signature = 'delegation:check {--dry-run : 只检查不删除/不通知} {--check-txt : 检测TXT冲突}';

    protected $description = 'Check CNAME delegation health and cleanup unused delegations';

    public function __construct(
        protected CnameDelegationService $delegationService,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $dryRun = (bool) $this->option('dry-run');
        $checkTxt = (bool) $this->option('check-txt');
        $this->info('开始检查 CNAME 委托健康状态...'.($dryRun ? ' (dry-run 模式)' : '').($checkTxt ? ' (含TXT冲突检测)' : ''));

        $totalCount = 0;
        $validCount = 0;
        $invalidKeepCount = 0;
        $deletedCount = 0;
        $unreachableCount = 0;
        $staleSkipCount = 0;
        $errorCount = 0;

        CnameDelegation::query()->chunkById(200, function ($delegations) use (
            $dryRun,
            $checkTxt,
            &$totalCount,
            &$validCount,
            &$invalidKeepCount,
            &$deletedCount,
            &$unreachableCount,
            &$staleSkipCount,
            &$errorCount,
        ): void {
            foreach ($delegations as $delegation) {
                $totalCount++;

                if (! $this->hasUsefulCertReference($delegation->user_id, $delegation->zone)) {
                    if ($dryRun) {
                        $deletedCount++;
                        $this->warn("✗ 委托 #$delegation->id ($delegation->zone) - 无有效证书引用，将删除 (dry-run)");
                    } elseif (CnameDelegation::whereKey($delegation->id)->delete() > 0) {
                        $deletedCount++;
                        $this->warn("✗ 委托 #$delegation->id ($delegation->zone) - 无有效证书引用，已删除");
                    } else {
                        $staleSkipCount++;
                        $this->warn("… 委托 #$delegation->id ($delegation->zone) - 删除前记录已变化，跳过");
                    }

                    continue;
                }

                try {
                    $probe = $this->delegationService->probeConfiguredDomains($delegation);

                    if ($checkTxt && ($txtWarning = $this->delegationService->checkTxtConflict($delegation))) {
                        $this->warn("  ⚠ $txtWarning");
                    }

                    $applied = $this->delegationService->applyProbeOutcomeIfUnchanged(
                        $delegation->id,
                        $probe['outcome'],
                        $delegation->last_checked_at,
                        $probe['proxy_domain'],
                    );

                    if (! $applied) {
                        $staleSkipCount++;
                        $this->warn("… 委托 #$delegation->id ($delegation->zone) - 探测期间已被更新/删除，本轮结论作废");

                        continue;
                    }

                    if ($probe['outcome'] === 'valid') {
                        $validCount++;
                        $this->line("✓ 委托 #$delegation->id ($delegation->zone) - 有效");
                    } elseif ($probe['outcome'] === 'invalid') {
                        $invalidKeepCount++;
                        $this->warn("✗ 委托 #$delegation->id ($delegation->zone) - 无效但仍有证书引用，保留");
                    } else {
                        $unreachableCount++;
                        $this->warn("… 委托 #$delegation->id ($delegation->zone) - 本轮探测不可达");
                    }
                } catch (Throwable $e) {
                    $errorCount++;
                    $this->error("✗ 委托 #$delegation->id ($delegation->zone) - 检查异常: {$e->getMessage()}");
                }
            }
        });

        $this->printSummary(
            $totalCount,
            $validCount,
            $invalidKeepCount,
            $deletedCount,
            $unreachableCount,
            $staleSkipCount,
            $errorCount,
        );

        if ($dryRun && $deletedCount > 0) {
            $this->warn('dry-run 模式：实际未删除任何记录，移除 --dry-run 参数执行实际删除');
        }
    }

    private function hasUsefulCertReference(int $userId, string $domain): bool
    {
        $variants = array_values(array_unique(array_filter([
            strtolower(DomainUtil::convertToAscii($domain)),
            strtolower(DomainUtil::convertToUnicode($domain)),
        ])));

        return Cert::query()
            ->whereIn('status', ['active', 'unpaid', 'pending', 'processing', 'approving', 'cancelling'])
            ->whereHas('order', fn ($query) => $query->where('user_id', $userId))
            ->where(function ($query) use ($variants): void {
                foreach ($variants as $domain) {
                    $query->orWhere(function ($query) use ($domain): void {
                        $query->where('common_name', $domain)
                            ->orWhere('common_name', 'like', "%.{$domain}")
                            ->orWhere('alternative_names', $domain)
                            ->orWhere('alternative_names', 'like', "{$domain},%")
                            ->orWhere('alternative_names', 'like', "%,{$domain}")
                            ->orWhere('alternative_names', 'like', "%,{$domain},%")
                            ->orWhere('alternative_names', 'like', "%.{$domain}")
                            ->orWhere('alternative_names', 'like', "%.{$domain},%");
                    });
                }
            })
            ->exists();
    }

    private function printSummary(
        int $total,
        int $valid,
        int $invalidKeep,
        int $deleted,
        int $unreachable,
        int $staleSkip,
        int $error,
    ): void {
        $this->info("\n检查完成！");
        $this->table(
            ['统计项', '数量'],
            [
                ['总计', $total],
                ['有效', $valid],
                ['无效(保留)', $invalidKeep],
                ['无引用(删除)', $deleted],
                ['不可达', $unreachable],
                ['陈旧跳过', $staleSkip],
                ['异常', $error],
            ],
        );
    }
}
