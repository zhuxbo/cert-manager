<?php

use App\Models\CnameDelegation;
use App\Services\Delegation\CnameDelegationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestData;

uses(TestCase::class, CreatesTestData::class, RefreshDatabase::class)->group('database');

beforeEach(function () {
    $this->seed = true;
    $this->seeder = DatabaseSeeder::class;
    $this->service = new CnameDelegationService;
});

// ==================== createOrGet ====================

test('create or get creates new delegation', function () {
    $user = $this->createTestUser();

    $delegation = $this->service->createOrGet($user->id, 'example.com', '_dnsauth');

    expect($delegation)->toBeInstanceOf(CnameDelegation::class);
    expect($delegation->user_id)->toBe($user->id);
    expect($delegation->zone)->toBe('example.com');
    expect($delegation->prefix)->toBe('_dnsauth');
    expect($delegation->label)->not->toBeEmpty();
    expect(strlen($delegation->label))->toBe(32);
    expect($delegation->valid)->toBeFalse();
});

test('create or get returns existing delegation', function () {
    $user = $this->createTestUser();

    $delegation1 = $this->service->createOrGet($user->id, 'example.com', '_dnsauth');
    $delegation2 = $this->service->createOrGet($user->id, 'example.com', '_dnsauth');

    expect($delegation2->id)->toBe($delegation1->id);
});

test('create or get normalizes domain to lowercase', function () {
    $user = $this->createTestUser();

    $delegation = $this->service->createOrGet($user->id, 'EXAMPLE.COM', '_dnsauth');

    expect($delegation->zone)->toBe('example.com');
});

test('create or get stores idn as unicode', function () {
    $user = $this->createTestUser();

    $delegation = $this->service->createOrGet($user->id, '中文.com', '_dnsauth');

    expect($delegation->zone)->toBe('中文.com');
});

test('create or get converts punycode input to unicode', function () {
    $user = $this->createTestUser();

    $delegation = $this->service->createOrGet($user->id, 'xn--fiq228c.com', '_dnsauth');

    expect($delegation->zone)->toBe('中文.com');
});

test('create or get generates unique label per user', function () {
    $user1 = $this->createTestUser();
    $user2 = $this->createTestUser();

    $delegation1 = $this->service->createOrGet($user1->id, 'example.com', '_dnsauth');
    $delegation2 = $this->service->createOrGet($user2->id, 'example.com', '_dnsauth');

    expect($delegation2->label)->not->toBe($delegation1->label);
});

test('create or get different prefixes create different delegations', function () {
    $user = $this->createTestUser();

    $delegation1 = $this->service->createOrGet($user->id, 'example.com', '_dnsauth');
    $delegation2 = $this->service->createOrGet($user->id, 'example.com', '_pki-validation');

    expect($delegation2->id)->not->toBe($delegation1->id);
});

// ==================== findDelegation（全 ca_map 驱动，传 ca 而非 prefix） ====================
//
// 重构说明：findDelegation/findValidDelegation 改为接收 ca（而非 prefix），
// 行为由 ca_map 的 exact 决定。当前 ca_map 全部 exact=false（用户定稿），
// 故所有 CA（含 _dnsauth 系 digicert）均为"子域优先 + 回落根域"。
// 原 `_dnsauth` 精确匹配语义改用 exact=true 覆盖单独测（见文件末尾）。

test('find delegation returns exact match', function () {
    $user = $this->createTestUser();
    $created = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
    ]);

    // digicert → _dnsauth
    $found = $this->service->findDelegation($user->id, 'example.com', 'digicert');

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($created->id);
});

test('find delegation strips wildcard prefix', function () {
    $user = $this->createTestUser();
    $created = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
    ]);

    $found = $this->service->findDelegation($user->id, '*.example.com', 'digicert');

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($created->id);
});

test('find delegation dnsauth ca falls back to root when not exact', function () {
    $user = $this->createTestUser();
    $created = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
    ]);

    // 有意语义变更：digicert（_dnsauth）现 exact=false，子域回落到根域委托
    $found = $this->service->findDelegation($user->id, 'sub.example.com', 'digicert');

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($created->id);
});

test('find delegation dnsauth ca normalizes www to root when not exact', function () {
    $user = $this->createTestUser();
    $created = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
    ]);

    // 有意语义变更：digicert（_dnsauth）现 exact=false，www.根域 归一回落根域委托
    $found = $this->service->findDelegation($user->id, 'www.example.com', 'digicert');

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($created->id);
});

