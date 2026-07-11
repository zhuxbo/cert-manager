<?php

namespace App\Console\Commands;

use App\Services\Notification\SystemAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * E4 服务器时钟监控（P2）。
 *
 * 调度：schedule:clock-check，每小时（freeze 期 skip）。
 *
 * 逐源采样 HTTP Date 头（RFC 7231，非 NTP 最小实现），默认国内可达源 aliyun/baidu
 * （主控已裁，config/env 可覆盖）。存活性与告警判定分离：任一源可达即跑，但告警须
 * **≥2 源一致佐证**（噪音敏感判定）：
 *  - 含 Age>0 的 CDN 缓存响应丢弃（Date 是原始生成时刻，不可信）。
 *  - 有效样本 <2（含全失败/仅单源）→ 跳过不告警；全失败升 Log::warning（防 E4 自身失效隐形）。
 *  - ≥2 源 offset 绝对值 > max_skew 且同号 → 告警（固定指纹 'clock_skew' 防偏差值波动 churn）。
 *  - 法定人数达标且未超阈（时钟正常）→ clearDedupe。
 *
 * 不装 chrony/固化时区/自动校时（属 ops，P2/P3）。
 */
class ClockCheckCommand extends Command
{
    protected $signature = 'schedule:clock-check';

    protected $description = '服务器时钟偏差监控（≥2 源一致才告警，全失败仅记 warning）';

    private const DEDUPE_KEY = 'clock';

    public function handle(): int
    {
        if (! config('monitoring.clock.enabled', true)) {
            return self::SUCCESS;
        }

        $sources = (array) config('monitoring.clock.sources', []);
        $maxSkew = (int) config('monitoring.clock.max_skew_seconds', 120);
        $timeout = (int) config('monitoring.clock.http_timeout_seconds', 5);

        $offsets = [];
        foreach ($sources as $source) {
            $offset = $this->sampleOffset((string) $source, $timeout);
            if ($offset !== null) {
                $offsets[] = $offset;
            }
        }

        // 有效样本 <2 → 跳过不告警（存活语义：任一成功即跑；告警须法定人数）
        if (count($offsets) < 2) {
            if (empty($offsets)) {
                // 全失败升 warning（生产 LOG_LEVEL=warning 可见）：防 E4「从没工作过」自身失效隐形
                Log::warning('[clock] 全部时间源无有效样本，时钟监控本轮空转', ['sources' => $sources]);
            } elseif (abs($offsets[0]) > $maxSkew) {
                // 单源超阈但无 ≥2 源佐证：仅 debug 不告警（宁静默勿误报）
                Log::debug('[clock] 单源时钟超阈但法定人数不足，跳过告警', ['offset_seconds' => $offsets[0]]);
            }

            return self::SUCCESS;
        }

        // 法定人数：≥2 源 offset 绝对值 > maxSkew 且同号
        $skewed = array_filter($offsets, fn ($o) => abs($o) > $maxSkew);
        $positive = array_values(array_filter($skewed, fn ($o) => $o > 0));
        $negative = array_values(array_filter($skewed, fn ($o) => $o < 0));
        $agreeing = count($positive) >= 2 ? $positive : (count($negative) >= 2 ? $negative : []);

        if (count($agreeing) >= 2) {
            $median = $this->median($agreeing);
            Log::warning('[clock] 服务器时钟偏差', [
                'median_seconds' => $median,
                'sources_agreed' => count($agreeing),
            ]);
            app(SystemAlert::class)->send(
                'clock',
                '服务器时钟偏差',
                '服务器时钟偏差约 '.round(abs($median)).'s（'.count($agreeing).' 源一致），请核对系统时间/NTP',
                [
                    'sources_agreed' => count($agreeing),
                    'skew_seconds' => (int) round($median),
                    'local' => now()->toDateTimeString(),
                ],
                self::DEDUPE_KEY,
                (int) config('monitoring.clock.dedupe_ttl_hours', 6),
                'clock_skew', // 固定指纹：偏差值波动不 churn
            );

            return self::SUCCESS;
        }

        // 法定人数达标且未超阈（时钟正常）→ 清键
        app(SystemAlert::class)->clearDedupe(self::DEDUPE_KEY);

        return self::SUCCESS;
    }

    /**
     * 采样单源时钟偏差（external - local，秒）。失败/缓存命中/无 Date → 返回 null（跳过该源）。
     */
    private function sampleOffset(string $source, int $timeout): ?int
    {
        $localNow = Carbon::now();

        try {
            $response = Http::timeout($timeout)->head($source);
        } catch (Throwable) {
            // 连接失败/超时 → 跳过该源
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        // CDN 缓存命中（Age>0）：Date 是原始生成时刻，不可信 → 丢弃该源样本
        if ((int) $response->header('Age') > 0) {
            return null;
        }

        $dateHeader = $response->header('Date');
        if (! $dateHeader) {
            return null;
        }

        try {
            $external = Carbon::parse($dateHeader);
        } catch (Throwable) {
            return null;
        }

        return $external->timestamp - $localNow->timestamp;
    }

    /**
     * @param  array<int, int>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 0
            ? ($values[$mid - 1] + $values[$mid]) / 2
            : (float) $values[$mid];
    }
}
