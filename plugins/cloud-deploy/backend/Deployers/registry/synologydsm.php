<?php

use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Deployers\Synologydsm\CertificateDeployer;
use Plugins\CloudDeploy\Deployers\Synologydsm\SynologydsmProvider;

/**
 * 群晖 DSM deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 1 端点：certificate（内联，登录 → SYNO.Core.Certificate:import 导入/替换证书 → 登出）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'synologydsm' 才会汇入 app(Registry::class)，
 * 并在 RegistryCompletenessTest 期望集补 'synologydsm' => ['certificate']（二者均为后续集成步骤，本任务不改共享文件）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new SynologydsmProvider);
    $registry->registerDeployer('synologydsm', 'certificate', fn () => new CertificateDeployer);
};
