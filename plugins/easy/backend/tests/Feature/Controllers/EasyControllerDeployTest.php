<?php

use App\Models\Cert;
use App\Models\DeployToken;
use App\Models\Order;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Plugins\Easy\Models\Agiso;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function createActiveEasyOrder(): array
{
    $user = User::factory()->create(['email' => 'test@example.com']);
    $order = Order::factory()->create(['user_id' => $user->id]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'cert' => '-----BEGIN CERTIFICATE-----',
        'intermediate_cert' => '-----END CERTIFICATE-----',
        'private_key' => '-----BEGIN PRIVATE KEY-----',
        'validation' => [['domain' => 'example.com', 'method' => 'txt']],
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $agiso = Agiso::create([
        'tid' => 'TEST123',
        'pay_method' => 'taobao',
        'product_code' => 'test',
        'period' => 1,
        'price' => '10.00',
        'amount' => '10.00',
        'count' => 1,
        'user_id' => $user->id,
        'order_id' => $order->id,
        'recharged' => 1,
    ]);

    return [$user, $order, $cert, $agiso];
}

function setSiteSetting(string $key, mixed $value, string $type = 'string'): void
{
    Cache::flush();
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => 'Site', 'weight' => 0]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => $key],
        ['value' => $value, 'type' => $type]
    );
}

beforeEach(function () {
    Cache::flush();
    setSiteSetting('url', 'https://test.example.com');
});

test('active 状态默认返回 deploy 字段', function () {
    [$user] = createActiveEasyOrder();

    $response = $this->postJson('/api/easy/check', [
        'tid' => 'TEST123',
        'email' => $user->email,
    ]);

    $response->assertOk()->assertJson(['code' => 1]);

    $data = $response->json('data');
    expect($data['status'])->toBe('active');
    expect($data)->toHaveKey('deploy');
    expect($data['deploy'])->toHaveKeys(['install', 'deploy', 'iis_install', 'iis_deploy', 'bt_install', 'bt_deploy']);
    expect($data['deploy']['install'])->toHaveKeys(['linux', 'windows']);
    expect($data['deploy']['iis_install'])->toHaveKeys(['download', 'windows']);
});

test('deploy 命令包含正确的 order_id 和 token', function () {
    [$user, $order] = createActiveEasyOrder();

    $response = $this->postJson('/api/easy/check', [
        'tid' => 'TEST123',
        'email' => $user->email,
    ]);

    $data = $response->json('data');
    $deploy = $data['deploy'];

    expect($deploy['deploy'])->toContain("--order $order->id");
    expect($deploy['iis_deploy'])->toContain("--order $order->id");
    expect($deploy['bt_deploy'])->toContain("order=$order->id");

    $deployToken = DeployToken::where('user_id', $user->id)->first();
    expect($deployToken)->not->toBeNull();
    $token = $deployToken->token;
    expect($deploy['deploy'])->toContain("--token $token");
});

test('deploy 自动创建 DeployToken', function () {
    [$user] = createActiveEasyOrder();

    expect(DeployToken::where('user_id', $user->id)->exists())->toBeFalse();

    $this->postJson('/api/easy/check', [
        'tid' => 'TEST123',
        'email' => $user->email,
    ]);

    expect(DeployToken::where('user_id', $user->id)->exists())->toBeTrue();
});

test('deploy 复用已有 DeployToken', function () {
    [$user] = createActiveEasyOrder();

    $existing = DeployToken::create([
        'user_id' => $user->id,
        'token' => 'existing-token-1234567890123456',
    ]);

    $response = $this->postJson('/api/easy/check', [
        'tid' => 'TEST123',
        'email' => $user->email,
    ]);

    $deploy = $response->json('data.deploy');
    expect($deploy['deploy'])->toContain($existing->token);
    expect(DeployToken::where('user_id', $user->id)->count())->toBe(1);
});

test('easyAutoDeploy 为 false 时不返回 deploy 字段', function () {
    [$user] = createActiveEasyOrder();
    setSiteSetting('easyAutoDeploy', false, 'boolean');

    $response = $this->postJson('/api/easy/check', [
        'tid' => 'TEST123',
        'email' => $user->email,
    ]);

    $data = $response->json('data');
    expect($data['status'])->toBe('active');
    expect($data)->not->toHaveKey('deploy');
});

test('easyAutoDeploy 为字符串 false 时不返回 deploy 字段', function () {
    [$user] = createActiveEasyOrder();
    setSiteSetting('easyAutoDeploy', 'false');

    $response = $this->postJson('/api/easy/check', [
        'tid' => 'TEST123',
        'email' => $user->email,
    ]);

    $data = $response->json('data');
    expect($data)->not->toHaveKey('deploy');
});

test('deploy 命令包含正确的 URL 前缀', function () {
    [$user] = createActiveEasyOrder();

    $response = $this->postJson('/api/easy/check', [
        'tid' => 'TEST123',
        'email' => $user->email,
    ]);

    $deploy = $response->json('data.deploy');
    expect($deploy['deploy'])->toContain('https://test.example.com/api/deploy');
    expect($deploy['bt_deploy'])->toContain('https://test.example.com/api/deploy');
    // 未配置 releaseDomain 时回落到 {siteUrl}/release
    expect($deploy['install']['linux'])->toContain('test.example.com/release');
    expect($deploy['install']['windows'])->toContain('test.example.com/release');
});

test('配置 releaseDomain 时 deploy 命令使用独立域名', function () {
    setSiteSetting('releaseDomain', 'release.example.com');
    [$user] = createActiveEasyOrder();

    $response = $this->postJson('/api/easy/check', [
        'tid' => 'TEST123',
        'email' => $user->email,
    ]);

    $deploy = $response->json('data.deploy');
    expect($deploy['install']['linux'])->toContain('https://release.example.com/sslctl/install.sh');
    expect($deploy['install']['linux'])->toContain('-- release.example.com');
    expect($deploy['install']['windows'])->toContain('-ReleaseHost release.example.com');
    expect($deploy['iis_install']['download'])->toContain('https://release.example.com/sslctlw/');
});
