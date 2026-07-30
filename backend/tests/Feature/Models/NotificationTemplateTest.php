<?php

use App\Models\Notification;
use App\Models\NotificationTemplate;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Validation\ValidationException;

test('模板渲染 Blade 变量', function () {
    $template = NotificationTemplate::factory()->create([
        'code' => 'test_render',
        'content' => '您好 {{ $name }}，您的证书 {{ $domain }} 已签发',
    ]);

    $result = $template->render(['name' => '张三', 'domain' => 'example.com']);

    expect($result)->toContain('张三');
    expect($result)->toContain('example.com');
});

test('模板渲染空变量', function () {
    $template = NotificationTemplate::factory()->create([
        'code' => 'test_empty',
        'content' => '静态内容，无变量',
    ]);

    $result = $template->render();

    expect($result)->toContain('静态内容');
});

test('模板渲染 Blade 条件语法', function () {
    $template = NotificationTemplate::factory()->create([
        'code' => 'test_condition',
        'content' => '@if($show)显示内容@else隐藏内容@endif',
    ]);

    $result1 = $template->render(['show' => true]);
    expect($result1)->toContain('显示内容');

    $result2 = $template->render(['show' => false]);
    expect($result2)->toContain('隐藏内容');
});

test('content 字段 base64 编解码', function () {
    $template = NotificationTemplate::factory()->create([
        'code' => 'test_encode',
        'content' => '<p>测试内容</p>',
    ]);

    $raw = $template->getRawOriginal('content');
    expect(base64_decode($raw))->toBe('<p>测试内容</p>');

    $template->refresh();
    expect($template->content)->toBe('<p>测试内容</p>');
});

test('example 字段 base64 编解码', function () {
    $template = NotificationTemplate::factory()->create([
        'code' => 'test_example',
        'example' => '<p>示例内容</p>',
    ]);

    $template->refresh();
    expect($template->example)->toBe('<p>示例内容</p>');
});

test('content 为 null 时返回 null', function () {
    $template = NotificationTemplate::factory()->create([
        'code' => 'test_null_content',
        'content' => null,
    ]);

    $template->refresh();
    expect($template->content)->toBeNull();
});

test('空字符串 content 存储为 null', function () {
    $template = NotificationTemplate::factory()->create([
        'code' => 'test_empty_str',
        'content' => '',
    ]);

    $template->refresh();
    expect($template->content)->toBeNull();
});

test('variables 字段为 JSON cast', function () {
    $template = NotificationTemplate::factory()->create([
        'code' => 'test_vars',
        'variables' => ['name', 'domain', 'expires_at'],
    ]);

    $template->refresh();
    expect($template->variables)->toBeArray();
    expect($template->variables)->toContain('name');
    expect($template->variables)->toContain('domain');
});

test('同一 code 不允许重复创建', function () {
    NotificationTemplate::factory()->create([
        'code' => 'duplicate_test',
    ]);

    expect(fn () => NotificationTemplate::factory()->create([
        'code' => 'duplicate_test',
    ]))->toThrow(ValidationException::class);
});

test('seeder 创建 auto_renew_failed 模板并能渲染失败通知（续费）', function () {
    (new NotificationTemplateSeeder)->run();

    $template = NotificationTemplate::where('code', 'auto_renew_failed')->first();
    expect($template)->not->toBeNull();
    expect($template->status)->toBe(1);
    expect($template->variables)->toContain('common_name')
        ->and($template->variables)->toContain('action')
        ->and($template->variables)->toContain('reason')
        // site_url 不进 variables（由 AutoRenewFailedNotificationBuilder 注入），测试发送无需手填
        ->and($template->variables)->not->toContain('site_url');

    // 与 AutoRenewCommand::sendFailureNotification 实际 context 一致（DefaultBuilder 直通）
    $html = $template->render([
        'common_name' => 'example.com',
        'action' => 'renew',
        'reason' => '账户余额不足，请充值后手动续期',
        'site_url' => 'https://console.example.com',
    ]);

    expect($html)->toContain('example.com') // 用证书域名标识，非数字订单号
        ->and($html)->toContain('续费') // action=renew → 友好标签
        ->and($html)->toContain('余额不足')
        ->and($html)->toContain('登录控制台') // 控制台按钮（仿到期通知）
        ->and($html)->toContain('https://console.example.com') // 按钮 href
        ->and($html)->toContain('联系客服') // 兜底处理项
        ->and($html)->not->toContain('{{'); // Blade 全部渲染，无残留占位符
});

test('auto_renew_failed 模板对 action=reissue 渲染重签标签', function () {
    (new NotificationTemplateSeeder)->run();

    $template = NotificationTemplate::where('code', 'auto_renew_failed')->first();
    $html = $template->render([
        'common_name' => 'test.example.com',
        'action' => 'reissue',
        'reason' => '部分域名 CNAME 委托未配置或验证未通过，已跳过',
        'site_url' => 'https://console.example.com',
    ]);

    expect($html)->toContain('重签')
        ->and($html)->toContain('CNAME 委托')
        ->and($html)->toContain('test.example.com')
        ->and($html)->toContain('https://console.example.com') // 控制台按钮 href（URL 仅出现在按钮）
        ->and($html)->not->toContain('{{');
});

test('user_created 模板 variables 不含 site_url（由 UserCreatedNotificationBuilder 注入，测试发送无需手填）', function () {
    (new NotificationTemplateSeeder)->run();

    $template = NotificationTemplate::where('code', 'user_created')->first();
    expect($template->variables)->toContain('username')
        ->and($template->variables)->toContain('password')
        ->and($template->variables)->not->toContain('site_url');
});

test('主系统模板只声明 Builder 实际读取的测试输入参数', function () {
    $variables = collect((new NotificationTemplateSeeder)->notificationTemplateDefaults())
        ->mapWithKeys(fn (array $template): array => [$template['code'] => $template['variables']])
        ->all();

    expect($variables)->toBe([
        'cert_issued' => ['order_id', 'email'],
        'cert_expire' => ['email'],
        'acme_expire' => ['email'],
        'cert_renew_stalled' => ['email'],
        'cert_renew_cancelled' => ['email', 'common_name', 'expires_at', 'order_id', 'product_type'],
        'cert_revoked' => ['email', 'common_name', 'expires_at', 'order_id', 'is_successor', 'product_type'],
        'security' => ['event'],
        'user_created' => ['username', 'password'],
        'auto_renew_failed' => ['common_name', 'action', 'reason'],
        'balance_forecast' => ['available', 'required', 'shortfall', 'certificates'],
        'task_failed' => ['task_id', 'error_message'],
        'finance_audit' => ['violation_count', 'violations', 'detected_at'],
        'system_alert' => ['category', 'title', 'message'],
    ]);
});

test('模板关联通知', function () {
    $template = NotificationTemplate::factory()->create([
        'code' => 'test_relation',
    ]);

    Notification::factory()->count(3)->create([
        'template_id' => $template->id,
    ]);

    expect($template->notifications)->toHaveCount(3);
});
