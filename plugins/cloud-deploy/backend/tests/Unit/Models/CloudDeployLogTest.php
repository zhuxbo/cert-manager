<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\CloudDeploy\Models\CloudDeployLog;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('log 仅追加（不写 updated_at），快照字段可存', function () {
    $log = CloudDeployLog::create([
        'user_id' => 1, 'target_id' => 10, 'order_id' => 20, 'cert_id' => 100,
        'provider' => 'aliyun', 'product' => 'cdn', 'resource_summary' => 'cdn.example.com',
        'access_name' => '我的阿里云',
        'trigger' => 'auto', 'status' => 'failed',
        'attempt_no' => 1, 'is_final' => false, 'error_code' => 'InvalidCert',
        'message' => 'cert chain incomplete', 'deployed_at' => now(),
    ]);

    expect($log->updated_at ?? null)->toBeNull();
    expect($log->provider)->toBe('aliyun');
    expect($log->is_final)->toBeFalse();
});
