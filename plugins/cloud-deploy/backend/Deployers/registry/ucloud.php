<?php

use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudPathxDeployer;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudProvider;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudUalbDeployer;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudUcdnDeployer;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudUclbDeployer;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudUewafDeployer;
use Plugins\CloudDeploy\Deployers\Ucloud\UcloudUs3Deployer;

/**
 * 优刻得（UCloud）deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', 'qiniu', 'baidu', 'cloudflare', 'aws', ...])`）追加 'ucloud'
 * 才会汇入 app(Registry::class)。在该数组纳入前，本 provider 与 6 个 deployer 已可独立通过单测验证
 * （测试直接实例化，不依赖 Registry 装配）。
 *
 * 6 端点：
 *   - ualb：应用型负载均衡 ULB（ULB 证书空间，loadbalancer/listener 双目标 + SNI）
 *   - ucdn：CDN（USSL 证书空间，按域名 ID 绑 HTTPS 配置）
 *   - uclb：传统型负载均衡 ULB（ULB 证书空间，loadbalancer/vserver 双目标，单证书先解后绑）
 *   - uewaf：Web 应用防火墙（内联型，证书 base64 直灌域名）
 *   - pathx：全球加速 PathX（USSL 证书空间，BindPathXSSL，必传 ProjectId 自动取默认项目）
 *   - us3：对象存储 US3/UFile（USSL 证书空间，存储桶自定义域名绑证书）
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new UcloudProvider);
    $registry->registerDeployer('ucloud', 'ualb', fn () => new UcloudUalbDeployer);
    $registry->registerDeployer('ucloud', 'ucdn', fn () => new UcloudUcdnDeployer);
    $registry->registerDeployer('ucloud', 'uclb', fn () => new UcloudUclbDeployer);
    $registry->registerDeployer('ucloud', 'uewaf', fn () => new UcloudUewafDeployer);
    $registry->registerDeployer('ucloud', 'pathx', fn () => new UcloudPathxDeployer);
    $registry->registerDeployer('ucloud', 'us3', fn () => new UcloudUs3Deployer);
};
