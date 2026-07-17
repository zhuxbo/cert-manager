<?php

use Plugins\CloudDeploy\Deployers\Goedge\GoedgeDeployer;
use Plugins\CloudDeploy\Deployers\Goedge\GoedgeProvider;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * GoEdge deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new GoedgeProvider);
    $registry->registerDeployer('goedge', 'goedge', fn () => new GoedgeDeployer);
};
