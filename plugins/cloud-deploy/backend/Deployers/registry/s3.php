<?php

use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Deployers\S3\S3Deployer;
use Plugins\CloudDeploy\Deployers\S3\S3Provider;

/**
 * S3 兼容对象存储 deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new S3Provider);
    $registry->registerDeployer('s3', 's3', fn () => new S3Deployer);
};
