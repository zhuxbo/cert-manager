<?php

use Plugins\CloudDeploy\Deployers\Qingcloud\QingcloudLbDeployer;
use Plugins\CloudDeploy\Deployers\Qingcloud\QingcloudProvider;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * 青云 deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 1 端点：lb（负载均衡服务器证书绑定，证书服务型，zone 维度）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'qingcloud' 才会汇入 app(Registry::class)。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new QingcloudProvider);
    $registry->registerDeployer('qingcloud', 'lb', fn () => new QingcloudLbDeployer);
};
