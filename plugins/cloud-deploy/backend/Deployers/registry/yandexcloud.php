<?php

use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Deployers\Yandexcloud\YandexcloudCertificateManagerDeployer;
use Plugins\CloudDeploy\Deployers\Yandexcloud\YandexcloudProvider;

/** @return Closure(Registry):void */
return function (Registry $registry): void {
    $registry->registerProvider(new YandexcloudProvider);
    $registry->registerDeployer('yandexcloud', 'certificatemanager', fn () => new YandexcloudCertificateManagerDeployer);
};
