<?php

declare(strict_types=1);

namespace App\Services\Order\Traits;

use App\Exceptions\ApiResponseException;
use App\Jobs\SubmitDocumentJob;
use App\Models\OrderDocument;
use App\Services\Order\Utils\FindUtil;
use App\Traits\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

trait ActionDocumentTrait
{
    use ApiResponse;

    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'xades'];

    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

    private const DOCUMENT_TYPES = ['APPLICANT', 'ORGANIZATION', 'AUTHORIZATION', 'ADDITIONAL'];

    /**
     * 上传文档（文件方式）
     */
    public function uploadDocument(int $orderId, UploadedFile $file, string $type, string $uploadedBy): void
    {
        $order = FindUtil::Order($orderId);

        in_array($type, self::DOCUMENT_TYPES) || $this->error('不支持的文档类型');
        $file->getSize() > self::MAX_FILE_SIZE && $this->error('文件大小不能超过 5MB');

        $ext = strtolower($file->getClientOriginalExtension());
        in_array($ext, self::ALLOWED_EXTENSIONS) || $this->error('不支持的文件格式，仅支持 PDF/JPG/PNG/DOC/DOCX/XADES');

        $fileName = basename($file->getClientOriginalName());

        // 与 base64 接收路径一致：按内容去重（同 order 同内容已存在）
        $hash = hash('sha256', (string) file_get_contents($file->getPathname()));
        $existing = OrderDocument::where('order_id', $order->id)->where('content_hash', $hash)->first();
        if ($existing) {
            // 已存在（重复上传）：未提交则补一次自动转发（兜底），幂等成功
            ! $existing->submitted && $this->autoForwardDocument($existing);
            $this->success();
        }

        $storageName = Str::uuid().".$ext";
        $relativePath = "verification/$orderId/$storageName";

        $file->storeAs("verification/$orderId", $storageName, 'local');

        $document = null;
        try {
            $document = OrderDocument::create([
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'type' => $type,
                'file_name' => $fileName,
                'file_path' => $relativePath,
                'file_size' => $file->getSize(),
                'content_hash' => $hash,
                'uploaded_by' => $uploadedBy,
            ]);
        } catch (QueryException $e) {
            // 并发竞态：唯一索引冲突（1062）→ 删孤儿文件，按已存在幂等处理；非重复键照抛
            @unlink(storage_path("app/$relativePath"));
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }
            $document = OrderDocument::where('order_id', $order->id)->where('content_hash', $hash)->first();
        }

        // 自动转发上游（与 V2 接收一致：UI 上传也自动提交，免手动点提交）
        $document && $this->autoForwardDocument($document);

        $this->success();
    }

    /**
     * 上传文档（base64 方式，用于 V2 API 接收）
     */
    public function uploadDocumentFromBase64(int $orderId, string $type, string $fileName, string $base64Content): void
    {
        $order = FindUtil::Order($orderId);

        in_array($type, self::DOCUMENT_TYPES) || $this->error('不支持的文档类型');

        $content = base64_decode($base64Content, true);
        $content === false && $this->error('base64 解码失败');
        strlen($content) > self::MAX_FILE_SIZE && $this->error('文件大小不能超过 5MB');

        $fileName = basename($fileName);
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        in_array($ext, self::ALLOWED_EXTENSIONS) || $this->error('不支持的文件格式');

        // 跨级去重：同一订单下相同内容只存一份，重试/重复推送幂等
        // （防 SubmitDocumentJob 重试在上游产生重复文档行 → Certum 重复提交）
        $hash = hash('sha256', $content);
        $existing = OrderDocument::where('order_id', $order->id)->where('content_hash', $hash)->first();
        if ($existing) {
            // 已存在（重试/重复推送）：若尚未转发上游则补一次，兜底"上次收到但没转成"
            ! $existing->submitted && $this->autoForwardDocument($existing);
            $this->success();
        }

        $storageName = Str::uuid().".$ext";
        $relativePath = "verification/$orderId/$storageName";
        $fullPath = storage_path("app/$relativePath");

        $dir = dirname($fullPath);
        is_dir($dir) || mkdir($dir, 0755, true);
        file_put_contents($fullPath, $content);

        $document = null;
        try {
            $document = OrderDocument::create([
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'type' => $type,
                'file_name' => $fileName,
                'file_path' => $relativePath,
                'file_size' => strlen($content),
                'content_hash' => $hash,
                'uploaded_by' => 'api',
            ]);
        } catch (QueryException $e) {
            // 并发竞态：唯一索引冲突（MySQL 1062）= 另一个请求已插入同 (order_id, content_hash)
            // 删掉刚写的孤儿文件，按已存在幂等处理；非重复键错误照常抛出
            @unlink($fullPath);
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }
            $document = OrderDocument::where('order_id', $order->id)->where('content_hash', $hash)->first();
        }

        // 自动转发上游（多级链路全自动，免每层人工点提交）
        $document && $this->autoForwardDocument($document);

        $this->success();
    }

    /**
     * 获取文档列表
     */
    public function getDocuments(int $orderId): void
    {
        FindUtil::Order($orderId);

        $documents = OrderDocument::where('order_id', $orderId)
            ->orderBy('created_at', 'desc')
            ->get();

        $this->success($documents->toArray());
    }

    /**
     * 预览文档
     */
    public function previewDocument(int $docId): BinaryFileResponse
    {
        $document = OrderDocument::find($docId);
        ! $document && $this->error('文档不存在');

        $fullPath = storage_path("app/$document->file_path");
        ! file_exists($fullPath) && $this->error('文件不存在');

        $mimeTypes = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'xades' => 'application/xml',
        ];

        $ext = strtolower(pathinfo($document->file_name, PATHINFO_EXTENSION));
        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
        $safeName = rawurlencode(basename($document->file_name));

        // 仅图片走 inline 直接预览；其余（pdf / xades-xml / 未知）一律 attachment 下载，
        // 避免浏览器把上传者可控字节当作可渲染内容（钓鱼 / 边缘 XSS）。
        // 配合 X-Content-Type-Options: nosniff 关闭 MIME 嗅探。
        $isImage = in_array($ext, ['jpg', 'jpeg', 'png'], true);
        $disposition = $isImage ? 'inline' : 'attachment';

        return response()->file($fullPath, [
            'Content-Type' => $mime,
            'Content-Disposition' => "$disposition; filename*=UTF-8''$safeName",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * 更新文档信息
     */
    public function updateDocument(int $docId, string $fileName, string $type): void
    {
        $document = OrderDocument::findOrFail($docId);
        $document->update([
            'file_name' => $fileName,
            'type' => $type,
        ]);
        $this->success();
    }

    /**
     * 删除文档
     */
    public function deleteDocument(int $docId): void
    {
        $document = OrderDocument::find($docId);
        ! $document && $this->error('文档不存在');

        $fullPath = storage_path("app/$document->file_path");
        file_exists($fullPath) && unlink($fullPath);

        $document->delete();
        $this->success();
    }

    /**
     * 提交文档到上游（异步）：逐个派发 SubmitDocumentJob，立即返回。
     *
     * 实际提交 + 重试（tries=3 指数退避）由队列完成；前端轮询文档列表的
     * submitted / submit_attempts / submit_error 看进度与失败原因。
     */
    public function submitDocuments(int $orderId): void
    {
        $order = FindUtil::Order($orderId);
        ! $order->latestCert->api_id && $this->error('订单尚未提交，无法提交文档');

        $documents = OrderDocument::where('order_id', $orderId)
            ->where('submitted', 0)
            ->get();

        $documents->isEmpty() && $this->error('没有待提交的文档');

        foreach ($documents as $document) {
            // afterCommit：避免 worker 抢在外层事务提交前取走 Job（本仓硬约定，反模式 11）
            SubmitDocumentJob::dispatch($document->id)->afterCommit()->onQueue(config('queue.names.tasks'));
        }

        $this->success(['queued' => $documents->count()]);
    }

    /**
     * 提交单个文档到上游（由 SubmitDocumentJob 调用）。
     *
     * - 返回 void：成功（标 submitted）或永久失败（文件缺失，记 error 不重试）
     * - 抛异常：可重试失败（订单尚未提交 / 上游返回失败），交由 Job 退避重试
     *
     * 去重由接收端 (order_id, content_hash) 唯一索引保证，重试不会在上游产生重复文档行。
     */
    public function submitDocument(int $docId): void
    {
        $document = OrderDocument::find($docId);
        if (! $document || $document->submitted) {
            return; // 幂等：已提交或已删除
        }

        $fullPath = storage_path("app/$document->file_path");
        if (! file_exists($fullPath)) {
            // 永久失败：文件没了重试也没用，记录原因、不抛异常（不触发重试）
            $document->update([
                'submit_attempts' => $document->submit_attempts + 1,
                'submit_error' => '文件不存在',
            ]);

            return;
        }

        $order = FindUtil::Order($document->order_id);
        $apiId = $order->latestCert->api_id;
        if (! $apiId) {
            // 可重试：订单可能稍后才提交上游（如自动转发时订单尚在 commit 队列中）
            $document->update([
                'submit_attempts' => $document->submit_attempts + 1,
                'submit_error' => '订单尚未提交',
            ]);
            throw new \RuntimeException('订单尚未提交，无法提交文档');
        }

        try {
            $result = $this->api->uploadDocument($document->order_id, [
                'order_id' => $apiId,
                'type' => $document->type,
                'fileName' => $document->file_name,
                'document_content' => base64_encode((string) file_get_contents($fullPath)),
            ]);
        } catch (\Throwable $e) {
            // 上游调用本身抛异常（source 配置错误 / 网络层等）：记录原因后照抛，交 Job 重试
            // ApiResponseException 的可读消息在 getApiResponse()['msg']（getMessage() 为空）
            $message = $e instanceof ApiResponseException ? ($e->getApiResponse()['msg'] ?? '') : $e->getMessage();
            $document->update([
                'submit_attempts' => $document->submit_attempts + 1,
                'submit_error' => mb_substr($message !== '' ? $message : $e::class, 0, 255),
            ]);
            throw $e;
        }

        if (($result['code'] ?? 0) === 1) {
            $document->update([
                'submitted' => 1,
                'submitted_at' => now(),
                'submit_attempts' => $document->submit_attempts + 1,
                'submit_error' => null,
            ]);

            return;
        }

        // 可重试：上游返回失败
        $message = (string) ($result['msg'] ?? '提交失败');
        $document->update([
            'submit_attempts' => $document->submit_attempts + 1,
            'submit_error' => mb_substr($message, 0, 255),
        ]);
        throw new \RuntimeException($message);
    }

    /**
     * V2 收到下游文档后自动转发上游（多级链路全自动，免每层人工点提交）。
     * SubmitDocumentJob 幂等 + 接收端去重键保证重复转发无害。
     */
    protected function autoForwardDocument(OrderDocument $document): void
    {
        // afterCommit：与 submitDocuments 一致，避免 worker 抢在事务提交前取走 Job（反模式 11）
        SubmitDocumentJob::dispatch($document->id)->afterCommit()->onQueue(config('queue.names.tasks'));
    }
}
