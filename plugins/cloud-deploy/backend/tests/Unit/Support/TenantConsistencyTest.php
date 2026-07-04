<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Support\TenantConsistency;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->userA = User::factory()->create();
    $this->userB = User::factory()->create();
    $this->accessA = CloudDeployAccess::create([
        'user_id' => $this->userA->id, 'name' => 'A', 'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
    ]);
});

test('同用户 access + 无 order → 一致', function () {
    expect(TenantConsistency::check($this->userA->id, $this->accessA->id, null))->toBeTrue();
});

test('access 属于他人 → 不一致', function () {
    expect(TenantConsistency::check($this->userB->id, $this->accessA->id, null))->toBeFalse();
});

test('access 不存在 → 不一致', function () {
    expect(TenantConsistency::check($this->userA->id, 999999, null))->toBeFalse();
});

test('order 属于他人 → 不一致', function () {
    $orderB = Order::factory()->create(['user_id' => $this->userB->id]);
    expect(TenantConsistency::check($this->userA->id, $this->accessA->id, $orderB->id))->toBeFalse();
});
