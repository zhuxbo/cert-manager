<?php

use App\Services\EnterpriseLookup\AliyunDriver;
use App\Services\EnterpriseLookup\LookupException;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\CreatesTestData;

uses(TestCase::class, CreatesTestData::class, RefreshDatabase::class)->group('database');

beforeEach(function () {
    $this->seed = true;
    $this->seeder = DatabaseSeeder::class;
    setEnterpriseLookupSetting('url', 'https://example.com/lookup');
    setEnterpriseLookupSetting('appCode', 'TESTCODE', 'base64');
    setEnterpriseLookupSetting('queryField', 'name');
    setEnterpriseLookupSetting('fieldMap', [
        'name' => 'data.companyName',
        'registration_number' => 'data.creditCode',
        'address' => 'data.regAddress',
        'state' => 'data.province',
        'city' => 'data.city',
        'legal_person' => 'data.legalPerson',
    ], 'array');
    Cache::flush();
});

test('lookup maps fields via field_map', function () {
    Http::fake(['*' => Http::response([
        'data' => [
            'companyName' => '示例企业',
            'creditCode' => '91110000XX01',
            'regAddress' => '北京海淀',
            'province' => '北京',
            'city' => '北京',
            'legalPerson' => '张三',
        ],
    ], 200)]);
    $r = app(AliyunDriver::class)->lookup('示例企业');
    expect($r['name'])->toBe('示例企业')
        ->and($r['registration_number'])->toBe('91110000XX01')
        ->and($r['address'])->toBe('北京海淀')
        ->and($r['state'])->toBe('北京')
        ->and($r['legal_person'])->toBe('张三');
});

test('lookup caches result for 24h', function () {
    Http::fake(['*' => Http::response(['data' => ['companyName' => 'A', 'creditCode' => 'X', 'regAddress' => 'Y']], 200)]);
    app(AliyunDriver::class)->lookup('A');
    Http::fake(['*' => Http::response(['data' => ['companyName' => 'CHANGED', 'creditCode' => 'Z', 'regAddress' => 'W']], 200)]);
    $r = app(AliyunDriver::class)->lookup('A');
    expect($r['name'])->toBe('A');  // 命中缓存，未走第二次 HTTP
});

test('lookup throws LookupException when all required fields are null', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);
    app(AliyunDriver::class)->lookup('未知企业');
})->throws(LookupException::class);

test('lookup throws LookupException on http error', function () {
    Http::fake(['*' => Http::response('', 500)]);
    app(AliyunDriver::class)->lookup('X');
})->throws(LookupException::class);

test('failed lookup caches negative result for 1h', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);
    try {
        app(AliyunDriver::class)->lookup('NOT_FOUND');
    } catch (LookupException $e) {
    }
    Http::fake(['*' => Http::response(['data' => ['companyName' => 'NOW_FOUND', 'creditCode' => 'X', 'regAddress' => 'Y']], 200)]);
    $threw = false;
    try {
        app(AliyunDriver::class)->lookup('NOT_FOUND');
    } catch (LookupException $e) {
        $threw = true;
    }
    expect($threw)->toBeTrue('应抛 LookupException（命中失败缓存）');
});

test('lookup throws LookupException when url is empty', function () {
    setEnterpriseLookupSetting('url', '');
    app(AliyunDriver::class)->lookup('X');
})->throws(LookupException::class, '工商查询未配置');

test('lookup throws LookupException when appCode is empty', function () {
    setEnterpriseLookupSetting('appCode', '', 'base64');
    app(AliyunDriver::class)->lookup('X');
})->throws(LookupException::class, '工商查询未配置');

test('queryField setting is sent as the HTTP query parameter name', function () {
    setEnterpriseLookupSetting('queryField', 'company');
    Http::fake(['*' => Http::response([
        'data' => ['companyName' => '示例', 'creditCode' => 'X', 'regAddress' => 'Y'],
    ], 200)]);

    app(AliyunDriver::class)->lookup('示例企业');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'company=')
            && ! str_contains($request->url(), 'name=示例')
            && ! str_contains($request->url(), 'name=%E7%A4%BA');  // urlencoded "示" 不应出现在 name=
    });
});

