<?php

use Plugins\CloudDeploy\Deployers\Flexcdn\FlexcdnDeployer;
use Plugins\CloudDeploy\Deployers\Flexcdn\FlexcdnProvider;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * FlexCDN deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new FlexcdnProvider);
    $registry->registerDeployer('flexcdn', 'flexcdn', fn () => new FlexcdnDeployer);
};
