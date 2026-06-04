<?php

use App\Models\Acme;
use App\Models\ApiToken;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * 关闭 user channel 暴露 acme channel —— /api/acme/new 走 Acme\ApiController 而非 User\AcmeController
 * 见 RouteServiceProvider 注释：user.php 字母序晚于 acme.php 注册，会覆盖同名路由
 */
function reloadRoutesWithoutUserChannel(): void
{
    config([
        'channels.admin' => true,
        'channels.user' => false,
        'channels.api' => true,
        'channels.deploy' => true,
    ]);
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    RouteServiceProvider::registerApiRoutes(base_path('routes'));
    $router->getRoutes()->refreshNameLookups();
    $router->getRoutes()->refreshActionLookups();
}

afterEach(function () {
    config([
        'channels.admin' => true,
        'channels.user' => true,
        'channels.api' => true,
        'channels.deploy' => true,
    ]);
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    RouteServiceProvider::registerApiRoutes(base_path('routes'));
    $router->getRoutes()->refreshNameLookups();
    $router->getRoutes()->refreshActionLookups();
});

function setupAcmeApiGatewaySettings(): void
{
    $group = SettingGroup::firstOrCreate(['name' => 'ca'], ['title' => '证书接口', 'weight' => 2]);
    foreach (['url' => 'https://fake-gateway.test/api/v2', 'token' => 'fake-key'] as $key => $value) {
        Setting::firstOrCreate(
            ['group_id' => $group->id, 'key' => $key],
            ['type' => 'string', 'value' => $value, 'weight' => 0]
        );
    }
}

test('POST /api/acme/new 字段集与 gateway 对齐：refer_id 透传 / plus(int 0/1) / 不含 source', function () {
    reloadRoutesWithoutUserChannel();
    // 实测 /api/acme/new 确实路由到 Acme\ApiController（user channel 已关，无覆盖）
    expect(Route::has('api.acme.new') || collect(Route::getRoutes())
        ->contains(fn ($r) => $r->uri() === 'api/acme/new' && str_contains($r->getActionName(), 'Acme\\ApiController')))->toBeTrue();

    $user = User::factory()->create(['balance' => '1000.00']);
    $rawToken = Str::random(64);
    ApiToken::factory()->create(['user_id' => $user->id, 'token' => $rawToken, 'status' => 1]);

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

    setupAcmeApiGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => [
                'order_id' => 'gw-api-1',
                'eab_kid' => 'kid-api',
                'eab_hmac' => 'hmac-api',
            ],
        ]),
    ]);

    $referId = 'cli-refer-'.bin2hex(random_bytes(8));
    $response = $this->withHeaders(['Authorization' => "Bearer $rawToken"])
        ->postJson('/api/acme/new', [
            'product_code' => $product->code,
            'contact_email' => 'api-acme@example.com',
            'refer_id' => $referId,
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($response->json('data'))
        ->order_id->toBeInt()
        ->eab_kid->toBe('kid-api')
        ->eab_hmac->toBe('hmac-api');

    // 发给 gateway 的 body：含 refer_id、period(int)、plus=1(int)、不含 source
    Http::assertSent(function ($request) use ($referId) {
        if (! str_contains($request->url(), '/acme/new')) {
            return false;
        }
        $body = json_decode($request->body(), true);

        return ($body['contact_email'] ?? null) === 'api-acme@example.com'
            && ($body['product_code'] ?? null) !== null
            && ($body['refer_id'] ?? null) === $referId
            && ($body['period'] ?? null) === 12
            && ($body['plus'] ?? null) === 1
            && ! array_key_exists('source', $body);
    });

    // 本地落库：refer_id 端到端透传写入 acmes.refer_id
    $acme = Acme::withoutGlobalScopes()->find($response->json('data.order_id'));
    expect($acme)
        ->status->toBe(Acme::STATUS_ACTIVE)
        ->refer_id->toBe($referId)
        ->plus->toBe(1)
        ->user_id->toBe($user->id)
        ->channel->toBe('api');
});

