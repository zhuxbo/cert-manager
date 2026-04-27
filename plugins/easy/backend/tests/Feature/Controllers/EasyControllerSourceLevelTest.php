<?php

use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Plugins\Easy\Controllers\EasyController;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('EasyController 按 sourceLevel 中的 source 键解析等级', function () {
    $controller = new class extends EasyController
    {
        public function exposeResolveLevelFromSource(string $source, array $sourceLevel): string
        {
            return $this->resolveLevelFromSource($source, $sourceLevel);
        }
    };

    $level = $controller->exposeResolveLevelFromSource('taobao', [
        'taobao' => 'gold',
        'wechat' => 'partner',
    ]);

    expect($level)->toBe('gold');
});

test('EasyController sourceLevel 未配置或为空时默认 platinum', function () {
    $controller = new class extends EasyController
    {
        public function exposeResolveLevelFromSource(string $source, array $sourceLevel): string
        {
            return $this->resolveLevelFromSource($source, $sourceLevel);
        }
    };

    expect($controller->exposeResolveLevelFromSource('unknown', []))->toBe('platinum');
    expect($controller->exposeResolveLevelFromSource('taobao', ['taobao' => '']))->toBe('platinum');
});

test('sourceLevel 已配置时新建用户使用映射等级', function () {
    Cache::flush();
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => 'Site', 'weight' => 0]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'url'],
        ['type' => 'string', 'value' => 'https://test.example.com']
    );
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'sourceLevel'],
        ['type' => 'array', 'value' => ['taobao' => 'gold', 'wechat' => 'partner']]
    );

    $controller = new class extends EasyController
    {
        public function exposeGetUserByEmail(string $email, string $source): User
        {
            return $this->getUserByEmail($email, $source);
        }
    };

    $user = $controller->exposeGetUserByEmail('new@example.com', 'taobao');
    expect($user->level_code)->toBe('gold');
});

test('sourceLevel 已配置时已有低等级用户自动提升', function () {
    Cache::flush();
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => 'Site', 'weight' => 0]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'url'],
        ['type' => 'string', 'value' => 'https://test.example.com']
    );
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'sourceLevel'],
        ['type' => 'array', 'value' => ['taobao' => 'platinum']]
    );

    $existing = User::factory()->create([
        'email' => 'existing@example.com',
        'level_code' => 'gold',
    ]);

    $controller = new class extends EasyController
    {
        public function exposeGetUserByEmail(string $email, string $source): User
        {
            return $this->getUserByEmail($email, $source);
        }
    };

    $user = $controller->exposeGetUserByEmail('existing@example.com', 'taobao');
    expect($user->level_code)->toBe('platinum');
});
