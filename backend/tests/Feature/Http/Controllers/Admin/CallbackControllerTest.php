<?php

use App\Models\Admin;
use App\Models\Callback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    $this->user = User::factory()->create();
});

test('管理员回调列表不返回 token 明文', function () {
    Callback::create([
        'user_id' => $this->user->id,
        'url' => 'https://example.com/cb',
        'token' => 'secret-webhook-token-1',
        'status' => 1,
    ]);

    $response = $this->actingAsAdmin($this->admin)->getJson('/api/admin/callback');

    $response->assertOk()->assertJson(['code' => 1]);
    $items = $response->json('data.items');
    // A2: admin 跨用户管理回调，列表不回传任何用户的 webhook 校验密钥
    expect($items)->toHaveCount(1)
        ->and($items[0])->not->toHaveKey('token');
});

test('管理员查看回调详情不返回 token 明文', function () {
    $callback = Callback::create([
        'user_id' => $this->user->id,
        'url' => 'https://example.com/cb',
        'token' => 'secret-webhook-token-2',
        'status' => 1,
    ]);

    $response = $this->actingAsAdmin($this->admin)->getJson("/api/admin/callback/$callback->id");

    $response->assertOk()->assertJson(['code' => 1]);
    $response->assertJsonPath('data.id', $callback->id);
    expect($response->json('data'))->not->toHaveKey('token');
});

test('管理员批量查看回调不返回 token 明文', function () {
    $c1 = Callback::create([
        'user_id' => $this->user->id,
        'url' => 'https://example.com/1',
        'token' => 'token-1',
        'status' => 1,
    ]);
    $user2 = User::factory()->create();
    $c2 = Callback::create([
        'user_id' => $user2->id,
        'url' => 'https://example.com/2',
        'token' => 'token-2',
        'status' => 1,
    ]);

    $response = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/callback/batch?ids[]='.$c1->id.'&ids[]='.$c2->id);

    $response->assertOk()->assertJson(['code' => 1]);
    foreach ($response->json('data') as $item) {
        expect($item)->not->toHaveKey('token');
    }
});

test('管理员更新回调时不传 token 保留原 token（留空=不改）', function () {
    $callback = Callback::create([
        'user_id' => $this->user->id,
        'url' => 'https://example.com/old',
        'token' => 'keep-this-token',
        'status' => 1,
    ]);

    // 详情已隐藏 token，前端编辑态默认空，不传 token；后端应保留原 token 不被覆盖成空
    $response = $this->actingAsAdmin($this->admin)->putJson("/api/admin/callback/$callback->id", [
        'user_id' => $this->user->id,
        'url' => 'https://example.com/new',
        'status' => 1,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);
    $callback->refresh();
    expect($callback->url)->toBe('https://example.com/new')
        ->and($callback->token)->toBe('keep-this-token');
});
