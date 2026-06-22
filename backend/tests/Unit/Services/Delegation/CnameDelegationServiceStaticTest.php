<?php

use App\Services\Delegation\CnameDelegationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// config 驱动的静态方法需要 Laravel 容器（config helper）已 boot；
// 与同目录其他 Delegation 测试统一 traits + group，避免并行 worker 生命周期错配致 config 解析失败
uses(TestCase::class, RefreshDatabase::class)->group('database');

// ==================== getDelegationPrefixForCa（config 驱动） ====================

test('get delegation prefix for ca', function (string $ca, string $expected) {
    expect(CnameDelegationService::getDelegationPrefixForCa($ca))->toBe($expected);
})->with([
    'Sectigo' => ['Sectigo', '_pki-validation'],
    'sectigo小写' => ['sectigo', '_pki-validation'],
    // comodo 不在 ca_map（用户定稿移除 sectigo 旧名），回落 default _dnsauth
    'Comodo回落default' => ['Comodo', '_dnsauth'],
    'Certum' => ['Certum', '_certum'],
    'DigiCert' => ['DigiCert', '_dnsauth'],
    'digicert小写' => ['digicert', '_dnsauth'],
    'GlobalSign' => ['GlobalSign', '_dnsauth'],
    'TrustAsia' => ['TrustAsia', '_dnsauth'],
    'Sheca' => ['sheca', '_dnsauth'],
    'CFCA' => ['cfca', '_dnsauth'],
    'Wotrus' => ['wotrus', '_dnsauth'],
    // 未配置的 CA 一律回落 default
    'GeoTrust回落default' => ['GeoTrust', '_dnsauth'],
    'LetsEncrypt回落default' => ['LetsEncrypt', '_dnsauth'],
    'ZeroSSL回落default' => ['ZeroSSL', '_dnsauth'],
    '未知CA' => ['Unknown', '_dnsauth'],
]);

/**
 * 未知 CA 返回 default 前缀（_dnsauth）
 */
test('unknown ca returns default prefix', function (string $ca) {
    expect(CnameDelegationService::getDelegationPrefixForCa($ca))->toBe('_dnsauth');
})->with([
    'LetsEncrypt' => ['LetsEncrypt'],
    'ZeroSSL' => ['ZeroSSL'],
    '空CA' => [''],
]);

/**
 * config 覆盖 default prefix 后，未知 CA 跟随 default
 */
test('unknown ca follows overridden default prefix', function () {
    config(['delegation.default.prefix' => '_pki-validation']);

    expect(CnameDelegationService::getDelegationPrefixForCa('unknown'))->toBe('_pki-validation');
    // 已配置的 ca 不受 default 影响
    expect(CnameDelegationService::getDelegationPrefixForCa('certum'))->toBe('_certum');
});
