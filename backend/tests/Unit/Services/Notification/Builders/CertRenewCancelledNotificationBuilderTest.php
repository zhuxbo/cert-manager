<?php

use App\Models\NotificationTemplate;
use App\Models\Product;
use App\Models\User;
use App\Services\Notification\Builders\CertRenewCancelledNotificationBuilder;
use App\Services\Notification\DTOs\NotificationIntent;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('database');

afterEach(function () {
    Mockery::close();
});

function renewCancelledMockUser(?string $email = 'user@example.com'): User&MockInterface
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $user->shouldReceive('getAttribute')->with('email')->andReturn($email);
    $user->shouldReceive('getAttribute')->with('username')->andReturn('testuser');

    return $user;
}

/** 预填 site 系统设置缓存，短路 get_system_setting 真实查询。 */
function preloadRenewCancelledSite(): void
{
    Cache::put('setting:group_name:site', ['url' => 'https://ssl.test/', 'name' => 'SSL证书管理系统'], 3600);
}

test('接收者非 User 时抛出异常', function () {
    $builder = new CertRenewCancelledNotificationBuilder;
    $intent = new NotificationIntent('cert_renew_cancelled', 'user', 1);

    /** @var Model $notifiable */
    $notifiable = Mockery::mock(Model::class);

    $builder->build($intent, $notifiable);
})->throws(RuntimeException::class, '通知接收者必须为用户');

test('邮箱为空时抛出异常', function () {
    $builder = new CertRenewCancelledNotificationBuilder;
    $intent = new NotificationIntent('cert_renew_cancelled', 'user', 1);

    $builder->build($intent, renewCancelledMockUser(email: null));
})->throws(RuntimeException::class, '邮箱为空');

test('build：$data 白名单键齐 + 值正确 + site 由 Builder 注入 + 绝不直通 context 敏感外键', function () {
    preloadRenewCancelledSite();
    $builder = new CertRenewCancelledNotificationBuilder;

    // context 混入一个白名单外的敏感键，验证 Builder 不整包直通（DefaultBuilder 直通红线的对照）
    $intent = new NotificationIntent('cert_renew_cancelled', 'user', 1, [
        'common_name' => 'shrink.example.com',
        'expires_at' => '2026-09-30',
        'order_id' => 8899,
        'action' => '重签',
        'product_type' => Product::TYPE_SMIME,
        'email' => 'user@example.com',
        'private_key' => '-----BEGIN PRIVATE KEY-----SECRET-----END PRIVATE KEY-----',
    ]);

    $result = $builder->build($intent, renewCancelledMockUser());

    // 值正确
    expect($result->data['common_name'])->toBe('shrink.example.com')
        ->and($result->data['expires_at'])->toBe('2026-09-30')
        ->and($result->data['order_id'])->toBe(8899)
        ->and($result->data['product_type'])->toBe(Product::TYPE_SMIME)
        ->and($result->data['product_type_label'])->toBe('S/MIME')
        ->and($result->data['username'])->toBe('testuser')
        ->and($result->data['email'])->toBe('user@example.com');

    // site 由 Builder 从系统设置注入（不依赖 context/variables）
    expect($result->data['site_url'])->toBe('https://ssl.test/')
        ->and($result->data['site_name'])->toBe('SSL证书管理系统')
        ->and($result->data['_meta']['subject'])->toContain('S/MIME 证书续签取消提醒')
        ->and($result->data['_meta']['is_html'])->toBeTrue();

    // $data 键严格白名单：无 private_key 等敏感外键
    expect(array_keys($result->data))->toEqualCanonicalizing([
        'username', 'email', 'site_name', 'site_url',
        'common_name', 'expires_at', 'order_id', 'product_type', 'product_type_label', 'subject', '_meta',
    ]);
    expect($result->data)
        ->not->toHaveKey('private_key')
        ->not->toHaveKey('action');

    // 兜底：整个持久化载荷序列化后不含敏感明文（防嵌套泄漏）
    expect(json_encode($result->data))->not->toContain('SECRET');
});

