<?php

use Plugins\CloudDeploy\Deployers\Qiniu\QiniuCdnDeployer;
use Plugins\CloudDeploy\Deployers\Qiniu\QiniuKodoDeployer;
use Plugins\CloudDeploy\Deployers\Qiniu\QiniuPiliDeployer;
use Plugins\CloudDeploy\Deployers\Qiniu\QiniuProvider;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * 七牛云 deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'qiniu' 才会汇入 app(Registry::class)。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new QiniuProvider);
    $registry->registerDeployer('qiniu', 'cdn', fn () => new QiniuCdnDeployer);
    $registry->registerDeployer('qiniu', 'kodo', fn () => new QiniuKodoDeployer);
    $registry->registerDeployer('qiniu', 'pili', fn () => new QiniuPiliDeployer);
};
