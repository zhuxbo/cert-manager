<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\HasUpgradeFreezeMiddleware;
use App\Models\Cert;
use App\Services\Delegation\AutoDcvTxtService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class CleanupDelegationTxtJob implements ShouldQueue
{
    use Dispatchable, HasUpgradeFreezeMiddleware, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @param array<int, array<string, mixed>> $validation */
    public function __construct(public int $certId, public array $validation) {}

    public function handle(AutoDcvTxtService $service): void
    {
        // 使用入队时的旧值快照，不重新加载可能已被更新的证书 validation。
        $cert = new Cert;
        $cert->id = $this->certId;
        $cert->validation = $this->validation;
        $service->cleanupCertificate($cert);
    }
}
