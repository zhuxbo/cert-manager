<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Traits\ActsAsAdmin;

uses(ActsAsAdmin::class);

beforeEach(function () {
    setEnterpriseLookupSetting('url', 'https://example.com/x');
    setEnterpriseLookupSetting('appCode', 'TC');
    setEnterpriseLookupSetting('queryField', 'name');
    setEnterpriseLookupSetting('fieldMap', [
        'name' => 'data.companyName',
        'registration_number' => 'data.creditCode',
        'address' => 'data.regAddress',
    ], 'array');
    Cache::flush();
});

test('admin lookup returns normalized fields', function () {
    Http::fake(['*' => Http::response(['data' => [
        'companyName' => '示例', 'creditCode' => 'XX', 'regAddress' => '北京',
    ]], 200)]);
    $r = $this->actingAsAdmin()->postJson('/api/admin/enterprise-lookup', ['name' => '示例']);
    $r->assertOk();
    expect($r->json('code'))->toBe(1);
    expect($r->json('data.name'))->toBe('示例');
});

test('admin lookup returns error when disabled', function () {
    setEnterpriseLookupSetting('url', '');
    $r = $this->actingAsAdmin()->postJson('/api/admin/enterprise-lookup', ['name' => '示例']);
    expect($r->json('code'))->toBe(0);
});

test('admin status returns enabled flag', function () {
    $r = $this->actingAsAdmin()->getJson('/api/admin/enterprise-lookup/status');
    $r->assertOk();
    expect($r->json('code'))->toBe(1);
    expect($r->json('data.enabled'))->toBeBool();
});

test('admin lookup throttled at 30 per minute', function () {
    Http::fake(['*' => Http::response(['data' => [
        'companyName' => 'X', 'creditCode' => 'X', 'regAddress' => 'X',
    ]], 200)]);
    Cache::flush();
    for ($i = 0; $i < 30; $i++) {
        $this->actingAsAdmin()->postJson('/api/admin/enterprise-lookup', ['name' => "n$i"])->assertOk();
    }
    // 项目自定义 RateLimiter 返回 code:0 而非 HTTP 429
    $r = $this->actingAsAdmin()->postJson('/api/admin/enterprise-lookup', ['name' => 'overflow']);
    expect($r->json('code'))->toBe(0);
});
