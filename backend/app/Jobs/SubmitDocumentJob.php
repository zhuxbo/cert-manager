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
 * - 上游失败抛异常触发框架重试（tries=3 指数退避 60s/300s）
 * - 跨级去重由接收端 (order_id, content_hash) 唯一索引保证，重试不产生重复
 */
class SubmitDocumentJob implements ShouldQueue
{
    use Dispatchable, HasUpgradeFreezeMiddleware, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $documentId) {}

    /**
     * 指数退避：第 1 次失败后等 60s，第 2 次后等 300s（共 3 次尝试）
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
