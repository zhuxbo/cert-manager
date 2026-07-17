<?php

use Plugins\CloudDeploy\Deployers\K8s\K8sProvider;
use Plugins\CloudDeploy\Deployers\K8s\K8sSecretDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * Kubernetes deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组追加 'k8s' 才会
 * 汇入 app(Registry::class)。provider key 'k8s'、product key 'secret'。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new K8sProvider);
    $registry->registerDeployer('k8s', 'secret', fn () => new K8sSecretDeployer);
};
