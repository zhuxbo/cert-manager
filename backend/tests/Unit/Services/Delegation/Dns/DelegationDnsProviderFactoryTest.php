<?php

declare(strict_types=1);

use App\Services\Delegation\Dns\AliyunDnsProvider;
use App\Services\Delegation\Dns\CloudflareDnsProvider;
use App\Services\Delegation\Dns\DelegationDnsProviderFactory;
use App\Services\Delegation\Dns\TencentDnsProvider;
use Tests\TestCase;

uses(TestCase::class);

test('按配置选择 Tencent provider', function () {
    $provider = (new DelegationDnsProviderFactory)->make([
        'provider' => 'tencent',
        'domain' => 'proxy.example.com',
        'secretId' => 'secret-id',
        'secretKey' => 'secret-key',
    ]);

    expect($provider)->toBeInstanceOf(TencentDnsProvider::class);
});

test('按配置选择 Cloudflare provider', function () {
    $provider = (new DelegationDnsProviderFactory)->make([
        'provider' => 'cloudflare',
        'domain' => 'proxy.example.com',
        'zoneId' => 'zone-id',
        'apiToken' => 'api-token',
    ]);

    expect($provider)->toBeInstanceOf(CloudflareDnsProvider::class);
});

test('按配置选择 Aliyun provider', function () {
    $provider = (new DelegationDnsProviderFactory)->make([
        'provider' => 'aliyun',
        'domain' => 'proxy.example.com',
        'accessKeyId' => 'access-key-id',
        'accessKeySecret' => 'access-key-secret',
    ]);

    expect($provider)->toBeInstanceOf(AliyunDnsProvider::class);
});

test('拒绝未知 provider 且异常不泄露凭据', function () {
    $secret = 'never-expose-this-secret';

    try {
        (new DelegationDnsProviderFactory)->make([
            'provider' => 'route53',
            'domain' => 'proxy.example.com',
            'secretKey' => $secret,
        ]);
        test()->fail('未知 provider 应抛出异常');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->not->toContain($secret);
    }
});
