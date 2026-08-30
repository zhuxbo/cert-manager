<?php

declare(strict_types=1);

use App\Services\Delegation\DelegationConfigService;
use App\Services\Delegation\DelegationDnsService;
use App\Services\Delegation\Dns\DelegationDnsProvider;
use App\Services\Delegation\Dns\DelegationDnsProviderFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::put('setting:group_name:delegation', [
        'proxyExampleCom' => [
            'provider' => 'cloudflare',
            'domain' => 'proxy.example.com',
            'zoneId' => 'zone-id',
            'apiToken' => 'never-log-api-token',
        ],
    ], 60);
});

afterEach(function () {
    Mockery::close();
});

function delegationDnsServiceWith(DelegationDnsProvider $provider): DelegationDnsService
{
    $factory = Mockery::mock(DelegationDnsProviderFactory::class);
    $factory->shouldReceive('make')
        ->with(Mockery::on(fn (array $config) => $config['domain'] === 'proxy.example.com'))
        ->andReturn($provider);

    return new DelegationDnsService($factory, new DelegationConfigService);
}

test('setTxtByLabel 按域选择 provider 并去重写入值', function () {
    $provider = Mockery::mock(DelegationDnsProvider::class);
    $provider->shouldReceive('upsertTxt')
        ->once()
        ->with('abc123hash', ['challenge-value-1'])
        ->andReturn(true);

    expect(delegationDnsServiceWith($provider)->setTxtByLabel(
        'proxy.example.com',
        'abc123hash',
        ['challenge-value-1', 'challenge-value-1'],
    ))->toBeTrue();
});

test('setTxtByLabel 参数为空时返回 false', function () {
    $factory = Mockery::mock(DelegationDnsProviderFactory::class);
    $factory->shouldNotReceive('make');
    $service = new DelegationDnsService($factory, new DelegationConfigService);

    expect($service->setTxtByLabel('', 'label', ['value']))->toBeFalse()
        ->and($service->setTxtByLabel('proxy.example.com', '', ['value']))->toBeFalse()
        ->and($service->setTxtByLabel('proxy.example.com', 'label', []))->toBeFalse();
});

test('订单写入异常转为 false 且日志不泄露凭据', function () {
    $secret = 'never-log-api-token';
    $provider = Mockery::mock(DelegationDnsProvider::class);
    $provider->shouldReceive('upsertTxt')
        ->once()
        ->andThrow(new RuntimeException('remote failed '.$secret));
    Log::spy();

    expect(delegationDnsServiceWith($provider)->setTxtByLabel(
        'proxy.example.com',
        'abc123hash',
        ['value'],
    ))->toBeFalse();

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) use ($secret) {
        return $message === '委托 TXT 记录写入失败'
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $secret);
    });
});

test('按域查询全部 TXT 并原样返回 provider 结果', function () {
    $records = [['id' => 'r1', 'name' => 'label', 'value' => 'value']];
    $provider = Mockery::mock(DelegationDnsProvider::class);
    $provider->shouldReceive('allTxt')->once()->andReturn($records);

    expect(delegationDnsServiceWith($provider)->getAllTxtRecords('proxy.example.com'))->toBe($records);
});

test('cleanup 查询异常保持抛出', function () {
    $provider = Mockery::mock(DelegationDnsProvider::class);
    $provider->shouldReceive('allTxt')->once()->andThrow(new RuntimeException('query failed'));

    expect(fn () => delegationDnsServiceWith($provider)->getAllTxtRecords('proxy.example.com'))
        ->toThrow(RuntimeException::class, 'query failed');
});

test('按域删除 label 下全部 TXT 值', function () {
    $provider = Mockery::mock(DelegationDnsProvider::class);
    $provider->shouldReceive('deleteTxt')->once()->with('abc123hash');

    delegationDnsServiceWith($provider)->deleteTxtByLabel('proxy.example.com', 'abc123hash');
});
