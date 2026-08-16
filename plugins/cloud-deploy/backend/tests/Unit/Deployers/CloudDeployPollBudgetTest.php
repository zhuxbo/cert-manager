<?php

use Plugins\CloudDeploy\Deployers\Aliyun\AliyunCasDeployDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\HasPollBudget;
use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Deployers\Tencent\TencentClbDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentCosDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentSslDeployDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentSslUpdateDeployer;
use Plugins\CloudDeploy\Deployers\Wangsu\WangsuCdnProDeployer;
use Plugins\CloudDeploy\Deployers\Zenlayer\ZenlayerCdnDeployer;
use Plugins\CloudDeploy\Deployers\Zenlayer\ZenlayerGaDeployer;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| G2 时序不变式（timing I4）——计算断言，不真 sleep。
|
| 每个长轮询 deployer 的 bind 最坏耗时（所有 SDK 调用各吃满 client timeout T + 迭代间隔，末次不 sleep）
| 必须 ≤50s，为 CloudDeployJob $timeout=55 留 5s 余量。任何把 maxPollAttempts / pollIntervalSeconds /
| 客户端 timeout 常量回调破预算的改动 → 本测试红。各 T 来自单一来源常量：
| - Aliyun：**readTimeout+connectTimeout 之和**（darabonba Dara.php:368 把 Guzzle 总 timeout 设为二者
|   之和，非仅 read），经 BuildsAliyunConfig::aliyunCallBudgetSeconds() 派生；
| - Wangsu/Zenlayer：RestClient 的 TIMEOUT_SECONDS（Guzzle RequestOptions::TIMEOUT 即总超时）；
| - Tencent：*Deployer::CLIENT_TIMEOUT_SECONDS（setReqTimeout 即 Guzzle timeout 总超时）。
|--------------------------------------------------------------------------
*/

dataset('long_poll_deployers', [
    'aliyun casdeploy' => [fn () => new AliyunCasDeployDeployer, 40],
    'wangsu cdnpro' => [fn () => new WangsuCdnProDeployer, 40],
    'tencent clb' => [fn () => new TencentClbDeployer, 50],
    'tencent cos' => [fn () => new TencentCosDeployer, 40],
    'tencent ssl-deploy' => [fn () => new TencentSslDeployDeployer, 45],
    'tencent ssl-update' => [fn () => new TencentSslUpdateDeployer, 30],
    'zenlayer cdn' => [fn () => new ZenlayerCdnDeployer, 50],
    'zenlayer ga' => [fn () => new ZenlayerGaDeployer, 40],
]);

test('长轮询 deployer bind 预算 ≤50s 且等于钉定值（常量回调即红）', function (Closure $make, int $expected) {
    $deployer = $make();
    expect($deployer)->toBeInstanceOf(HasPollBudget::class);

    $worst = $deployer->pollBudget()->worstCaseBindSeconds();
    expect($worst)->toBeLessThanOrEqual(50)
        ->and($worst)->toBe($expected);
})->with('long_poll_deployers');

test('Aliyun 预算 T = readTimeout+connectTimeout 之和（darabonba Dara.php:368 合成 Guzzle 总 timeout，防再漂移）', function () {
    $expected = intdiv(
        AliyunCasDeployDeployer::ALIYUN_READ_TIMEOUT_MS + AliyunCasDeployDeployer::ALIYUN_CONNECT_TIMEOUT_MS,
        1000,
    );

    expect((new AliyunCasDeployDeployer)->pollBudget()->clientTimeoutSeconds)->toBe($expected)
        ->and(AliyunCasDeployDeployer::aliyunCallBudgetSeconds())->toBe($expected);
});

test('全仓恰好这 8 个 deployer 实现 HasPollBudget（新增轮询端点须补预算守门）', function () {
    /** @var Registry $registry */
    $registry = app(Registry::class);

    $implementing = [];
    foreach ($registry->allDeployers() as ['provider' => $p, 'product' => $pr]) {
        if ($registry->resolveDeployer($p, $pr) instanceof HasPollBudget) {
            $implementing[] = "$p/$pr";
        }
    }
    sort($implementing);

    $expected = [
        'aliyun/casdeploy',
        'tencent/clb',
        'tencent/cos',
        'tencent/ssl-deploy',
        'tencent/ssl-update',
        'wangsu/cdnpro',
        'zenlayer/cdn',
        'zenlayer/ga',
    ];
    sort($expected);

    expect($implementing)->toEqual($expected);
});
