<?php

use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Deployers\Upyun\UpyunCdnDeployer;
use Plugins\CloudDeploy\Deployers\Upyun\UpyunFileDeployer;
use Plugins\CloudDeploy\Deployers\Upyun\UpyunProvider;

/**
 * 又拍云 deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', 'qiniu', 'baidu', 'cloudflare', ...])`）追加 'upyun'
 * 才会汇入 app(Registry::class)。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new UpyunProvider);
    $registry->registerDeployer('upyun', 'cdn', fn () => new UpyunCdnDeployer);
    $registry->registerDeployer('upyun', 'file', fn () => new UpyunFileDeployer);
};
