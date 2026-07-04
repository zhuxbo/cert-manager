<?php

use Plugins\CloudDeploy\Deployers\Baotapanelgo\BaotapanelgoConsoleDeployer;
use Plugins\CloudDeploy\Deployers\Baotapanelgo\BaotapanelgoProvider;
use Plugins\CloudDeploy\Deployers\Baotapanelgo\BaotapanelgoSiteDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * 宝塔面板（Windows Go 版）deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组追加 'baotapanelgo'
 * 才会汇入 app(Registry::class)。provider key 'baotapanelgo'、product keys 'site' + 'console'。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new BaotapanelgoProvider);
    $registry->registerDeployer('baotapanelgo', 'site', fn () => new BaotapanelgoSiteDeployer);
    $registry->registerDeployer('baotapanelgo', 'console', fn () => new BaotapanelgoConsoleDeployer);
};
