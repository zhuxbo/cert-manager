<?php

namespace App\Http\Controllers\Admin;

use App\Jobs\CreateBackupJob;
use App\Jobs\RestoreBackupJob;
use App\Services\Backup\BackupService;
use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use App\Services\Upgrade\DatabaseStructureService;
use Illuminate\Http\Request;
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
        private DatabaseStructureService $structureService
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
        try {
            app(BinaryLocator::class)->mysqldump();
        } catch (BinaryNotFoundException $e) {
            $this->error('未找到 mysqldump 命令', $e->diagnose());
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

    /**
     * 查进度：GET /api/admin/database/jobs/{token}
     */
    public function jobStatus(string $token): void
    {
        if (! preg_match('/^[A-Za-z0-9]{32,40}$/', $token)) {
            $this->error('非法 token');
        }

        $progress = $this->service->getJobProgress($token);
        if ($progress === null) {
            $this->error('任务不存在或已过期');
        }

        $this->success(['progress' => $progress]);
    }

    /**
     * Schema 对比：GET /api/admin/database/backups/{id}/schema-diff
     * 返回当前数据库结构 vs 备份自带 schema.json 的差异摘要。
     */
    public function schemaDiff(string $id): void
    {
        $backup = $this->service->resolveBackup($id);
        if ($backup === null) {
            $this->error('备份不存在');
        }

        $schema = $this->service->readSchema($id);
        if ($schema === null) {
            $this->success([
                'has_schema' => false,
                'message' => '旧备份无 schema 信息，无法比对',
            ]);
        }

        // 双方都按"当前 ignore 列表"过滤：
        //   - 旧备份 schema.json 可能含有现已排除的表（如 refresh_tokens），过滤掉避免误报
        //   - 当前库同样过滤，对齐基准
        $connection = config('database.default');
        $database = config("database.connections.$connection.database");
        $ignoreTables = $this->service->resolveIgnoreTables($database);

        $schema = $this->service->filterStructureTables($schema, $ignoreTables);
        $current = $this->structureService->exportCurrentStructure($connection);
        $current = $this->service->filterStructureTables($current, $ignoreTables);
        $diff = $this->structureService->compareStructures($schema, $current);

        $summary = [
            'missing_tables' => array_keys($diff['missing_tables'] ?? []),
            'extra_tables' => array_keys($diff['extra_tables'] ?? []),
            'modified_tables' => [],
        ];
        foreach ($diff['table_differences'] ?? [] as $table => $td) {
            $items = [];
            foreach (['missing_columns', 'extra_columns', 'modified_columns', 'missing_indexes', 'extra_indexes'] as $k) {
                if (! empty($td[$k])) {
                    $items[$k] = array_keys($td[$k]);
                }
            }
            if (! empty($items)) {
                $summary['modified_tables'][$table] = $items;
            }
        }

        $hasDiff = ! empty($summary['missing_tables'])
            || ! empty($summary['extra_tables'])
            || ! empty($summary['modified_tables']);

        // 备份结构中文概览：表名 + MySQL 表注释 + 列数，供前端展示
        $tablesOverview = [];
        foreach ($schema['tables'] ?? [] as $tableName => $tableDef) {
            $tablesOverview[] = [
                'name' => $tableName,
                'comment' => (string) ($tableDef['comment'] ?? ''),
                'columns' => count($tableDef['columns'] ?? []),
            ];
        }
        usort($tablesOverview, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $this->success([
            'has_schema' => true,
            'has_diff' => $hasDiff,
            'summary' => $summary,
            'tables_overview' => $tablesOverview,
        ]);
    }

    /**
     * 触发异步恢复：POST /api/admin/database/backups/{id}/restore
     * body: { mode: full|incremental }
     */
    public function restore(Request $request, string $id): void
    {
        $data = $request->validate([
            'mode' => 'required|in:full,incremental',
        ]);

        $backup = $this->service->resolveBackup($id);
        if ($backup === null) {
            $this->error('备份不存在');
        }

        try {
            $locator = app(BinaryLocator::class);
            $locator->mysqldump();
            $locator->mysql();
        } catch (BinaryNotFoundException $e) {
            $this->error('未找到 '.$e->getTool().' 命令', $e->diagnose());
        }

        $token = $this->service->newJobToken();
        $adminId = (int) ($this->guard->id() ?? 0);

        $this->service->setJobProgress($token, [
            'status' => 'queued',
            'message' => '任务已入队',
            'backup_id' => $id,
            'mode' => $data['mode'],
            'admin_id' => $adminId,
            'updated_at' => now()->toDateTimeString(),
        ]);

        RestoreBackupJob::dispatch($token, $id, $data['mode'], $adminId)
            ->onQueue(config('queue.names.tasks'));

        $this->success(['token' => $token]);
    }

    /**
     * 删除：DELETE /api/admin/database/backups/{id}
     */
    public function destroy(string $id): void
    {
        $backup = $this->service->resolveBackup($id);
        if ($backup === null) {
            $this->error('备份不存在');
        }

        $count = $this->service->deleteBackup($id);
        $this->success(['deleted' => $count]);
    }

    /**
     * 签发一次性下载 token：POST /api/admin/database/backups/{id}/download-token
     */
    public function downloadToken(string $id): void
    {
        $backup = $this->service->resolveBackup($id);
        if ($backup === null) {
            $this->error('备份不存在');
        }

        $adminId = (int) ($this->guard->id() ?? 0);
        $token = $this->service->issueDownloadToken($id, $adminId);

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
}
