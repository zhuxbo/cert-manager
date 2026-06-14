<?php

use App\Models\NotificationTemplate;
use App\Services\Notification\TemplateSelector;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('database');

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->selector = new TemplateSelector;
});

test('按代码查询启用的模板', function () {
    NotificationTemplate::create([
        'code' => 'test_selector_basic',
        'name' => '测试模板',
        'content' => 'Hello {{ $username }}',
        'variables' => ['username'],
        'status' => 1,
    ]);

    $template = $this->selector->select('test_selector_basic');

    expect($template)->toBeInstanceOf(NotificationTemplate::class);
    expect($template->code)->toBe('test_selector_basic');
});

test('查询不存在的代码返回空选择', function () {
    $template = $this->selector->select('nonexistent_code_'.uniqid());

    expect($template)->toBeNull();
});

test('禁用的模板不会被选中', function () {
    NotificationTemplate::create([
        'code' => 'test_disabled_tpl',
        'name' => '禁用模板',
        'content' => 'Hello',
        'variables' => [],
        'status' => 0,
    ]);

    $template = $this->selector->select('test_disabled_tpl');

    expect($template)->toBeNull();
});