test('统一续签文案后不再输出 action 字段', function () {
    preloadRenewCancelledSite();
    $builder = new CertRenewCancelledNotificationBuilder;
    $intent = new NotificationIntent('cert_renew_cancelled', 'user', 1, [
        'common_name' => 'x.example.com',
        'expires_at' => '2026-10-01',
        'order_id' => 1,
        'email' => 'user@example.com',
    ]);

    $result = $builder->build($intent, renewCancelledMockUser());

    expect($result->data)->not->toHaveKey('action')
        ->and($result->data['product_type'])->toBe(Product::TYPE_SSL)
        ->and($result->data['product_type_label'])->toBe('SSL');
});

test('四类产品均展示对应类型且空值或未知值回落 SSL', function (mixed $productType, string $expectedType, string $expectedLabel) {
    preloadRenewCancelledSite();
    $builder = new CertRenewCancelledNotificationBuilder;
    $intent = new NotificationIntent('cert_renew_cancelled', 'user', 1, [
        'common_name' => 'identifier',
        'expires_at' => '2026-10-01',
        'order_id' => 1,
        'product_type' => $productType,
        'email' => 'user@example.com',
    ]);

    $result = $builder->build($intent, renewCancelledMockUser());

    expect($result->data['product_type'])->toBe($expectedType)
        ->and($result->data['product_type_label'])->toBe($expectedLabel)
        ->and($result->data['_meta']['subject'])->toContain("{$expectedLabel} 证书");
})->with([
    'SSL' => [Product::TYPE_SSL, Product::TYPE_SSL, 'SSL'],
    'S/MIME' => [Product::TYPE_SMIME, Product::TYPE_SMIME, 'S/MIME'],
    '代码签名' => [Product::TYPE_CODESIGN, Product::TYPE_CODESIGN, '代码签名'],
    '文档签名' => [Product::TYPE_DOCSIGN, Product::TYPE_DOCSIGN, '文档签名'],
    '空值' => [null, Product::TYPE_SSL, 'SSL'],
    '未知值' => ['unknown', Product::TYPE_SSL, 'SSL'],
]);

test('三件套 + 强制发：config builder 命中 + 不入偏好表 + allowsNotification 默认 true + seeder 幂等', function () {
    // ① config builders 命中新 code（缺则端到端回落 DefaultBuilder、白名单失效直通 context）
    expect(config('notification.builders')['cert_renew_cancelled'])->toBe(CertRenewCancelledNotificationBuilder::class);

    // ② 强制发：不入 user_default_preferences（误加即强制发静默失效且用户可关，一行断言锁死）
    expect(array_key_exists('cert_renew_cancelled', config('notification.user_default_preferences')))->toBeFalse();

    // ③ allowsNotification 未登记 code 默认 true；用户显式塞 false 被 setter 白名单丢弃 → 仍强制发
    $user = User::factory()->create();
    expect($user->allowsNotification('cert_renew_cancelled'))->toBeTrue();
    $user->notification_settings = ['cert_renew_cancelled' => false];
    $user->save();
    expect($user->fresh()->allowsNotification('cert_renew_cancelled'))->toBeTrue();

    // ④ seeder 幂等（firstOrCreate，新 code 首建、重跑 no-op）
    (new NotificationTemplateSeeder)->run();
    (new NotificationTemplateSeeder)->run();
    $templates = NotificationTemplate::where('code', 'cert_renew_cancelled')->get();
    expect($templates)->toHaveCount(1)
        ->and($templates->first()->status)->toBe(1)
        ->and($templates->first()->variables)->toBe([
            'email',
            'common_name',
            'expires_at',
            'order_id',
            'product_type',
        ]);

    // ⑤ 模板含性质说明段（不受常规到期提醒偏好控制）
    expect($templates->first()->content)
        ->toContain('证书续签取消重要提醒')
        ->toContain('不受常规到期提醒偏好控制');
});
