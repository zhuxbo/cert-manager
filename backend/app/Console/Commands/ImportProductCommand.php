<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Notification\SystemAlert;
use App\Services\Order\Action;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * E2 产品属性漂移同步 cron（P2）。
 *
 * 调度：schedule:import-product，每天 04:30（freeze 期 skip）。
 *
 * 固定 type='update'：仅属性漂移同步（不 create、不删）。cron 治不了上游下线产品
 * （上游 getProducts 只返 status=1 且 makeHidden，下线产品缺席响应）——只做在场产品的
 * 属性同步；缺席禁用/扣费卡死归 P0-1 路径4 reconcile。
 *
 * 逐来源 catch：Action::importProduct(resilient:true) 单产品失败不中断整来源（收集 msg），
 * 一个来源崩溃（非 ApiResponseException）不阻断其余来源。有失败（来源级崩溃或产品级收集）
 * → 汇总 Log::error + SystemAlert（内容指纹：同样失败不重发、失败摘要变则再发）；零失败 →
 * clearDedupe。
 */
class ImportProductCommand extends Command
{
    protected $signature = 'schedule:import-product';

    protected $description = '逐来源同步产品属性漂移（仅 update，失败聚合告警）';

    private const DEDUPE_KEY = 'import_product';

    public function handle(): int
    {
        if (! config('monitoring.import_product.enabled', true)) {
            return self::SUCCESS;
        }

        $sources = Product::query()->select('source')->distinct()->pluck('source')->filter()->values();
        if ($sources->isEmpty()) {
            $sources = collect(['default']);
        }

        $failures = [];
        foreach ($sources as $source) {
            try {
                $action = app(Action::class);
                // 固定 type='update'（仅属性漂移，不 create/删）+ resilient=true（逐产品 catch）
                $action->importProduct((string) $source, '', '', 'update', true);
                $issues = $action->getImportIssues();
                if (! empty($issues)) {
                    $failures[] = "source={$source}: ".count($issues).' 个产品同步失败';
                }
            } catch (Throwable $e) {
                // 来源级崩溃（非 ApiResponseException）：不阻断其余来源
                Log::error('[import_product] 来源同步崩溃', [
                    'source' => $source,
                    'error' => $e->getMessage(),
                ]);
                $failures[] = "source={$source}: ".$e->getMessage();
            }
        }

        if (empty($failures)) {
            // 零失败 → 清键（下次失败立即告警）
            app(SystemAlert::class)->clearDedupe(self::DEDUPE_KEY);

            return self::SUCCESS;
        }

        $summary = implode('; ', $failures);
        Log::error('[import_product] 产品属性同步存在失败', ['failures' => $failures]);
        app(SystemAlert::class)->send(
            'import_product',
            '产品属性同步存在失败',
            '本次产品同步出现失败：'.$summary,
            ['failure_count' => count($failures), 'summary' => mb_substr($summary, 0, 180)],
            self::DEDUPE_KEY,
            (int) config('monitoring.import_product.dedupe_ttl_hours', 72),
            // 内容指纹（失败摘要变则再发）：不传固定指纹
        );

        return self::SUCCESS;
    }
}
