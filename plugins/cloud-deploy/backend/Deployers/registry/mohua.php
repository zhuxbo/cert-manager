<?php

use Plugins\CloudDeploy\Deployers\Mohua\MohuaProvider;
use Plugins\CloudDeploy\Deployers\Mohua\MvhDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * 嘿华云 deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 1 端点：mvh（内联，POST /provision/custom/{hostId}/domains 设置虚拟主机域名 SSL）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'mohua' 才会汇入 app(Registry::class)，
 * 并在 RegistryCompletenessTest 期望集补 'mohua' => ['mvh']（二者均为后续集成步骤，本任务不改共享文件）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new MohuaProvider);
    $registry->registerDeployer('mohua', 'mvh', fn () => new MvhDeployer);
};
