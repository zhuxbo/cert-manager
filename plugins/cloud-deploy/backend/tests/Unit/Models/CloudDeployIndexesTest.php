<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('cloud_deploy_targets 有 product / last_status / last_deployed_at 索引', function () {
    // 既有索引（回归保护，确保改动未误删）
    expect(Schema::hasIndex('cloud_deploy_targets', ['user_id']))->toBeTrue();
    expect(Schema::hasIndex('cloud_deploy_targets', ['access_id']))->toBeTrue();
    expect(Schema::hasIndex('cloud_deploy_targets', ['order_id']))->toBeTrue();

    // 本次新增
    expect(Schema::hasIndex('cloud_deploy_targets', ['product']))->toBeTrue();
    expect(Schema::hasIndex('cloud_deploy_targets', ['last_status']))->toBeTrue();
    expect(Schema::hasIndex('cloud_deploy_targets', ['last_deployed_at']))->toBeTrue();
    expect(Schema::hasIndex('cloud_deploy_targets', ['user_id', 'access_id', 'product', 'config_hash']))->toBeTrue();
});

test('cloud_deploy_targets 以无索引 nullable text 保存 pending job', function () {
    $column = collect(Schema::getColumns('cloud_deploy_targets'))
        ->firstWhere('name', 'pending_job');

    expect($column)->not->toBeNull();
    expect($column['type_name'])->toBe('text');
    expect($column['nullable'])->toBeTrue();
    expect(collect(Schema::getIndexes('cloud_deploy_targets'))
        ->contains(fn (array $index) => in_array('pending_job', $index['columns'] ?? [], true)))
        ->toBeFalse();
});

test('cloud_deploy_targets 用规范化 config_hash 唯一约束兜底同一推送目标', function () {
    CloudDeployTarget::create([
        'user_id' => 1,
        'access_id' => 10,
        'order_id' => 100,
        'product' => 'cdn',
        'config' => ['b' => 2, 'a' => 1],
    ]);

    expect(fn () => CloudDeployTarget::create([
        'user_id' => 1,
        'access_id' => 10,
        'order_id' => 101,
        'product' => 'cdn',
        'config' => ['a' => 1, 'b' => 2],
    ]))->toThrow(QueryException::class);
});

test('cloud_deploy_logs 有 order_id / provider / product / created_at 索引', function () {
    // 既有索引（回归保护）
    expect(Schema::hasIndex('cloud_deploy_logs', ['user_id']))->toBeTrue();
    expect(Schema::hasIndex('cloud_deploy_logs', ['target_id']))->toBeTrue();
    expect(Schema::hasIndex('cloud_deploy_logs', ['status']))->toBeTrue();

    // 本次新增
    expect(Schema::hasIndex('cloud_deploy_logs', ['order_id']))->toBeTrue();
    expect(Schema::hasIndex('cloud_deploy_logs', ['provider']))->toBeTrue();
    expect(Schema::hasIndex('cloud_deploy_logs', ['product']))->toBeTrue();
    expect(Schema::hasIndex('cloud_deploy_logs', ['created_at']))->toBeTrue();
});

test('§8.2 取舍：logs 不引入复合 (user_id, created_at) 冗余索引，保留单列 user_id', function () {
    // 决策方案①：单列 user_id + 单列 created_at，不加复合
    expect(Schema::hasIndex('cloud_deploy_logs', ['user_id']))->toBeTrue();
    expect(Schema::hasIndex('cloud_deploy_logs', ['user_id', 'created_at']))->toBeFalse();
});
