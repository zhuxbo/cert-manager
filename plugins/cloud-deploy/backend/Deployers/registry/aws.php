<?php

use Plugins\CloudDeploy\Deployers\Aws\AwsAcmDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsAlbDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsAmplifyDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsApigatewayDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsClbDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsCloudFrontDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsIamDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsNlbDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsProvider;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * AWS deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new AwsProvider);
    $registry->registerDeployer('aws', 'acm', fn () => new AwsAcmDeployer);
    $registry->registerDeployer('aws', 'iam', fn () => new AwsIamDeployer);
    $registry->registerDeployer('aws', 'alb', fn () => new AwsAlbDeployer);
    $registry->registerDeployer('aws', 'nlb', fn () => new AwsNlbDeployer);
    $registry->registerDeployer('aws', 'clb', fn () => new AwsClbDeployer);
    $registry->registerDeployer('aws', 'cloudfront', fn () => new AwsCloudFrontDeployer);
    $registry->registerDeployer('aws', 'amplify', fn () => new AwsAmplifyDeployer);
    $registry->registerDeployer('aws', 'apigateway', fn () => new AwsApigatewayDeployer);
};
