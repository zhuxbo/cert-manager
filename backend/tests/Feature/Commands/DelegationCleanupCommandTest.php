<?php

use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Delegation\DelegationConfigService;
use App\Services\Delegation\DelegationDnsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Traits\CreatesTestData;

uses(CreatesTestData::class);

beforeEach(function () {
    $this->dnsService = Mockery::mock(DelegationDnsService::class);
    $this->app->instance(DelegationDnsService::class, $this->dnsService);
});

afterEach(fn () => Mockery::close());

/** 配置一个会被 DelegationConfigService::all() 枚举到的代理域。 */
function cleanupConfigureDomain(string $domain, array $overrides = []): void
{
    Cache::flush();
    $group = SettingGroup::firstOrCreate(
        ['name' => 'delegation'],
        ['title' => '委托设置', 'weight' => 1],
    );
    $key = app(DelegationConfigService::class)->keyForDomain($domain);

    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => $key],
        [
            'type' => 'array',
            'value' => array_merge([
                'domain' => $domain,
                'provider' => 'cloudflare',
                'apiToken' => 'test-token',
                'zoneId' => 'test-zone',
            ], $overrides),
            'weight' => 1,
        ],
    );
    Setting::clearGroupCache($group->id);
}

function cleanupConfigureRawSetting(string $key, mixed $value): void
{
    Cache::flush();
    $group = SettingGroup::firstOrCreate(
        ['name' => 'delegation'],
        ['title' => '委托设置', 'weight' => 1],
    );
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => $key],
        ['type' => 'array', 'value' => $value, 'weight' => 1],
    );
    Setting::clearGroupCache($group->id);
}

function cleanupExpiredDnsRecord(string|int $id, string $name, string $value = 'token'): array
{
    return [
        'id' => $id,
        'name' => $name,
        'value' => $value,
        'changed_at' => now()->subDays(31)->timestamp,
    ];
}

test('未配置任何代理域时正常终止', function () {
    $this->artisan('delegation:cleanup')
        ->expectsOutputToContain('代理域名未设置')
        ->assertSuccessful();
});

test('单域没有需要清理的记录时正常退出', function () {
    cleanupConfigureDomain('proxy.example.com');

    $this->dnsService->shouldReceive('getAllTxtRecords')
        ->once()->with('proxy.example.com')->andReturn([]);
    $this->dnsService->shouldReceive('deleteRecords')->never();

    $this->artisan('delegation:cleanup')
        ->expectsOutputToContain('没有需要清理的记录')
        ->assertSuccessful();
});

test('仅删除 32 位 hex 委托记录并保留其他 TXT', function () {
    cleanupConfigureDomain('proxy.example.com');
    $hex32 = str_repeat('a', 32);

    $this->dnsService->shouldReceive('getAllTxtRecords')
        ->once()->with('proxy.example.com')->andReturn([
            ['id' => 1, 'name' => '_dmarc', 'value' => 'v=DMARC1; p=none;'],
            ['id' => 2, 'name' => '@', 'value' => 'v=spf1 include:_spf.example.com ~all'],
            ['id' => 3, 'name' => 'default._domainkey', 'value' => 'v=DKIM1; k=rsa; p=MIGf...'],
            cleanupExpiredDnsRecord(4, $hex32, 'orphan-token'),
        ]);
    $this->dnsService->shouldReceive('deleteRecords')
        ->once()->with('proxy.example.com', [4]);

    $this->artisan('delegation:cleanup')->assertSuccessful();
});

test('64 位 hex 历史委托记录仍被清理', function () {
    cleanupConfigureDomain('proxy.example.com');
    $hex64 = str_repeat('b', 64);

    $this->dnsService->shouldReceive('getAllTxtRecords')
        ->once()->with('proxy.example.com')->andReturn([
            ['id' => 10, 'name' => 'site-verification', 'value' => 'keep-me'],
            cleanupExpiredDnsRecord(11, $hex64, 'orphan-token-64'),
        ]);
    $this->dnsService->shouldReceive('deleteRecords')
        ->once()->with('proxy.example.com', [11]);

    $this->artisan('delegation:cleanup')->assertSuccessful();
});

