<?php

use App\Services\Delegation\AutoDcvTxtService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestData;

uses(TestCase::class, CreatesTestData::class, RefreshDatabase::class)->group('database');

beforeEach(function () {
    $this->seed = true;
    $this->seeder = DatabaseSeeder::class;
    $this->service = new AutoDcvTxtService;
});

// ==================== handleOrder ====================

test('handle order returns false when dcv method not txt', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, [
        'dcv' => ['method' => 'http', 'dns' => ['host' => '_dnsauth']],
    ]);

    $order->refresh();
    $result = $this->service->handleOrder($order);

    expect($result)->toBeFalse();
});

test('handle order returns false when validation empty', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, [
        'dcv' => ['method' => 'txt', 'dns' => ['host' => '_dnsauth']],
        'validation' => [],
    ]);

    $order->refresh();
    $result = $this->service->handleOrder($order);

    expect($result)->toBeFalse();
});

test('handle order returns true when all processed', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, [
        'dcv' => ['method' => 'txt', 'is_delegate' => true, 'dns' => ['host' => '_dnsauth']],
        'validation' => [
            [
                'host' => '_dnsauth.example.com',
                'domain' => 'example.com',
                'value' => 'token123',
                'auto_txt_written' => true,
            ],
        ],
    ]);

    $order->refresh();
    $result = $this->service->handleOrder($order);

    expect($result)->toBeTrue();
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
]);

// ==================== shouldProcessDelegation ====================

test('should process delegation returns false when no changes', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, [
        'dcv' => ['method' => 'txt', 'dns' => ['host' => '_dnsauth']],
        'validation' => [
            [
                'host' => '_dnsauth.example.com',
                'domain' => 'example.com',
                'value' => 'token123',
                'auto_txt_written' => true,
            ],
        ],
    ]);

    $order->refresh();
    $result = $this->service->shouldProcessDelegation($order);

    expect($result)->toBeFalse();
});

test('should process delegation returns true when has changes', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);

    // 创建委托记录
    $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
        'valid' => true,
    ]);

    $this->createTestCert($order, [
        'dcv' => ['method' => 'txt', 'dns' => ['host' => '_dnsauth']],
        'validation' => [
            [
                'host' => '_dnsauth.example.com',
                'domain' => 'example.com',
                'value' => 'token123',
            ],
        ],
    ]);

    $order->refresh();
    $result = $this->service->shouldProcessDelegation($order);

    expect($result)->toBeTrue();
});

// ==================== collectTxtRecords ====================

test('collect txt records skips already processed', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, [
        'dcv' => ['method' => 'txt', 'dns' => ['host' => '_dnsauth']],
        'validation' => [
            [
                'host' => '_dnsauth.example.com',
                'domain' => 'example.com',
                'value' => 'token123',
                'auto_txt_written' => true,
            ],
        ],
    ]);

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('collectTxtRecords');

    $order->refresh();
    [$txtRecords, $updatedValidation, $hasChanges] = $method->invoke($this->service, $order);

    expect($txtRecords)->toBeEmpty();
    expect($hasChanges)->toBeFalse();
});

test('collect txt records skips incomplete validation', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, [
        'dcv' => ['method' => 'txt', 'dns' => ['host' => '_dnsauth']],
        'validation' => [
            [
                'host' => '_dnsauth.example.com',
                // 缺少 domain 和 value
            ],
        ],
    ]);

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('collectTxtRecords');

    $order->refresh();
    [$txtRecords, $updatedValidation, $hasChanges] = $method->invoke($this->service, $order);

    expect($txtRecords)->toBeEmpty();
    expect($hasChanges)->toBeFalse();
});

test('collect txt records uses dcv host when validation host missing', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);

    // 创建委托记录
    $delegation = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
        'valid' => true,
    ]);

    $this->createTestCert($order, [
        'dcv' => ['method' => 'txt', 'dns' => ['host' => '_dnsauth']],
        'validation' => [
            [
                // host 缺失，依赖 dcv.dns.host 回退
                'domain' => 'example.com',
                'value' => 'token123',
            ],
        ],
    ]);

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('collectTxtRecords');

    $order->refresh();
    [$txtRecords, $updatedValidation, $hasChanges] = $method->invoke($this->service, $order);

    expect($txtRecords)->toHaveCount(1);
    expect($updatedValidation[0]['delegation_id'])->toBe($delegation->id);
    expect($updatedValidation[0]['auto_txt_written'])->toBeTrue();
    expect($hasChanges)->toBeTrue();
});

