<?php

use App\Http\Middleware\DynamicCors;
use Tests\TestCase;

uses(TestCase::class);

/*
 * 审核 #22：证书下载端点（ActionFileTrait::downFlow）曾把请求 Origin 原样回显到
 * Access-Control-Allow-Origin（无 Origin 时回落 '*'），绕过统一 CORS 白名单。
 * 修复后 downFlow 复用 DynamicCors::isAllowedOrigin 白名单网关 —— 只有命中白名单的
 * Origin 才会被回显。本测试锁定该网关的安全语义：任意/非白名单 Origin 必须被拒绝。
 *
 * 契约：白名单是「host-only」逗号列表（与 config/cors.php 的 ALLOWED_ORIGINS 一致，
 * 默认 localhost），isAllowedOrigin 先 parse_url 取请求 Origin 的 host 再与白名单 host
 * 模式匹配。`*.domain` 匹配任意级子域。
 */

$allowed = 'app.example.com,admin.example.com,*.user.example.com';

test('白名单内的精确 Origin 通过', function () use ($allowed) {
    expect(DynamicCors::isAllowedOrigin('https://app.example.com', $allowed))->toBeTrue();
    expect(DynamicCors::isAllowedOrigin('https://admin.example.com', $allowed))->toBeTrue();
});

test('通配子域匹配 *. 规则', function () use ($allowed) {
    expect(DynamicCors::isAllowedOrigin('https://a.user.example.com', $allowed))->toBeTrue();
    expect(DynamicCors::isAllowedOrigin('https://deep.nested.user.example.com', $allowed))->toBeTrue();
    // *.user.example.com 的 ([a-z0-9-]+\.)* 允许零级前缀，顶域 user.example.com 也命中
    // （白名单了某域的通配即许可其顶域本身，属同域、无安全外溢）
    expect(DynamicCors::isAllowedOrigin('https://user.example.com', $allowed))->toBeTrue();
});

test('任意攻击者 Origin 不被回显（核心安全断言）', function () use ($allowed) {
    expect(DynamicCors::isAllowedOrigin('https://evil.com', $allowed))->toBeFalse();
    expect(DynamicCors::isAllowedOrigin('https://attacker.example.com.evil.com', $allowed))->toBeFalse();
    // 子串/前后缀混淆不得绕过：必须整体匹配
    expect(DynamicCors::isAllowedOrigin('https://app.example.com.evil.com', $allowed))->toBeFalse();
    expect(DynamicCors::isAllowedOrigin('https://notapp.example.com', $allowed))->toBeFalse();
    expect(DynamicCors::isAllowedOrigin('https://user.example.com.evil.com', $allowed))->toBeFalse();
});

test('畸形 / 空 Origin 一律拒绝', function () use ($allowed) {
    expect(DynamicCors::isAllowedOrigin('', $allowed))->toBeFalse();
    expect(DynamicCors::isAllowedOrigin('not-a-url', $allowed))->toBeFalse();
    expect(DynamicCors::isAllowedOrigin('null', $allowed))->toBeFalse();
});

test('空白名单时任何 Origin 都被拒绝（绝不回落通配）', function () {
    expect(DynamicCors::isAllowedOrigin('https://app.example.com', ''))->toBeFalse();
    expect(DynamicCors::isAllowedOrigin('https://anything.com', ''))->toBeFalse();
});