test('find delegation other prefix falls back to root domain', function () {
    $user = $this->createTestUser();
    $created = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_pki-validation',
    ]);

    // sectigo → _pki-validation，子域回落根域（行为不变）
    $found = $this->service->findDelegation($user->id, 'sub.example.com', 'sectigo');

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($created->id);
});

test('find delegation prefers subdomain over root', function () {
    $user = $this->createTestUser();
    $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_pki-validation',
    ]);
    $subDelegation = $this->createTestDelegation($user, [
        'zone' => 'sub.example.com',
        'prefix' => '_pki-validation',
    ]);

    $found = $this->service->findDelegation($user->id, 'sub.example.com', 'sectigo');

    expect($found->id)->toBe($subDelegation->id);
});

test('find delegation returns null when not found', function () {
    $user = $this->createTestUser();

    $found = $this->service->findDelegation($user->id, 'notexist.com', 'digicert');

    expect($found)->toBeNull();
});

test('find delegation derives prefix from ca', function () {
    $user = $this->createTestUser();
    // 同 zone 不同 prefix，确认 ca 派生 prefix 正确隔离
    $sectigoDelegation = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_pki-validation',
    ]);
    $certumDelegation = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_certum',
    ]);

    expect($this->service->findDelegation($user->id, 'example.com', 'sectigo')->id)
        ->toBe($sectigoDelegation->id);
    expect($this->service->findDelegation($user->id, 'example.com', 'certum')->id)
        ->toBe($certumDelegation->id);
});

// ==================== findValidDelegation（传 ca，仅返回 valid=true） ====================

test('find valid delegation returns only valid', function () {
    $user = $this->createTestUser();
    $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
        'valid' => true,
    ]);

    $found = $this->service->findValidDelegation($user->id, 'example.com', 'digicert');

    expect($found)->not->toBeNull();
    expect($found->valid)->toBeTrue();
});

test('find valid delegation returns null for invalid', function () {
    $user = $this->createTestUser();
    $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
        'valid' => false,
    ]);

    $found = $this->service->findValidDelegation($user->id, 'example.com', 'digicert');

    expect($found)->toBeNull();
});

test('find valid delegation dnsauth ca normalizes www to root when not exact', function () {
    $user = $this->createTestUser();
    $created = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
        'valid' => true,
    ]);

    // 有意语义变更：digicert（_dnsauth）现 exact=false，www 归一回落根域
    $found = $this->service->findValidDelegation($user->id, 'www.example.com', 'digicert');

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($created->id);
});

// ==================== checkAndUpdateValidity ====================

test('check and update validity returns boolean', function () {
    $user = $this->createTestUser();
    $delegation = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
        'valid' => false,
        'fail_count' => 3,
    ]);

    // 实际调用会失败（因为没有真实的 CNAME 记录）
    // 这个测试验证方法能正常执行并返回布尔值
    $result = $this->service->checkAndUpdateValidity($delegation);

    expect($result)->toBeBool();
    expect($delegation->last_checked_at)->not->toBeNull();
});

test('check and update validity failure increments fail count', function () {
    $user = $this->createTestUser();
    $delegation = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
        'valid' => true,
        'fail_count' => 0,
    ]);

    // 实际调用会失败（因为没有真实的 CNAME 记录）
    $result = $this->service->checkAndUpdateValidity($delegation);

    $delegation->refresh();
    expect($result)->toBeFalse();
    expect($delegation->valid)->toBeFalse();
    expect($delegation->fail_count)->toBeGreaterThan(0);
});

test('check and update validity caps fail count at 100', function () {
    $user = $this->createTestUser();

    // 99 → 失败一次 → 100
    $d99 = $this->createTestDelegation($user, [
        'zone' => 'example99.com',
        'prefix' => '_dnsauth',
        'valid' => true,
        'fail_count' => 99,
    ]);
    $this->service->checkAndUpdateValidity($d99);
    $d99->refresh();
    expect($d99->fail_count)->toBe(100);

    // 100 → 失败一次 → 仍然 100（不会溢出到 101，防 TINYINT 越界）
    $d100 = $this->createTestDelegation($user, [
        'zone' => 'example100.com',
        'prefix' => '_dnsauth',
        'valid' => true,
        'fail_count' => 100,
    ]);
    $this->service->checkAndUpdateValidity($d100);
    $d100->refresh();
    expect($d100->fail_count)->toBe(100);
});

// ==================== withCnameGuide ====================

test('with cname guide', function () {
    $user = $this->createTestUser();
    $delegation = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
    ]);

    $result = $this->service->withCnameGuide($delegation);

    expect($result)->toHaveKey('cname_to');
    expect($result['cname_to']['host'])->toBe('_dnsauth.example.com');
    // value 依赖系统设置 delegation.proxyZone，可能为空
    expect($result['cname_to'])->toHaveKey('value');
});

