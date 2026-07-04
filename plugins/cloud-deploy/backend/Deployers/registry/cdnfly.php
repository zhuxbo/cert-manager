<?php

use Plugins\CloudDeploy\Deployers\Cdnfly\CdnDeployer;
use Plugins\CloudDeploy\Deployers\Cdnfly\CdnflyProvider;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * Cdnfly deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 1 端点：cdn（内联，按 deploy_target 走网站 GET+POST+PUT 或证书 PUT 两条流程）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'cdnfly' 才会汇入 app(Registry::class)，
 * 并在 RegistryCompletenessTest 期望集补 'cdnfly' => ['cdn']（二者均为后续集成步骤，本任务不改共享文件）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new CdnflyProvider);
    $registry->registerDeployer('cdnfly', 'cdn', fn () => new CdnDeployer);
};
