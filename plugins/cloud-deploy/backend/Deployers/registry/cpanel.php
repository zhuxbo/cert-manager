<?php

use Plugins\CloudDeploy\Deployers\Cpanel\CpanelDeployer;
use Plugins\CloudDeploy\Deployers\Cpanel\CpanelProvider;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * cPanel deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new CpanelProvider);
    $registry->registerDeployer('cpanel', 'cpanel', fn () => new CpanelDeployer);
};
