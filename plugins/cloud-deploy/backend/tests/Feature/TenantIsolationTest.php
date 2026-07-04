<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Tests\TestCase;
use Tests\Traits\ActsAsUser;

uses(TestCase::class, RefreshDatabase::class, ActsAsUser::class);

beforeEach(function () {
    $this->me = User::factory()->create();
    $this->other = User::factory()->create();
});

test('不能删除/更新他人 target', function () {
    $access = CloudDeployAccess::create([
        'user_id' => $this->other->id, 'name' => 'x', 'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
    ]);
    $order = Order::factory()->create(['user_id' => $this->other->id]);
    $target = CloudDeployTarget::create([
        'user_id' => $this->other->id, 'access_id' => $access->id, 'order_id' => $order->id,
        'product' => 'cdn', 'config' => ['domain' => 'o.example.com'],
    ]);

    $this->actingAsUser($this->me)
        ->deleteJson("/api/cloud-deploy/target/{$target->id}")
        ->assertOk()->assertJson(['code' => 0]);

    expect(CloudDeployTarget::withoutGlobalScopes()->whereKey($target->id)->exists())->toBeTrue();
});

test('列表只见自己的 target', function () {
    foreach ([$this->me, $this->other] as $u) {
        $a = CloudDeployAccess::create([
            'user_id' => $u->id, 'name' => 'a', 'provider' => 'aliyun',
            'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
        ]);
        $o = Order::factory()->create(['user_id' => $u->id]);
        CloudDeployTarget::create([
            'user_id' => $u->id, 'access_id' => $a->id, 'order_id' => $o->id,
            'product' => 'cdn', 'config' => ['domain' => 'x'],
        ]);
    }

    $res = $this->actingAsUser($this->me)->getJson('/api/cloud-deploy/target')->assertOk();
    expect($res->json('data.total'))->toBe(1);
});
