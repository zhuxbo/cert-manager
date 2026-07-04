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
