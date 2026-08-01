<?php

use App\Models\Cert;
use App\Models\CnameDelegation;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Delegation\CnameDelegationService;
use App\Services\Order\AutoRenewService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Carbon::setTestNow();
    Mockery::close();
});

function autoRenewMutationModels(
    array $orderAttributes = [],
    array $productAttributes = [],
    array $userSettings = []
): array {
    $user = new User(['auto_settings' => array_merge([
        'auto_renew' => false,
        'auto_reissue' => true,
    ], $userSettings)]);
    $product = new Product(array_merge([
        'status' => 1,
        'renew' => 1,
        'reissue' => 1,
        'product_type' => Product::TYPE_SSL,
    ], $productAttributes));
    $order = new Order(array_merge([
        'auto_renew' => true,
        'auto_reissue' => true,
        'period_till' => now()->addDays(15),
    ], $orderAttributes));
    $order->setRelation('product', $product);

    return [$order, $user];
}

test('willAutoRenewExecute 精确覆盖全部门槛', function (
    array $orderAttributes,
    array $productAttributes,
    array $userSettings,
    bool $expected
) {
    [$order, $user] = autoRenewMutationModels(
        $orderAttributes,
        $productAttributes,
        $userSettings
    );
    $service = app(AutoRenewService::class);

    expect($service->willAutoRenewExecute($order, $user))->toBe($expected);
})->with([
    '订单关闭' => [['auto_renew' => false], [], ['auto_renew' => true], false],
    '回落用户关闭' => [['auto_renew' => null], [], ['auto_renew' => false], false],
    '回落用户开启' => [['auto_renew' => null], [], ['auto_renew' => true], true],
    '产品禁用' => [[], ['status' => 0], [], false],
    '产品不支持续费' => [[], ['renew' => 0], [], false],
    '非 SSL' => [[], ['product_type' => Product::TYPE_SMIME], [], false],
    '剩余 16 天' => [['period_till' => now()->addDays(16)], [], [], false],
    '剩余 15 天' => [['period_till' => now()->addDays(15)], [], [], true],
    '已过期 30 天' => [['period_till' => now()->subDays(30)], [], [], true],
    '无结束时间' => [['period_till' => null], [], [], true],
]);

test('willAutoReissueExecute 精确覆盖全部门槛', function (
    array $orderAttributes,
    array $productAttributes,
    array $userSettings,
    bool $expected
) {
    [$order, $user] = autoRenewMutationModels(
        $orderAttributes,
        $productAttributes,
        $userSettings
    );
    $service = app(AutoRenewService::class);

    expect($service->willAutoReissueExecute($order, $user))->toBe($expected);
})->with([
    '订单关闭' => [['auto_reissue' => false], [], ['auto_reissue' => true], false],
    '回落用户关闭' => [['auto_reissue' => null], [], ['auto_reissue' => false], false],
    '回落用户开启' => [
        ['auto_reissue' => null, 'period_till' => now()->addDays(16)],
        [],
        ['auto_reissue' => true],
        true,
    ],
    '产品不支持重签' => [[], ['reissue' => 0], [], false],
    '产品禁用仍可重签' => [
        ['period_till' => now()->addDays(16)],
        ['status' => 0],
        [],
        true,
    ],
    '非 SSL' => [[], ['product_type' => Product::TYPE_CODESIGN], [], false],
    '剩余 15 天' => [['period_till' => now()->addDays(15)], [], [], false],
    '剩余 16 天' => [['period_till' => now()->addDays(16)], [], [], true],
    '已过期 30 天' => [['period_till' => now()->subDays(30)], [], [], false],
    '无结束时间' => [['period_till' => null], [], [], true],
]);

test('willBeHandledByAutoRenew 精确组合模板、channel 和续签结果', function (
    bool $templateEnabled,
    string $channel,
    array $orderAttributes,
    bool $expected
) {
    [$order, $user] = autoRenewMutationModels($orderAttributes);
    $order->setRelation('latestCert', new Cert(['channel' => $channel]));

    expect(app(AutoRenewService::class)->willBeHandledByAutoRenew(
        $order,
        $user,
        $templateEnabled
    ))->toBe($expected);
})->with([
    '模板关闭' => [false, 'web', [], false],
    'API 订单' => [true, 'api', [], false],
    '自动续费可执行' => [true, 'web', ['period_till' => now()->addDays(15)], true],
    '自动重签可执行' => [
        true,
        'web',
        ['auto_renew' => false, 'period_till' => now()->addDays(16)],
        true,
    ],
    '两者都不可执行' => [
        true,
        'web',
        ['auto_renew' => false, 'auto_reissue' => false],
        false,
    ],
]);

test('checkDelegationValidity 跳过空项、修剪域名并继续验证', function () {
    $delegation = new CnameDelegation(['zone' => 'example.com']);
    $mock = Mockery::mock(CnameDelegationService::class);
    $mock->shouldReceive('findDelegation')
        ->once()
        ->with(7, 'example.com', 'sectigo')
        ->andReturn($delegation);
    $mock->shouldReceive('checkAndUpdateValidity')
        ->once()
        ->with($delegation)
        ->andReturnTrue();

    expect((new AutoRenewService($mock))->checkDelegationValidity(
        7,
        ' , example.com , ',
        'sectigo'
    ))->toBeTrue();
});

test('checkDelegationValidity 任一验证失败立即返回 false', function () {
    $delegation = new CnameDelegation(['zone' => 'example.com']);
    $mock = Mockery::mock(CnameDelegationService::class);
    $mock->shouldReceive('findDelegation')->once()->andReturn($delegation);
    $mock->shouldReceive('checkAndUpdateValidity')->once()->andReturnFalse();

    expect((new AutoRenewService($mock))->checkDelegationValidity(
        7,
        'example.com,next.example.com',
        'sectigo'
    ))->toBeFalse();
});

test('isAutoRenewEnabled 优先订单值并回落到用户默认', function (
    ?bool $orderValue,
    bool $userValue,
    bool $expected
) {
    [$order, $user] = autoRenewMutationModels(
        ['auto_renew' => $orderValue],
        [],
        ['auto_renew' => $userValue]
    );

    expect(app(AutoRenewService::class)->isAutoRenewEnabled($order, $user))
        ->toBe($expected);
})->with([
    '订单 true' => [true, false, true],
    '订单 false' => [false, true, false],
    '回落用户 true' => [null, true, true],
    '回落用户 false' => [null, false, false],
]);
