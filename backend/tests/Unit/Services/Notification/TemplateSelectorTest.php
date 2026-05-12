<?php

use App\Models\NotificationTemplate;
use App\Services\Notification\TemplateSelection;
use App\Services\Notification\TemplateSelector;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('database');

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->selector = new TemplateSelector;
});

test('按代码查询模板返回匹配的模板', function () {
    NotificationTemplate::create([
        'code' => 'test_selector_mail',
        'name' => '测试模板',
        'content' => 'Hello {{ $username }}',
        'variables' => ['username'],
        'status' => 1,
        'channels' => ['mail'],
    ]);

    $selection = $this->selector->select('test_selector_mail');

    expect($selection)->toBeInstanceOf(TemplateSelection::class);
    expect($selection->isEmpty())->toBeFalse();
    expect($selection->channels())->toContain('mail');
});

test('查询不存在的代码返回空选择', function () {
    $selection = $this->selector->select('nonexistent_code_'.uniqid());

    expect($selection->isEmpty())->toBeTrue();
    expect($selection->channels())->toBeEmpty();
});

test('按通道过滤只返回匹配的通道', function () {
    NotificationTemplate::create([
        'code' => 'test_filter_multi',
        'name' => '多通道模板',
        'content' => 'Hello',
        'variables' => [],
        'status' => 1,
        'channels' => ['mail', 'sms'],
    ]);

    $selection = $this->selector->select('test_filter_multi', ['sms']);

    expect($selection->isEmpty())->toBeFalse();
    expect($selection->channels())->toBe(['sms']);
});

test('禁用的模板不会被选中', function () {
    NotificationTemplate::create([
        'code' => 'test_disabled_tpl',
        'name' => '禁用模板',
        'content' => 'Hello',
        'variables' => [],
        'status' => 0,
        'channels' => ['mail'],
    ]);

    $selection = $this->selector->select('test_disabled_tpl');

    expect($selection->isEmpty())->toBeTrue();
});
