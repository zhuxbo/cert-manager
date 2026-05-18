<?php

use App\Services\ZipcodeLookup\ZipcodeLookup;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    ZipcodeLookup::resetCache();
});

test('matches regionname at district precision (区/县)', function () {
    $r = app(ZipcodeLookup::class)->find('河南省南阳市卧龙区');
    expect($r)->not->toBeNull()
        ->and($r['zipcode'])->toBe('473000')
        ->and($r['province'])->toBe('河南省')
        ->and($r['city'])->toBe('南阳市')
        ->and($r['district'])->toBe('卧龙区');
});

test('returns null when regionname is empty', function () {
    expect(app(ZipcodeLookup::class)->find(''))->toBeNull();
    expect(app(ZipcodeLookup::class)->find(null))->toBeNull();
});

test('regionname pointing to a 县级市 — city 字段填县级市,district 留空', function () {
    $r = app(ZipcodeLookup::class)->find('河南省南阳市邓州市');
    expect($r)->not->toBeNull()
        ->and($r['zipcode'])->toBe('474150')
        ->and($r['city'])->toBe('邓州市')
        ->and($r['district'])->toBe('');
});

test('regionname only to 地级市 + companyName 含县级市 → city 填县级市', function () {
    $r = app(ZipcodeLookup::class)->find('浙江省金华市', '义乌市鸿运商贸有限公司');
    expect($r)->not->toBeNull()
        ->and($r['zipcode'])->toBe('322000')
        ->and($r['city'])->toBe('义乌市')
        ->and($r['district'])->toBe('');
});

test('companyName 含县级市去后缀名也命中 (义乌 而非 义乌市)', function () {
    $r = app(ZipcodeLookup::class)->find('浙江省金华市', '义乌鸿运商贸有限公司');
    expect($r['city'])->toBe('义乌市');
});

test('regionname 已精确到区 + 公司名含同 city 下县级市 → 用县级市覆盖', function () {
    // 例:工商响应到了 婺城区(金华下),但公司名实际是义乌的
    $r = app(ZipcodeLookup::class)->find('浙江省金华市婺城区', '义乌某商贸');
    expect($r['city'])->toBe('义乌市')
        ->and($r['district'])->toBe('');
});

test('公司名含 X 但 regionname 在另一省 → 不会误判跨省县级市', function () {
    // 重庆 下没有 义乌 这个县级市;即使公司名里有"义乌"也不会改 city
    $r = app(ZipcodeLookup::class)->find('重庆市渝中区', '重庆义乌商品城');
    expect($r)->not->toBeNull()
        ->and($r['city'])->toBe('重庆市')
        ->and($r['district'])->toBe('渝中区');
});

test('直辖市 regionname 两段(北京市朝阳区)正确命中', function () {
    $r = app(ZipcodeLookup::class)->find('北京市朝阳区');
    expect($r)->not->toBeNull()
        ->and($r['zipcode'])->toBe('100020')
        ->and($r['province'])->toBe('北京市')
        ->and($r['city'])->toBe('北京市')
        ->and($r['district'])->toBe('朝阳区');
});

test('regionname 只到地级市 + 无公司名 → 返回市级代表邮编(min)', function () {
    $r = app(ZipcodeLookup::class)->find('河南省南阳市');
    expect($r)->not->toBeNull()
        ->and($r['zipcode'])->toBe('473000')
        ->and($r['city'])->toBe('南阳市')
        ->and($r['district'])->toBe('');
});

test('未匹配的 regionname 返回 null', function () {
    expect(app(ZipcodeLookup::class)->find('火星共和国某某区'))->toBeNull();
});

test('regionname 仅 city(无 province)+ 公司名含县级市 → 仍能命中县级市', function () {
    // 真实场景:阿里云对县级市公司的响应可能缺 province / regionname,只给 city
    $r = app(ZipcodeLookup::class)->find('南阳市', '邓州市永泰棉纺股份有限公司');
    expect($r)->not->toBeNull()
        ->and($r['zipcode'])->toBe('474150')
        ->and($r['city'])->toBe('邓州市')
        ->and($r['district'])->toBe('');
});

test('regionname 仅 city 无公司名兜底 → 返回该地级市代表邮编', function () {
    $r = app(ZipcodeLookup::class)->find('南阳市');
    expect($r)->not->toBeNull()
        ->and($r['zipcode'])->toBe('473000')
        ->and($r['city'])->toBe('南阳市')
        ->and($r['district'])->toBe('');
});

test('static cache returns same data after multiple lookups', function () {
    $a = app(ZipcodeLookup::class)->find('河南省南阳市卧龙区');
    $b = app(ZipcodeLookup::class)->find('河南省南阳市卧龙区');
    expect($a)->toBe($b);
});
