<?php

use Plugins\CloudDeploy\Deployers\Proxmoxbs\NodeDeployer;
use Plugins\CloudDeploy\Deployers\Proxmoxbs\ProxmoxbsProvider;
use Plugins\CloudDeploy\Deployers\Registry;

return function (Registry $registry): void {
    $registry->registerProvider(new ProxmoxbsProvider);
    $registry->registerDeployer('proxmoxbs', 'node', fn () => new NodeDeployer);
};
