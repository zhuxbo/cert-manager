<?php

use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Deployers\Unicloud\UnicloudProvider;
use Plugins\CloudDeploy\Deployers\Unicloud\WebhostDeployer;

/**
 * uniCloud deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 1 端点：webhost（内联，POST /host/create-domain-with-cert 变更托管网站域名证书；两级 token 鉴权）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'unicloud' 才会汇入 app(Registry::class)，
 * 并在 RegistryCompletenessTest 期望集补 'unicloud' => ['webhost']（二者均为后续集成步骤，本任务不改共享文件）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new UnicloudProvider);
    $registry->registerDeployer('unicloud', 'webhost', fn () => new WebhostDeployer);
};
