<?php

use App\Models\Notification;
use App\Models\NotificationTemplate;
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

test('模板关联通知', function () {
    $template = NotificationTemplate::factory()->create([
        'code' => 'test_relation',
    ]);

    Notification::factory()->count(3)->create([
        'template_id' => $template->id,
    ]);

    expect($template->notifications)->toHaveCount(3);
});
