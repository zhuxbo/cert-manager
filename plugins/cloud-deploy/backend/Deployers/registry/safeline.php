<?php

use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Deployers\Safeline\SafelineDeployer;
use Plugins\CloudDeploy\Deployers\Safeline\SafelineProvider;

/**
 * 雷池 WAF（SafeLine）deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new SafelineProvider);
    $registry->registerDeployer('safeline', 'safeline', fn () => new SafelineDeployer);
};
