<?php

use Plugins\CloudDeploy\Deployers\Ksyun\KsyunCdnDeployer;
use Plugins\CloudDeploy\Deployers\Ksyun\KsyunKcmDeployer;
use Plugins\CloudDeploy\Deployers\Ksyun\KsyunProvider;
use Plugins\CloudDeploy\Deployers\Ksyun\KsyunSlbDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * 金山云 deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 3 端点：cdn（CDN 域名内联绑证书）/ kcm（证书管理 KCM 纯托管上传）/ slb（负载均衡证书替换，region 维度）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', 'qiniu', 'baidu', 'cloudflare', ...])`）追加 'ksyun'
 * 才会汇入 app(Registry::class)。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new KsyunProvider);
    $registry->registerDeployer('ksyun', 'cdn', fn () => new KsyunCdnDeployer);
    $registry->registerDeployer('ksyun', 'kcm', fn () => new KsyunKcmDeployer);
    $registry->registerDeployer('ksyun', 'slb', fn () => new KsyunSlbDeployer);
};
