<?php

use Plugins\CloudDeploy\Deployers\Oraclecloud\OraclecloudCertificatesMgmtDeployer;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OraclecloudProvider;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * Oracle Cloud deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new OraclecloudProvider);
    $registry->registerDeployer('oraclecloud', 'certificatesmgmt', fn () => new OraclecloudCertificatesMgmtDeployer);
};
