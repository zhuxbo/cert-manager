<?php

use Plugins\CloudDeploy\Deployers\Huaweiibmc\ConsoleDeployer;
use Plugins\CloudDeploy\Deployers\Huaweiibmc\HuaweiibmcProvider;
use Plugins\CloudDeploy\Deployers\Registry;

return function (Registry $registry): void {
    $registry->registerProvider(new HuaweiibmcProvider);
    $registry->registerDeployer('huaweiibmc', 'console', fn () => new ConsoleDeployer);
};
