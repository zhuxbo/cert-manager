<?php

namespace Plugins\CloudDeploy\Services;

use App\Contracts\PluginLogPurger;
use App\Services\Logs\ChunkedLogDeleter;
use App\Services\Logs\LogPurgeContext;
use App\Services\Logs\LogPurgeResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CloudDeployLogPurger implements PluginLogPurger
{
    public function __construct(private readonly ChunkedLogDeleter $deleter) {}

    public function plugin(): string
    {
        return 'cloud-deploy';
    }

    public function tables(): array
    {
        return ['cloud_deploy_logs'];
    }

    public function purge(LogPurgeContext $context): LogPurgeResult
    {
        if (! Schema::hasTable('cloud_deploy_logs')) {
            return new LogPurgeResult('cloud-deploy', [], 0, ['cloud_deploy_logs table does not exist; skipped']);
        }

        $expired = $this->deleter->delete(
            fn () => DB::table('cloud_deploy_logs')->where('created_at', '<', $context->auditCutoff),
            $context->chunkSize,
            $context->dryRun,
        );
        $intermediate = $this->deleter->delete(
            fn () => DB::table('cloud_deploy_logs')
                ->where('created_at', '<', $context->fullCutoff)
                ->where('created_at', '>=', $context->auditCutoff)
                ->where('is_final', false),
            $context->chunkSize,
            $context->dryRun,
        );

        return new LogPurgeResult(
            'cloud-deploy',
            ['cloud_deploy_logs' => $expired + $intermediate],
            0,
        );
    }
}
