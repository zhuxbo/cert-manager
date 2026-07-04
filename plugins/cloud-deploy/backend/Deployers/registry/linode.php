<?php

use Plugins\CloudDeploy\Deployers\Linode\LinodeProvider;
use Plugins\CloudDeploy\Deployers\Linode\LosDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * Linode deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 1 端点：los（内联，对象存储桶 SSL：GET→DELETE→POST /object-storage/buckets/{region}/{bucket}/ssl）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'linode' 才会汇入 app(Registry::class)，
 * 并在 RegistryCompletenessTest 期望集补 'linode' => ['los']（二者均为后续集成步骤，本任务不改共享文件）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new LinodeProvider);
    $registry->registerDeployer('linode', 'los', fn () => new LosDeployer);
};
