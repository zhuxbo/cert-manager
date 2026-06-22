<?php

use App\Services\Delegation\AutoDcvTxtService;
use Tests\TestCase;

// splitPrefixAndZone 现走 config 驱动的 CnameDelegationService::supportedPrefixes()，
// 需 Laravel 容器（config helper）已 boot；此文件无 DB 依赖故只挂 TestCase（不挂 RefreshDatabase）
uses(TestCase::class);

beforeEach(function () {
    $this->service = new AutoDcvTxtService;
});

// ==================== allTxtRecordsProcessed ====================

test('all txt records processed', function (array $validation, bool $expected) {
    $result = $this->service->allTxtRecordsProcessed($validation);
    expect($result)->toBe($expected);
})->with([
    '空数组' => [[], true],
    '全部已处理' => [
        [
            ['auto_txt_written' => true],
            ['auto_txt_written' => true],
        ],
        true,
    ],
    '部分已处理' => [
        [
            ['auto_txt_written' => true],
            ['auto_txt_written' => false],
        ],
        false,
    ],
    '无标记' => [
        [
            ['host' => 'example.com'],
        ],
        false,
    ],
    '标记为false' => [
        [
            ['auto_txt_written' => false],
        ],
        false,
    ],
    '单个已处理' => [
        [
            ['auto_txt_written' => true],
        ],
        true,
    ],
    '多个未处理' => [
        [
            ['host' => 'a.com'],
            ['host' => 'b.com'],
        ],
        false,
    ],
]);

// ==================== splitPrefixAndZone ====================

test('split prefix and zone', function (string $host, ?string $expectedPrefix, ?string $expectedZone) {
    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('splitPrefixAndZone');

    [$prefix, $zone] = $method->invoke($this->service, $host);

    expect($prefix)->toBe($expectedPrefix);
    expect($zone)->toBe($expectedZone);
})->with([
    '_dnsauth' => ['_dnsauth.example.com', '_dnsauth', 'example.com'],
    '_pki-validation' => ['_pki-validation.example.com', '_pki-validation', 'example.com'],
    '_certum' => ['_certum.example.com', '_certum', 'example.com'],
    '子域名' => ['_dnsauth.sub.example.com', '_dnsauth', 'sub.example.com'],
    '多级子域名' => ['_dnsauth.a.b.example.com', '_dnsauth', 'a.b.example.com'],
    '不支持的前缀' => ['_unknown.example.com', null, null],
    '_acme-challenge不再支持' => ['_acme-challenge.example.com', null, null],
    '太短' => ['_dnsauth.com', null, null],
    '无前缀' => ['example.com', null, null],
    '大写转换' => ['_DNSAUTH.EXAMPLE.COM', '_dnsauth', 'example.com'],
    '仅前缀' => ['_dnsauth', null, null],
    '两级域名' => ['_dnsauth.co', null, null],
]);
