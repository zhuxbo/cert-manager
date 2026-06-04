<?php

use App\Models\Acme;
use App\Models\DeployToken;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * 创建 Gateway 系统设置
 */
function setupDeployGatewaySettings(string $url = 'https://fake-gateway.test/api/v2', string $token = 'fake-key'): void
{
    $group = SettingGroup::firstOrCreate(['name' => 'ca'], ['title' => '证书接口', 'weight' => 2]);

    foreach (['url' => $url, 'token' => $token, 'acme_url' => null, 'acme_token' => null] as $key => $value) {
        $setting = Setting::firstOrCreate(
            ['group_id' => $group->id, 'key' => $key],
            ['type' => 'string', 'value' => null, 'weight' => 0]
        );
        if ($value !== null) {
            $setting->value = $value;
            $setting->save();
        }
    }
}

test('new 一步到位成功', function () {
    $user = User::factory()->create(['balance' => '1000.00']);
    $deployToken = DeployToken::factory()->create(['user_id' => $user->id]);

    $product = Product::factory()->create([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
        'periods' => [12],
    ]);

    ProductPrice::create([
        'product_id' => $product->id,
        'level_code' => $user->level_code ?? 'standard',
        'period' => 12,
        'price' => '100.00',
        'alternative_standard_price' => '10.00',
        'alternative_wildcard_price' => '20.00',
    ]);

    setupDeployGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'order_id' => 'gw-123',
                'vendor_id' => 'v-456',
                'contact_email' => 'deploy@example.com',
                'eab_kid' => 'kid-deploy',
                'eab_hmac' => 'hmac-deploy',
            ],
        ]),
    ]);

    $response = test()->withHeaders(['Authorization' => "Bearer $deployToken->token"])
        ->postJson('/api/deploy/acme/new', [
            'product_code' => $product->code,
            'contact_email' => 'deploy@example.com',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data'))
        ->order_id->toBeInt()
        ->eab_kid->toBe('kid-deploy')
        ->eab_hmac->toBe('hmac-deploy')
        ->status->toBe('active');

    $acme = Acme::withoutGlobalScopes()->find($response->json('data.order_id'));
    expect($acme)
        ->status->toBe(Acme::STATUS_ACTIVE)
        ->user_id->toBe($user->id)
        ->channel->toBe('deploy');
});

test('new 支持自选 contact_email 并透传给 Gateway', function () {
    $user = User::factory()->create(['email' => 'deploy-owner@example.com', 'balance' => '1000.00']);
    $deployToken = DeployToken::factory()->create(['user_id' => $user->id]);

    $product = Product::factory()->create([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
        'periods' => [12],
    ]);

    ProductPrice::create([
        'product_id' => $product->id,
        'level_code' => $user->level_code ?? 'standard',
        'period' => 12,
        'price' => '100.00',
        'alternative_standard_price' => '10.00',
        'alternative_wildcard_price' => '20.00',
    ]);

    setupDeployGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'order_id' => 'gw-deploy-contact',
                'vendor_id' => 'v-deploy',
                'contact_email' => 'acme-deploy@example.com',
                'eab_kid' => 'kid-deploy-c',
                'eab_hmac' => 'hmac-deploy-c',
            ],
        ]),
    ]);

    $response = test()->withHeaders(['Authorization' => "Bearer $deployToken->token"])
        ->postJson('/api/deploy/acme/new', [
            'product_code' => $product->code,
            'contact_email' => 'acme-deploy@example.com',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    // 请求体应把调用方指定的 contact_email 作为 customer 发给上游
    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);

        return ($body['contact_email'] ?? null) === 'acme-deploy@example.com';
    });

    $acme = Acme::withoutGlobalScopes()->find($response->json('data.order_id'));
    expect($acme->contact_email)->toBe('acme-deploy@example.com');
});

test('new 产品不存在报错', function () {
    $user = User::factory()->create(['balance' => '1000.00']);
    $deployToken = DeployToken::factory()->create(['user_id' => $user->id]);

    test()->withHeaders(['Authorization' => "Bearer $deployToken->token"])
        ->postJson('/api/deploy/acme/new', [
            'product_code' => 'nonexistent-acme-code',
            'contact_email' => 'x@example.com',
        ])
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('new 缺少 contact_email 校验失败', function () {
    $user = User::factory()->create(['balance' => '1000.00']);
    $deployToken = DeployToken::factory()->create(['user_id' => $user->id]);

    $product = Product::factory()->create([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
        'periods' => [12],
    ]);

    test()->withHeaders(['Authorization' => "Bearer $deployToken->token"])
        ->postJson('/api/deploy/acme/new', [
            'product_code' => $product->code,
        ])
        ->assertOk()
        ->assertJson(['code' => 0])
        ->assertJsonPath('errors.contact_email.0', fn ($msg) => is_string($msg));
});

test('new 余额不足报错', function () {
    $user = User::factory()->create(['balance' => '0.00', 'credit_limit' => '0.00']);
    $deployToken = DeployToken::factory()->create(['user_id' => $user->id]);

    $product = Product::factory()->create([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
        'periods' => [12],
    ]);

    ProductPrice::create([
        'product_id' => $product->id,
        'level_code' => $user->level_code ?? 'standard',
        'period' => 12,
        'price' => '100.00',
        'alternative_standard_price' => '10.00',
        'alternative_wildcard_price' => '20.00',
    ]);

    test()->withHeaders(['Authorization' => "Bearer $deployToken->token"])
        ->postJson('/api/deploy/acme/new', [
            'product_code' => $product->code,
            'contact_email' => 'x@example.com',
        ])
        ->assertOk()
        ->assertJson(['code' => 0]);
});

test('get 返回当前用户订单含 eab', function () {
    $user = User::factory()->create();
    $deployToken = DeployToken::factory()->create(['user_id' => $user->id]);

    $product = Product::factory()->create(['product_type' => Product::TYPE_ACME]);
    $acme = Acme::factory()->active()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $response = test()->withHeaders(['Authorization' => "Bearer $deployToken->token"])
        ->getJson("/api/deploy/acme/$acme->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data'))
        ->id->toBe($acme->id)
        ->eab_kid->toBe($acme->eab_kid)
        ->eab_hmac->not->toBeNull()
        ->status->toBe('active');
});

test('get 查看他人订单返回 404', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $deployToken = DeployToken::factory()->create(['user_id' => $user->id]);

    $product = Product::factory()->create(['product_type' => Product::TYPE_ACME]);
    $acme = Acme::factory()->active()->create([
        'user_id' => $otherUser->id,
        'product_id' => $product->id,
    ]);

    test()->withHeaders(['Authorization' => "Bearer $deployToken->token"])
        ->getJson("/api/deploy/acme/$acme->id")
        ->assertOk()
        ->assertJson(['code' => 0]);
});