test('未满 30 天以及缺失或畸形更新时间的委托记录均不删除', function () {
    cleanupConfigureDomain('proxy.example.com');
    $freshLabel = str_repeat('c', 32);
    $unknownLabel = str_repeat('d', 32);
    $malformedLabel = str_repeat('e', 32);

    $this->dnsService->shouldReceive('getAllTxtRecords')
        ->once()->with('proxy.example.com')->andReturn([
            [
                'id' => 'fresh-id',
                'name' => $freshLabel,
                'value' => 'fresh-token',
                'changed_at' => now()->subDays(29)->timestamp,
            ],
            ['id' => 'unknown-id', 'name' => $unknownLabel, 'value' => 'unknown-token', 'changed_at' => null],
            [
                'id' => 'malformed-id',
                'name' => $malformedLabel,
                'value' => 'malformed-token',
                'changed_at' => 'not-a-timestamp',
            ],
        ]);
    $this->dnsService->shouldReceive('deleteRecords')->never();

    $this->artisan('delegation:cleanup')->assertSuccessful();
});

test('同一 label 只按记录 ID 删除超过 30 天的旧值', function () {
    cleanupConfigureDomain('proxy.example.com');
    $label = str_repeat('e', 32);

    $this->dnsService->shouldReceive('getAllTxtRecords')
        ->once()->with('proxy.example.com')->andReturn([
            cleanupExpiredDnsRecord('old-id', $label, 'old-token'),
            [
                'id' => 'fresh-id',
                'name' => $label,
                'value' => 'fresh-token',
                'changed_at' => now()->subDays(2)->timestamp,
            ],
        ]);
    $this->dnsService->shouldReceive('deleteRecords')
        ->once()->with('proxy.example.com', ['old-id']);

    $this->artisan('delegation:cleanup')->assertSuccessful();
});

test('同一 label 的多条 TXT 完整删除后按初始记录数记录成功计数', function () {
    cleanupConfigureDomain('proxy.example.com');
    $label = str_repeat('1', 32);
    Log::spy();

    $this->dnsService->shouldReceive('getAllTxtRecords')
        ->once()->with('proxy.example.com')->andReturn([
            cleanupExpiredDnsRecord('first-token', $label, 'token-one'),
            cleanupExpiredDnsRecord('second-token', strtoupper($label), 'token-two'),
        ]);
    $this->dnsService->shouldReceive('deleteRecords')
        ->once()->with('proxy.example.com', ['first-token', 'second-token']);

    $this->artisan('delegation:cleanup')->assertSuccessful();

    Log::shouldHaveReceived('info')->once()->with(
        '批量清理委托DNS记录成功',
        [
            'proxy_domain' => 'proxy.example.com',
            'deleted_count' => 2,
            'deleted_labels' => [$label],
        ],
    );
});

test('后一 label 删除失败时成功计数只包含此前完整删除 label 的初始记录数', function () {
    cleanupConfigureDomain('proxy.example.com');
    $firstLabel = str_repeat('2', 32);
    $secondLabel = str_repeat('3', 32);
    Log::spy();

    $this->dnsService->shouldReceive('getAllTxtRecords')
        ->once()->with('proxy.example.com')->andReturn([
            cleanupExpiredDnsRecord('first-token', $firstLabel, 'token-one'),
            cleanupExpiredDnsRecord('second-token', strtoupper($firstLabel), 'token-two'),
            cleanupExpiredDnsRecord('third-token', $secondLabel, 'token-three'),
        ]);
    $this->dnsService->shouldReceive('deleteRecords')
        ->once()->ordered()->with('proxy.example.com', ['first-token', 'second-token']);
    $this->dnsService->shouldReceive('deleteRecords')
        ->once()->ordered()->with('proxy.example.com', ['third-token'])
        ->andThrow(new RuntimeException('second label failed'));

    $this->artisan('delegation:cleanup')->assertExitCode(1);

    Log::shouldHaveReceived('info')->once()->with(
        '批量清理委托DNS记录成功',
        [
            'proxy_domain' => 'proxy.example.com',
            'deleted_count' => 2,
            'deleted_labels' => [$firstLabel],
        ],
    );
});

