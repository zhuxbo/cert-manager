<?php

use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudAoDeployer;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudCdnDeployer;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudCmsDeployer;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudElbDeployer;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudFaasDeployer;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudIcdnDeployer;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudLvdnDeployer;
use Plugins\CloudDeploy\Deployers\Ctcccloud\CtcccloudProvider;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * 天翼云 deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 7 端点：ao（边缘安全加速 AccessOne）/ cdn（CDN）/ cms（证书管理，仅上传）/ elb（弹性负载均衡，region 维度）/
 * faas（函数计算自定义域名，内联）/ icdn（国际 CDN）/ lvdn（视频直播加速）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'ctcccloud' 才会汇入 app(Registry::class)。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new CtcccloudProvider);
    $registry->registerDeployer('ctcccloud', 'ao', fn () => new CtcccloudAoDeployer);
    $registry->registerDeployer('ctcccloud', 'cdn', fn () => new CtcccloudCdnDeployer);
    $registry->registerDeployer('ctcccloud', 'cms', fn () => new CtcccloudCmsDeployer);
    $registry->registerDeployer('ctcccloud', 'elb', fn () => new CtcccloudElbDeployer);
    $registry->registerDeployer('ctcccloud', 'faas', fn () => new CtcccloudFaasDeployer);
    $registry->registerDeployer('ctcccloud', 'icdn', fn () => new CtcccloudIcdnDeployer);
    $registry->registerDeployer('ctcccloud', 'lvdn', fn () => new CtcccloudLvdnDeployer);
};
