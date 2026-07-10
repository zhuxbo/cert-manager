<?php

use App\Models\Cert;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Notification\Builders\CertExpireNotificationBuilder;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\DTOs\NotificationPayload;
use App\Services\Notification\TemplateSelector;
use App\Services\Order\AutoRenewService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
});

function buildMockOrder(array $certData = [], array $productData = []): Order&MockInterface
{
    $cert = Mockery::mock(Cert::class)->makePartial();
    $cert->shouldReceive('getAttribute')->with('status')->andReturn($certData['status'] ?? 'active');
    $cert->shouldReceive('getAttribute')->with('expires_at')->andReturn(
        $certData['expires_at'] ?? now()->addDays(7)
    );
    $cert->shouldReceive('getAttribute')->with('common_name')->andReturn($certData['common_name'] ?? 'example.com');
    $cert->shouldReceive('getAttribute')->with('alternative_names')->andReturn($certData['alternative_names'] ?? 'example.com');
    $cert->shouldReceive('getAttribute')->with('channel')->andReturn($certData['channel'] ?? 'api');

    $product = Mockery::mock(Product::class)->makePartial();
    $product->shouldReceive('getAttribute')->with('ca')->andReturn($productData['ca'] ?? 'Sectigo');

    $order = Mockery::mock(Order::class)->makePartial();
    $order->shouldReceive('getAttribute')->with('latestCert')->andReturn($cert);
    $order->shouldReceive('getAttribute')->with('product')->andReturn($product);
    $order->shouldReceive('getAttribute')->with('user_id')->andReturn(1);

    return $order;
}

function buildMockUser(?string $email = 'user@example.com'): User&MockInterface
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $user->shouldReceive('getAttribute')->with('email')->andReturn($email);
    $user->shouldReceive('getAttribute')->with('username')->andReturn('testuser');

    return $user;
}

function buildPartialBuilder(AutoRenewService $svc, Collection $orders): CertExpireNotificationBuilder&MockInterface
{
    $builder = Mockery::mock(CertExpireNotificationBuilder::class, [$svc])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $builder->shouldReceive('fetchExpiringOrders')->andReturn($orders);

    // build() 顶部调 get_system_setting('site', ...)，预填 cache 短路真实查询，避免依赖 setting_groups 表
    Cache::put(
        'setting:group_name:site',
        ['url' => 'https://ssl.test/', 'name' => 'SSL证书管理系统'],
        3600
    );

    // B2：build() 调 app(TemplateSelector::class)->select('auto_renew_failed') 判定是否 gate 排除。
    // 默认绑定为「启用」（生产默认 status=1），保持既有排除测试语义；本 Unit 无 DB，用容器 mock 隔离。
    bindAutoRenewFailedTemplate(true);

    return $builder;
}

/**
 * 绑定 TemplateSelector 到容器，控制 auto_renew_failed 模板启用态（B2 排除 gate）。
 */
function bindAutoRenewFailedTemplate(bool $enabled): void
{
    $selector = Mockery::mock(TemplateSelector::class);
    $selector->shouldReceive('select')
        ->with('auto_renew_failed')
        ->andReturn($enabled ? new NotificationTemplate(['code' => 'auto_renew_failed', 'status' => 1]) : null);
    app()->instance(TemplateSelector::class, $selector);
}

test('接收者非 User 时抛出异常', function () {
    $autoRenewService = Mockery::mock(AutoRenewService::class);

    $builder = new CertExpireNotificationBuilder($autoRenewService);
    $intent = new NotificationIntent('cert_expire', 'user', 1);

    /** @var Model $notifiable */
    $notifiable = Mockery::mock(Model::class);

    $builder->build($intent, $notifiable);
})->throws(RuntimeException::class, '通知接收者必须为用户');

test('邮箱为空时抛出异常', function () {
    $autoRenewService = Mockery::mock(AutoRenewService::class);

    $builder = new CertExpireNotificationBuilder($autoRenewService);
    $intent = new NotificationIntent('cert_expire', 'user', 1);

    $builder->build($intent, buildMockUser(email: null));
})->throws(RuntimeException::class, '邮箱为空');

test('orders 为空 → 返回 null', function () {
    $autoRenewService = Mockery::mock(AutoRenewService::class);

    $builder = buildPartialBuilder($autoRenewService, new Collection);
    $intent = new NotificationIntent('cert_expire', 'user', 1, ['email' => 'user@example.com']);

    expect($builder->build($intent, buildMockUser()))->toBeNull();
});

test('自动续签会执行的非 api 订单 → 全部 skip 返回 null（交由 auto_renew_failed 提醒，去重）', function () {
    $autoRenewService = Mockery::mock(AutoRenewService::class);
    $autoRenewService->shouldReceive('willAutoRenewExecute')->andReturn(true);
    $autoRenewService->shouldReceive('willAutoReissueExecute')->andReturn(false);

    // channel=web（非 api）+ willAutoRenew=true → 会被 AutoRenewCommand 处理 → 排除
    $orders = new Collection([buildMockOrder(['common_name' => 'a.com', 'channel' => 'web'])]);
    $builder = buildPartialBuilder($autoRenewService, $orders);
    $intent = new NotificationIntent('cert_expire', 'user', 1, ['email' => 'user@example.com']);

    expect($builder->build($intent, buildMockUser()))->toBeNull();
});

