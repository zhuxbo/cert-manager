<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ApiResponseException;
use App\Jobs\Concerns\HasUpgradeFreezeMiddleware;
use App\Models\OrderDocument;
use App\Services\Order\Action;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 异步提交单个验证文档到上游（Certum 系 OV/EV 文档）。
 *
 * - 按单文档入队：重试相互隔离，一个坏文档不阻塞其余
 * - 幂等：已 submitted / 文件缺失直接退出，不重试
 * - 上游失败抛异常触发框架重试（指数退避 60s/300s，末值复用）
 * - 跨级去重由接收端 (order_id, content_hash) 唯一索引保证，重试不产生重复
 */
class SubmitDocumentJob implements ShouldQueue
{
    use Dispatchable, HasUpgradeFreezeMiddleware, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 最大尝试次数 = 5（C5）：吸收升级 freeze 期 SkipWhenUpgradeFrozen 的 release(60) attempts 累加，
     * 把误杀阈值从 ~3min 抬到 ~5min。有意【不加 maxExceptions】——本 Job 设计为对上游瞬态错误抛异常重试，
     * maxExceptions=1 会在首次上游错误即 fail、杀掉重试语义（与备份类 fail-fast 相反）。freeze release
     * 与真实重试共享同一 attempts 预算，真实重试上限 3→5，对轻量幂等（含跨级唯一索引去重）的文档提交 benign。
     * 代价：真·坏文档最终失败时延由 ~6min（60+300）拉长到 ~16min（60+300+300+300），告警浮现相应延后。
     */
    public int $tries = 5;

    public function __construct(public int $documentId) {}

    /**
     * 指数退避：第 1 次失败后等 60s，其后每次 300s（tries=5 时 Laravel 复用末值 → 60/300/300/300）
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        // submitDocument 内部完成：幂等判定 / 文件缺失永久失败 / 上游调用 / 状态入库。
        // 成功或永久失败返回 void（不重试）；可重试失败（订单未提交 / 上游错误）抛异常 → 框架退避重试。
        app(Action::class)->submitDocument($this->documentId);
    }

    /**
     * 重试耗尽后兜底：确保失败原因落库 + 写错误日志供监控（文档列表 submit_error 已对用户可见）。
     */
    public function failed(Throwable $e): void
    {
        // ApiResponseException 的 getMessage() 恒为空（可读消息在 getApiResponse()['msg']），
        // 直接用 getMessage() 会把 submitDocument 已写入的可读错误抹成空串。
        $message = $e instanceof ApiResponseException
            ? (string) ($e->getApiResponse()['msg'] ?? '')
            : $e->getMessage();
        if ($message === '') {
            $message = $e::class;
        }

        $doc = OrderDocument::find($this->documentId);
        // 仅在尚无可读错误时回填：submitDocument 每次失败都已记录更具体的原因，不应被兜底覆盖
        if ($doc && ! $doc->submitted && ! $doc->submit_error) {
            $doc->update(['submit_error' => mb_substr($message, 0, 255)]);
        }

        Log::error('[document.submit.failed] 文档提交上游重试耗尽', [
            'document_id' => $this->documentId,
            'message' => $message,
        ]);
    }
}
