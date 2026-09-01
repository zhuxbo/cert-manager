<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Backup\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DatabaseRestoreStatusController extends Controller
{
    private const STATUSES = [
        'queued' => '任务已入队',
        'running' => '任务正在执行',
        'completed' => '任务已完成',
        'failed' => '任务失败，请查看服务端日志',
        'unknown' => '任务状态暂不可用',
    ];

    private const STAGES = [
        'preflight' => '正在检查恢复前提',
        'freeze' => '正在冻结写入和队列',
        'create_shadow' => '正在创建影子表',
        'import' => '正在导入备份',
        'prepare_structure' => '正在整理恢复结构',
        'validate' => '正在校验数据库',
        'wait_metadata_lock' => '正在等待元数据锁',
        'cutover' => '正在原子切换恢复表',
        'runtime_cleanup' => '正在清理队列和应用缓存',
        'complete' => '数据库恢复完成',
    ];

    public function __construct(private readonly BackupService $service) {}

    public function __invoke(Request $request, string $token): JsonResponse
    {
        if (! $request->isMethod('GET')) {
            abort(405);
        }
        if (preg_match('/^[A-Za-z0-9_-]{32}$/D', $token) !== 1) {
            abort(404);
        }

        $progress = $this->service->getJobProgress($token);
        if ($progress === null) {
            abort(404);
        }

        $status = is_string($progress['status'] ?? null)
            && array_key_exists($progress['status'], self::STATUSES)
            ? $progress['status']
            : 'unknown';
        $stage = is_string($progress['stage'] ?? null)
            && array_key_exists($progress['stage'], self::STAGES)
            ? $progress['stage']
            : null;

        $safeProgress = [
            'status' => $status,
            'message' => $status === 'running' && $stage !== null
                ? self::STAGES[$stage]
                : self::STATUSES[$status],
        ];
        if ($stage !== null) {
            $safeProgress['stage'] = $stage;
        }

        foreach (['progress', 'percent'] as $field) {
            $value = $progress[$field] ?? null;
            if ((is_int($value) || is_float($value)) && $value >= 0 && $value <= 100) {
                $safeProgress[$field] = $value;
            }
        }
        $backupId = $progress['backup_id'] ?? null;
        if (is_string($backupId) && preg_match('/^[a-z_]+_[0-9]{8}_[0-9]{6}$/D', $backupId) === 1) {
            $safeProgress['backup_id'] = $backupId;
        }
        $updatedAt = $progress['updated_at'] ?? null;
        if (is_string($updatedAt) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $updatedAt) === 1) {
            $safeProgress['updated_at'] = $updatedAt;
        }

        return response()->json([
            'code' => 1,
            'data' => ['progress' => $safeProgress],
        ], headers: ['Cache-Control' => 'no-store']);
    }
}
