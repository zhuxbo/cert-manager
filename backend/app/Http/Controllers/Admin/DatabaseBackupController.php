<?php

namespace App\Http\Controllers\Admin;

use App\Jobs\CreateBackupJob;
use App\Jobs\RestoreBackupJob;
use App\Services\Backup\BackupService;
use App\Services\Backup\MysqlToolchainChecker;
use App\Services\Backup\Restore\RestorePreflight;
use App\Services\Backup\Restore\RestoreRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * 数据库备份管理（admin 端）。
 * 路由：routes/api.admin.php 内 /database 前缀。
 * 下载端点走无认证路由（由 token 校验），其余端点走 api.admin 中间件。
 */
class DatabaseBackupController extends BaseController
{
    public function __construct(
        private BackupService $service,
    ) {
        parent::__construct();
    }

    /**
     * 列表：GET /api/admin/database/backups
     */
    public function index(): void
    {
        $items = $this->service->listBackups();
        $this->success([
            'items' => $items,
            'total' => count($items),
        ]);
    }

    /**
     * 触发异步创建：POST /api/admin/database/backups
     * 返回 token，由前端轮询 /jobs/{token} 拿进度。
     */
    public function store(): void
    {
        $inspection = app(MysqlToolchainChecker::class)->inspect(requireMysql: false, requireMysqldump: true);
        if (! $inspection['supported']) {
            $this->error('MySQL 工具链不受支持', $inspection['errors']);
        }

        $token = $this->service->newJobToken();
        $adminId = (int) ($this->guard->id() ?? 0);

        $this->service->setJobProgress($token, [
            'status' => 'queued',
            'message' => '任务已入队',
            'admin_id' => $adminId,
            'updated_at' => now()->toDateTimeString(),
        ]);

        CreateBackupJob::dispatch($token, $adminId)
            ->onQueue(config('queue.names.tasks'));

        $this->success(['token' => $token]);
    }

    public function restorePreflight(string $backupId): void
    {
        $adminId = (int) ($this->guard->id() ?? 0);
        $report = app(RestorePreflight::class)->inspect(
            new RestoreRequest($backupId, false, 'admin:'.$adminId),
        );

        $this->success($this->publicPreflightReport($report));
    }

    /**
     * 触发异步恢复：POST /api/admin/database/backups/{backupId}/restore
     * body: { allow_schema_difference: boolean }
     */
    public function restore(Request $request, string $backupId): void
    {
        $validator = Validator::make($request->all(), [
            'allow_schema_difference' => ['required', 'boolean'],
            'mode' => ['prohibited'],
        ]);
        if ($validator->fails()) {
            $this->unprocessable('提交数据验证失败', ['errors' => $validator->errors()->toArray()]);
        }
        $allowSchemaDifference = (bool) $validator->validated()['allow_schema_difference'];

        $backup = $this->service->resolveBackup($backupId);
        if ($backup === null) {
            $this->error('备份不存在');
        }

        $adminId = (int) ($this->guard->id() ?? 0);
        $report = app(RestorePreflight::class)->inspect(
            new RestoreRequest($backupId, $allowSchemaDifference, 'admin:'.$adminId),
        );
        if (($report['runnable'] ?? false) !== true) {
            $message = ($report['hard_blockers'] ?? []) !== []
                ? '恢复预检存在硬阻断'
                : '恢复预检需要确认 Schema 差异';
            $this->unprocessable($message, ['data' => $this->publicPreflightReport($report)]);
        }
        $token = $this->service->newJobToken();

        $this->service->setJobProgress($token, [
            'status' => 'queued',
            'message' => '任务已入队',
            'backup_id' => $backupId,
            'admin_id' => $adminId,
            'updated_at' => now()->toDateTimeString(),
        ]);

        RestoreBackupJob::dispatch($token, $backupId, $allowSchemaDifference, 'admin:'.$adminId)
            ->onQueue(config('queue.names.tasks'));

        $this->success(['token' => $token]);
    }

    /**
     * 删除：DELETE /api/admin/database/backups/{backupId}
     */
    public function destroy(string $backupId): void
    {
        $backup = $this->service->resolveBackup($backupId);
        if ($backup === null) {
            $this->error('备份不存在');
        }

        $count = $this->service->deleteBackup($backupId);
        $this->success(['deleted' => $count]);
    }

    /**
     * 签发一次性下载 token：POST /api/admin/database/backups/{backupId}/download-token
     */
    public function downloadToken(string $backupId): void
    {
        $backup = $this->service->resolveBackup($backupId);
        if ($backup === null) {
            $this->error('备份不存在');
        }

        $adminId = (int) ($this->guard->id() ?? 0);
        $token = $this->service->issueDownloadToken($backupId, $adminId);

        $this->success([
            'token' => $token,
            'expires_in' => BackupService::DOWNLOAD_TOKEN_TTL,
            'url' => url('/api/admin/database/backups/download').'?token='.$token,
        ]);
    }

    /**
     * 一次性下载：GET /api/admin/database/backups/download?token=...
     * 无 admin 中间件，由 token 校验。
     */
    public function download(Request $request): BinaryFileResponse|Response
    {
        $token = (string) $request->query('token', '');
        if ($token === '' || ! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            abort(404);
        }

        $backupId = $this->service->consumeDownloadToken($token);
        if ($backupId === null) {
            abort(404);
        }

        $backup = $this->service->resolveBackup($backupId);
        if ($backup === null) {
            abort(404);
        }

        return response()->download(
            $backup['sql'],
            basename($backup['sql']),
            [
                'Content-Type' => 'application/gzip',
                'Cache-Control' => 'no-store',
            ]
        );
    }

    /** @return array<string, mixed> */
    private function publicPreflightReport(array $report): array
    {
        return array_intersect_key($report, array_flip([
            'runnable',
            'hard_blockers',
            'confirmations',
            'warnings',
            'artifact',
            'toolchain',
            'versions',
            'schema',
            'space',
            'state',
        ]));
    }

    /** @param array<string, mixed> $payload */
    private function unprocessable(string $message, array $payload): never
    {
        throw new HttpResponseException(response()->json([
            'code' => 0,
            'msg' => $message,
        ] + $payload, 422, [], JSON_UNESCAPED_UNICODE));
    }
}
