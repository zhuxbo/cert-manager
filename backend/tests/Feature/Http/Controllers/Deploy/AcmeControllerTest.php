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
            'data' => ['api_id' => 'gw-123', 'vendor_id' => 'v-456', 'eab_kid' => 'kid-deploy', 'eab_hmac' => 'hmac-deploy'],
        ]),
    ]);

    $response = test()->withHeaders(['Authorization' => "Bearer $deployToken->token"])
        ->postJson('/api/deploy/acme/new', [
            'product_id' => $product->id,
            'period' => 12,
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

test('new 产品不存在报错', function () {
    $user = User::factory()->create(['balance' => '1000.00']);
    $deployToken = DeployToken::factory()->create(['user_id' => $user->id]);

    test()->withHeaders(['Authorization' => "Bearer $deployToken->token"])
        ->postJson('/api/deploy/acme/new', [
            'product_id' => 99999,
            'period' => 12,
        ])
        ->assertOk()
        ->assertJson(['code' => 0]);
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
            'product_id' => $product->id,
            'period' => 12,
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
