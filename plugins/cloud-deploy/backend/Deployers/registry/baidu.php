<?php

use Plugins\CloudDeploy\Deployers\Baidu\BaiduAppblbDeployer;
use Plugins\CloudDeploy\Deployers\Baidu\BaiduBlbDeployer;
use Plugins\CloudDeploy\Deployers\Baidu\BaiduCdnDeployer;
use Plugins\CloudDeploy\Deployers\Baidu\BaiduCertDeployer;
use Plugins\CloudDeploy\Deployers\Baidu\BaiduProvider;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * 百度智能云 deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 4 端点：cdn（CDN 域名内联绑证书）/ blb（普通型负载均衡）/ appblb（应用型负载均衡）/ cert（证书中心纯上传）。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new BaiduProvider);
    $registry->registerDeployer('baidu', 'cdn', fn () => new BaiduCdnDeployer);
    $registry->registerDeployer('baidu', 'blb', fn () => new BaiduBlbDeployer);
    $registry->registerDeployer('baidu', 'appblb', fn () => new BaiduAppblbDeployer);
    $registry->registerDeployer('baidu', 'cert', fn () => new BaiduCertDeployer);
};
