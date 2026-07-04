<?php

use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Deployers\Wangsu\WangsuCdnDeployer;
use Plugins\CloudDeploy\Deployers\Wangsu\WangsuCdnProDeployer;
use Plugins\CloudDeploy\Deployers\Wangsu\WangsuCertificateDeployer;
use Plugins\CloudDeploy\Deployers\Wangsu\WangsuProvider;

/**
 * 网宿科技（Wangsu / ChinaNetCenter）deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 3 端点：cdn（证书服务型）/ cdnpro（内联型，自带证书上传 + 部署任务轮询）/ certificate（仅上传）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'wangsu' 才会汇入 app(Registry::class)，
 * 并在 RegistryCompletenessTest 期望集补 'wangsu' => ['cdn','cdnpro','certificate']（二者均为后续
 * 集成步骤，本任务不改共享文件）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new WangsuProvider);
    $registry->registerDeployer('wangsu', 'cdn', fn () => new WangsuCdnDeployer);
    $registry->registerDeployer('wangsu', 'cdnpro', fn () => new WangsuCdnProDeployer);
    $registry->registerDeployer('wangsu', 'certificate', fn () => new WangsuCertificateDeployer);
};
