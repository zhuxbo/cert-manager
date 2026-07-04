<?php

use Plugins\CloudDeploy\Deployers\Bunny\BunnyProvider;
use Plugins\CloudDeploy\Deployers\Bunny\CdnDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * Bunny deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 1 端点：cdn（内联，POST /pullzone/{id}/addCertificate 添加 Pull Zone 自定义证书）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'bunny' 才会汇入 app(Registry::class)，
 * 并在 RegistryCompletenessTest 期望集补 'bunny' => ['cdn']（二者均为后续集成步骤，本任务不改共享文件）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new BunnyProvider);
    $registry->registerDeployer('bunny', 'cdn', fn () => new CdnDeployer);
};