// ==================== update ====================

test('update regen label', function () {
    $user = $this->createTestUser();
    $delegation = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
        'valid' => true,
    ]);
    $oldLabel = $delegation->label;

    $updated = $this->service->update($user->id, $delegation->id, ['regen_label' => true]);

    // 注意：由于 label 生成使用相同的输入，新 label 可能相同
    // 但 valid 应该被重置
    expect($updated->valid)->toBeFalse();
    expect($updated->fail_count)->toBe(0);
});

test('update throws exception for other user', function () {
    $user1 = $this->createTestUser();
    $user2 = $this->createTestUser();
    $delegation = $this->createTestDelegation($user1, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
    ]);

    $this->service->update($user2->id, $delegation->id, ['regen_label' => true]);
})->throws(ModelNotFoundException::class);

// ==================== findExact（精确 zone+prefix 对，无推断/回落） ====================

test('find exact matches exact zone and prefix', function () {
    $user = $this->createTestUser();
    $created = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
    ]);

    $found = $this->service->findExact($user->id, 'example.com', '_dnsauth');

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($created->id);
});

test('find exact does not fall back to root domain', function () {
    $user = $this->createTestUser();
    $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_pki-validation',
    ]);

    // findExact 永不回落：子域查不到根域记录
    $found = $this->service->findExact($user->id, 'sub.example.com', '_pki-validation');

    expect($found)->toBeNull();
});

test('find exact does not normalize www', function () {
    $user = $this->createTestUser();
    $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_pki-validation',
    ]);

    // findExact 不做 www 归一：www.example.com 查不到 example.com 记录
    $found = $this->service->findExact($user->id, 'www.example.com', '_pki-validation');

    expect($found)->toBeNull();
});

test('find exact respects only valid flag', function () {
    $user = $this->createTestUser();
    $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
        'valid' => false,
    ]);

    expect($this->service->findExact($user->id, 'example.com', '_dnsauth', false))->not->toBeNull();
    expect($this->service->findExact($user->id, 'example.com', '_dnsauth', true))->toBeNull();
});

// ==================== getDelegationPrefixForCa / isExactForCa（config 驱动） ====================

test('get delegation prefix for ca reads config', function () {
    expect(CnameDelegationService::getDelegationPrefixForCa('sectigo'))->toBe('_pki-validation');
    expect(CnameDelegationService::getDelegationPrefixForCa('certum'))->toBe('_certum');
    expect(CnameDelegationService::getDelegationPrefixForCa('digicert'))->toBe('_dnsauth');
});

test('get delegation prefix for ca falls back to default for unknown ca', function () {
    expect(CnameDelegationService::getDelegationPrefixForCa('unknown'))->toBe('_dnsauth');
    expect(CnameDelegationService::getDelegationPrefixForCa(''))->toBe('_dnsauth');
    // comodo 不在 ca_map（用户定稿移除），回落 default _dnsauth
    expect(CnameDelegationService::getDelegationPrefixForCa('comodo'))->toBe('_dnsauth');
});

test('is exact for ca defaults to false for all configured cas', function () {
    expect($this->service->isExactForCa('sectigo'))->toBeFalse();
    expect($this->service->isExactForCa('certum'))->toBeFalse();
    expect($this->service->isExactForCa('digicert'))->toBeFalse();
    expect($this->service->isExactForCa('globalsign'))->toBeFalse();
});

test('is exact for ca falls back to default for unknown ca', function () {
    // 未知 ca → config 返回 null → 回落 default.exact (false)
    expect($this->service->isExactForCa('unknown'))->toBeFalse();
    expect($this->service->isExactForCa(''))->toBeFalse();
});

test('is exact for ca honors env override true', function () {
    // 在测试内覆盖 config，验证 exact=true 分支被读取（false ?? x 不回落、配置值直读）
    config(['delegation.ca_map.digicert.exact' => true]);

    expect($this->service->isExactForCa('digicert'))->toBeTrue();
    // 其他未覆盖的仍 false
    expect($this->service->isExactForCa('sectigo'))->toBeFalse();
});

test('is exact for ca honors default override true for unknown ca', function () {
    config(['delegation.default.exact' => true]);

    // 未知 ca → config 返回 null → 回落 default.exact (now true)
    expect($this->service->isExactForCa('unknown'))->toBeTrue();
    // 已配置的 ca（值仍为 false）不受 default 影响
    expect($this->service->isExactForCa('sectigo'))->toBeFalse();
});

