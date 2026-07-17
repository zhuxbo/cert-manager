<?php

use Plugins\CloudDeploy\Deployers\Ratpanel\RatpanelConsoleDeployer;
use Plugins\CloudDeploy\Deployers\Ratpanel\RatpanelProvider;
use Plugins\CloudDeploy\Deployers\Ratpanel\RatpanelSiteDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * 耗子面板（RatPanel）deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 * 两端点：site（网站证书）+ console（控制台证书）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new RatpanelProvider);
    $registry->registerDeployer('ratpanel', 'site', fn () => new RatpanelSiteDeployer);
    $registry->registerDeployer('ratpanel', 'console', fn () => new RatpanelConsoleDeployer);
};
