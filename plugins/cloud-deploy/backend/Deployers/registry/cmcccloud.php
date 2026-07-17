<?php

use Plugins\CloudDeploy\Deployers\Cmcccloud\CmcccloudCdnDeployer;
use Plugins\CloudDeploy\Deployers\Cmcccloud\CmcccloudProvider;
use Plugins\CloudDeploy\Deployers\Cmcccloud\CmcccloudVlbDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * 移动云 deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 2 端点：cdn（CDN 域名内联绑证书）/ vlb（弹性负载均衡监听器绑证书，证书服务型，资源池维度）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'cmcccloud' 才会汇入 app(Registry::class)。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new CmcccloudProvider);
    $registry->registerDeployer('cmcccloud', 'cdn', fn () => new CmcccloudCdnDeployer);
    $registry->registerDeployer('cmcccloud', 'vlb', fn () => new CmcccloudVlbDeployer);
};