test('POST /api/acme/new 未传 refer_id 时 manager 兜底生成 + 未传 plus 时默认 1', function () {
    reloadRoutesWithoutUserChannel();

    $user = User::factory()->create(['balance' => '1000.00']);
    $rawToken = Str::random(64);
    ApiToken::factory()->create(['user_id' => $user->id, 'token' => $rawToken, 'status' => 1]);

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

    setupAcmeApiGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => ['order_id' => 'gw-fallback', 'eab_kid' => 'k', 'eab_hmac' => 'h'],
        ]),
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer $rawToken"])
        ->postJson('/api/acme/new', [
            'product_code' => $product->code,
            'contact_email' => 'fallback@example.com',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/acme/new')) {
            return false;
        }
        $body = json_decode($request->body(), true);

        // plus 未传时默认 1；period 未传时默认 12；refer_id 由 manager 内部 bin2hex 生成
        return ($body['plus'] ?? null) === 1
            && ($body['period'] ?? null) === 12
            && isset($body['refer_id'])
            && preg_match('/^[0-9a-f]{32}$/', $body['refer_id']) === 1;
    });

    $acme = Acme::withoutGlobalScopes()->find($response->json('data.order_id'));
    expect($acme->refer_id)->toMatch('/^[0-9a-f]{32}$/');
});

test('POST /api/acme/new period 入参透传：多年期产品支持显式传 period 落库', function () {
    reloadRoutesWithoutUserChannel();

    $user = User::factory()->create(['balance' => '2000.00']);
    $rawToken = Str::random(64);
    ApiToken::factory()->create(['user_id' => $user->id, 'token' => $rawToken, 'status' => 1]);

    // 模拟未来 Certum 多年期产品（同时支持 12 和 24 月）
    $product = Product::factory()->create([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
        'periods' => [12, 24],
    ]);
    foreach ([12, 24] as $p) {
        ProductPrice::create([
            'product_id' => $product->id,
            'level_code' => $user->level_code ?? 'standard',
            'period' => $p,
            'price' => '100.00',
            'alternative_standard_price' => '10.00',
            'alternative_wildcard_price' => '20.00',
        ]);
    }

    setupAcmeApiGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => ['order_id' => 'gw-period-24', 'eab_kid' => 'k', 'eab_hmac' => 'h'],
        ]),
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer $rawToken"])
        ->postJson('/api/acme/new', [
            'product_code' => $product->code,
            'period' => 24,
            'contact_email' => 'multi-year@example.com',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    // manager 把 period 当完整 schema 透传给 gateway（gateway 当前丢弃，未来升级自然贯通）
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/acme/new')) {
            return false;
        }
        $body = json_decode($request->body(), true);

        return ($body['period'] ?? null) === 24;
    });

    $acme = Acme::withoutGlobalScopes()->find($response->json('data.order_id'));
    expect($acme->period)->toBe(24);
});

test('POST /api/acme/new 未传 period 时回落 product.periods[0]（产品仅支持 24 月时取 24，不报错）', function () {
    reloadRoutesWithoutUserChannel();

    $user = User::factory()->create(['balance' => '2000.00']);
    $rawToken = Str::random(64);
    ApiToken::factory()->create(['user_id' => $user->id, 'token' => $rawToken, 'status' => 1]);

    // 产品只支持 24 月（如未来仅多年期的 Certum 产品），用户不传 period 应回落 24 而非硬编码 12
    $product = Product::factory()->create([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
        'periods' => [24],
    ]);
    ProductPrice::create([
        'product_id' => $product->id,
        'level_code' => $user->level_code ?? 'standard',
        'period' => 24,
        'price' => '200.00',
        'alternative_standard_price' => '10.00',
        'alternative_wildcard_price' => '20.00',
    ]);

    setupAcmeApiGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => ['order_id' => 'gw-fallback-24', 'eab_kid' => 'k', 'eab_hmac' => 'h'],
        ]),
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer $rawToken"])
        ->postJson('/api/acme/new', [
            'product_code' => $product->code,
            'contact_email' => 'fallback-24@example.com',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $acme = Acme::withoutGlobalScopes()->find($response->json('data.order_id'));
    expect($acme->period)->toBe(24);
});

