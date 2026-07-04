<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('credentials 落库为密文且可解密', function () {
    $access = CloudDeployAccess::create([
        'user_id' => 1,
        'name' => '我的阿里云',
        'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK123', 'access_key_secret' => 'SECRET456'],
    ]);

    // DB 原始值是密文，不含明文
    $raw = DB::table('cloud_deploy_accesses')->where('id', $access->id)->value('credentials');
    expect($raw)->not->toContain('AK123');
    expect($raw)->not->toContain('SECRET456');

    // 重新取出解密正确
    $fresh = CloudDeployAccess::find($access->id);
    expect($fresh->credentials)->toBe(['access_key_id' => 'AK123', 'access_key_secret' => 'SECRET456']);
});

test('credentials 默认 hidden 不进数组', function () {
    $access = CloudDeployAccess::create([
        'user_id' => 1, 'name' => 'x', 'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'AK', 'access_key_secret' => 'S'],
    ]);

    expect($access->toArray())->not->toHaveKey('credentials');
});
