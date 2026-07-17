<?php

use Plugins\CloudDeploy\Deployers\Onepanel\OnepanelConsoleDeployer;
use Plugins\CloudDeploy\Deployers\Onepanel\OnepanelProvider;
use Plugins\CloudDeploy\Deployers\Onepanel\OnepanelSiteDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * 1Panel deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组追加 'onepanel'
 * 才会汇入 app(Registry::class)。provider key 'onepanel'（类/目录不能数字开头，对应 certimate 1panel）、
 * product keys 'site'（网站/证书）+ 'console'（面板自身 SSL）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new OnepanelProvider);
    $registry->registerDeployer('onepanel', 'site', fn () => new OnepanelSiteDeployer);
    $registry->registerDeployer('onepanel', 'console', fn () => new OnepanelConsoleDeployer);
};
