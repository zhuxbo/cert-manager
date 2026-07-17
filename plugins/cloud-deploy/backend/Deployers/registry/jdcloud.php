<?php

use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudAlbDeployer;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudCdnDeployer;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudLiveDeployer;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudProvider;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudSslDeployer;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudVodDeployer;
use Plugins\CloudDeploy\Deployers\Jdcloud\JdcloudWafDeployer;
use Plugins\CloudDeploy\Deployers\Registry;

/**
 * 京东云 deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 6 端点：ssl（SSL 证书中心纯上传）/ cdn / live（内联）/ vod（内联）/ waf / alb（lb 服务）。
 * ssl/cdn/waf/alb 走 SSL 证书中心上传（JdcloudSslUploader，storeKind=jdcloud_ssl）；live/vod 内联直灌 PEM。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'jdcloud' 才会汇入 app(Registry::class)。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new JdcloudProvider);
    $registry->registerDeployer('jdcloud', 'ssl', fn () => new JdcloudSslDeployer);
    $registry->registerDeployer('jdcloud', 'cdn', fn () => new JdcloudCdnDeployer);
    $registry->registerDeployer('jdcloud', 'live', fn () => new JdcloudLiveDeployer);
    $registry->registerDeployer('jdcloud', 'vod', fn () => new JdcloudVodDeployer);
    $registry->registerDeployer('jdcloud', 'waf', fn () => new JdcloudWafDeployer);
    $registry->registerDeployer('jdcloud', 'alb', fn () => new JdcloudAlbDeployer);
};
