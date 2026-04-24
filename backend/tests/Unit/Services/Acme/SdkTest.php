<?php

use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Acme\Api\default\Sdk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class, RefreshDatabase::class)->group('database');

/**
 * 创建 ACME SDK 所需的系统设置
 */
function createAcmeSdkSettings(string $acmeUrl = '', string $acmeToken = '', string $caUrl = '', string $caToken = ''): void
{
    $group = SettingGroup::firstOrCreate(['name' => 'ca'], ['title' => '证书接口', 'weight' => 2]);

    foreach (['acme_url' => $acmeUrl, 'acme_token' => $acmeToken, 'url' => $caUrl, 'token' => $caToken] as $key => $value) {
        $setting = Setting::firstOrCreate(
            ['group_id' => $group->id, 'key' => $key],
            ['type' => 'string', 'value' => null, 'weight' => 0]
        );
        $setting->value = $value ?: null;
        $setting->save();
    }
}

test('new sends POST to gateway', function () {
    createAcmeSdkSettings(acmeUrl: 'https://gateway.test/api/acme', acmeToken: 'test-acme-token');

    Http::fake([
        'gateway.test/api/acme/new' => Http::response(['code' => 1, 'data' => ['id' => 1]], 200),
    ]);

    $sdk = new Sdk;
    $result = $sdk->new(['contact_email' => 'test@example.com', 'product_code' => '12345']);

    expect($result['code'])->toBe(1);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://gateway.test/api/acme/new'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-acme-token')
            && $request->data()['contact_email'] === 'test@example.com';
    });
});

test('get sends GET to gateway', function () {
    createAcmeSdkSettings(acmeUrl: 'https://gateway.test/api/acme', acmeToken: 'test-acme-token');

    Http::fake([
        'gateway.test/api/acme/get*' => Http::response(['code' => 1, 'data' => ['id' => 42]], 200),
    ]);

    $sdk = new Sdk;
    $result = $sdk->get(42);

    expect($result['code'])->toBe(1);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'https://gateway.test/api/acme/get')
            && $request->method() === 'GET'
            && $request['order_id'] == 42;
    });
});

test('cancel sends POST to gateway', function () {
    createAcmeSdkSettings(acmeUrl: 'https://gateway.test/api/acme', acmeToken: 'test-acme-token');

    Http::fake([
        'gateway.test/api/acme/cancel' => Http::response(['code' => 1, 'data' => []], 200),
    ]);

    $sdk = new Sdk;
    $result = $sdk->cancel(42);

    expect($result['code'])->toBe(1);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://gateway.test/api/acme/cancel'
            && $request->method() === 'POST'
            && $request->data()['order_id'] == 42;
    });
});

test('sync sends POST to gateway', function () {
    createAcmeSdkSettings(acmeUrl: 'https://gateway.test/api/acme', acmeToken: 'test-acme-token');

    Http::fake([
        'gateway.test/api/acme/sync' => Http::response(['code' => 1, 'data' => []], 200),
    ]);

    $sdk = new Sdk;
    $result = $sdk->sync(42);

    expect($result['code'])->toBe(1);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://gateway.test/api/acme/sync'
            && $request->method() === 'POST'
            && $request->data()['order_id'] == 42;
    });
});

test('returns error when not configured', function () {
    createAcmeSdkSettings();

    $sdk = new Sdk;
    $result = $sdk->get(1);

    expect($result['code'])->toBe(0);
    expect($result['msg'])->toContain('未配置');
});

test('returns error on connection failure', function () {
    createAcmeSdkSettings(acmeUrl: 'https://gateway.test/api/acme', acmeToken: 'test-acme-token');

    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
    });

    $sdk = new Sdk;
    $result = $sdk->get(1);

    expect($result['code'])->toBe(0);
    expect($result['msg'])->toContain('连接失败');
});

test('returns error when response has no code', function () {
    createAcmeSdkSettings(acmeUrl: 'https://gateway.test/api/acme', acmeToken: 'test-acme-token');

    Http::fake([
        'gateway.test/api/acme/get*' => Http::response(['data' => []], 200),
    ]);

    $sdk = new Sdk;
    $result = $sdk->get(1);

    expect($result['code'])->toBe(0);
    expect($result['msg'])->toContain('格式错误');
});

test('returns error on http failure', function () {
    createAcmeSdkSettings(acmeUrl: 'https://gateway.test/api/acme', acmeToken: 'test-acme-token');

    Http::fake([
        'gateway.test/api/acme/get*' => Http::response(['msg' => '服务器错误'], 500),
    ]);

    $sdk = new Sdk;
    $result = $sdk->get(1);

    expect($result['code'])->toBe(0);
});

test('falls back to ca url when acme_url not set', function () {
    createAcmeSdkSettings(caUrl: 'https://gateway.test/api/v2', caToken: 'fallback-token');

    Http::fake([
        'gateway.test/api/acme/get-products*' => Http::response(['code' => 1, 'data' => []], 200),
    ]);

    $sdk = new Sdk;
    $result = $sdk->getProducts();

    expect($result['code'])->toBe(1);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'https://gateway.test/api/acme/get-products')
            && $request->hasHeader('Authorization', 'Bearer fallback-token');
    });
});