test('相同 label 只在其绑定代理域进入保留集', function () {
    cleanupConfigureDomain('old.example.com');
    cleanupConfigureDomain('new.example.com');

    $user = $this->createTestUser();
    $sharedLabel = str_repeat('c', 32);
    $oldDelegation = $this->createTestDelegation($user, [
        'zone' => 'active.example.com',
        'label' => $sharedLabel,
        'proxy_domain' => 'old.example.com',
    ]);
    $this->createTestDelegation($this->createTestUser(), [
        'zone' => 'inactive.example.com',
        'label' => $sharedLabel,
        'proxy_domain' => 'new.example.com',
    ]);
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, [
        'status' => 'processing',
        'validation' => [[
            'domain' => 'active.example.com',
            'method' => 'txt',
            'delegation_id' => $oldDelegation->id,
            'auto_txt_written' => true,
        ]],
    ]);

    $this->dnsService->shouldReceive('getAllTxtRecords')
        ->once()->with('old.example.com')
        ->andReturn([cleanupExpiredDnsRecord('old-id', strtoupper($sharedLabel))]);
    $this->dnsService->shouldReceive('getAllTxtRecords')
        ->once()->with('new.example.com')
        ->andReturn([cleanupExpiredDnsRecord('new-id', $sharedLabel)]);
    $this->dnsService->shouldReceive('deleteRecords')
        ->once()->with('new.example.com', ['new-id']);
    $this->dnsService->shouldReceive('deleteRecords')
        ->with('old.example.com', Mockery::any())->never();

    $this->artisan('delegation:cleanup')->assertSuccessful();
});

test('在途订单按冻结目标保留 TXT 而不是共享记录当前代理域', function () {
    cleanupConfigureDomain('old.example.com');
    cleanupConfigureDomain('new.example.com');

    $user = $this->createTestUser();
    $delegation = $this->createTestDelegation($user, [
        'zone' => 'active.example.com',
        'label' => str_repeat('7', 32),
        'proxy_domain' => 'old.example.com',
    ]);
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, [
        'status' => 'processing',
        'validation' => [[
            'domain' => 'active.example.com',
            'method' => 'txt',
            'delegation_id' => $delegation->id,
            'delegation_target' => $delegation->label.'.new.example.com',
            'auto_txt_written' => true,
        ]],
    ]);

    $this->dnsService->shouldReceive('getAllTxtRecords')
        ->once()->with('old.example.com')
        ->andReturn([cleanupExpiredDnsRecord('old-id', $delegation->label)]);
    $this->dnsService->shouldReceive('getAllTxtRecords')
        ->once()->with('new.example.com')
        ->andReturn([cleanupExpiredDnsRecord('new-id', $delegation->label)]);
    $this->dnsService->shouldReceive('deleteRecords')
        ->once()->with('old.example.com', ['old-id']);
    $this->dnsService->shouldReceive('deleteRecords')
        ->with('new.example.com', Mockery::any())->never();

    $this->artisan('delegation:cleanup')->assertSuccessful();
});

test('一个域查询失败后继续清理其他域并失败退出', function () {
    cleanupConfigureDomain('old.example.com');
    cleanupConfigureDomain('new.example.com');
    $newOrphan = str_repeat('d', 32);

    $this->dnsService->shouldReceive('getAllTxtRecords')
        ->once()->with('old.example.com')
        ->andThrow(new RuntimeException('旧域配置不可用'));
    $this->dnsService->shouldReceive('getAllTxtRecords')
        ->once()->with('new.example.com')
        ->andReturn([cleanupExpiredDnsRecord('new-id', $newOrphan)]);
    $this->dnsService->shouldReceive('deleteRecords')
        ->once()->with('new.example.com', ['new-id']);

    $this->artisan('delegation:cleanup')
        ->expectsOutputToContain('old.example.com')
        ->assertExitCode(1);
});

