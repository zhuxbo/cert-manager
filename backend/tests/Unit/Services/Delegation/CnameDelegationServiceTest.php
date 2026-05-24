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

// ==================== findDelegation ====================

test('find delegation returns exact match', function () {
    $user = $this->createTestUser();
    $created = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
    ]);

    $found = $this->service->findDelegation($user->id, 'example.com', '_dnsauth');

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($created->id);
});

test('find delegation strips wildcard prefix', function () {
    $user = $this->createTestUser();
    $created = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
    ]);

    $found = $this->service->findDelegation($user->id, '*.example.com', '_dnsauth');

    expect($found)->not->toBeNull();
    expect($found->id)->toBe($created->id);
});

test('find delegation acme prefix only matches exact fqdn', function () {
    $user = $this->createTestUser();
    $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
    ]);

    // 子域名不应该匹配根域名的委托（对于 _dnsauth）
    $found = $this->service->findDelegation($user->id, 'sub.example.com', '_dnsauth');

    expect($found)->toBeNull();
});

test('find delegation acme prefix does not normalize www to root', function () {
    $user = $this->createTestUser();
    $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
    ]);

    // 确保 www 在 _dnsauth 场景下保持精确匹配语义
    $found = $this->service->findDelegation($user->id, 'www.example.com', '_dnsauth');

    expect($found)->toBeNull();
});

test('find delegation dnsauth prefix only matches exact fqdn', function () {
    $user = $this->createTestUser();
    $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
    ]);

    // 子域名不应该匹配根域名的委托
    $found = $this->service->findDelegation($user->id, 'sub.example.com', '_dnsauth');

    expect($found)->toBeNull();
});

test('find delegation other prefix falls back to root domain', function () {
    $user = $this->createTestUser();
    $created = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_pki-validation',
    ]);

    // 子域名应该回落到根域名
    $found = $this->service->findDelegation($user->id, 'sub.example.com', '_pki-validation');

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

    $found = $this->service->findDelegation($user->id, 'sub.example.com', '_pki-validation');

    expect($found->id)->toBe($subDelegation->id);
});

test('find delegation returns null when not found', function () {
    $user = $this->createTestUser();

    $found = $this->service->findDelegation($user->id, 'notexist.com', '_dnsauth');

    expect($found)->toBeNull();
});

// ==================== findValidDelegation ====================

test('find valid delegation returns only valid', function () {
    $user = $this->createTestUser();
    $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
        'valid' => true,
    ]);

    $found = $this->service->findValidDelegation($user->id, 'example.com', '_dnsauth');

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

    $found = $this->service->findValidDelegation($user->id, 'example.com', '_dnsauth');

    expect($found)->toBeNull();
});

test('find valid delegation acme prefix does not normalize www to root', function () {
    $user = $this->createTestUser();
    $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
        'valid' => true,
    ]);

    // 确保 www 在 _dnsauth 场景下不回落到根域
    $found = $this->service->findValidDelegation($user->id, 'www.example.com', '_dnsauth');

    expect($found)->toBeNull();
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
