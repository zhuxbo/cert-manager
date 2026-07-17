<?php

use Plugins\CloudDeploy\Deployers\Googlecloud\GooglecloudCertificateManagerDeployer;
use Plugins\CloudDeploy\Deployers\Googlecloud\GooglecloudProvider;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * Google Cloud deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new GooglecloudProvider);
    $registry->registerDeployer('googlecloud', 'certificatemanager', fn () => new GooglecloudCertificateManagerDeployer);
};
