<?php

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestData;

uses(TestCase::class, CreatesTestData::class, RefreshDatabase::class)->group('database');

beforeEach(function () {
    $this->seed = true;
    $this->seeder = DatabaseSeeder::class;
});

/**
 * 测试 auto_settings 为 null 时返回默认值
 */
test('returns default when null', function () {
    $user = $this->createTestUser();
    $user->auto_settings = null;
    $user->save();

    $user->refresh();

    expect($user->auto_settings)->toBeArray();
    expect($user->auto_settings['auto_renew'])->toBeFalse();
    expect($user->auto_settings['auto_reissue'])->toBeTrue();
});

/**
 * 测试正确解析 JSON 字符串
 */
test('normalizes json string', function () {
    $user = $this->createTestUser();

    // 通过 setRawAttributes 设置原始 JSON 字符串
    $user->setRawAttributes(array_merge(
        $user->getAttributes(),
        ['auto_settings' => '{"auto_renew":true,"auto_reissue":false}']
    ));
    $settings = $user->auto_settings;

    expect($settings['auto_renew'])->toBeTrue();
    expect($settings['auto_reissue'])->toBeFalse();
});

/**
 * 测试正确处理数组输入
 */
test('normalizes array input', function () {
    $user = $this->createTestUser();
    $user->auto_settings = ['auto_renew' => true, 'auto_reissue' => true];
    $user->save();

    $user->refresh();

    expect($user->auto_settings['auto_renew'])->toBeTrue();
    expect($user->auto_settings['auto_reissue'])->toBeTrue();
});

/**
 * 测试各种真值转换为 boolean true
 */
test('casts truthy to boolean', function () {
    $user = $this->createTestUser();

    // 测试字符串 "1"
    $user->auto_settings = ['auto_renew' => '1', 'auto_reissue' => '0'];
    $user->save();
    $user->refresh();

    expect($user->auto_settings['auto_renew'])->toBeTrue();
    expect($user->auto_settings['auto_reissue'])->toBeFalse();

    // 测试整数 1
    $user->auto_settings = ['auto_renew' => 1, 'auto_reissue' => 0];
    $user->save();
    $user->refresh();

    expect($user->auto_settings['auto_renew'])->toBeTrue();
    expect($user->auto_settings['auto_reissue'])->toBeFalse();
});

/**
 * 测试只返回 auto_renew 和 auto_reissue，忽略其他字段
 */
test('ignores extra fields', function () {
    $user = $this->createTestUser();
    $user->auto_settings = [
        'auto_renew' => true,
        'auto_reissue' => false,
        'extra_field' => 'should_be_ignored',
    ];
    $user->save();

    $user->refresh();

    expect($user->auto_settings)->toHaveKey('auto_renew');
    expect($user->auto_settings)->toHaveKey('auto_reissue');
    expect($user->auto_settings)->not->toHaveKey('extra_field');
    expect($user->auto_settings)->toHaveCount(2);
});

/**
 * 测试空数组输入返回默认值
 */
test('returns default for empty array', function () {
    $user = $this->createTestUser();
    $user->auto_settings = [];
    $user->save();

    $user->refresh();

    expect($user->auto_settings['auto_renew'])->toBeFalse();
    expect($user->auto_settings['auto_reissue'])->toBeTrue();
});
