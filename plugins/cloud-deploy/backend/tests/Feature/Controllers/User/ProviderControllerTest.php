<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\ActsAsUser;

uses(TestCase::class, RefreshDatabase::class, ActsAsUser::class);

test('providers 返回嵌套 catalog（provider→credentialSchema + products→configSchema）', function () {
    $user = User::factory()->create();

    $res = $this->actingAsUser($user)->getJson('/api/cloud-deploy/providers')
        ->assertOk()->assertJson(['code' => 1]);

    $providers = collect($res->json('data.providers'));
    $aliyun = $providers->firstWhere('key', 'aliyun');
    $tencent = $providers->firstWhere('key', 'tencent');

    expect($aliyun)->not->toBeNull();
    expect($tencent)->not->toBeNull();
    // provider 级凭证 schema
    expect(collect($aliyun['credentialSchema'])->pluck('key'))->toContain('access_key_id', 'access_key_secret');
    expect(collect($tencent['credentialSchema'])->pluck('key'))->toContain('secret_id', 'secret_key');
    // product 级 config schema
    $aliyunCdn = collect($aliyun['products'])->firstWhere('product', 'cdn');
    expect($aliyunCdn)->not->toBeNull();
    expect(collect($aliyunCdn['configSchema'])->pluck('key'))->toContain('domain');
});

test('catalog 已移除兼容层扁平 meta（前端改 schema-driven 后不再下发）', function () {
    $user = User::factory()->create();

    $res = $this->actingAsUser($user)->getJson('/api/cloud-deploy/providers')->assertOk();

    expect($res->json('data'))->toHaveKey('providers');
    expect($res->json('data'))->not->toHaveKey('meta');
});
