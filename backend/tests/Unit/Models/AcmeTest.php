<?php

use App\Models\Acme;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;
use Tests\Traits\CreatesTestData;

uses(TestCase::class, CreatesTestData::class, RefreshDatabase::class)->group('database');

beforeEach(function () {
    $this->seed = true;
    $this->seeder = DatabaseSeeder::class;
});

/**
 * 测试模型创建
 */
test('can create acme model with all fillable fields', function () {
    $user = $this->createTestUser();
    $product = Product::factory()->create(['product_type' => Product::TYPE_ACME]);

    $acme = Acme::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'brand' => 'certum',
        'period' => 12,
        'purchased_standard_count' => 3,
        'purchased_wildcard_count' => 1,
        'refer_id' => 'test-refer-id',
        'api_id' => 'test-api-id',
        'vendor_id' => 'test-vendor-id',
        'contact_email' => 'buyer@example.com',
        'eab_kid' => 'test-eab-kid',
        'eab_hmac' => 'test-eab-hmac-secret',
        'period_from' => now(),
        'period_till' => now()->addYear(),
        'status' => Acme::STATUS_PENDING,
        'remark' => 'test remark',
        'amount' => '199.99',
        'admin_remark' => 'admin note',
    ]);

    expect($acme->exists)->toBeTrue();
    expect($acme->brand)->toBe('certum');
    expect($acme->period)->toBe(12);
    expect($acme->purchased_standard_count)->toBe(3);
    expect($acme->purchased_wildcard_count)->toBe(1);
    expect($acme->refer_id)->toBe('test-refer-id');
    expect($acme->api_id)->toBe('test-api-id');
    expect($acme->vendor_id)->toBe('test-vendor-id');
    expect($acme->contact_email)->toBe('buyer@example.com');
    expect($acme->eab_kid)->toBe('test-eab-kid');
    expect($acme->status)->toBe('pending');
    expect($acme->remark)->toBe('test remark');
    expect($acme->admin_remark)->toBe('admin note');
});

/**
 * 测试使用 factory 创建
 */
test('can create acme via factory', function () {
    $acme = Acme::factory()->create();

    expect($acme->exists)->toBeTrue();
    expect($acme->id)->not->toBeNull();
    expect($acme->user_id)->not->toBeNull();
    expect($acme->product_id)->not->toBeNull();
});

/**
 * 测试 Snowflake ID 自动生成
 */
test('generates snowflake id on creation', function () {
    $acme = Acme::factory()->create();

    expect($acme->id)->toBeInt();
    expect($acme->id)->toBeGreaterThan(0);
    expect($acme->getIncrementing())->toBeFalse();
});

/**
 * 测试 period 转为 integer
 */
test('period casts to integer', function () {
    $acme = Acme::factory()->create(['period' => '24']);

    expect($acme->period)->toBe(24);
    expect($acme->period)->toBeInt();
});

/**
 * 测试 purchased_standard_count 转为 integer
 */
test('purchased standard count casts to integer', function () {
    $acme = Acme::factory()->create(['purchased_standard_count' => '5']);

    expect($acme->purchased_standard_count)->toBe(5);
    expect($acme->purchased_standard_count)->toBeInt();
});

/**
 * 测试 purchased_wildcard_count 转为 integer
 */
test('purchased wildcard count casts to integer', function () {
    $acme = Acme::factory()->create(['purchased_wildcard_count' => '2']);

    expect($acme->purchased_wildcard_count)->toBe(2);
    expect($acme->purchased_wildcard_count)->toBeInt();
});

/**
 * 测试 amount 转为 decimal:2
 */
test('amount casts to decimal with two places', function () {
    $acme = Acme::factory()->create(['amount' => '199.9']);

    expect($acme->amount)->toBe('199.90');
});

/**
 * 测试时间字段转为 datetime
 */
test('datetime fields cast correctly', function () {
    $now = now();
    $acme = Acme::factory()->create([
        'period_from' => $now,
        'period_till' => $now->copy()->addYear(),
        'cancelled_at' => $now,
    ]);

    expect($acme->period_from)->toBeInstanceOf(Carbon::class);
    expect($acme->period_till)->toBeInstanceOf(Carbon::class);
    expect($acme->cancelled_at)->toBeInstanceOf(Carbon::class);
});

/**
 * 测试 eab_hmac 加密存储
 */
test('eab hmac is encrypted in database', function () {
    $secret = 'my-secret-hmac-key';
    $acme = Acme::factory()->create(['eab_hmac' => $secret]);

    // 数据库中的原始值不是明文
    $dbRaw = $acme->getRawOriginal('eab_hmac');
    expect($dbRaw)->not->toBe($secret);

    // 加密值可以解密回原始值
    expect(Crypt::decryptString($dbRaw))->toBe($secret);

    // 模型 getter 返回解密后的值
    $acme->refresh();
    expect($acme->eab_hmac)->toBe($secret);
});

/**
 * 测试 eab_hmac 在 toArray 中隐藏
 */
test('eab hmac is hidden in array output', function () {
    $acme = Acme::factory()->create(['eab_hmac' => 'secret']);

    $array = $acme->toArray();
    expect($array)->not->toHaveKey('eab_hmac');
});

/**
 * 测试 user 关联
 */
test('belongs to user', function () {
    $user = $this->createTestUser();
    $acme = Acme::factory()->create(['user_id' => $user->id]);

    expect($acme->user)->toBeInstanceOf(User::class);
    expect($acme->user->id)->toBe($user->id);
});

/**
 * 测试 product 关联
 */
test('belongs to product', function () {
    $product = Product::factory()->create(['product_type' => Product::TYPE_ACME]);
    $acme = Acme::factory()->create(['product_id' => $product->id]);

    expect($acme->product)->toBeInstanceOf(Product::class);
    expect($acme->product->id)->toBe($product->id);
});

/**
 * 测试状态常量
 */
test('status constants match migration enum values', function () {
    expect(Acme::STATUS_UNPAID)->toBe('unpaid');
    expect(Acme::STATUS_PENDING)->toBe('pending');
    expect(Acme::STATUS_ACTIVE)->toBe('active');
    expect(Acme::STATUS_CANCELLING)->toBe('cancelling');
    expect(Acme::STATUS_CANCELLED)->toBe('cancelled');
    expect(Acme::STATUS_REVOKED)->toBe('revoked');
    expect(Acme::STATUS_EXPIRED)->toBe('expired');
});

/**
 * 测试表名为 acmes
 */
test('uses acmes table', function () {
    $acme = new Acme;

    expect($acme->getTable())->toBe('acmes');
});
