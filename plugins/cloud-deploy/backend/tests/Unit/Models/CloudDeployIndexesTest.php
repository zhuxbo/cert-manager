<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
