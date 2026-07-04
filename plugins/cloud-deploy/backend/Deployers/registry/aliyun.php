<?php

use Plugins\CloudDeploy\Deployers\Aliyun\AliyunAlbDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunApigwDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunCasDeployDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunCasDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunCdnDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunClbDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunDcdnDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunDdosproDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunEsaDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunEsaSaasDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunFcDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunGaDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunLiveDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunNlbDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunOssDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunProvider;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunVodDeployer;
use Plugins\CloudDeploy\Deployers\Aliyun\AliyunWafDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * 阿里云 deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new AliyunProvider);
    $registry->registerDeployer('aliyun', 'cas', fn () => new AliyunCasDeployer);
    $registry->registerDeployer('aliyun', 'casdeploy', fn () => new AliyunCasDeployDeployer);
    $registry->registerDeployer('aliyun', 'cdn', fn () => new AliyunCdnDeployer);
    $registry->registerDeployer('aliyun', 'dcdn', fn () => new AliyunDcdnDeployer);
    $registry->registerDeployer('aliyun', 'live', fn () => new AliyunLiveDeployer);
    $registry->registerDeployer('aliyun', 'vod', fn () => new AliyunVodDeployer);
    $registry->registerDeployer('aliyun', 'alb', fn () => new AliyunAlbDeployer);
    $registry->registerDeployer('aliyun', 'nlb', fn () => new AliyunNlbDeployer);
    $registry->registerDeployer('aliyun', 'clb', fn () => new AliyunClbDeployer);
    $registry->registerDeployer('aliyun', 'ga', fn () => new AliyunGaDeployer);
    $registry->registerDeployer('aliyun', 'waf', fn () => new AliyunWafDeployer);
    $registry->registerDeployer('aliyun', 'oss', fn () => new AliyunOssDeployer);
    $registry->registerDeployer('aliyun', 'fc', fn () => new AliyunFcDeployer);
    $registry->registerDeployer('aliyun', 'apigw', fn () => new AliyunApigwDeployer);
    $registry->registerDeployer('aliyun', 'ddospro', fn () => new AliyunDdosproDeployer);
    $registry->registerDeployer('aliyun', 'esa', fn () => new AliyunEsaDeployer);
    $registry->registerDeployer('aliyun', 'esasaas', fn () => new AliyunEsaSaasDeployer);
};
