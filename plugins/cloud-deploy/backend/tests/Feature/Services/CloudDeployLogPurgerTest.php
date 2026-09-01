<?php

use App\Services\Logs\ChunkedLogDeleter;
use App\Services\Logs\LogPurgeContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\CloudDeploy\Models\CloudDeployLog;
use Plugins\CloudDeploy\Services\CloudDeployLogPurger;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function createCloudDeployPurgeLog(bool $isFinal, string $status, int $days): CloudDeployLog
{
    $log = CloudDeployLog::create([
        'user_id' => 1, 'target_id' => 10, 'order_id' => 20, 'cert_id' => 100,
        'provider' => 'aliyun', 'product' => 'cdn', 'trigger' => 'auto',
        'status' => $status, 'attempt_no' => 1, 'is_final' => $isFinal,
    ]);
    $log->forceFill(['created_at' => now()->subDays($days)])->saveQuietly();

    return $log;
}

test('Cloud Deploy 只按最终阶段分层不按成功失败升级', function () {
    $recent = createCloudDeployPurgeLog(false, 'failed', 6);
    $intermediateSuccess = createCloudDeployPurgeLog(false, 'success', 8);
    $intermediateFailed = createCloudDeployPurgeLog(false, 'failed', 8);
    $finalSuccess = createCloudDeployPurgeLog(true, 'success', 30);
    $finalFailed = createCloudDeployPurgeLog(true, 'failed', 30);
    $expired = createCloudDeployPurgeLog(true, 'success', 181);

    $result = (new CloudDeployLogPurger(new ChunkedLogDeleter))->purge(new LogPurgeContext(
        CarbonImmutable::now()->subDays(7),
        CarbonImmutable::now()->subDays(180),
        2,
        false,
    ));

    expect($recent->fresh())->not->toBeNull()
        ->and($intermediateSuccess->fresh())->toBeNull()
        ->and($intermediateFailed->fresh())->toBeNull()
        ->and($finalSuccess->fresh())->not->toBeNull()
        ->and($finalFailed->fresh())->not->toBeNull()
        ->and($expired->fresh())->toBeNull()
        ->and($result->deletedByTable['cloud_deploy_logs'])->toBe(3)
        ->and($result->unclassified)->toBe(0);
});
