<?php

use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Deployers\Webhook\WebhookDeployer;
use Plugins\CloudDeploy\Deployers\Webhook\WebhookProvider;

/**
 * Webhook deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组追加 'webhook' 才会
 * 汇入 app(Registry::class)。provider key 'webhook'、product key 'webhook'。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new WebhookProvider);
    $registry->registerDeployer('webhook', 'webhook', fn () => new WebhookDeployer);
};
