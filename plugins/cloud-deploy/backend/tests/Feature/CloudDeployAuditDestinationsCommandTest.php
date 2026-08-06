<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('出站目标审计只输出最小定位信息且不泄露路径查询或凭证', function () {
    $user = User::factory()->create();
    CloudDeployAccess::create([
        'user_id' => $user->id,
        'name' => '公网 Webhook',
        'provider' => 'webhook',
        'credentials' => [
            'url' => 'https://1.1.1.1/private/callback?token=SECRET-QUERY',
            'headers' => 'Authorization: Bearer SECRET-HEADER',
        ],
    ]);
    CloudDeployAccess::create([
        'user_id' => $user->id,
        'name' => '私网面板',
        'provider' => 'samwaf',
        'credentials' => [
            'server_url' => 'https://10.20.30.40:9443/api?key=SECRET-PATH',
            'api_key' => 'SECRET-API-KEY',
        ],
    ]);
    CloudDeployAccess::create([
        'user_id' => $user->id,
        'name' => '无目标字段',
        'provider' => 'aliyun',
        'credentials' => ['access_key_id' => 'SECRET-AK', 'access_key_secret' => 'SECRET-SK'],
    ]);

    expect(Artisan::call('cloud-deploy:audit-destinations', ['--json' => true]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('"provider":"webhook"')
        ->toContain('"host":"1.1.1.1"')
        ->toContain('"status":"allowed"')
        ->toContain('"provider":"samwaf"')
        ->toContain('"host":"10.20.30.40"')
        ->toContain('"status":"private_not_allowed"')
        ->toContain('"suggested_allowlist":"samwaf@10.20.30.40:9443"')
        ->not->toContain('/private/callback')
        ->not->toContain('/api')
        ->not->toContain('SECRET-QUERY')
        ->not->toContain('SECRET-HEADER')
        ->not->toContain('SECRET-PATH')
        ->not->toContain('SECRET-API-KEY')
        ->not->toContain('SECRET-AK')
        ->not->toContain('SECRET-SK');

    expect(CloudDeployAccess::withoutGlobalScopes()->count())->toBe(3);
});
