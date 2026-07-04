<?php

use Plugins\CloudDeploy\Deployers\Apisix\ApisixProvider;
use Plugins\CloudDeploy\Deployers\Apisix\CertificateDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * APISIX deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 1 端点：certificate（内联，PUT /apisix/admin/ssls/{id} 更新 SSL 对象）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'apisix' 才会汇入 app(Registry::class)，
 * 并在 RegistryCompletenessTest 期望集补 'apisix' => ['certificate']（二者均为后续集成步骤，本任务不改共享文件）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new ApisixProvider);
    $registry->registerDeployer('apisix', 'certificate', fn () => new CertificateDeployer);
};
