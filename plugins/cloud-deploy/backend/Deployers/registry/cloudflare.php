<?php

use Plugins\CloudDeploy\Deployers\Cloudflare\CloudflareProvider;
use Plugins\CloudDeploy\Deployers\Cloudflare\CloudflareSslDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * Cloudflare deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new CloudflareProvider);
    $registry->registerDeployer('cloudflare', 'ssl', fn () => new CloudflareSslDeployer);
};
