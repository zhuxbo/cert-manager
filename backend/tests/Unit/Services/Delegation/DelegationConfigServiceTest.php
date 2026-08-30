<?php

use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Delegation\DelegationConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('delegation');

function delegationConfigSetting(string $key, mixed $value, string $type = 'array'): void
{
    $group = SettingGroup::firstOrCreate(
        ['name' => 'delegation'],
        ['title' => '委托设置', 'description' => null, 'weight' => 1],
    );

    Setting::create([
        'group_id' => $group->id,
        'key' => $key,
        'type' => $type,
        'options' => null,
        'is_multiple' => false,
        'value' => $value,
        'description' => '测试委托设置',
        'weight' => 1,
    ]);
}

test('规范化域名并生成设置键', function () {
    $service = app(DelegationConfigService::class);

    expect($service->normalizeDomain('PROXY.EXAMPLE.COM.'))->toBe('proxy.example.com')
        ->and($service->keyForDomain('PROXY.EXAMPLE.COM.'))->toBe('proxyExampleCom');
});

test('Unicode 与 Punycode 委托域规范为同一 ASCII 域和同一设置键', function () {
    $service = app(DelegationConfigService::class);
    $unicode = '例子.测试';
    $punycode = 'xn--fsqu00a.xn--0zwm56d';

    expect($service->normalizeDomain($unicode))->toBe($punycode)
        ->and($service->normalizeDomain(strtoupper($punycode).'.'))->toBe($punycode)
        ->and($service->keyForDomain($unicode))->toBe($service->keyForDomain($punycode));
});

test('嵌入 Unicode 域配置可通过等价 Punycode 精确读取', function () {
    $service = app(DelegationConfigService::class);
    $punycode = 'xn--fsqu00a.xn--0zwm56d';
    delegationConfigSetting($service->keyForDomain($punycode), [
        'domain' => '例子.测试',
        'provider' => 'cloudflare',
        'apiToken' => 'test-token',
        'zoneId' => 'zone-id',
    ]);

    expect($service->get($punycode)['domain'])->toBe($punycode)
        ->and($service->all())->toHaveKey($punycode);
});

test('拒绝 ASCII 总长或单标签超过 DNS 上限的委托域', function (string $domain) {
    $service = app(DelegationConfigService::class);

    expect(fn () => $service->normalizeDomain($domain))->toThrow(InvalidArgumentException::class);
})->with([
    '单标签 64 字符' => [str_repeat('a', 64).'.example.com'],
    '总长超过 253 字符' => [implode('.', array_fill(0, 43, 'aaaaa')).'.com'],
]);

test('返回默认代理域及对应 provider 配置', function () {
    delegationConfigSetting('defaultDomain', 'proxy.example.com', 'string');
    delegationConfigSetting('proxyExampleCom', [
        'domain' => 'proxy.example.com',
        'provider' => 'cloudflare',
        'apiToken' => 'test-token',
        'zoneId' => 'zone-id',
    ]);

    $service = app(DelegationConfigService::class);

    expect($service->defaultDomain())->toBe('proxy.example.com')
        ->and($service->get('PROXY.EXAMPLE.COM.'))->toMatchArray([
            'domain' => 'proxy.example.com',
            'provider' => 'cloudflare',
            'apiToken' => 'test-token',
            'zoneId' => 'zone-id',
        ])
        ->and($service->all())->toBe([
            'proxy.example.com' => [
                'domain' => 'proxy.example.com',
                'provider' => 'cloudflare',
                'apiToken' => 'test-token',
                'zoneId' => 'zone-id',
            ],
        ]);
});

test('返回有效的 Aliyun 代理域配置', function () {
    delegationConfigSetting('proxyExampleCom', [
        'domain' => 'proxy.example.com',
        'provider' => 'aliyun',
        'accessKeyId' => 'access-key-id',
        'accessKeySecret' => 'access-key-secret',
    ]);

    expect(app(DelegationConfigService::class)->get('proxy.example.com'))
        ->toMatchArray([
            'domain' => 'proxy.example.com',
            'provider' => 'aliyun',
            'accessKeyId' => 'access-key-id',
            'accessKeySecret' => 'access-key-secret',
        ]);
});

test('缺失默认域或域名配置时返回空值', function () {
    $service = app(DelegationConfigService::class);

    expect($service->defaultDomain())->toBe('')
        ->and($service->get('proxy.example.com'))->toBe([])
        ->and($service->all())->toBe([]);
});

test('不完整的 provider 示例和任意草稿均不参与运行配置', function () {
    delegationConfigSetting('tencentExample', [
        'domain' => '',
        'provider' => 'tencent',
        'secretId' => '',
        'secretKey' => '',
    ]);
    delegationConfigSetting('cloudflareExample', [
        'domain' => '',
        'provider' => 'cloudflare',
        'zoneId' => '',
        'apiToken' => '',
    ]);
    delegationConfigSetting('aliyunExample', [
        'domain' => '',
        'provider' => 'aliyun',
        'accessKeyId' => '',
        'accessKeySecret' => '',
    ]);
    delegationConfigSetting('customDraftExampleCom', [
        'domain' => 'custom-draft.example.com',
        'provider' => 'cloudflare',
        'zoneId' => '',
        'apiToken' => '',
    ]);

    $service = app(DelegationConfigService::class);

    expect($service->all())->toBe([])
        ->and($service->get('custom-draft.example.com'))->toBe([])
        ->and($service->invalidSettings())->toBe([
            'customDraftExampleCom' => 'provider 或凭据无效',
        ]);
});

