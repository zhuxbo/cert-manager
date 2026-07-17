<?php

use Plugins\CloudDeploy\Deployers\Baotawaf\BaotawafConsoleDeployer;
use Plugins\CloudDeploy\Deployers\Baotawaf\BaotawafProvider;
use Plugins\CloudDeploy\Deployers\Baotawaf\BaotawafSiteDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * 堡塔云 WAF deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组追加 'baotawaf'
 * 才会汇入 app(Registry::class)。provider key 'baotawaf'、product keys 'site' + 'console'。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new BaotawafProvider);
    $registry->registerDeployer('baotawaf', 'site', fn () => new BaotawafSiteDeployer);
    $registry->registerDeployer('baotawaf', 'console', fn () => new BaotawafConsoleDeployer);
};
