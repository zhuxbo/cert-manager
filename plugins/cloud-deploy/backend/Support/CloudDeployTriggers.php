<?php

namespace Plugins\CloudDeploy\Support;

use App\Models\Cert;
use App\Models\Chain;
use Plugins\CloudDeploy\Jobs\CloudChainBackfillJob;
use Plugins\CloudDeploy\Jobs\CloudDeployTriggerJob;

class CloudDeployTriggers
{
    public static function onCertUpdated(Cert $cert): void
    {
        if ($cert->status === 'active' && $cert->wasChanged('status')) {
            CloudDeployTriggerJob::dispatch($cert->id)
                ->afterCommit()
                ->onQueue(config('queue.names.tasks'));
        }
    }

    public static function onChainCreated(Chain $chain): void
    {
        if (! empty($chain->common_name)) {
            CloudChainBackfillJob::dispatch($chain->common_name)
                ->afterCommit()
                ->onQueue(config('queue.names.tasks'));
        }
    }
}