test('拒绝嵌入域名与请求域名不一致的配置', function () {
    delegationConfigSetting('proxyExampleCom', [
        'domain' => 'other.example.com',
        'provider' => 'cloudflare',
        'apiToken' => 'test-token',
    ]);

    $service = app(DelegationConfigService::class);

    expect($service->get('proxy.example.com'))->toBe([])
        ->and($service->all())->toBe([]);
});

test('拒绝与域名派生键冲突的配置', function () {
    delegationConfigSetting('defaultDomain', 'proxy.example.com', 'string');
    delegationConfigSetting('proxyExampleCom', [
        'domain' => 'proxy-example.com',
        'provider' => 'cloudflare',
        'apiToken' => 'test-token',
        'zoneId' => 'zone-id',
    ]);

    $service = app(DelegationConfigService::class);

    expect($service->get('proxy.example.com'))->toBe([])
        ->and($service->get('proxy-example.com')['domain'])->toBe('proxy-example.com');
});

dataset('无效委托 provider 配置', [
    '腾讯云缺少 secret key' => [[
        'domain' => 'proxy.example.com',
        'provider' => 'tencent',
        'secretId' => 'secret-id',
    ]],
    'Cloudflare 缺少 zone id' => [[
        'domain' => 'proxy.example.com',
        'provider' => 'cloudflare',
        'apiToken' => 'api-token',
    ]],
    '阿里云缺少 access key id' => [[
        'domain' => 'proxy.example.com',
        'provider' => 'aliyun',
        'accessKeySecret' => 'access-key-secret',
    ]],
    '阿里云缺少 access key secret' => [[
        'domain' => 'proxy.example.com',
        'provider' => 'aliyun',
        'accessKeyId' => 'access-key-id',
    ]],
    '未知 provider' => [[
        'domain' => 'proxy.example.com',
        'provider' => 'route53',
        'accessKey' => 'access-key',
    ]],
]);

test('拒绝 provider 缺少必填凭据的配置', function (array $config) {
    delegationConfigSetting('proxyExampleCom', $config);

    $service = app(DelegationConfigService::class);

    expect($service->get('proxy.example.com'))->toBe([])
        ->and($service->all())->toBe([])
        ->and($service->invalidSettings())->toBe([
            'proxyExampleCom' => 'provider 或凭据无效',
        ]);
})->with('无效委托 provider 配置');

test('拒绝超过 settings 键长度上限的域名', function () {
    $service = app(DelegationConfigService::class);
    $domain = implode('.', array_fill(0, 51, 'ab')).'.com';

    expect(fn () => $service->keyForDomain($domain))->toThrow(InvalidArgumentException::class);
});

test('报告畸形域配置且不在摘要中暴露凭据', function () {
    delegationConfigSetting('defaultDomain', 'proxy.example.com', 'string');
    delegationConfigSetting('missingDomain', [
        'provider' => 'cloudflare',
        'apiToken' => 'missing-domain-secret',
    ]);
    delegationConfigSetting('mismatchedKey', [
        'domain' => 'mismatch.example.com',
        'provider' => 'cloudflare',
        'apiToken' => 'mismatch-secret',
    ]);
    delegationConfigSetting('emptyDomain', [
        'domain' => '  . ',
        'provider' => 'cloudflare',
        'apiToken' => 'empty-domain-secret',
    ]);
    delegationConfigSetting('overlongDomain', [
        'domain' => implode('.', array_fill(0, 51, 'ab')).'.com',
        'provider' => 'cloudflare',
        'apiToken' => 'overlong-secret',
    ]);

    $service = app(DelegationConfigService::class);
    $invalid = $service->invalidSettings();

    expect($service->all())->toBe([])
        ->and($invalid)->toEqual([
            'missingDomain' => '缺少有效 domain',
            'mismatchedKey' => 'domain 与设置键不匹配',
            'emptyDomain' => '缺少有效 domain',
            'overlongDomain' => 'domain 派生设置键超过 100 个字符',
        ])
        ->and(json_encode($invalid))->not->toContain('secret')
        ->and($invalid)->not->toHaveKey('defaultDomain');
});

test('invalidSettings 报告 delegation 组的 wrong type 和非数组值', function () {
    delegationConfigSetting('wrongTypeExampleCom', 'wrong-type.example.com', 'string');
    delegationConfigSetting('brokenArrayExampleCom', ['domain' => 'broken-array.example.com']);
    $group = SettingGroup::where('name', 'delegation')->firstOrFail();
    Setting::where('group_id', $group->id)
        ->where('key', 'brokenArrayExampleCom')
        ->update(['value' => '{broken-json']);
    Setting::clearGroupCache($group->id);

    expect(app(DelegationConfigService::class)->invalidSettings())
        ->toHaveKeys(['wrongTypeExampleCom', 'brokenArrayExampleCom']);
});
