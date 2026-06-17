<?php

use App\Services\Order\Utils\DomainUtil;
use Tests\TestCase;

uses(TestCase::class);

// addGiftDomain 必须与输入域名的编码无关：
// - punycode 根域 → 补 punycode 的 www（不被转成中文）
// - Unicode 根域 → 补 Unicode 的 www
// 历史实现用 `$domain === getRootDomain($domain)` 判断根域，而 getRootDomain
// 无条件把根域转成 Unicode，导致 punycode 输入比较失配、赠送的 www 子域丢失。
// 该缺陷在“getCert 无条件先转 Unicode”时被掩盖；改为仅 Certum 转 Unicode 后，
// 非 Certum 的 punycode IDN 域名会丢失 gift_root_domain 赠送的 www。

test('punycode 根域补齐 punycode www（编码不被转成中文）', function () {
    expect(DomainUtil::addGiftDomain('xn--fiq228c.com'))
        ->toBe('xn--fiq228c.com,www.xn--fiq228c.com');
});

test('punycode www 子域补齐 punycode 根域', function () {
    expect(DomainUtil::addGiftDomain('www.xn--fiq228c.com'))
        ->toBe('www.xn--fiq228c.com,xn--fiq228c.com');
});

test('Unicode 根域补齐 Unicode www（Certum 路径不回归）', function () {
    $uni = idn_to_utf8('xn--fiq228c.com');
    expect(DomainUtil::addGiftDomain($uni))->toBe("$uni,www.$uni");
});

test('ASCII 根域补齐 www（不受影响）', function () {
    expect(DomainUtil::addGiftDomain('example.com'))
        ->toBe('example.com,www.example.com');
});
