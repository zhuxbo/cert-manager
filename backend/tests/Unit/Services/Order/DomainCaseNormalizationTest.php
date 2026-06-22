<?php

use App\Models\Product;
use App\Models\User;
use App\Services\Order\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('database');

// 域名大小写不敏感，getCert 必须对所有 CA 把提交的域名小写归一。
// 否则经 API / Deploy Token 入口提交的混合大小写域名（前端会 toLowerCase，API 不会）
// 会原样进入 alternative_names / common_name 并外发上游/CA。
test('非 Certum 域名提交混合大小写时归一为小写（new）', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'product_type' => 'ssl',
        'validation_type' => 'dv',
        'ca' => 'digicert',
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
        'channel' => 'api',
        'user_id' => $user->id,
        'product' => $product->toArray(),
        'domains' => 'Example.COM,Sub.Example.COM',
        'validation_method' => 'txt',
        'csr_generate' => 1,
        'encryption' => ['alg' => 'rsa', 'bits' => 2048],
    ]);

    expect($cert['alternative_names'])->toBe('example.com,sub.example.com');
    expect($cert['common_name'])->toBe('example.com');
    expect($cert['standard_count'])->toBe(2);
});

// 续费 replace_san=0 会把旧证书域名并入再 array_unique 去重。array_unique 大小写敏感，
// 若新提交是混合大小写（Example.COM）而旧证书是小写（example.com），二者去重不掉，
// 产生一个“幽灵 SAN” → standard_count 多算 1 → getLatestCertAmount 多扣一个标准域名价。
// 小写归一后两者相同、被正确去重，不多扣费。
test('续费 replace_san=0 时混合大小写不产生幽灵 SAN 多扣费（renew）', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'product_type' => 'ssl',
        'validation_type' => 'dv',
        'ca' => 'digicert',
        'validation_methods' => ['txt'],
        'encryption_alg' => ['rsa'],
        'signature_digest_alg' => ['sha256'],
        'reuse_csr' => 0,
        'replace_san' => 0,
        'add_san' => 1,
        'gift_root_domain' => 0,
        'standard_max' => 100,
        'wildcard_max' => 100,
        'total_max' => 100,
    ]);

    $action = app(Action::class);
    $cert = (new ReflectionMethod($action, 'getCert'))->invoke($action, [
        'params' => [],
        'action' => 'renew',
        'channel' => 'api',
        'user_id' => $user->id,
        'product' => $product->toArray(),
        'last_cert' => [
            'alternative_names' => 'example.com',
            'standard_count' => 1,
            'wildcard_count' => 0,
        ],
        'domains' => 'Example.COM',
        'validation_method' => 'txt',
        'csr_generate' => 1,
        'encryption' => ['alg' => 'rsa', 'bits' => 2048],
    ]);

    expect($cert['alternative_names'])->toBe('example.com');
    expect($cert['standard_count'])->toBe(1);
    expect($cert['wildcard_count'])->toBe(0);
});
