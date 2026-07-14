<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Utils\Email;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * M3 外部健康拨测（P0-4.3）。
 *
 * 载体：独立 BT cron（name=<域名>-probe，每 5min），**故意不用 schedule: 前缀**——绝不经
 * schedule:run 触发，与被监控对象（worker/scheduler）脱离同生共死；发信走裸 Email 同步 SMTP，
 * **不经** NotificationCenter/队列（后者本质入队，与「脱离 worker」自相矛盾）。
 *
 * 打破同生共死：worker 死（queue_lag 攀升 → /api/health 503）、scheduler 死（心跳 stale → 503）
 * 时本 cron 仍被 crond 触发，检出 503 并同步发信。crond/机器/PHP-fatal 层无内部兜底 → 宝塔站点
 * 外部监控（部署必选项，见 skills/ops/deploy-ops.md）。
 *
 * 判活：2xx 且 body status !== 'error' → 健康（degraded=200 不告警，新装机/清缓存不误报）；
 * 非 2xx / 连接失败 / status=error → down，同步发信 + 落去重键（down 持续期每 TTL 一封）。
 */
class HealthProbeCommand extends Command
{
    protected $signature = 'monitor:probe';

    protected $description = '外部健康拨测 /api/health（脱离队列同步发信，打破告警与执行通道同生共死）';

    private const DEDUPE_KEY = 'monitor:probe:down';

    public function handle(): int
    {
        $url = (string) config('monitoring.probe.url', 'http://127.0.0.1/api/health');
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        $down = false;
        $reason = '';

        try {
            // verify=false：本机自探跟随 301→https 时 127.0.0.1/域名证书不匹配不误报；
            // Host 头命中 nginx vhost（本机回环拨测真实站点）。
            $request = Http::timeout(10)->connectTimeout(5)->withOptions(['verify' => false]);
            if (is_string($host) && $host !== '') {
                $request = $request->withHeaders(['Host' => $host]);
            }
            $response = $request->get($url);

            if (! $response->successful()) {
                $down = true;
                $reason = 'HTTP '.$response->status();
            } elseif (($response->json('status') ?? null) === 'error') {
                // 防御：health 对 error 已返回 503（上方 successful 即挡下），此处兜底 2xx+status=error
                $down = true;
                $reason = 'status=error';
            }
        } catch (Throwable $e) {
            $down = true;
            $reason = '连接失败: '.$e->getMessage();
        }

        if (! $down) {
            // 健康 → 清去重键（恢复后再 down 立即告警）
            $this->clearDedupe();

            return self::SUCCESS;
        }

        $this->alertDown($url, $reason);

        return self::SUCCESS;
    }

    /**
     * down 告警（去重：down 持续期每 dedupe_ttl_hours 一封）。
     *
     * 「先确认可达、后置去重键」——mail 发送成功才占键，失败不占（否则整 TTL 静默丢告警）。
     */
    private function alertDown(string $url, string $reason): void
    {
        if ($this->dedupeActive()) {
            // down 去重窗内不重发（仅留痕）
            Log::warning('[monitor.probe] 健康拨测失败（去重窗内不重发）', ['url' => $url, 'reason' => $reason]);

            return;
        }

        Log::error('[monitor.probe] 健康拨测失败', ['url' => $url, 'reason' => $reason]);

        if ($this->sendAlertMail($url, $reason)) {
            $ttlHours = max(1, (int) config('monitoring.probe.dedupe_ttl_hours', 1));
            $this->markDedupe($ttlHours);
        }
    }

    /**
     * 去重键是否已占（down 去重窗内）。
     *
     * Cache 后端故障（redis 宕机——正是本命令要告警的头号场景）→ fail-open 当未占键：
     * 宁可 down 期每周期重发一封，绝不因 cache 死而静默丢告警（本命令是脱离 worker 的最后防线）。
     */
    private function dedupeActive(): bool
    {
        try {
            return Cache::get(self::DEDUPE_KEY) === true;
        } catch (Throwable $e) {
            Log::warning('[monitor.probe] 去重键读取失败（cache 故障），按未占键处理', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * 占去重键（发信成功后）。Cache 故障时占键失败 → down 期下周期可能重发一封，可接受。
     */
    private function markDedupe(int $ttlHours): void
    {
        try {
            Cache::put(self::DEDUPE_KEY, true, now()->addHours($ttlHours));
        } catch (Throwable $e) {
            Log::warning('[monitor.probe] 去重键写入失败（cache 故障），down 期下周期可能重发', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 清去重键（恢复后）。Cache 故障时清键失败无害（键 TTL 到期自然消失）。
     */
    private function clearDedupe(): void
    {
        try {
            Cache::forget(self::DEDUPE_KEY);
        } catch (Throwable $e) {
            Log::warning('[monitor.probe] 去重键清除失败（cache 故障）', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 裸 Email 同步发送告警（脱离 NotificationCenter/队列）。
     *
     * admin 邮箱解析镜像 SystemAlert：site.adminEmail → Admin::where(email) → Admin::first()。
     * PHPMailer 默认 Timeout=300s 不可接受（拨测 5min cron 周期，SMTP 挂起会逼近周期堆积），
     * 显式设 Timeout=15。经 app(Email::class) 构造供测试注入 mock（等价 new Email）。
     *
     * @return bool 是否实际发出（false = 无邮箱 / 未配置 / 发送失败）
     */
    private function sendAlertMail(string $url, string $reason): bool
    {
        try {
            // admin 目标解析单一源（Admin::resolveAlertTarget，原 4 份内联之一）。裸 SMTP 只需投递地址，
            // 不需 admin->id；故判 !email——有 site.adminEmail 别名即使无 Admin 记录也发（最后防线语义保留）。
            // resolveAlertTarget 内 get_system_setting 经 Setting 已 cache 故障回落 DB，不破本命令 fail-open 韧性。
            $targetEmail = Admin::resolveAlertTarget()['email'];

            if (! $targetEmail) {
                Log::warning('[monitor.probe] 未找到管理员邮箱，无法发送拨测告警');

                return false;
            }

            $mail = app(Email::class);
            $mail->isSMTP();
            $mail->isHTML(false);

            if (! $mail->configured) {
                Log::warning('[monitor.probe] 邮件服务未配置，无法发送拨测告警');

                return false;
            }

            $mail->Timeout = 15;
            $mail->addAddress($targetEmail);
            $mail->setSubject('【告警】站点健康拨测失败');
            $mail->Body = sprintf(
                "站点健康拨测失败，请立即检查 worker / scheduler / 服务状态。\n\n拨测地址：%s\n失败原因：%s\n时间：%s\n\n（本告警脱离队列同步发送，worker/scheduler 死亡时仍可送达）",
                $url,
                $reason,
                now()->toDateTimeString()
            );

            if (! $mail->send()) {
                Log::warning('[monitor.probe] 拨测告警邮件发送失败', ['error' => (string) $mail->ErrorInfo]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('[monitor.probe] 拨测告警邮件异常', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
