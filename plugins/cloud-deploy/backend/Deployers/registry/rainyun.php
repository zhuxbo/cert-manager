<?php

use Plugins\CloudDeploy\Deployers\Rainyun\RainyunProvider;
use Plugins\CloudDeploy\Deployers\Rainyun\RcdnDeployer;
use Plugins\CloudDeploy\Deployers\Rainyun\SslcenterDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * 雨云 deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 2 端点：
 *   - rcdn（证书服务型，上传证书中心拿 id → POST /product/rcdn/instance/{id}/ssl_bind 绑定）。
 *   - sslcenter（内联，POST/PUT /product/sslcenter 上传/替换证书中心证书）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'rainyun' 才会汇入 app(Registry::class)，
 * 并在 RegistryCompletenessTest 期望集补 'rainyun' => ['rcdn', 'sslcenter']（二者均为后续集成步骤，本任务不改共享文件）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new RainyunProvider);
    $registry->registerDeployer('rainyun', 'rcdn', fn () => new RcdnDeployer);
    $registry->registerDeployer('rainyun', 'sslcenter', fn () => new SslcenterDeployer);
};