test('自动续签会执行的非 api 订单即使委托未配置也排除（不再按委托细分，避免与 auto_renew_failed 双发）', function () {
    $autoRenewService = Mockery::mock(AutoRenewService::class);
    $autoRenewService->shouldReceive('willAutoRenewExecute')->andReturn(true);
    $autoRenewService->shouldReceive('willAutoReissueExecute')->andReturn(false);
    // checkDelegationValidity 不应再被 builder 调用（去重已不依赖委托判断）
    $autoRenewService->shouldNotReceive('checkDelegationValidity');

    $orders = new Collection([buildMockOrder([
        'common_name' => 'invalid.com',
        'expires_at' => now()->addDays(7),
        'channel' => 'web',
    ])]);
    $builder = buildPartialBuilder($autoRenewService, $orders);
    $intent = new NotificationIntent('cert_expire', 'user', 1, ['email' => 'user@example.com']);

    // 委托有效性不再影响 builder：会被 AutoRenewCommand 处理的非 api 订单一律排除 → null
    expect($builder->build($intent, buildMockUser()))->toBeNull();
});

test('B2：auto_renew_failed 模板停用时不排除自动续签订单（回落发 cert_expire，防两头空）', function () {
    $autoRenewService = Mockery::mock(AutoRenewService::class);
    $autoRenewService->shouldReceive('willAutoRenewExecute')->andReturn(true);
    $autoRenewService->shouldReceive('willAutoReissueExecute')->andReturn(false);

    // 非 api + willAutoRenew=true：模板启用时本会被排除；停用时应回落纳入
    $orders = new Collection([buildMockOrder(['common_name' => 'fallback.com', 'channel' => 'web'])]);
    $builder = buildPartialBuilder($autoRenewService, $orders);
    // 覆盖为停用（buildPartialBuilder 默认绑定启用，此处最后一次绑定生效）
    bindAutoRenewFailedTemplate(false);
    $intent = new NotificationIntent('cert_expire', 'user', 1, ['email' => 'user@example.com']);

    $result = $builder->build($intent, buildMockUser());

    // 模板停用 → 不排除 → 汇总邮件含该证书（回落，防静默过期）
    expect($result)->toBeInstanceOf(NotificationPayload::class);
    expect($result->data['certificates'])->toHaveCount(1);
    expect($result->data['certificates'][0]['domain'])->toBe('fallback.com');
});

test('api channel 订单即使 willAutoRenew=true 也不排除（AutoRenewCommand 不处理 api，照常发 cert_expire 防漏发）', function () {
    $autoRenewService = Mockery::mock(AutoRenewService::class);
    // 即便判定会执行，api channel 在 AutoRenewCommand getRenewOrders 已被排除，故 ExpireCommand/Builder 不能排除
    $autoRenewService->shouldReceive('willAutoRenewExecute')->andReturn(true);
    $autoRenewService->shouldReceive('willAutoReissueExecute')->andReturn(false);

    $orders = new Collection([buildMockOrder([
        'common_name' => 'api-order.com',
        'expires_at' => now()->addDays(7),
        'channel' => 'api',
    ])]);
    $builder = buildPartialBuilder($autoRenewService, $orders);
    $intent = new NotificationIntent('cert_expire', 'user', 1, ['email' => 'user@example.com']);

    $result = $builder->build($intent, buildMockUser());

    expect($result)->toBeInstanceOf(NotificationPayload::class);
    expect($result->data['certificates'])->toHaveCount(1);
    expect($result->data['certificates'][0]['domain'])->toBe('api-order.com');
    expect($result->data['certificates'][0]['delegation_status'])->toBe('need_renew');
});

test('自动任务不会执行 → 加入通知列表，delegation_status=need_renew', function () {
    $autoRenewService = Mockery::mock(AutoRenewService::class);
    $autoRenewService->shouldReceive('willAutoRenewExecute')->andReturn(false);
    $autoRenewService->shouldReceive('willAutoReissueExecute')->andReturn(false);

    $orders = new Collection([buildMockOrder([
        'common_name' => 'manual.com',
        'expires_at' => now()->addDays(3),
        'channel' => 'web',
    ])]);
    $builder = buildPartialBuilder($autoRenewService, $orders);
    $intent = new NotificationIntent('cert_expire', 'user', 1, ['email' => 'user@example.com']);

    $result = $builder->build($intent, buildMockUser());

    expect($result)->toBeInstanceOf(NotificationPayload::class);
    expect($result->data['has_delegation_issue'])->toBeFalse();
    expect($result->data['certificates'])->toHaveCount(1);
    expect($result->data['certificates'][0]['domain'])->toBe('manual.com');
    expect($result->data['certificates'][0]['delegation_status'])->toBe('need_renew');
    expect($result->data['site_name'])->toBe('SSL证书管理系统');
    expect($result->data['email'])->toBe('user@example.com');
    expect($result->data['username'])->toBe('testuser');
    // _meta 结构守护：与 FinanceAudit/TaskFailed/CertIssued 对齐，MailChannel 据此读 subject + is_html
    expect($result->data['_meta']['subject'])->toContain('SSL证书到期提醒');
    expect($result->data['_meta']['is_html'])->toBeTrue();
});

test('intent.context.email 为空时回落 notifiable.email', function () {
    $autoRenewService = Mockery::mock(AutoRenewService::class);
    $autoRenewService->shouldReceive('willAutoRenewExecute')->andReturn(false);
    $autoRenewService->shouldReceive('willAutoReissueExecute')->andReturn(false);

    $orders = new Collection([buildMockOrder()]);
    $builder = buildPartialBuilder($autoRenewService, $orders);

    // 不传 context.email，让 builder 走 fallback 取 notifiable->email
    $intent = new NotificationIntent('cert_expire', 'user', 1);

    $result = $builder->build($intent, buildMockUser());

    expect($result->data['email'])->toBe('user@example.com');
});

test('NotificationPayload 正确构造', function () {
    $payload = new NotificationPayload(['email' => 'test@example.com', 'username' => 'testuser']);

    expect($payload->data)->toBe(['email' => 'test@example.com', 'username' => 'testuser']);
});
