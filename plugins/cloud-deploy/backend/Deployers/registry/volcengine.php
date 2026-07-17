<?php

use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcAlbDeployer;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcApigDeployer;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcCdnDeployer;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcCertCenterDeployer;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcClbDeployer;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcDcdnDeployer;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcImagexDeployer;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcLiveDeployer;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcProvider;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcTosDeployer;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcVodDeployer;
use Plugins\CloudDeploy\Deployers\Volcengine\VolcWafDeployer;

/**
 * 火山引擎 deployer 注册（按 provider 拆分，新增端点只改本文件，防并行冲突）。
 *
 * 11 端点：cdn / dcdn / alb / clb / apig / certcenter / imagex / live / tos / vod / waf。
 * 上传器三类按端点实际证书空间：cdn→VolcCdnUploader、live→VolcLiveUploader、其余→VolcCertCenterUploader。
 *
 * 接入提示：本文件需在 CloudDeployServiceProvider::register() 的 registry 装配数组
 * （`foreach (['aliyun', 'tencent', ...])`）追加 'volcengine' 才会汇入 app(Registry::class)。
 *
 * @return Closure(Registry):void
 */
return function (Registry $registry): void {
    $registry->registerProvider(new VolcProvider);
    $registry->registerDeployer('volcengine', 'cdn', fn () => new VolcCdnDeployer);
    $registry->registerDeployer('volcengine', 'dcdn', fn () => new VolcDcdnDeployer);
    $registry->registerDeployer('volcengine', 'alb', fn () => new VolcAlbDeployer);
    $registry->registerDeployer('volcengine', 'clb', fn () => new VolcClbDeployer);
    $registry->registerDeployer('volcengine', 'apig', fn () => new VolcApigDeployer);
    $registry->registerDeployer('volcengine', 'certcenter', fn () => new VolcCertCenterDeployer);
    $registry->registerDeployer('volcengine', 'imagex', fn () => new VolcImagexDeployer);
    $registry->registerDeployer('volcengine', 'live', fn () => new VolcLiveDeployer);
    $registry->registerDeployer('volcengine', 'tos', fn () => new VolcTosDeployer);
    $registry->registerDeployer('volcengine', 'vod', fn () => new VolcVodDeployer);
    $registry->registerDeployer('volcengine', 'waf', fn () => new VolcWafDeployer);
};
