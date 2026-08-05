<?php

namespace Plugins\CloudDeploy;

use App\Models\Cert;
use App\Models\Chain;
use App\Utils\UpgradeFreezeLock;
use Composer\Autoload\ClassLoader;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Plugins\CloudDeploy\Commands\CloudDeployAuditDestinationsCommand;
use Plugins\CloudDeploy\Commands\CloudDeployReconcileCommand;
use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Notifications\CloudDeployFailedNotificationBuilder;
use Plugins\CloudDeploy\Support\CloudDeployTriggers;
use Plugins\CloudDeploy\Support\OutboundDestinationPolicy;
use Plugins\CloudDeploy\Support\SafeHttpClientFactory;

class CloudDeployServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadPluginVendor();
        $this->mergeConfigFrom(__DIR__.'/config/cloud-deploy.php', 'cloud-deploy');

        $this->app->singleton(OutboundDestinationPolicy::class);
        $this->app->singleton(SafeHttpClientFactory::class);

        // Registry 单例：按 provider 拆分的 registry/*.php 各返回一个 Closure(Registry)，逐个 apply 注册 provider + deployer
        $this->app->singleton(Registry::class, function () {
            $registry = new Registry;
            foreach (['aliyun', 'tencent', 'qiniu', 'baidu', 'cloudflare', 'aws', 'upyun', 'digitalocean', 'ksyun', 'volcengine', 'jdcloud', 'byteplus', 'ucloud', 'vercel', 'netlify', 'bunny', 'gcore', 'linode', 'wangsu', 'ctcccloud', 'huaweicloud', 'rainyun', 'mohua', 'unicloud', 'cachefly', 'cdnfly', 'flyio', 'googlecloud', 'azure', 'oraclecloud', 's3', 'cmcccloud', 'zenlayer', 'qingcloud', 'baishan', 'dogecloud',
                'k8s', 'webhook', 'onepanel', 'baotapanel', 'baotapanelgo', 'baotawaf', 'ratpanel', 'cpanel', 'safeline', 'samwaf', 'goedge', 'flexcdn', 'lecdn', 'nginxproxymanager', 'synologydsm', 'proxmoxve', 'dokploy', 'kong', 'apisix'] as $name) {
                (require __DIR__."/Deployers/registry/$name.php")($registry);
            }

            return $registry;
        });

        // 失败通知专用 Builder：运行时注入 config（已实测 config:cache 下可靠）。白名单防 AK 外溢。
        config(['notification.builders.cloud_deploy_failed' => CloudDeployFailedNotificationBuilder::class]);
    }

    /**
     * 加载插件独立 vendor（承载阿里/腾讯官方云 SDK）。
     *
     * 关键：Composer 默认 register(prepend=true) 把插件 ClassLoader 压 SPL 栈首，会让插件版本
     * 接管整个进程（含主系统）——实测主系统被切到插件的 psr7 2.8。故 require 后必须把插件
     * ClassLoader unregister() 再 register(false) 挂栈尾：共享类（GuzzleHttp、Psr 等命名空间）
     * 回落主系统版本，插件独有类（AlibabaCloud、TencentCloud、Darabonba 命名空间，主 loader
     * findFile 返回 false）仍从插件 vendor 解析。不做 Strauss scoping（实测不 scoping 下 SDK
     * 正常、scoping 反而 fatal）。
     *
     * 幂等：require_once 保证 autoload 文件单进程只执行一次；unregister()+register(false) 重复调用
     * 安全（已在栈尾时无副作用），可被 route:cache 构建期 + 运行期各触发一次。
     * 缺 vendor 不 fatal（is_file 守卫）——孤立环境降级，由调用方 class_exists 兜底。
     */
    protected function loadPluginVendor(): void
    {
        $autoload = __DIR__.'/vendor/autoload.php';
        if (! is_file($autoload)) {
            return;
        }

        require_once $autoload;

        $vendorReal = realpath(__DIR__.'/vendor');
        if ($vendorReal === false || ! class_exists(ClassLoader::class, false)) {
            return;
        }

        foreach (ClassLoader::getRegisteredLoaders() as $dir => $loader) {
            if (realpath($dir) === $vendorReal) {
                $loader->unregister();
                $loader->register(false); // prepend=false → 挂 SPL 栈尾，共享类回落主系统
            }
        }
    }

    public function boot(): void
    {
        $basePath = dirname(__DIR__);

        $this->loadRoutesFrom("$basePath/backend/routes/user.php");
        $this->loadRoutesFrom("$basePath/backend/routes/admin.php");
        $this->loadMigrationsFrom("$basePath/backend/migrations");

        $this->commands([
            CloudDeployAuditDestinationsCommand::class,
            CloudDeployReconcileCommand::class,
        ]);

        // callAfterResolving(Schedule)：插件自注册定时，无需改主系统 routes/console.php（已容器实测）
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('cloud-deploy:reconcile')
                ->dailyAt('04:30')
                ->skip(fn () => UpgradeFreezeLock::isFrozen())
                ->withoutOverlapping()
                ->name('cloud-deploy-reconcile');
        });

        Cert::updated(function (Cert $cert) {
            try {
                CloudDeployTriggers::onCertUpdated($cert);
            } catch (\Throwable $e) {
                Log::warning('[cloud-deploy] cert listener failed', ['cert' => $cert->id, 'e' => $e->getMessage()]);
            }
        });

        Chain::created(function (Chain $chain) {
            try {
                CloudDeployTriggers::onChainCreated($chain);
            } catch (\Throwable $e) {
                Log::warning('[cloud-deploy] chain listener failed', ['e' => $e->getMessage()]);
            }
        });
    }
}
