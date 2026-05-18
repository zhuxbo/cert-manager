<?php

use App\Services\ZipcodeLookup\ZipcodeLookup;
use Illuminate\Support\Facades\Cache;
use Tests\Traits\ActsAsUser;

uses(ActsAsUser::class);

beforeEach(function () {
    ZipcodeLookup::resetCache();
    Cache::flush();
});

test('user lookup returns district precision by regionname', function () {
    $r = $this->actingAsUser()->postJson('/api/zipcode-lookup', [
        'regionname' => '河南省南阳市卧龙区',
    ]);
    $r->assertOk();
    expect($r->json('code'))->toBe(1)
        ->and($r->json('data.zipcode'))->toBe('473000')
        ->and($r->json('data.city'))->toBe('南阳市')
        ->and($r->json('data.district'))->toBe('卧龙区');
});

test('user lookup with companyName fills city to county-level city', function () {
    $r = $this->actingAsUser()->postJson('/api/zipcode-lookup', [
        'regionname' => '浙江省金华市',
        'name' => '义乌市鸿运商贸有限公司',
    ]);
    $r->assertOk();
    expect($r->json('data.zipcode'))->toBe('322000')
        ->and($r->json('data.city'))->toBe('义乌市')
        ->and($r->json('data.district'))->toBe('');
});

test('user lookup returns error when unmatched', function () {
    $r = $this->actingAsUser()->postJson('/api/zipcode-lookup', [
        'regionname' => '火星共和国某某区',
    ]);
    expect($r->json('code'))->toBe(0);
});

test('user lookup validates required regionname', function () {
    $r = $this->actingAsUser()->postJson('/api/zipcode-lookup', []);
    expect($r->json('code'))->toBe(0);
});

test('user lookup throttled at 60 per minute', function () {
    Cache::flush();
    for ($i = 0; $i < 60; $i++) {
        $this->actingAsUser()->postJson('/api/zipcode-lookup', [
            'regionname' => '河南省南阳市卧龙区',
        ])->assertOk();
    }
    $r = $this->actingAsUser()->postJson('/api/zipcode-lookup', [
        'regionname' => '河南省南阳市卧龙区',
    ]);
    expect($r->json('code'))->toBe(0);
});
