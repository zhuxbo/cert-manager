<?php

use Plugins\CloudDeploy\Deployers\Cachefly\CacheflyProvider;
use Plugins\CloudDeploy\Deployers\Cachefly\CertificateDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * CacheFly deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 1 端点：certificate（内联，POST /certificates 上传证书）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'cachefly' 才会汇入 app(Registry::class)，
 * 并在 RegistryCompletenessTest 期望集补 'cachefly' => ['certificate']（二者均为后续集成步骤，本任务不改共享文件）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new CacheflyProvider);
    $registry->registerDeployer('cachefly', 'certificate', fn () => new CertificateDeployer);
};
