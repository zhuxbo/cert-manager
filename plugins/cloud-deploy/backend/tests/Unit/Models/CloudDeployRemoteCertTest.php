<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\CloudDeploy\Models\CloudDeployRemoteCert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('创建不写 updated_at（仅追加表）', function () {
    $rc = CloudDeployRemoteCert::create([
        'user_id' => 1, 'access_id' => 10, 'cert_id' => 100,
        'fingerprint' => 'AA:BB', 'store_kind' => 'tencent_ssl', 'remote_cert_id' => 'cas-123',
    ]);

    expect($rc->created_at)->not->toBeNull();
    expect($rc->updated_at ?? null)->toBeNull();
});

test('(access_id, store_kind, fingerprint) 唯一', function () {
    $base = ['user_id' => 1, 'access_id' => 10, 'cert_id' => 100, 'fingerprint' => 'AA:BB', 'store_kind' => 'tencent_ssl', 'remote_cert_id' => 'cas-1'];
    CloudDeployRemoteCert::create($base);

    expect(fn () => CloudDeployRemoteCert::create([...$base, 'remote_cert_id' => 'cas-2']))
        ->toThrow(QueryException::class);
});

test('store_kind 不同则不撞唯一索引（标识空间隔离）', function () {
    $base = ['user_id' => 1, 'access_id' => 10, 'cert_id' => 100, 'fingerprint' => 'AA:BB'];
    CloudDeployRemoteCert::create([...$base, 'store_kind' => 'cas', 'remote_cert_id' => 'cas-id']);
    CloudDeployRemoteCert::create([...$base, 'store_kind' => 'slb', 'remote_cert_id' => 'slb-id']);

    expect(CloudDeployRemoteCert::where('access_id', 10)->where('fingerprint', 'AA:BB')->count())->toBe(2);
});