test('collect txt records expands prefix only host', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);

    // 创建委托记录
    $delegation = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
        'valid' => true,
    ]);

    $this->createTestCert($order, [
        'dcv' => ['method' => 'txt', 'dns' => ['host' => '_dnsauth']],
        'validation' => [
            [
                // 仅前缀，需补全域名
                'host' => '_dnsauth',
                'domain' => 'example.com',
                'value' => 'token123',
            ],
        ],
    ]);

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('collectTxtRecords');

    $order->refresh();
    [$txtRecords, $updatedValidation, $hasChanges] = $method->invoke($this->service, $order);

    expect($txtRecords)->toHaveCount(1);
    expect($updatedValidation[0]['delegation_id'])->toBe($delegation->id);
    expect($updatedValidation[0]['auto_txt_written'])->toBeTrue();
    expect($hasChanges)->toBeTrue();
});

test('collect txt records skips when missing host and dcv host', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);

    $this->createTestCert($order, [
        'dcv' => ['method' => 'txt'],
        'validation' => [
            [
                'domain' => 'example.com',
                'value' => 'token123',
            ],
        ],
    ]);

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('collectTxtRecords');

    $order->refresh();
    [$txtRecords, $updatedValidation, $hasChanges] = $method->invoke($this->service, $order);

    expect($txtRecords)->toBeEmpty();
    expect($hasChanges)->toBeFalse();
    expect($updatedValidation[0])->not->toHaveKey('auto_txt_written');
});

test('collect txt records groups by delegation', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);

    // 创建委托记录
    $delegation = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
        'valid' => true,
    ]);

    $this->createTestCert($order, [
        'dcv' => ['method' => 'txt', 'dns' => ['host' => '_dnsauth']],
        'validation' => [
            [
                'host' => '_dnsauth.example.com',
                'domain' => 'example.com',
                'value' => 'token1',
            ],
            [
                'host' => '_dnsauth.example.com',
                'domain' => 'example.com',
                'value' => 'token2',
            ],
        ],
    ]);

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('collectTxtRecords');

    $order->refresh();
    [$txtRecords, $updatedValidation, $hasChanges] = $method->invoke($this->service, $order);

    expect($txtRecords)->toHaveCount(1); // 按 delegation 分组
    expect($txtRecords[$delegation->id]['tokens'])->toHaveCount(2);
    expect($hasChanges)->toBeTrue();
});

test('collect txt records marks delegation id', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);

    // 创建委托记录
    $delegation = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
        'valid' => true,
    ]);

    $this->createTestCert($order, [
        'dcv' => ['method' => 'txt', 'dns' => ['host' => '_dnsauth']],
        'validation' => [
            [
                'host' => '_dnsauth.example.com',
                'domain' => 'example.com',
                'value' => 'token123',
            ],
        ],
    ]);

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('collectTxtRecords');

    $order->refresh();
    [$txtRecords, $updatedValidation, $hasChanges] = $method->invoke($this->service, $order);

    expect($updatedValidation[0]['delegation_id'])->toBe($delegation->id);
    expect($updatedValidation[0]['auto_txt_written'])->toBeTrue();
    expect($updatedValidation[0]['auto_txt_written_at'])->not->toBeEmpty();
});

test('collect txt records skips when no delegation found', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);
    // 不创建委托记录

    $this->createTestCert($order, [
        'dcv' => ['method' => 'txt', 'dns' => ['host' => '_dnsauth']],
        'validation' => [
            [
                'host' => '_dnsauth.example.com',
                'domain' => 'example.com',
                'value' => 'token123',
            ],
        ],
    ]);

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('collectTxtRecords');

    $order->refresh();
    [$txtRecords, $updatedValidation, $hasChanges] = $method->invoke($this->service, $order);

    expect($txtRecords)->toBeEmpty();
    expect($hasChanges)->toBeFalse();
    expect($updatedValidation[0])->not->toHaveKey('auto_txt_written');
});

// ==================== CA 驱动回落（修复退化 bug 的核心）====================

