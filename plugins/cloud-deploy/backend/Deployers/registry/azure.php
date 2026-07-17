<?php

use Plugins\CloudDeploy\Deployers\Azure\AzureKeyVaultDeployer;
use Plugins\CloudDeploy\Deployers\Azure\AzureProvider;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * Azure deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new AzureProvider);
    $registry->registerDeployer('azure', 'keyvault', fn () => new AzureKeyVaultDeployer);
};
