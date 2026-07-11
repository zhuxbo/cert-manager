<?php

namespace App\Console\Commands;

use App\Services\Notification\SystemAlert;
use App\Services\Order\Api\default\Sdk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * E1 上游 CA 凭证健康心跳（P1-6②）。
 *
 * 调度：schedule:ca-healthcheck，每 15 分钟一次（freeze 期 skip）。
 *
 * 单一上游网关模型：直调 default\Sdk::getProducts() 取原始数组（不经 Api 路由——handleResult
 * 会 throw 且抹掉 http code）。只读、单次无重试，避免高频探测触发上游风控。
 *
 * 分类（仅做不需上游配合的部分）：
 *  - code===1 → 健康 → clearDedupe，不告警。
 *  - msg==='Api url or token is not set' → 未配置态，Log::info 剔出告警（新装/测试实例把「没填」
 *    当「失效」是狼来了），不占去重键。
 *  - 鉴权维度 → 告警：**主信号** 'Http status code 401'/'403'（Sdk 状态码路径，可靠）；
 *    **辅助信号** 含 'Unauthorized'（上游 200-body 透传，措辞属对上游应答体的猜测——可与上游
 *    实现校准：实现期亲读上游网关仓核对坏 token 实际应答形状后调整白名单；上游措辞变更会致
 *    此辅助信号失明，401/403 主信号兜底）。
 *  - 其余 code===0（连接超时/请求失败/No return code/5xx）→ 不告警（连通性归 P0-4 心跳/拨测，
 *    防瞬断噪音），Log::info 留痕。
 */
class CaHealthcheckCommand extends Command
{
    protected $signature = 'schedule:ca-healthcheck';

    protected $description = '上游 CA 凭证健康心跳（凭证失效告警，连通性不告警）';

    private const DEDUPE_KEY = 'ca_credentials';

    public function handle(): int
    {
        if (! config('monitoring.ca_healthcheck.enabled', true)) {
            return self::SUCCESS;
        }

        $result = app(Sdk::class)->getProducts();
        $code = $result['code'] ?? 0;
        $msg = (string) ($result['msg'] ?? '');

        // 健康：清去重键（恢复后再异常立即告警）
        if ($code === 1) {
            app(SystemAlert::class)->clearDedupe(self::DEDUPE_KEY);

            return self::SUCCESS;
        }

        // 未配置态：剔出告警，不占键（新装/测试实例未填上游）
        if ($msg === 'Api url or token is not set') {
            Log::info('[ca_healthcheck] 上游未配置，跳过（不告警）');

            return self::SUCCESS;
        }

        // 鉴权维度 → 告警（主信号 401/403；辅助信号 Unauthorized）
        if ($this->isAuthFailure($msg)) {
            Log::warning('[ca_healthcheck] 上游 CA 凭证疑似异常', ['msg' => $msg]);
            app(SystemAlert::class)->send(
                'ca_credentials',
                '上游 CA 凭证异常',
                $msg,
                ['probe' => 'get-products'],
                self::DEDUPE_KEY,
                (int) config('monitoring.ca_healthcheck.dedupe_ttl_hours', 24),
            );

            return self::SUCCESS;
        }

        // 其余 code===0（连通性维度）→ 不告警，仅留痕
        Log::info('[ca_healthcheck] 上游非鉴权类异常，不告警', ['msg' => $msg]);

        return self::SUCCESS;
    }

    /**
     * 鉴权失败判定：主信号 401/403（可靠状态码路径），辅助信号 Unauthorized（上游 200-body 透传）。
     */
    private function isAuthFailure(string $msg): bool
    {
        return str_contains($msg, 'Http status code 401')
            || str_contains($msg, 'Http status code 403')
            || str_contains($msg, 'Unauthorized');
    }
}
