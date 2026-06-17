<?php

use App\Models\Product;
use App\Models\User;
use App\Services\Order\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('database');

// getCert 内 punycode→中文 的强制转换应仅对 product.ca === 'certum' 生效，
// 其他 CA 保持用户提交的原样（punycode 仍是 punycode），不再被转成中文。
//
// 用多域名输入，commonName（domains[0]）固定为 ASCII 的 example.com，
// 把待转换的 IDN 标签放在 SAN，避免依赖"Unicode CN 能否生成 CSR"的环境差异。
test('punycode→中文 转换仅对 product.ca === certum 生效', function (string $ca, string $expectedAltNames) {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'product_type' => 'ssl',
        'validation_type' => 'dv',
        'ca' => $ca,
        'validation_methods' => ['txt'],
        'encryption_alg' => ['rsa'],
        'signature_digest_alg' => ['sha256'],
        'reuse_csr' => 0,
        'gift_root_domain' => 0,
        'standard_max' => 100,
        'wildcard_max' => 100,
        'total_max' => 100,
    ]);

    $action = app(Action::class);
    $cert = (new ReflectionMethod($action, 'getCert'))->invoke($action, [
        'params' => [],
        'action' => 'new',
        'channel' => 'admin',
        'user_id' => $user->id,
        'product' => $product->toArray(),
        'domains' => 'example.com,xn--fiq228c.com',
        'validation_method' => 'txt',
        'csr_generate' => 1,
        'encryption' => ['alg' => 'rsa', 'bits' => 2048],
    ]);

    expect($cert['alternative_names'])->toBe($expectedAltNames);
    expect($cert['common_name'])->toBe('example.com');
})->with([
    'certum 转中文' => ['certum', 'example.com,中文.com'],
    'digicert 保持 punycode' => ['digicert', 'example.com,xn--fiq228c.com'],
]);
