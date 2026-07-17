<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\CloudDeploy\Models\CloudDeployLog;
use Tests\TestCase;
use Tests\Traits\ActsAsUser;

uses(TestCase::class, RefreshDatabase::class, ActsAsUser::class);

test('只返回当前用户的部署日志', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    CloudDeployLog::create([
        'user_id' => $me->id, 'target_id' => 1, 'order_id' => 1, 'cert_id' => 1,
        'provider' => 'aliyun', 'product' => 'cdn', 'trigger' => 'auto', 'status' => 'success',
        'attempt_no' => 1, 'is_final' => true, 'deployed_at' => now(),
    ]);
    CloudDeployLog::create([
        'user_id' => $other->id, 'target_id' => 2, 'order_id' => 2, 'cert_id' => 2,
        'provider' => 'tencent', 'product' => 'cdn', 'trigger' => 'auto', 'status' => 'failed',
        'attempt_no' => 1, 'is_final' => true, 'deployed_at' => now(),
    ]);

    $res = $this->actingAsUser($me)->getJson('/api/cloud-deploy/log')
        ->assertOk()->assertJson(['code' => 1]);

    expect($res->json('data.total'))->toBe(1);
    expect($res->json('data.items.0.provider'))->toBe('aliyun');
});

/** 建一条当前用户的部署日志（最小字段 + 可覆盖）。 */
function makeLog(int $userId, array $attrs = []): CloudDeployLog
{
    return CloudDeployLog::create(array_merge([
        'user_id' => $userId, 'target_id' => 1, 'order_id' => 1, 'cert_id' => 1,
        'provider' => 'aliyun', 'product' => 'cdn', 'resource_summary' => 'a.example.com',
        'access_name' => 'A', 'trigger' => 'manual', 'status' => 'success',
        'attempt_no' => 1, 'is_final' => true, 'deployed_at' => now(),
    ], $attrs));
}

test('is_final=1 默认去噪：只返回终态行', function () {
    $me = User::factory()->create();
    makeLog($me->id, ['is_final' => true, 'status' => 'success']);
    makeLog($me->id, ['is_final' => false, 'status' => 'failed']); // 中间重试行
    makeLog($me->id, ['is_final' => false, 'status' => 'failed']);

    $res = $this->actingAsUser($me)->getJson('/api/cloud-deploy/log?is_final=1')->assertOk();
    expect($res->json('data.total'))->toBe(1);
    expect($res->json('data.items.0.is_final'))->toBeTrue();
});

test('is_final=true 字符串（前端 qs 布尔形态）被归一、去噪生效不 422', function () {
    // qs.stringify({is_final:true}) => "is_final=true"；prepareForValidation 须把 "true"→1，
    // 否则 boolean 规则拒 "true" 返回 422、记录弹窗默认视图加载失败（§6 破绽）。
    $me = User::factory()->create();
    makeLog($me->id, ['is_final' => true, 'status' => 'success']);
    makeLog($me->id, ['is_final' => false, 'status' => 'failed']);

    $res = $this->actingAsUser($me)->getJson('/api/cloud-deploy/log?is_final=true')->assertOk(); // 不 422
    expect($res->json('data.total'))->toBe(1);
    expect($res->json('data.items.0.is_final'))->toBeTrue();
});

test('is_final=false 显示重试细节（中间行）', function () {
    $me = User::factory()->create();
    makeLog($me->id, ['is_final' => true]);
    makeLog($me->id, ['is_final' => false]);
    makeLog($me->id, ['is_final' => false]);

    $res = $this->actingAsUser($me)->getJson('/api/cloud-deploy/log?is_final=0')->assertOk();
    expect($res->json('data.total'))->toBe(2);
});

test('provider/product/trigger 等值过滤', function () {
    $me = User::factory()->create();
    makeLog($me->id, ['provider' => 'aliyun', 'product' => 'cdn', 'trigger' => 'manual']);
    makeLog($me->id, ['provider' => 'tencent', 'product' => 'clb', 'trigger' => 'auto']);

    $res = $this->actingAsUser($me)->getJson('/api/cloud-deploy/log?provider=tencent&product=clb&trigger=auto')->assertOk();
    expect($res->json('data.total'))->toBe(1);
    expect($res->json('data.items.0.provider'))->toBe('tencent');
});

test('keyword 模糊匹配 resource_summary（部署资源域名快照）', function () {
    $me = User::factory()->create();
    makeLog($me->id, ['resource_summary' => 'shop.example.com']);
    makeLog($me->id, ['resource_summary' => 'other.test.org']);

    $res = $this->actingAsUser($me)->getJson('/api/cloud-deploy/log?keyword=example.com')->assertOk();
    expect($res->json('data.total'))->toBe(1);
});

test('quickSearch 命中域名快照/订单号/凭证名快照（orWhere 分组）', function () {
    $me = User::factory()->create();
    makeLog($me->id, ['order_id' => 7001, 'resource_summary' => 'billing.acme.io', 'access_name' => 'prod-key']);
    makeLog($me->id, ['order_id' => 7002, 'resource_summary' => 'nomatch.test.net', 'access_name' => 'other-key']);

    // 命中 resource_summary
    expect($this->actingAsUser($me)->getJson('/api/cloud-deploy/log?quickSearch=acme.io')->assertOk()->json('data.total'))->toBe(1);
    // 命中 order_id
    expect($this->actingAsUser($me)->getJson('/api/cloud-deploy/log?quickSearch=7001')->assertOk()->json('data.total'))->toBe(1);
    // 命中 access_name 快照
    expect($this->actingAsUser($me)->getJson('/api/cloud-deploy/log?quickSearch=prod-key')->assertOk()->json('data.total'))->toBe(1);
});
