<?php

use Plugins\CloudDeploy\Deployers\Baotapanel\BaotapanelConsoleDeployer;
use Plugins\CloudDeploy\Deployers\Baotapanel\BaotapanelProvider;
use Plugins\CloudDeploy\Deployers\Baotapanel\BaotapanelSiteDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * 宝塔面板 deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组追加 'baotapanel'
 * 才会汇入 app(Registry::class)。provider key 'baotapanel'、product keys 'site'（网站）+ 'console'（面板 SSL）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new BaotapanelProvider);
    $registry->registerDeployer('baotapanel', 'site', fn () => new BaotapanelSiteDeployer);
    $registry->registerDeployer('baotapanel', 'console', fn () => new BaotapanelConsoleDeployer);
};
