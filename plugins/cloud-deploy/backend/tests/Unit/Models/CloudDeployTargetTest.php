<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('config 以数组读写，enabled 默认 true', function () {
    $target = CloudDeployTarget::create([
        'user_id' => 1, 'access_id' => 10, 'order_id' => 20,
        'product' => 'cdn',
        'config' => ['domain' => 'cdn.example.com'],
    ]);

    $fresh = CloudDeployTarget::find($target->id);
    expect($fresh->config)->toBe(['domain' => 'cdn.example.com']);
    expect($fresh->enabled)->toBeTrue();
});

test('pending job 以数组持久化且不进入序列化结果', function () {
    $pending = [
        'job_id' => 'job-123',
        'cert_id' => 10001,
        'remote_cert_id' => 'cert-456',
        'expires_at' => now()->addDays(10)->timestamp,
    ];
    $target = CloudDeployTarget::create([
        'user_id' => 1, 'access_id' => 10, 'order_id' => 20,
        'product' => 'cdn',
        'config' => ['domain' => 'cdn.example.com'],
        'pending_job' => $pending,
    ]);

    $fresh = CloudDeployTarget::findOrFail($target->id);

    expect($fresh->pending_job)->toBe($pending);
    expect($fresh->toArray())->not->toHaveKey('pending_job');
});