test('一个域删除失败后继续删除其他域并失败退出', function () {
    cleanupConfigureDomain('old.example.com');
    cleanupConfigureDomain('new.example.com');
    $oldOrphan = str_repeat('e', 32);
    $newOrphan = str_repeat('f', 32);

    $this->dnsService->shouldReceive('getAllTxtRecords')
        ->once()->with('old.example.com')
        ->andReturn([cleanupExpiredDnsRecord('old-id', $oldOrphan)]);
    $this->dnsService->shouldReceive('getAllTxtRecords')
        ->once()->with('new.example.com')
        ->andReturn([cleanupExpiredDnsRecord('new-id', $newOrphan)]);
    $this->dnsService->shouldReceive('deleteRecords')
        ->once()->with('old.example.com', ['old-id'])
        ->andThrow(new RuntimeException('旧域删除失败'));
    $this->dnsService->shouldReceive('deleteRecords')
        ->once()->with('new.example.com', ['new-id']);

    $this->artisan('delegation:cleanup')->assertExitCode(1);
});

dataset('畸形在途 delegation_id', ['numeric string', 'boolean', 'float']);

test('在途 validation 的非正整数 delegation_id 在 DNS 操作前失败关闭', function (string $type) {
    cleanupConfigureDomain('proxy.example.com');
    $user = $this->createTestUser();
    $delegation = $this->createTestDelegation($user);
    $invalidId = match ($type) {
        'numeric string' => (string) $delegation->id,
        'boolean' => true,
        'float' => 1.5,
    };
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, [
        'status' => 'processing',
        'validation' => [[
            'domain' => 'example.com',
            'method' => 'txt',
            'delegation_id' => $invalidId,
            'auto_txt_written' => true,
        ]],
    ]);

    $this->dnsService->shouldReceive('getAllTxtRecords')->never();
    $this->dnsService->shouldReceive('deleteRecords')->never();

    $this->artisan('delegation:cleanup')
        ->expectsOutputToContain('委托 ID 无效')
        ->assertExitCode(1);
})->with('畸形在途 delegation_id');

test('畸形域配置不阻断健康域清理且最终失败退出', function () {
    cleanupConfigureDomain('healthy.example.com');
    cleanupConfigureRawSetting('missingDomain', [
        'provider' => 'cloudflare',
        'apiToken' => 'must-not-be-logged',
    ]);
    cleanupConfigureRawSetting('wrongKey', [
        'domain' => 'mismatch.example.com',
        'provider' => 'cloudflare',
        'apiToken' => 'must-not-be-logged-either',
    ]);

    $this->dnsService->shouldReceive('getAllTxtRecords')
        ->once()->with('healthy.example.com')->andReturn([]);
    $this->dnsService->shouldReceive('deleteRecords')->never();

    $this->artisan('delegation:cleanup')
        ->expectsOutputToContain('missingDomain')
        ->expectsOutputToContain('wrongKey')
        ->assertExitCode(1);
});

test('超长域配置不阻断健康域清理且最终失败退出', function () {
    cleanupConfigureDomain('healthy.example.com');
    cleanupConfigureRawSetting('overlongDomain', [
        'domain' => implode('.', array_fill(0, 51, 'ab')).'.com',
        'provider' => 'cloudflare',
        'apiToken' => 'must-not-be-logged',
    ]);

    $this->dnsService->shouldReceive('getAllTxtRecords')
        ->once()->with('healthy.example.com')->andReturn([]);
    $this->dnsService->shouldReceive('deleteRecords')->never();

    $this->artisan('delegation:cleanup')
        ->expectsOutputToContain('overlongDomain')
        ->assertExitCode(1);
});

test('只有畸形域配置时失败退出且不访问 DNS', function () {
    cleanupConfigureRawSetting('missingDomain', ['provider' => 'cloudflare']);

    $this->dnsService->shouldReceive('getAllTxtRecords')->never();
    $this->dnsService->shouldReceive('deleteRecords')->never();

    $this->artisan('delegation:cleanup')->assertExitCode(1);
});
