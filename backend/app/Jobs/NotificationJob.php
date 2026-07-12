<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Bootstrap\ApiExceptions;
use App\Jobs\Concerns\HasUpgradeFreezeMiddleware;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Services\Notification\Builders\NotificationBuilderInterface;
use App\Services\Notification\ChannelManager;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use App\Services\Notification\Exceptions\TransientBuildException;
use App\Services\Notification\NotificationRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 携密通知（如 user_created 初始密码）的 context 会作为构造参数序列化进队列存储。
 * 实现 ShouldBeEncrypted 让 Laravel 用 APP_KEY 加密整个 job payload，
 * 避免明文密码/凭据落入 jobs（执行前窗口）与 failed_jobs（长期留存）表。
 * sync 队列不序列化、直接执行，加密 marker 无副作用。
 */
class NotificationJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, HasUpgradeFreezeMiddleware, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 幂等 ShouldQueue 约定（CLAUDE.md）：tries=5 + maxExceptions=1。
     *  - tries=5：给 SkipWhenUpgradeFrozen 的 release(60) 烧 attempts 留余量（freeze 每分钟烧 1 个）；
     *    正常态瞬态重试走 retryDelay() backoff [60,300,300,300]。
     *  - maxExceptions=1：本设计瞬态路径 catch 后 release/fail 均不抛，maxExceptions 只对「真·未捕获
     *    异常」（如建行时 DB 挂）快失败，正是意图。
     */
    public int $tries = 5;

    public int $maxExceptions = 1;

    /**
     * build 失败落 FAILED 记录的固定文案（notify I-1）。
     *
     * 一律用常量、绝不落异常 getMessage()：build catch 是通用路径（覆盖全部 Builder 及框架底层
     * 异常），消息内容无法逐一审计（异常消息可能回显携密 context 值），常量是唯一「不做假设」的方案，
     * 对齐 send 阶段常量范式（handle() 内「发送失败，请稍后重试」）。原始 getMessage() 仅进 error_logs。
     */
    protected const BUILD_FAILED_REASON = '通知内容生成失败';

    protected const BUILD_FAILED_TRANSIENT_REASON = '通知内容生成失败（瞬态重试已耗尽）';

    public function __construct(
        protected string $notifiableType,
        protected int $notifiableId,
        protected int $templateId,
        protected string $channel,
        protected array $context,
        protected string $builderClass
    ) {}

    public function handle(NotificationRepository $notificationRepository, ChannelManager $channelManager): void
    {
        $template = NotificationTemplate::find($this->templateId);
        if (! $template || $template->status !== 1) {
            $this->logSkip('模板不存在或已禁用');

            return;
        }

        $notifiable = $this->resolveNotifiable();
        if (! $notifiable) {
            // Job 延时执行期间接收者可能被删除（账号注销 / 数据清理），属预期降级路径
            $this->logSkip('通知接收者不存在', level: 'debug');

            return;
        }

        $builder = app($this->builderClass);
        if (! $builder instanceof NotificationBuilderInterface) {
            $this->logSkip('通知构建器未实现接口');

            return;
        }

        $intent = new NotificationIntent(
            $template->code,
            $this->notifiableType,
            $this->notifiableId,
            $this->context
        );

        try {
            $payload = $builder->build($intent, $notifiable);
        } catch (Throwable $e) {
            // build 失败分档（M4 send 分档的姊妹）：瞬态（IO/磁盘满，唯一命中 CertIssued）未达上限
            // release 自愈、末轮落 FAILED；永久（数据/校验错）直接落 FAILED。堵「签发成功但交付邮件
            // 静默缺失、通知列表无行」的可见性洞（原实现 catch 后直接 return、不建行不重试）。
            $this->handleBuildFailure($e, $notificationRepository, $notifiable, $template);

            return;
        }

        if (! $payload) {
            // Builder 返回 null 表示合法的"无需发送"路径（如 CertExpire 委托有效时跳过）
            $this->logSkip('无需发送通知', level: 'debug');

            return;
        }

        $preparedPayload = $notificationRepository->preparePayload($template, $payload->data);
        $notification = $this->resolveNotificationRow($notificationRepository, $notifiable, $template, $preparedPayload);
        $notification->status = Notification::STATUS_SENDING;
        $notification->setRelation('notifiable', $notifiable);
        $notification->setRelation('template', $template);
        $notification->save();

        // 渲染期 transient：敏感字段（如初始密码）只注入内存供通道渲染，绝不写入 notifications.data。
        // 此时 DB 行已落地为不含 transient 的 $preparedPayload，下面 finally 再还原内存数据。
        if ($payload->transient !== []) {
            $notification->setAttribute('data', array_merge($preparedPayload, $payload->transient));
        }

        $isSuccessful = false;
        $retryable = false;
        $result = [
            'status' => Notification::STATUS_FAILED,
            'message' => null,
            'timestamp' => now()->toDateTimeString(),
        ];

        try {
            // Channel::send() 返回格式: ['code' => 1, 'msg' => '可选消息'] 成功，['code' => 0, 'msg' => '错误消息'] 失败
            // M4：失败结果可携 retryable 键（缺省 false）——瞬态 SMTP 失败才重试，配置类永久失败不重试。
            $sendResult = $channelManager->channel($this->channel)->send($notification);
            $success = $sendResult['code'] === 1;
            $retryable = (bool) ($sendResult['retryable'] ?? false);
            $result['status'] = $success ? Notification::STATUS_SENT : Notification::STATUS_FAILED;
            $result['message'] = $sendResult['msg'] ?? null;
            $result['timestamp'] = now()->toDateTimeString();
            $isSuccessful = $success;
        } catch (Throwable $e) {
            // send 内未预期异常（如 PHPMailer 抛出）→ 视同瞬态可重试
            app(ApiExceptions::class)->logException($e);
            $result['message'] = '发送失败，请稍后重试';
            $result['timestamp'] = now()->toDateTimeString();
            $retryable = true;
        } finally {
            // 还原为不含 transient 的持久化数据，避免 updateSendResult 把敏感字段写回库
            if ($payload->transient !== []) {
                $notification->setAttribute('data', $preparedPayload);
            }
        }

        $notificationRepository->updateSendResult($notification, $result, $isSuccessful);

        // 成功 / 永久失败：清理本轮临时产物后收尾，不重试。
        // cleanupTempPaths 兜底所有通道（含插件注入的非 mail 通道无自清理逻辑，防含私钥 ZIP 泄漏）；
        // 以 is_dir/is_file 守卫，与 MailChannel finally 清理幂等叠加。
        if ($isSuccessful || ! $retryable) {
            if (! $isSuccessful) {
                // 永久失败（未配置/空邮箱/附件问题）→ 落 FAILED 行（updateSendResult 已标）+ warning 留痕；
                // 不 release 不 throw：防新装机 failed_jobs 风暴 + CertIssued 重试重生成含私钥 ZIP。
                $this->logSkip('通知永久失败不重试：'.($result['message'] ?? ''), 'warning');
            }
            $this->cleanupTempPaths($payload);

            return;
        }

        // 瞬态失败：先清本轮 build 产物（防含私钥 ZIP 逐轮泄漏；下轮 handle 重跑 build 重生成，
        // 重试成功轮用户邮件仍带新附件），未到上限则 release 错峰重试，末轮交 failed() 标终态。
        $this->cleanupTempPaths($payload);

        if ($this->attempts() < $this->tries) {
            $this->release($this->retryDelay());

            return;
        }

        // 末轮瞬态仍失败：交 failed() 标终态 + Log::error（真实 worker 触发；行已在 updateSendResult 落 FAILED）
        $this->fail(new RuntimeException($result['message'] ?? '通知发送失败'));
    }

    /**
     * build 阶段失败分档（handle 内 build catch 委派）。
     *
     * - 瞬态（TransientBuildException，IO/磁盘满，恢复后重跑 build 自愈）：未达 tries 上限 → release
     *   错峰重试、不落记录（前几轮瞬态失败不留行）；末轮（attempts>=tries）→ 落 FAILED 记录 + fail()。
     * - 永久（数据/校验错，重试无益）→ 落 FAILED 记录 + warning 留痕、不重试。
     *
     * attempts 预算与 send 阶段、SkipWhenUpgradeFrozen 的 release(60) 共享 tries=5（notify M-1）：
     * freeze 期每分钟烧 1 个 attempt，升级窗内磁盘满的瞬态重试窗口被压缩、可能提前末轮落 FAILED；
     * 最坏况仍是可见 FAILED 行（可见性目标达成），与 send 阶段同权衡。
     *
     * build 抛异常时 $payload 未产出（=null），CertIssued 已在 build 抛异常前自清 tempDir，
     * NotificationJob 侧无 cleanup_paths 可泄漏，故瞬态 release 前无需清理（无 payload 可传）。
     */
    protected function handleBuildFailure(
        Throwable $e,
        NotificationRepository $notificationRepository,
        Model $notifiable,
        NotificationTemplate $template
    ): void {
        // 原始异常（含 getMessage）仅进 error_logs 保排障，绝不落 notifications.data（notify I-1）
        app(ApiExceptions::class)->logException($e);

        if ($e instanceof TransientBuildException) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->retryDelay());

                return;
            }

            // 末轮瞬态仍失败：落 FAILED 记录（可见性）+ fail() 交终态兜底
            $this->persistBuildFailure($notificationRepository, $notifiable, $template, self::BUILD_FAILED_TRANSIENT_REASON);
            $this->fail($e);

            return;
        }

        // 永久失败：落 FAILED 记录 + warning 留痕，不重试
        $this->persistBuildFailure($notificationRepository, $notifiable, $template, self::BUILD_FAILED_REASON);
        $this->logSkip('构建通知数据失败（永久）', 'warning');
    }

    /**
     * 落 build 失败的 FAILED 通知记录（只存安全摘要、不携密、不复用行）。
     *
     * - $reason 一律固定常量（BUILD_FAILED_*），绝不落异常 getMessage()（notify I-1）。
     * - 单写 FAILED（createNotification 传 status=FAILED）：不走 PENDING→markAsFailed 两步，
     *   消除两写间进程死留 stuck-pending 行的窗口（保留期清理只清终态行）。
     * - 直接 createNotification 新建、不走 findReusableRow：前几轮瞬态失败不落行、无前序行可复用，
     *   直接新建杜绝误复用同接收者近 1h 内另一证书的通知行。
     * - _meta 仅摘录 order_id（严格键白名单 + is_scalar 守卫）：非敏感整型标识，供 admin 定位缺失的
     *   交付邮件。禁止扩成 context 直通（user_created 的 context 携密码明文）——未来新增摘录键须逐键
     *   过携密评审。除白名单摘录外，payload 绝不含 $this->context 任何字段。
     * - DB 写全程 try/catch，失败降级双日志（error_logs + 文件）、不上抛（避免 maxExceptions=1 误杀）。
     */
    protected function persistBuildFailure(
        NotificationRepository $notificationRepository,
        Model $notifiable,
        NotificationTemplate $template,
        string $reason
    ): void {
        try {
            $meta = ['build_failed' => true, 'subject' => '通知构建失败'];

            if (isset($this->context['order_id']) && is_scalar($this->context['order_id'])) {
                $meta['order_id'] = $this->context['order_id'];
            }

            $payload = [
                '_meta' => $meta,
                'result' => [
                    'status' => Notification::STATUS_FAILED,
                    'message' => $reason,
                    'timestamp' => now()->toDateTimeString(),
                ],
            ];

            $notificationRepository->createNotification($notifiable, $template, $payload, Notification::STATUS_FAILED);
        } catch (Throwable $dbError) {
            app(ApiExceptions::class)->logException($dbError);
            Log::error('[notification.build.failed] 落 failed 记录失败', [
                'template_id' => $this->templateId,
                'notifiable_type' => $this->notifiableType,
                'notifiable_id' => $this->notifiableId,
            ]);
        }
    }

    /**
     * 定位或新建通知记录行（行复用启发式，零迁移）。
     *
     * 仅在重试轮（attempts()>1）尝试复用同接收者+模板+近 1h 的 sending/failed 行，避免
     * 「重试 N 次 = N 行 notifications」；命中则 update 不 insert（首轮或未命中新建）。
     * 查询按 $notifiable->getMorphClass()（存库为 FQCN，非 job 携带的短别名）定位，走
     * morphs + template_id + status 索引。
     *
     * 局限（Mi2，观察项登记）：notifications 表无 channel 列，多通道并存时启发式可能跨通道
     * 复用行（mail 重试复用插件通道行）——后果是跨通道行归属错乱而非仅少一行。当前基座
     * mail-only，该局限休眠；引入插件通道时升级为 idempotency_key（含 channel）+唯一索引方案。
     */
    protected function resolveNotificationRow(
        NotificationRepository $notificationRepository,
        Model $notifiable,
        NotificationTemplate $template,
        array $preparedPayload
    ): Notification {
        if ($this->attempts() > 1) {
            $existing = $this->findReusableRow($notifiable);
            if ($existing) {
                $existing->data = $preparedPayload;

                return $existing;
            }
        }

        return $notificationRepository->createNotification($notifiable, $template, $preparedPayload);
    }

    /**
     * 查找可复用的通知行（近 1h 内同接收者+模板的 sending/failed 行，最新优先）。
     */
    protected function findReusableRow(Model $notifiable): ?Notification
    {
        return Notification::query()
            ->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey())
            ->where('template_id', $this->templateId)
            ->whereIn('status', [Notification::STATUS_SENDING, Notification::STATUS_FAILED])
            ->where('created_at', '>=', now()->subHour())
            ->latest('id')
            ->first();
    }

    /**
     * 瞬态重试退避（秒）：backoff [60,300,300,300]。
     *
     * attempts()=1→60s、2/3/4→300s；attempts()=5(=tries) 为末轮，不再 release（交 failed()）。
     */
    protected function retryDelay(): int
    {
        $backoff = [60, 300, 300, 300];

        return $backoff[max(0, $this->attempts() - 1)] ?? 300;
    }

    /**
     * 最终失败兜底（末轮瞬态仍失败 / handle 抛未捕获异常时由 worker 触发）。
     *
     * Log::error 前置于 DB 操作：failed() 的典型触发路径②是 handle 建行时 DB 挂（maxExceptions=1
     * 快失败），此时下方 resolveNotifiable/findReusableRow 再查 DB 大概率再抛。故先记结构化日志保
     * 可观测性，再尽力定位复用行 → 标 FAILED 终态 + 幂等清理该行持久化的 cleanup_paths（与 handle 内
     * release 前清理、MailChannel finally 清理幂等叠加，is_dir/is_file 守卫无 double-free）；DB 段包
     * try/catch，二次 DB 异常不再上抛吞掉日志（worker 层 JobFailed 事件与 failed_jobs 行仍是兜底信号）。
     */
    public function failed(?Throwable $e = null): void
    {
        Log::error('[notification.dispatch.failed] 通知发送最终失败', [
            'template_id' => $this->templateId,
            'notifiable_type' => $this->notifiableType,
            'notifiable_id' => $this->notifiableId,
            'error' => $e?->getMessage(),
        ]);

        try {
            $notifiable = $this->resolveNotifiable();
            if ($notifiable) {
                $row = $this->findReusableRow($notifiable);
                if ($row) {
                    if ($row->status !== Notification::STATUS_FAILED) {
                        $row->markAsFailed();
                    }
                    $this->cleanupPaths($row->data['_meta']['cleanup_paths'] ?? []);
                }
            }
        } catch (Throwable $dbError) {
            // 行定位/清理失败（如 failed() 恰因 DB 挂触发）不再上抛：日志已记于前，兜底可观测性不受损
            app(ApiExceptions::class)->logException($dbError);
        }
    }

    /**
     * 清理 payload._meta.cleanup_paths 指向的临时文件/目录（兜底，不抛异常）。
     */
    protected function cleanupTempPaths(NotificationPayload $payload): void
    {
        $this->cleanupPaths($payload->data['_meta']['cleanup_paths'] ?? []);
    }

    /**
     * 清理一组临时路径（幂等，is_dir/is_file 守卫，不抛异常）。
     *
     * @param  mixed  $cleanupPaths  期望为 string[]；非数组/空则 no-op（供 failed() 读持久化行的 cleanup_paths）
     */
    protected function cleanupPaths(mixed $cleanupPaths): void
    {
        if (! is_array($cleanupPaths) || $cleanupPaths === []) {
            return;
        }

        foreach ($cleanupPaths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            try {
                if (is_dir($path)) {
                    File::deleteDirectory($path);
                } elseif (is_file($path)) {
                    File::delete($path);
                }
            } catch (Throwable $e) {
                // 清理失败不应影响通知主流程，记录后继续
                app(ApiExceptions::class)->logException($e);
            }
        }
    }

    protected function resolveNotifiable(): ?Model
    {
        $map = Config::get('notification.notifiables', []);
        $class = $map[$this->notifiableType] ?? $this->notifiableType;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::find($this->notifiableId);
    }

    /**
     * 通知跳过日志：
     *   - 默认 warning（生产可见，让运维感知模板缺失 / builder 异常等）
     *   - 显式传 level='debug' 用于预期内的噪声场景
     */
    protected function logSkip(string $reason, string $level = 'warning'): void
    {
        if ($level === 'debug' && ! config('app.debug')) {
            return;
        }

        Log::log($level, '[notification.dispatch.skip] '.$reason, [
            'template_id' => $this->templateId,
            'notifiable_type' => $this->notifiableType,
            'notifiable_id' => $this->notifiableId,
        ]);
    }
}
