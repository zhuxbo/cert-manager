<?php

use Plugins\CloudDeploy\Deployers\Huaweicloud\AadDeployer;
use Plugins\CloudDeploy\Deployers\Huaweicloud\ApigDeployer;
use Plugins\CloudDeploy\Deployers\Huaweicloud\CdnDeployer;
use Plugins\CloudDeploy\Deployers\Huaweicloud\ElbDeployer;
use Plugins\CloudDeploy\Deployers\Huaweicloud\HuaweicloudProvider;
use Plugins\CloudDeploy\Deployers\Huaweicloud\LiveDeployer;
use Plugins\CloudDeploy\Deployers\Huaweicloud\ObsDeployer;
use Plugins\CloudDeploy\Deployers\Huaweicloud\ScmDeployer;
use Plugins\CloudDeploy\Deployers\Huaweicloud\WafDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * 华为云 deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 8 端点：scm（SCM 证书管理纯托管）/ cdn（CDN 全局绑证书）/ elb（弹性负载均衡监听器）/ waf（云模式防护域名）/
 * live（视频直播域名）/ obs（对象存储自定义域名）/ apig（API 网关替换证书）/ aad（DDoS 高防按域名设证书）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'huaweicloud' 才会汇入 app(Registry::class)。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new HuaweicloudProvider);
    $registry->registerDeployer('huaweicloud', 'scm', fn () => new ScmDeployer);
    $registry->registerDeployer('huaweicloud', 'cdn', fn () => new CdnDeployer);
    $registry->registerDeployer('huaweicloud', 'elb', fn () => new ElbDeployer);
    $registry->registerDeployer('huaweicloud', 'waf', fn () => new WafDeployer);
    $registry->registerDeployer('huaweicloud', 'live', fn () => new LiveDeployer);
    $registry->registerDeployer('huaweicloud', 'obs', fn () => new ObsDeployer);
    $registry->registerDeployer('huaweicloud', 'apig', fn () => new ApigDeployer);
    $registry->registerDeployer('huaweicloud', 'aad', fn () => new AadDeployer);
};
