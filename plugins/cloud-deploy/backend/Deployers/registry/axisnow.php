<?php

use Plugins\CloudDeploy\Deployers\Axisnow\AxisnowProvider;
use Plugins\CloudDeploy\Deployers\Axisnow\CertificateDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

return function (Registry $registry): void {
    $registry->registerProvider(new AxisnowProvider);
    $registry->registerDeployer('axisnow', 'certificate', fn () => new CertificateDeployer);
};
