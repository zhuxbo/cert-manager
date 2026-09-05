<?php

namespace App\Console\Commands;

use App\Services\Notification\SystemAlert;
use App\Services\Order\Api\default\Sdk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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
 *  - 其余 code===0（连接超时/请求失败/No return code/5xx）→ 连通性维度（M7）：连续 N 次失败才
 *    告警（防瞬断噪音，滤上游滚动重启），SystemAlert 固定指纹 'ca_outage'（计数型防 churn 击穿）。
 */
class CaHealthcheckCommand extends Command
{
    protected $signature = 'schedule:ca-healthcheck';

    protected $description = '上游 CA 健康心跳（凭证失效 + 整体连通性告警）';

    private const DEDUPE_KEY = 'ca_credentials';

    private const CONNECTIVITY_DEDUPE_KEY = 'ca_connectivity';

    private const CONNECTIVITY_FAILS_KEY = 'ca_healthcheck:connectivity_fails';

    public function handle(): int
    {
        if (! config('monitoring.ca_healthcheck.enabled', true)) {
            return self::SUCCESS;
        }

        $result = app(Sdk::class)->getProducts();
        $code = $result['code'] ?? 0;
        $msg = (string) ($result['msg'] ?? '');

        // 健康：清凭证去重键 + 重置连通性（计数清零 + 清连通性去重键，恢复后再异常立即告警）
        if ($code === 1) {
            app(SystemAlert::class)->clearDedupe(self::DEDUPE_KEY);
            $this->resetConnectivity();

            return self::SUCCESS;
        }

        // 未配置态：剔出告警，不占键、不动连通性计数（新装/测试实例未填上游）
        if ($msg === 'Api url or token is not set') {
            Log::info('[ca_healthcheck] 上游未配置，跳过（不告警）');

            return self::SUCCESS;
        }

        // 鉴权维度 → 告警（主信号 401/403；辅助信号 Unauthorized）
        // 上游可达（能返回鉴权错误）→ 重置连通性计数，避免「上游回来但坏 token」时连通性残留混叠。
        if ($this->isAuthFailure($msg)) {
            $this->resetConnectivity();
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

        // 其余 code===0（连通性维度：连接超时/请求失败/5xx）→ 连续 N 次失败才告警
        $this->handleConnectivityFailure($msg);

        return self::SUCCESS;
    }

    /**
     * 连通性失败处理：累计计数，达阈值发 SystemAlert（固定指纹 ca_outage 防计数 churn 击穿去重）。
     *
     * 阈值 3×15min=45min 滤上游滚动重启瞬断；TTL 6h ≥ 3×45min 契约。计数写入 runtime store
     * 跨 cron 周期累计，普通 cache:clear 不重置连续失败窗口。
     */
    private function handleConnectivityFailure(string $msg): void
    {
        $fails = (int) Cache::store('runtime')->get(self::CONNECTIVITY_FAILS_KEY, 0) + 1;
        Cache::store('runtime')->forever(self::CONNECTIVITY_FAILS_KEY, $fails);

        $threshold = (int) config('monitoring.ca_healthcheck.connectivity_threshold', 3);
        if ($fails < $threshold) {
            Log::info('[ca_healthcheck] 上游连通性异常累计（未达告警阈值）', ['consecutive' => $fails, 'msg' => $msg]);

            return;
        }

        Log::warning('[ca_healthcheck] 上游整体连通性异常', ['consecutive' => $fails, 'msg' => $msg]);
        app(SystemAlert::class)->send(
            'ca_connectivity',
            '上游 CA 连通性异常',
            $msg,
            ['probe' => 'get-products', 'consecutive' => $fails],
            self::CONNECTIVITY_DEDUPE_KEY,
            (int) config('monitoring.ca_healthcheck.connectivity_ttl_hours', 6),
            'ca_outage',
        );
    }

    /**
     * 重置连通性状态（healthy / 上游可达分支义务）：清计数 + 清连通性去重键。
     */
    private function resetConnectivity(): void
    {
        Cache::store('runtime')->forget(self::CONNECTIVITY_FAILS_KEY);
        app(SystemAlert::class)->clearDedupe(self::CONNECTIVITY_DEDUPE_KEY);
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
