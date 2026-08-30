<?php

use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\Task;
use App\Services\Delegation\AutoDcvTxtService;
use App\Services\Delegation\DelegationConfigService;
use App\Services\Order\Api\Api;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Traits\ActsAsUser;
use Tests\Traits\CreatesTestData;

uses(ActsAsUser::class, CreatesTestData::class);

function configureRevalidateProxyDomain(string $provider, string $domain = 'legacy.example.com'): void
{
    $configService = app(DelegationConfigService::class);
    $group = SettingGroup::firstOrCreate(
        ['name' => 'delegation'],
        ['title' => 'CNAME委托', 'description' => null, 'weight' => 11],
    );

    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => $configService->keyForDomain($domain)],
        [
            'type' => 'array',
            'options' => null,
            'is_multiple' => false,
            'value' => $provider === 'cloudflare'
                ? ['domain' => $domain, 'provider' => 'cloudflare', 'apiToken' => 'cf-token', 'zoneId' => 'cf-zone']
                : ['domain' => $domain, 'provider' => 'tencent', 'secretId' => 'secret-id', 'secretKey' => 'secret-key'],
            'description' => '测试代理域',
            'weight' => 2,
        ],
    );
}

test('重验证控制器清标记后通过绑定域的当前 Cloudflare 配置重写 TXT', function () {
    Queue::fake();
    $user = $this->createTestUser();
    $product = $this->createTestProduct(['source' => 'default']);
    $order = $this->createTestOrder($user, $product);
    $delegation = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'proxy_domain' => 'legacy.example.com',
    ]);
    $cert = $this->createTestCert($order, [
        'status' => 'processing',
        'api_id' => 'CA-REVALIDATE-1',
        'dcv' => ['method' => 'txt', 'is_delegate' => true, 'dns' => ['host' => '_dnsauth']],
        'validation' => [[
            'host' => '_dnsauth.example.com',
            'domain' => 'example.com',
            'value' => 'REVALIDATE-TOKEN',
            'delegation_id' => $delegation->id,
            'delegation_target' => $delegation->target_fqdn,
            'delegation_valid' => true,
            'auto_txt_written' => true,
            'auto_txt_written_at' => '2026-08-01 00:00:00',
        ]],
    ]);

    configureRevalidateProxyDomain('tencent');
    configureRevalidateProxyDomain('cloudflare');
    configureRevalidateProxyDomain('cloudflare', 'new.example.net');
    $group = SettingGroup::where('name', 'delegation')->firstOrFail();
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'defaultDomain'],
        ['type' => 'string', 'value' => 'new.example.net'],
    );

    expect($cert->fresh()->validation[0])
        ->toHaveKey('auto_txt_written', true)
        ->toHaveKey('auto_txt_written_at', '2026-08-01 00:00:00');

    $api = Mockery::mock(Api::class);
    $api->shouldReceive('revalidate')->once()->with($order->id)->andReturn(['code' => 1]);
    app()->instance(Api::class, $api);

    $this->actingAsUser($user)
        ->postJson("/api/order/revalidate/$order->id")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($cert->fresh()->validation[0])
        ->not->toHaveKey('auto_txt_written')
        ->toHaveKey('delegation_target', $delegation->target_fqdn)
        ->and(Task::where('order_id', $order->id)->where('action', 'delegation')->exists())->toBeTrue();

    Http::fake(function ($request) {
        if ($request->method() === 'GET') {
            return Http::response([
                'success' => true,
                'result' => [],
                'result_info' => ['page' => 1, 'total_pages' => 1],
            ]);
        }

        return Http::response(['success' => true, 'result' => ['id' => 'cf-record-1']]);
    });

    expect((new AutoDcvTxtService)->handleOrder($order->fresh()))->toBeTrue();

    Http::assertSent(function ($request) use ($delegation) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.cloudflare.com/client/v4/zones/cf-zone/dns_records'
            && $request['name'] === $delegation->label.'.legacy.example.com'
            && $request['content'] === 'REVALIDATE-TOKEN';
    });
    expect($cert->fresh()->validation[0]['auto_txt_written'])->toBeTrue();
});
