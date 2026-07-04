<?php

use Plugins\CloudDeploy\Deployers\Digitalocean\DigitaloceanCertificateDeployer;
use Plugins\CloudDeploy\Deployers\Digitalocean\DigitaloceanProvider;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * DigitalOcean deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new DigitaloceanProvider);
    $registry->registerDeployer('digitalocean', 'certificate', fn () => new DigitaloceanCertificateDeployer);
};
