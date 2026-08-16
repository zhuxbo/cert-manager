<?php

use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Deployers\Samwaf\SamwafConsoleDeployer;
use Plugins\CloudDeploy\Deployers\Samwaf\SamwafDeployer;
use Plugins\CloudDeploy\Deployers\Samwaf\SamwafProvider;

/**
 * SamWaf deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new SamwafProvider);
    $registry->registerDeployer('samwaf', 'samwaf', fn () => new SamwafDeployer);
    $registry->registerDeployer('samwaf', 'console', fn () => new SamwafConsoleDeployer);
};
