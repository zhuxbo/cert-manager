<?php

use Plugins\CloudDeploy\Deployers\Lecdn\LecdnDeployer;
use Plugins\CloudDeploy\Deployers\Lecdn\LecdnProvider;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * LeCDN deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new LecdnProvider);
    $registry->registerDeployer('lecdn', 'lecdn', fn () => new LecdnDeployer);
};
