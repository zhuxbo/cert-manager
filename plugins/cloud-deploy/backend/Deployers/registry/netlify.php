<?php

use Plugins\CloudDeploy\Deployers\Netlify\NetlifyProvider;
use Plugins\CloudDeploy\Deployers\Netlify\WebsiteDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * Netlify deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 1 端点：website（内联，POST /api/v1/sites/{siteId}/ssl 配置站点 SNI 证书）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'netlify' 才会汇入 app(Registry::class)，
 * 并在 RegistryCompletenessTest 期望集补 'netlify' => ['website']（二者均为后续集成步骤，本任务不改共享文件）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new NetlifyProvider);
    $registry->registerDeployer('netlify', 'website', fn () => new WebsiteDeployer);
};
