<?php

declare(strict_types=1);

use App\Services\Delegation\ProxyDNS;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

// 锁定 ProxyDNS 单例 DnspodClient 的 reqTimeout=15（36759f48：60→15，收紧 FPM 同步路径最坏占用，
// 同时覆盖 DelegationCleanupCommand 的分页查询，见 ProxyDNS::initializeClient 内注释）。
//
// get_system_setting('site','delegation') → Setting::getValue → getByGroupName('site')
//   → Cache::remember('setting:group_name:site', ...)（Setting::CACHE_PREFIX = 'setting:'）
// 直接 put 该缓存键即可命中、不查 DB、不真联网（与 SdkUploadDocumentTimeoutTest.php 同一手法）。
//
// initializeClient() 是 protected，反射调用触发一次初始化（构造 DnspodClient 仅本地组装
// ClientProfile/HttpConnection，不发起网络请求）；随后反射读 protected $client，沿
// DnspodClient(AbstractClient)::getClientProfile()（public）→ ClientProfile::getHttpProfile()
// （public）→ HttpProfile::getReqTimeout()（public）取值断言。
beforeEach(function () {
    Cache::put('setting:group_name:site', [
        'delegation' => [
            'secretId' => 'test-secret-id',
            'secretKey' => 'test-secret-key',
            'region' => 'ap-guangzhou',
            'proxyZone' => 'proxy.example.com',
        ],
    ], 60);
});

it('DnspodClient 单例 reqTimeout 为 15s（覆盖 FPM 写 TXT + console cleanup 分页）', function () {
    $proxyDNS = new ProxyDNS;

    $initialize = new ReflectionMethod(ProxyDNS::class, 'initializeClient');
    $initialize->invoke($proxyDNS);

    $clientProp = new ReflectionProperty(ProxyDNS::class, 'client');
    $client = $clientProp->getValue($proxyDNS);

    expect($client)->not->toBeNull()
        ->and($client->getClientProfile()->getHttpProfile()->getReqTimeout())->toBe(15);
});
