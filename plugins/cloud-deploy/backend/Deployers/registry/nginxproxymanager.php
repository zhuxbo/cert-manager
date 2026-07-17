<?php

use Plugins\CloudDeploy\Deployers\Nginxproxymanager\CertificateDeployer;
use Plugins\CloudDeploy\Deployers\Nginxproxymanager\NginxproxymanagerProvider;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * Nginx Proxy Manager deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 1 端点：certificate（内联，POST /nginx/certificates/{id}/upload 替换证书 + 默认站点写回触发重启）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'nginxproxymanager' 才会汇入 app(Registry::class)，
 * 并在 RegistryCompletenessTest 期望集补 'nginxproxymanager' => ['certificate']（二者均为后续集成步骤，本任务不改共享文件）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new NginxproxymanagerProvider);
    $registry->registerDeployer('nginxproxymanager', 'certificate', fn () => new CertificateDeployer);
};
