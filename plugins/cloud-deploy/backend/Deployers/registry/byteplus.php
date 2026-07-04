<?php

use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusAlbDeployer;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusApigDeployer;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusCdnDeployer;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusCertCenterDeployer;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusClbDeployer;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusMediaLiveDeployer;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusProvider;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusTosDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * BytePlus（火山引擎国际版）deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 7 端点：cdn / alb / clb / apig / certcenter（仅上传）/ medialive / tos。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'byteplus' 才会汇入 app(Registry::class)，
 * 并在 RegistryCompletenessTest 期望集补 'byteplus' => [...]（二者均为后续集成步骤，本任务不改共享文件）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new BytePlusProvider);
    $registry->registerDeployer('byteplus', 'cdn', fn () => new BytePlusCdnDeployer);
    $registry->registerDeployer('byteplus', 'alb', fn () => new BytePlusAlbDeployer);
    $registry->registerDeployer('byteplus', 'clb', fn () => new BytePlusClbDeployer);
    $registry->registerDeployer('byteplus', 'apig', fn () => new BytePlusApigDeployer);
    $registry->registerDeployer('byteplus', 'certcenter', fn () => new BytePlusCertCenterDeployer);
    $registry->registerDeployer('byteplus', 'medialive', fn () => new BytePlusMediaLiveDeployer);
    $registry->registerDeployer('byteplus', 'tos', fn () => new BytePlusTosDeployer);
};