test('daily quota blocks call after limit reached and does not hit upstream', function () {
    setEnterpriseLookupSetting('dailyLimit', 2, 'integer');
    Http::fake(['*' => Http::response([
        'data' => ['companyName' => 'A', 'creditCode' => 'X', 'regAddress' => 'Y'],
    ], 200)]);

    app(AliyunDriver::class)->lookup('A');
    app(AliyunDriver::class)->lookup('B');

    $threw = false;
    try {
        app(AliyunDriver::class)->lookup('C');
    } catch (LookupException $e) {
        $threw = true;
        expect($e->getCode())->toBe(429)
            ->and($e->getMessage())->toContain('额度已用完');
    }
    expect($threw)->toBeTrue('第 3 次应被额度拦截');

    // 限额返回后,counter 不应被错误地多 +1(decrement 回退)
    expect((int) Cache::get('enterprise_lookup:daily:'.now()->format('Y-m-d')))->toBe(2);

    // 上游仅被前 2 次调用,第 3 次未发出请求
    Http::assertSentCount(2);
});

test('cache hit does not consume daily quota', function () {
    setEnterpriseLookupSetting('dailyLimit', 1, 'integer');
    Http::fake(['*' => Http::response([
        'data' => ['companyName' => 'A', 'creditCode' => 'X', 'regAddress' => 'Y'],
    ], 200)]);

    // 第 1 次:调上游,计数 +1
    app(AliyunDriver::class)->lookup('A');
    // 第 2~5 次:同企业,命中 24h 成功缓存,不计数
    app(AliyunDriver::class)->lookup('A');
    app(AliyunDriver::class)->lookup('A');
    app(AliyunDriver::class)->lookup('A');
    app(AliyunDriver::class)->lookup('A');

    expect((int) Cache::get('enterprise_lookup:daily:'.now()->format('Y-m-d')))->toBe(1);
    Http::assertSentCount(1);
});

test('dailyLimit zero is treated as unlimited', function () {
    setEnterpriseLookupSetting('dailyLimit', 0, 'integer');
    Http::fake(['*' => Http::response([
        'data' => ['companyName' => 'X', 'creditCode' => 'X', 'regAddress' => 'X'],
    ], 200)]);

    for ($i = 0; $i < 5; $i++) {
        app(AliyunDriver::class)->lookup("E$i");
    }

    // 0 视为无限制,计数 key 也不应创建
    expect(Cache::get('enterprise_lookup:daily:'.now()->format('Y-m-d')))->toBeNull();
});

test('429 quota exhaustion does not poison failure cache', function () {
    setEnterpriseLookupSetting('dailyLimit', 1, 'integer');
    Http::fake(['*' => Http::response([
        'data' => ['companyName' => 'OK', 'creditCode' => 'X', 'regAddress' => 'Y'],
    ], 200)]);

    app(AliyunDriver::class)->lookup('FIRST');

    // 第 2 次不同企业 — 配额已满,应抛 429
    try {
        app(AliyunDriver::class)->lookup('SECOND');
    } catch (LookupException $e) {
        expect($e->getCode())->toBe(429);
    }

    // 模拟次日重置:清空配额计数器,提升 limit
    Cache::forget('enterprise_lookup:daily:'.now()->format('Y-m-d'));
    setEnterpriseLookupSetting('dailyLimit', 10, 'integer');

    // 此时 SECOND 应能正常查询(未被失败缓存污染)
    $r = app(AliyunDriver::class)->lookup('SECOND');
    expect($r['name'])->toBe('OK');
});

test('fieldMap entry with empty source is skipped and yields null', function () {
    setEnterpriseLookupSetting('fieldMap', [
        'name' => 'data.companyName',
        'registration_number' => 'data.creditCode',
        'address' => 'data.regAddress',
        'legal_person' => '',  // 空 source — 应被跳过，结果为 null
    ], 'array');
    Http::fake(['*' => Http::response([
        'data' => [
            'companyName' => 'A', 'creditCode' => 'B', 'regAddress' => 'C',
            // 故意保留一个顶级值 — 验证 data_get($body, '') 不会被当成"返回整个 body"
            '' => 'SHOULD_NOT_LEAK',
        ],
    ], 200)]);

    $r = app(AliyunDriver::class)->lookup('A');
    expect($r['legal_person'])->toBeNull()
        ->and($r['name'])->toBe('A');
});
