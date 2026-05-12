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