// ==================== supportedPrefixes（config 派生白名单） ====================

test('supported prefixes derives from config and dedups', function () {
    $prefixes = CnameDelegationService::supportedPrefixes();

    // 包含三种实际前缀（多个 _dnsauth 系 ca 去重后仅一个）
    expect($prefixes)->toContain('_pki-validation');
    expect($prefixes)->toContain('_certum');
    expect($prefixes)->toContain('_dnsauth');
    // _dnsauth 去重：6 家显式 + default 都是 _dnsauth，只出现一次
    expect(array_count_values($prefixes)['_dnsauth'])->toBe(1);
});

// ==================== resolveZone（创建期 zone 解析，全 ca_map 驱动） ====================

test('resolve zone returns root domain for non exact ca', function () {
    // sectigo（_pki-validation, exact=false）→ 子域取根域
    expect($this->service->resolveZone('sub.example.com', 'sectigo'))->toBe('example.com');
    // digicert（_dnsauth, exact=false）→ 子域取根域（有意语义变更）
    expect($this->service->resolveZone('sub.example.com', 'digicert'))->toBe('example.com');
});

test('resolve zone normalizes www to root for non exact ca', function () {
    expect($this->service->resolveZone('www.example.com', 'sectigo'))->toBe('example.com');
    expect($this->service->resolveZone('www.example.com', 'digicert'))->toBe('example.com');
});

test('resolve zone strips wildcard prefix', function () {
    expect($this->service->resolveZone('*.example.com', 'sectigo'))->toBe('example.com');
    expect($this->service->resolveZone('*.example.com', 'digicert'))->toBe('example.com');
});

test('resolve zone converts punycode to unicode', function () {
    expect($this->service->resolveZone('xn--fiq228c.com', 'sectigo'))->toBe('中文.com');
});

test('resolve zone returns exact domain for exact ca', function () {
    config(['delegation.ca_map.digicert.exact' => true]);

    // exact=true：精确域名，不取根域、不归一 www
    expect($this->service->resolveZone('sub.example.com', 'digicert'))->toBe('sub.example.com');
    expect($this->service->resolveZone('www.example.com', 'digicert'))->toBe('www.example.com');
    // 通配符仍去除
    expect($this->service->resolveZone('*.example.com', 'digicert'))->toBe('example.com');
});

// ==================== exact=true 精确匹配（覆盖 config，拒绝回落） ====================

test('find delegation exact ca rejects root fallback', function () {
    config(['delegation.ca_map.digicert.exact' => true]);

    $user = $this->createTestUser();
    $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
    ]);

    // exact=true：子域查不到根域委托（拒绝回落）
    expect($this->service->findDelegation($user->id, 'sub.example.com', 'digicert'))->toBeNull();
    // 精确域名能命中
    expect($this->service->findDelegation($user->id, 'example.com', 'digicert'))->not->toBeNull();
});

test('find delegation exact ca does not normalize www', function () {
    config(['delegation.ca_map.digicert.exact' => true]);

    $user = $this->createTestUser();
    $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
    ]);

    // exact=true：www.example.com 不归一，查不到 example.com 记录
    expect($this->service->findDelegation($user->id, 'www.example.com', 'digicert'))->toBeNull();
});

// ==================== 同前缀不同 exact：行为由 ca 而非 prefix 决定 ====================

test('same prefix different exact behaves by ca not prefix', function () {
    // digicert 与 globalsign 同为 _dnsauth；覆盖 digicert=exact、globalsign 保持 false
    config(['delegation.ca_map.digicert.exact' => true]);
    config(['delegation.ca_map.globalsign.exact' => false]);

    $user = $this->createTestUser();
    $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
    ]);

    // 同一 _dnsauth 根域委托 + 同一子域查询：
    // digicert(exact=true) 拒绝回落 → null
    expect($this->service->findDelegation($user->id, 'sub.example.com', 'digicert'))->toBeNull();
    // globalsign(exact=false) 回落根域 → 命中
    expect($this->service->findDelegation($user->id, 'sub.example.com', 'globalsign'))->not->toBeNull();
});

test('same prefix different exact resolve zone behaves by ca not prefix', function () {
    config(['delegation.ca_map.digicert.exact' => true]);
    config(['delegation.ca_map.globalsign.exact' => false]);

    // digicert(exact=true) → 精确子域；globalsign(exact=false) → 根域；二者同 prefix=_dnsauth
    expect($this->service->resolveZone('sub.example.com', 'digicert'))->toBe('sub.example.com');
    expect($this->service->resolveZone('sub.example.com', 'globalsign'))->toBe('example.com');
});