test('POST /api/acme/new 未传 period 时默认 12', function () {
    reloadRoutesWithoutUserChannel();

    $user = User::factory()->create(['balance' => '1000.00']);
    $rawToken = Str::random(64);
    ApiToken::factory()->create(['user_id' => $user->id, 'token' => $rawToken, 'status' => 1]);

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

    setupAcmeApiGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => ['order_id' => 'gw-default', 'eab_kid' => 'k', 'eab_hmac' => 'h'],
        ]),
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer $rawToken"])
        ->postJson('/api/acme/new', [
            'product_code' => $product->code,
            'contact_email' => 'default-period@example.com',
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    $acme = Acme::withoutGlobalScopes()->find($response->json('data.order_id'));
    expect($acme->period)->toBe(12);
});

test('POST /api/acme/new 跨用户同 refer_id 穿透应用层 → DB unique 兜底翻译为 Refer id already exists', function () {
    // checkAcmeReferId 按 user_id 限定查重，跨用户场景下应用层不拦截 →
    // DB acmes.refer_id 全局 unique 兜底，ApiExceptions::causedByDuplicateKey 翻译为友好消息
    reloadRoutesWithoutUserChannel();

    $otherUser = User::factory()->create();
    $currentUser = User::factory()->create(['balance' => '1000.00']);
    $rawToken = Str::random(64);
    ApiToken::factory()->create(['user_id' => $currentUser->id, 'token' => $rawToken, 'status' => 1]);

    $product = Product::factory()->create([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
        'periods' => [12],
    ]);
    ProductPrice::create([
        'product_id' => $product->id,
        'level_code' => $currentUser->level_code ?? 'standard',
        'period' => 12,
        'price' => '100.00',
        'alternative_standard_price' => '10.00',
        'alternative_wildcard_price' => '20.00',
    ]);

    // 占位：另一用户已有 acme 行带相同 refer_id（模拟跨用户 refer_id 冲突）
    Acme::factory()->create([
        'user_id' => $otherUser->id,
        'product_id' => $product->id,
        'refer_id' => 'global-dup-refer',
    ]);

    // 当前用户传同 refer_id：应用层 checkAcmeReferId 按 user_id 过滤命中不到 → 穿透到 DB
    $response = $this->withHeaders(['Authorization' => "Bearer $rawToken"])
        ->postJson('/api/acme/new', [
            'product_code' => $product->code,
            'contact_email' => 'cross-user@example.com',
            'refer_id' => 'global-dup-refer',
        ])
        ->assertStatus(409);

    expect($response->json('msg'))->toBe('Refer id already exists');
});

test('POST /api/acme/new refer_id 同 user 重复触发应用层防重', function () {
    reloadRoutesWithoutUserChannel();

    $user = User::factory()->create(['balance' => '1000.00']);
    $rawToken = Str::random(64);
    ApiToken::factory()->create(['user_id' => $user->id, 'token' => $rawToken, 'status' => 1]);

    $product = Product::factory()->create([
        'product_type' => Product::TYPE_ACME,
        'source' => 'default',
        'periods' => [12],
    ]);

    // 占位：已有同 user 的 acme 行携带 refer_id='dup-refer'
    Acme::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'refer_id' => 'dup-refer',
    ]);

    $this->withHeaders(['Authorization' => "Bearer $rawToken"])
        ->postJson('/api/acme/new', [
            'product_code' => $product->code,
            'contact_email' => 'dup@example.com',
            'refer_id' => 'dup-refer',
        ])
        ->assertOk()
        ->assertJson(['code' => 0, 'msg' => 'Refer id already exists']);
});
