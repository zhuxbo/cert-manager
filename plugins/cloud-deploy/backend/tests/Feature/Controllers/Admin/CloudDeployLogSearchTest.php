<?php

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\CloudDeploy\Models\CloudDeployLog;
use Tests\TestCase;
use Tests\Traits\ActsAsAdmin;

uses(TestCase::class, RefreshDatabase::class, ActsAsAdmin::class);

/** 造一条 log（user 真建以支持 username 维度），其余快照列由 $attrs 覆盖。 */
function mkLog(array $attrs = [], ?string $username = null): CloudDeployLog
{
    $user = User::factory()->create($username ? ['username' => $username] : []);

    return CloudDeployLog::create(array_merge([
        'user_id' => $user->id, 'target_id' => 1, 'order_id' => 1000, 'cert_id' => 1,
        'provider' => 'aliyun', 'product' => 'cdn', 'resource_summary' => 'res.example.com',
        'access_name' => 'acc', 'trigger' => 'manual', 'status' => 'success',
        'attempt_no' => 1, 'is_final' => true, 'deployed_at' => now(),
    ], $attrs));
}

beforeEach(function () {
    $this->admin = Admin::factory()->create();
});

test('admin logs 按 order_id 精确筛选（admin 记录弹窗整单入口，不串户）', function () {
    mkLog(['order_id' => 555]);
    mkLog(['order_id' => 777]);

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/log?order_id=555')->assertOk();
    expect($res->json('data.total'))->toBe(1);
});

test('admin logs 按 target_id 精确筛选（点状态列入口，不串户）', function () {
    mkLog(['target_id' => 11]);
    mkLog(['target_id' => 22]);

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/log?target_id=11')->assertOk();
    expect($res->json('data.total'))->toBe(1);
});

test('admin logs 按 is_final 去噪（默认 true 只显终态，传 0 含重试中间行）', function () {
    mkLog(['is_final' => true, 'attempt_no' => 2]);
    mkLog(['is_final' => false, 'attempt_no' => 1]); // 重试中间行

    $final = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/log?is_final=1')->assertOk();
    expect($final->json('data.total'))->toBe(1);

    $all = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/log?is_final=0')->assertOk();
    expect($all->json('data.total'))->toBe(1); // 只命中 is_final=false 那条
});

test('admin logs 按 status 筛选（logs 列名=status）', function () {
    mkLog(['status' => 'success']);
    mkLog(['status' => 'failed']);

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/log?status=failed')->assertOk();
    expect($res->json('data.total'))->toBe(1);
});

test('admin logs 按 provider/product/trigger 快照筛选', function () {
    mkLog(['provider' => 'aliyun', 'product' => 'cdn', 'trigger' => 'manual']);
    mkLog(['provider' => 'tencent', 'product' => 'oss', 'trigger' => 'auto']);

    expect($this->actingAsAdmin($this->admin)->getJson('/api/admin/cloud-deploy/log?provider=tencent')->assertOk()->json('data.total'))->toBe(1);
    expect($this->actingAsAdmin($this->admin)->getJson('/api/admin/cloud-deploy/log?product=oss')->assertOk()->json('data.total'))->toBe(1);
    expect($this->actingAsAdmin($this->admin)->getJson('/api/admin/cloud-deploy/log?trigger=auto')->assertOk()->json('data.total'))->toBe(1);
});

test('admin logs 按域名(resource_summary 快照) keyword 筛选', function () {
    mkLog(['resource_summary' => 'shop.alpha.com']);
    mkLog(['resource_summary' => 'api.beta.com']);

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/log?keyword=alpha')->assertOk();
    expect($res->json('data.total'))->toBe(1);
});

test('admin logs 按 created_at 时间范围筛选', function () {
    $old = mkLog();
    $old->forceFill(['created_at' => '2026-01-01 00:00:00'])->saveQuietly();
    $new = mkLog();
    $new->forceFill(['created_at' => '2026-06-01 00:00:00'])->saveQuietly();

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/log?created_at_start=2026-05-01 00:00:00&created_at_end=2026-07-01 00:00:00')
        ->assertOk();
    expect($res->json('data.total'))->toBe(1);
});

test('admin logs 按 user_id 筛选（沿用既有能力）', function () {
    $l = mkLog();
    mkLog();

    $res = $this->actingAsAdmin($this->admin)
        ->getJson("/api/admin/cloud-deploy/log?user_id={$l->user_id}")->assertOk();
    expect($res->json('data.total'))->toBe(1);
});

test('admin logs 按 username 独立筛选（专用用户名框，whereExists join users）', function () {
    mkLog(username: 'logfilter_gamma');
    mkLog(username: 'logfilter_delta');

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/cloud-deploy/log?username=gamma')->assertOk();
    expect($res->json('data.total'))->toBe(1);
    expect($res->json('data.items.0.username'))->toBe('logfilter_gamma'); // 顶层 username 拍平（attachUsernames）
});

test('admin logs quickSearch 命中订单号/域名/凭证名快照/用户名', function () {
    mkLog(['order_id' => 90909]);
    mkLog(['resource_summary' => 'quick-domain.com']);
    mkLog(['access_name' => 'quick-access']);
    mkLog(username: 'quickloguser');
    mkLog(); // 噪声

    expect($this->actingAsAdmin($this->admin)->getJson('/api/admin/cloud-deploy/log?quickSearch=90909')->assertOk()->json('data.total'))->toBe(1);
    expect($this->actingAsAdmin($this->admin)->getJson('/api/admin/cloud-deploy/log?quickSearch=quick-domain')->assertOk()->json('data.total'))->toBe(1);
    expect($this->actingAsAdmin($this->admin)->getJson('/api/admin/cloud-deploy/log?quickSearch=quick-access')->assertOk()->json('data.total'))->toBe(1);
    expect($this->actingAsAdmin($this->admin)->getJson('/api/admin/cloud-deploy/log?quickSearch=quickloguser')->assertOk()->json('data.total'))->toBe(1);
});
