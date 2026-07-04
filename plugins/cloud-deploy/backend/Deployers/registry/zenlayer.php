<?php

use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Deployers\Zenlayer\ZenlayerCdnDeployer;
use Plugins\CloudDeploy\Deployers\Zenlayer\ZenlayerGaDeployer;
use Plugins\CloudDeploy\Deployers\Zenlayer\ZenlayerProvider;

/**
 * Zenlayer deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 2 端点：cdn（CDN 域名绑证书）/ ga（全球加速 ZGA 加速器绑证书）。均证书服务型。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'zenlayer' 才会汇入 app(Registry::class)。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new ZenlayerProvider);
    $registry->registerDeployer('zenlayer', 'cdn', fn () => new ZenlayerCdnDeployer);
    $registry->registerDeployer('zenlayer', 'ga', fn () => new ZenlayerGaDeployer);
};
