<?php

use Plugins\CloudDeploy\Deployers\Baishan\BaishanCdnDeployer;
use Plugins\CloudDeploy\Deployers\Baishan\BaishanProvider;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * 白山云 deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 1 端点：cdn（CDN 域名设证书，证书服务型）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'baishan' 才会汇入 app(Registry::class)。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new BaishanProvider);
    $registry->registerDeployer('baishan', 'cdn', fn () => new BaishanCdnDeployer);
};
