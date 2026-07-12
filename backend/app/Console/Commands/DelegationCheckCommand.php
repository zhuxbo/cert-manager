<?php

namespace App\Console\Commands;

use App\Models\Cert;
use App\Models\CnameDelegation;
use App\Models\User;
use App\Services\Delegation\CnameDelegationService;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Notification\SystemAlert;
use Illuminate\Console\Command;
use Throwable;

/**
 * CNAME 委托健康检查命令（周巡检 + 手工运行）。
 *
 * 用法：php artisan delegation:check [--dry-run] [--check-txt]
 *
 * 两阶段 + 全局熔断，防 dnsTools 系统性停摆误报/误删：
 *  阶段①：逐条纯探测（不落库），跨 chunk 累积标量三态 outcome（valid|invalid|unreachable）
 *  与 last_checked_at 快照；
 *  阶段②轮末：先判全局熔断——不可达占比 ≥ RATIO 且样本 ≥ MIN_SAMPLE 判系统性停摆，本轮零落库/
 *  零删除/零通知 + SystemAlert 告警；未熔断才逐条 CAS 落库（applyProbeOutcomeIfUnchanged，
 *  条件 = 快照未变，防两阶段间隔内 ValidateCommand/手动检查写入的新鲜结论被陈旧探测覆盖）、
 *  按 post-apply fail_count gate 删除/通知，通知按 user 聚合（累积-后派发，每用户一封）。
 *
 * 无效委托处理（未熔断轮）：
 * - 有 active 证书（active/unpaid/pending/processing/approving）：保留，fail_count≥阈值时发用户通知；
 * - 无 active 证书：fail_count≥阈值才删除（未达阈值保留、等下轮确认，抖动 gate 防单次误删）。
 */
class DelegationCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'delegation:check {--dry-run : 只检查不删除/不通知} {--check-txt : 检测TXT冲突}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check CNAME delegation health and cleanup unused invalid delegations';

    /** 抖动 gate 阈值：连续失败 ≥ 此值才发通知/才删除（删除/通知/console 预警共用）。 */
    private const NOTIFY_FAIL_THRESHOLD = 2;

    /** 全局熔断：不可达占比 ≥ 此值判系统性停摆（含恰等于）。 */
    private const CIRCUIT_BREAKER_RATIO = 0.5;

    /** 全局熔断样本下限：总数 < 此值不熔断（防小基数误熔断；冻结层仍独立生效）。 */
    private const CIRCUIT_BREAKER_MIN_SAMPLE = 5;

    /** 熔断告警去重键（与 F2-1 实际键 dnstools_outage 不同键、不冲突）。 */
    private const PATROL_OUTAGE_KEY = 'delegation_patrol_outage';

    /** 熔断告警固定指纹（不可达轮数逐轮波动，固定指纹防 churn 击穿去重刷屏）。 */
    private const PATROL_OUTAGE_FINGERPRINT = 'patrol_outage';

    /** 熔断告警 TTL：504h = 21 天 = 3× 周巡检周期（契约 ≥3× 巡检周期，防去重虚设）。 */
    private const PATROL_OUTAGE_TTL_HOURS = 504;

    protected CnameDelegationService $delegationService;

    public function __construct(CnameDelegationService $delegationService)
    {
        parent::__construct();
        $this->delegationService = $delegationService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $dryRun = (bool) $this->option('dry-run');
        $checkTxt = (bool) $this->option('check-txt');
        $this->info('开始检查 CNAME 委托健康状态...'.($dryRun ? ' (dry-run 模式)' : '').($checkTxt ? ' (含TXT冲突检测)' : ''));

        // ── 阶段①：逐条纯探测（不落库），跨 chunk 累积标量三态 outcome ───────────────
        $outcomes = [];
        $errorCount = 0;
        $unreachableCount = 0;

        CnameDelegation::query()->chunkById(200, function ($delegations) use ($checkTxt, &$outcomes, &$errorCount, &$unreachableCount) {
            foreach ($delegations as $delegation) {
                try {
                    $outcome = $this->delegationService->probeValidity($delegation);

                    // 检测 TXT 冲突（仅在 --check-txt 时执行，避免批量查询拉长耗时）
                    if ($checkTxt) {
                        $txtWarning = $this->delegationService->checkTxtConflict($delegation);
                        if ($txtWarning) {
                            $this->warn("  ⚠ $txtWarning");
                        }
                    }

                    if ($outcome === 'unreachable') {
                        $unreachableCount++;
                    }

                    $outcomes[] = [
                        'id' => $delegation->id,
                        'user_id' => $delegation->user_id,
                        'zone' => $delegation->zone,
                        'fail_count' => $delegation->fail_count, // 落库前现值
                        'last_checked_at' => $delegation->last_checked_at, // 阶段②落库前 TOCTOU CAS 基准
                        'outcome' => $outcome,
                    ];
                } catch (Throwable $e) {
                    // 探测层已内吞异常归 unreachable；此处兜命令层非探测异常（DB 等），继续跑
                    $errorCount++;
                    $this->error("✗ 委托 #$delegation->id ($delegation->zone) - 检查异常: {$e->getMessage()}");
                }
            }
        });

        $totalCount = count($outcomes);

        // ── 阶段②轮末：全局熔断判定（系统性停摆 → 本轮零落库/零删除/零通知 + 告警）────────
        if ($this->isCircuitBroken($totalCount, $unreachableCount)) {
            $this->reportOutage($totalCount, $unreachableCount);
            $this->printSummary($totalCount, 0, 0, 0, $errorCount, $unreachableCount, 0);

            return;
        }

        // 未熔断（健康轮）→ 清熔断去重键（恢复后再停摆立即告警）
        app(SystemAlert::class)->clearDedupe(self::PATROL_OUTAGE_KEY);

        // ── 阶段②：CAS 落库 + post-apply gate + 通知候选累积（累积-后派发）───────────────
        $validCount = 0;
        $invalidKeepCount = 0;
        $deletedCount = 0;
        $staleSkipCount = 0;
        $notifyCandidates = []; // user_id => [{delegation_id, zone}]

        foreach (array_chunk($outcomes, 200) as $batch) {
            foreach ($batch as $o) {
                // TOCTOU CAS 落库（dry-run 也照写，与历史一致；仅删除/通知受 dry-run 拦截）：
                // 条件 = 阶段①快照 last_checked_at 未变。探测期间已有更新鲜结论落库
                // （ValidateCommand 每分钟/双端手动检查/AutoRenew）或行已删 → affected=0，
                // 本条陈旧结论作废、跳过全部 gate（不删/不通知/不计数）。
                $applied = $this->delegationService->applyProbeOutcomeIfUnchanged(
                    $o['id'], $o['outcome'], $o['last_checked_at']
                );

                if (! $applied) {
                    $staleSkipCount++;
                    $this->warn("… 委托 #{$o['id']} ({$o['zone']}) - 探测期间已被并发更新/删除，本轮结论作废");

                    continue;
                }

                if ($o['outcome'] === 'valid') {
                    $validCount++;
                    $this->line("✓ 委托 #{$o['id']} ({$o['zone']}) - 有效");

                    continue;
                }

                if ($o['outcome'] === 'unreachable') {
                    // 冻结个体：本轮不删不通知（结果不可信，等下轮或熔断层）
                    $this->warn("… 委托 #{$o['id']} ({$o['zone']}) - 本轮探测不可达，冻结计数");

                    continue;
                }

                // outcome === 'invalid'：post-apply fail_count（CAS 命中时与 DB 侧 LEAST 自增等价）
                $postFailCount = min($o['fail_count'] + 1, 100);
                $hasActiveCert = $this->hasActiveCertForDomain($o['user_id'], $o['zone']);

                if ($hasActiveCert) {
                    // 有 active 证书使用该域名，保留委托记录
                    $invalidKeepCount++;
                    $this->warn("✗ 委托 #{$o['id']} ({$o['zone']}) - 无效但有 active 证书，保留");

                    // post-apply fail_count 达阈：console 预警 + 通知候选
                    if ($postFailCount >= self::NOTIFY_FAIL_THRESHOLD) {
                        $this->error("  ⚠ 连续失败 $postFailCount 次，请检查 CNAME 配置");
                        $notifyCandidates[$o['user_id']][] = ['delegation_id' => $o['id'], 'zone' => $o['zone']];
                    }
                } elseif ($postFailCount >= self::NOTIFY_FAIL_THRESHOLD) {
                    // 无 active 证书且达抖动阈值：删除委托记录（已无用）
                    if ($dryRun) {
                        $deletedCount++;
                        $this->warn("✗ 委托 #{$o['id']} ({$o['zone']}) - 无效且无 active 证书，将删除 (dry-run)");
                    } elseif (CnameDelegation::whereKey($o['id'])->where('valid', false)->delete() > 0) {
                        // 条件删除：CAS 落库后至删除前的极窄窗内被并发恢复 valid=true 则不删
                        $deletedCount++;
                        $this->warn("✗ 委托 #{$o['id']} ({$o['zone']}) - 无效且无 active 证书，已删除");
                    } else {
                        $this->warn("… 委托 #{$o['id']} ({$o['zone']}) - 删除前已被并发恢复有效，跳过删除");
                    }
                } else {
                    // 无 active 证书但未达阈值：保留、等下轮确认（抖动 gate 防单次误删）
                    $this->warn("✗ 委托 #{$o['id']} ({$o['zone']}) - 无效但未达失败阈值（{$postFailCount}），暂留待下轮确认");
                }
            }
        }

        // ── 通知派发（per-user 聚合，每用户一封；dry-run 跳过）───────────────────────
        if (! $dryRun) {
            $this->dispatchInvalidNotifications($notifyCandidates);
        }

        $this->printSummary($totalCount, $validCount, $invalidKeepCount, $deletedCount, $errorCount, $unreachableCount, $staleSkipCount);

        if ($dryRun && $deletedCount > 0) {
            $this->warn('dry-run 模式：实际未删除任何记录，移除 --dry-run 参数执行实际删除');
        }
    }

    /**
     * 全局熔断判定：总数达样本下限且不可达占比达阈值 → 系统性停摆。
     */
    private function isCircuitBroken(int $total, int $unreachable): bool
    {
        if ($total < self::CIRCUIT_BREAKER_MIN_SAMPLE) {
            return false;
        }

        return ($unreachable / $total) >= self::CIRCUIT_BREAKER_RATIO;
    }

    /**
     * 熔断轮告警（固定指纹防 churn，TTL 504h，healthy 轮由 clearDedupe 复位）。
     */
    private function reportOutage(int $total, int $unreachable): void
    {
        $ratio = $total > 0 ? round($unreachable / $total, 2) : 0;
        $this->error("检测到系统性 DNS 探测停摆（不可达 $unreachable/{$total}，占比 {$ratio}），本轮跳过落库/删除/通知");

        app(SystemAlert::class)->send(
            'delegation_patrol',
            '委托健康巡检 DNS 探测系统性停摆',
            "本轮委托巡检 $total 条中 $unreachable 条不可达（占比 $ratio ≥ ".self::CIRCUIT_BREAKER_RATIO.'），疑似 DNS 探测服务/解析器停摆，已跳过本轮全部落库/删除/通知，恢复后自动复位',
            ['total' => $total, 'unreachable' => $unreachable, 'ratio' => $ratio],
            self::PATROL_OUTAGE_KEY,
            self::PATROL_OUTAGE_TTL_HOURS,
            self::PATROL_OUTAGE_FINGERPRINT,
        );
    }

    /**
     * 按 user 聚合派发委托失效通知（每用户一封，context 显式传 delegation_ids）。
     * 单用户失败不中断整批（镜像 BalanceForecastCommand）。
     *
     * @param  array<int, array<int, array{delegation_id: int, zone: string}>>  $notifyCandidates
     */
    private function dispatchInvalidNotifications(array $notifyCandidates): void
    {
        if (empty($notifyCandidates)) {
            return;
        }

        $notificationCenter = app(NotificationCenter::class);

        foreach ($notifyCandidates as $userId => $items) {
            $user = User::find($userId);
            if (! $user || ! $user->email) {
                continue;
            }

            try {
                $notificationCenter->dispatch(new NotificationIntent(
                    'delegation_invalid',
                    'user',
                    $userId,
                    [
                        // Builder 数据来源只能是该 ids 列表（禁 Builder 自行全表扫失效委托）
                        'delegation_ids' => array_column($items, 'delegation_id'),
                        'email' => $user->email,
                    ]
                ));
                $this->info("用户 #$userId 委托失效通知：".count($items).' 条');
            } catch (Throwable $e) {
                $this->error("用户 #$userId 委托失效通知失败: {$e->getMessage()}");
            }
        }
    }

    /**
     * 输出统计信息。
     */
    private function printSummary(int $total, int $valid, int $invalidKeep, int $deleted, int $error, int $unreachable, int $staleSkip): void
    {
        $this->info("\n检查完成！");
        $this->table(
            ['统计项', '数量'],
            [
                ['总计', $total],
                ['有效', $valid],
                ['无效(保留)', $invalidKeep],
                ['无效(删除)', $deleted],
                ['不可达(冻结)', $unreachable],
                ['陈旧跳过', $staleSkip],
                ['异常', $error],
            ]
        );
    }

    /**
     * 检查用户是否有包含该域名的有效证书
     * 状态包括：active、unpaid、pending、processing、approving
     */
    private function hasActiveCertForDomain(int $userId, string $domain): bool
    {
        return Cert::whereIn('status', ['active', 'unpaid', 'pending', 'processing', 'approving'])
            ->whereHas('order', fn ($q) => $q->where('user_id', $userId))
            ->where(function ($query) use ($domain) {
                // 检查 common_name 或 alternative_names 是否包含该域名
                $query->where('common_name', $domain)
                    ->orWhere('alternative_names', 'like', "%$domain%");
            })
            ->exists();
    }
}