// 核心用例：回落型 CA（sectigo，exact=false）委托记录建在根域 example.com，
// 证书域名是子域 sub.example.com，DCV host 为 _pki-validation.sub.example.com。
// splitPrefixAndZone 得到 zone=sub.example.com（子域），必须按 ca 回落到根域委托。
// 修复前用 findExact(sub.example.com) → 漏匹配 → 静默跳过；
// 修复后用 findDelegation(sub.example.com, 'sectigo') → 回落根域 → 命中。
test('collect txt records falls back to root delegation for subdomain (sectigo)', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['ca' => 'sectigo']);
    $order = $this->createTestOrder($user, $product);

    // 委托记录建在根域（回落型 CA 一条覆盖所有子域）
    $delegation = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_pki-validation',
        'valid' => true,
    ]);

    // 证书域名是子域，DCV host 为子域 host
    $this->createTestCert($order, [
        'common_name' => 'sub.example.com',
        'alternative_names' => 'sub.example.com',
        'dcv' => ['method' => 'txt', 'is_delegate' => true, 'dns' => ['host' => '_pki-validation']],
        'validation' => [
            [
                'host' => '_pki-validation.sub.example.com',
                'domain' => 'sub.example.com',
                'value' => 'token-sub',
            ],
        ],
    ]);

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('collectTxtRecords');

    $order->refresh();
    [$txtRecords, $updatedValidation, $hasChanges] = $method->invoke($this->service, $order);

    // 回落命中根域委托
    expect($txtRecords)->toHaveCount(1);
    expect($txtRecords[$delegation->id]['delegation']->id)->toBe($delegation->id);
    expect($updatedValidation[0]['delegation_id'])->toBe($delegation->id);
    expect($updatedValidation[0]['auto_txt_written'])->toBeTrue();
    expect($hasChanges)->toBeTrue();
});

// 同样回落型 CA（certum，_certum 前缀），子域回落根域
test('collect txt records falls back to root delegation for subdomain (certum)', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['ca' => 'certum']);
    $order = $this->createTestOrder($user, $product);

    $delegation = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_certum',
        'valid' => true,
    ]);

    $this->createTestCert($order, [
        'common_name' => 'sub.example.com',
        'alternative_names' => 'sub.example.com',
        'dcv' => ['method' => 'txt', 'is_delegate' => true, 'dns' => ['host' => '_certum']],
        'validation' => [
            [
                'host' => '_certum.sub.example.com',
                'domain' => 'sub.example.com',
                'value' => 'token-certum',
            ],
        ],
    ]);

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('collectTxtRecords');

    $order->refresh();
    [$txtRecords, $updatedValidation, $hasChanges] = $method->invoke($this->service, $order);

    expect($txtRecords)->toHaveCount(1);
    expect($updatedValidation[0]['delegation_id'])->toBe($delegation->id);
    expect($updatedValidation[0]['auto_txt_written'])->toBeTrue();
    expect($hasChanges)->toBeTrue();
});

// 根域证书也能命中（不回落也对，回归保护）
test('collect txt records matches root delegation for root domain (sectigo)', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['ca' => 'sectigo']);
    $order = $this->createTestOrder($user, $product);

    $delegation = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_pki-validation',
        'valid' => true,
    ]);

    $this->createTestCert($order, [
        'common_name' => 'example.com',
        'alternative_names' => 'example.com',
        'dcv' => ['method' => 'txt', 'is_delegate' => true, 'dns' => ['host' => '_pki-validation']],
        'validation' => [
            [
                'host' => '_pki-validation.example.com',
                'domain' => 'example.com',
                'value' => 'token-root',
            ],
        ],
    ]);

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('collectTxtRecords');

    $order->refresh();
    [$txtRecords, $updatedValidation, $hasChanges] = $method->invoke($this->service, $order);

    expect($txtRecords)->toHaveCount(1);
    expect($updatedValidation[0]['delegation_id'])->toBe($delegation->id);
    expect($hasChanges)->toBeTrue();
});

// exact=true 的 CA（用 config 覆盖 digicert 为 exact）：子域不回落根域 → miss
// 证明 CA 驱动语义被正确传导（exact 行为与 findDelegation 一致）
test('collect txt records does not fall back for exact ca subdomain', function () {
    config()->set('delegation.ca_map.digicert.exact', true);

    $user = $this->createTestUser();
    $product = $this->createTestProduct(['ca' => 'digicert']);
    $order = $this->createTestOrder($user, $product);

    // 委托建在根域，但 exact CA 不回落
    $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'prefix' => '_dnsauth',
        'valid' => true,
    ]);

    $this->createTestCert($order, [
        'common_name' => 'sub.example.com',
        'alternative_names' => 'sub.example.com',
        'dcv' => ['method' => 'txt', 'is_delegate' => true, 'dns' => ['host' => '_dnsauth']],
        'validation' => [
            [
                'host' => '_dnsauth.sub.example.com',
                'domain' => 'sub.example.com',
                'value' => 'token-exact',
            ],
        ],
    ]);

    $reflection = new ReflectionClass($this->service);
    $method = $reflection->getMethod('collectTxtRecords');

    $order->refresh();
    [$txtRecords, $updatedValidation, $hasChanges] = $method->invoke($this->service, $order);

    // exact CA 子域不回落根域委托 → 未命中
    expect($txtRecords)->toBeEmpty();
    expect($hasChanges)->toBeFalse();
    expect($updatedValidation[0])->not->toHaveKey('auto_txt_written');
});
