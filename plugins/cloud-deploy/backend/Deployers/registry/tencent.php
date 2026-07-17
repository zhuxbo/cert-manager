<?php

use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Deployers\Tencent\TencentCdnDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentClbDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentCosDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentCssDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentEcdnDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentEoDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentGa2Deployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentGaapDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentProvider;
use Plugins\CloudDeploy\Deployers\Tencent\TencentScfDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentSslDeployDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentSslDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentSslUpdateDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentTseDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentVodDeployer;
use Plugins\CloudDeploy\Deployers\Tencent\TencentWafDeployer;

/**
 * 腾讯云 deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new TencentProvider);
    $registry->registerDeployer('tencent', 'cdn', fn () => new TencentCdnDeployer);
    $registry->registerDeployer('tencent', 'ecdn', fn () => new TencentEcdnDeployer);
    $registry->registerDeployer('tencent', 'eo', fn () => new TencentEoDeployer);
    $registry->registerDeployer('tencent', 'css', fn () => new TencentCssDeployer);
    $registry->registerDeployer('tencent', 'vod', fn () => new TencentVodDeployer);
    $registry->registerDeployer('tencent', 'clb', fn () => new TencentClbDeployer);
    $registry->registerDeployer('tencent', 'scf', fn () => new TencentScfDeployer);
    $registry->registerDeployer('tencent', 'waf', fn () => new TencentWafDeployer);
    $registry->registerDeployer('tencent', 'cos', fn () => new TencentCosDeployer);
    $registry->registerDeployer('tencent', 'gaap', fn () => new TencentGaapDeployer);
    $registry->registerDeployer('tencent', 'ga2', fn () => new TencentGa2Deployer);
    $registry->registerDeployer('tencent', 'ssl', fn () => new TencentSslDeployer);
    $registry->registerDeployer('tencent', 'ssl-deploy', fn () => new TencentSslDeployDeployer);
    $registry->registerDeployer('tencent', 'ssl-update', fn () => new TencentSslUpdateDeployer);
    $registry->registerDeployer('tencent', 'tse', fn () => new TencentTseDeployer);
};
