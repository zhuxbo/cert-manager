<?php

namespace App\Providers;

use App\Http\Controllers\PluginManifestController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class PluginServiceProvider extends ServiceProvider
{
    protected array $plugins = [];

    public function register(): void
    {
        $this->loadPlugins();

        // 绑定插件清单到容器，供控制器使用
        $this->app->singleton('plugin.public_manifests', fn () => $this->getPublicPluginManifests());
        $this->app->singleton('plugin.admin_manifests', fn () => $this->getPluginManifests());
    }

    public function boot(): void
    {
        foreach ($this->plugins as $plugin) {
            if (isset($plugin['provider'])) {
                $this->app->register($plugin['provider']);
            }
        }

        // 使用控制器路由（可序列化，兼容 route:cache）
        Route::prefix('api/admin')->middleware(['global', 'api.admin'])->group(function () {
            Route::get('plugins', [PluginManifestController::class, 'admin']);
        });

        Route::prefix('api')->middleware('global')->group(function () {
            Route::get('plugins', [PluginManifestController::class, 'index']);
        });
    }

    protected function loadPlugins(): void
    {
        $pluginsPath = base_path('../plugins');
        if (! is_dir($pluginsPath)) {
            return;
        }

        foreach (glob("$pluginsPath/*/plugin.json") ?: [] as $manifestFile) {
            // 并行测试会动态创建/删除临时插件目录，glob 枚举后文件可能已消失（TOCTOU），静默跳过
            if (! is_file($manifestFile)) {
                continue;
            }

            $content = @file_get_contents($manifestFile);
            if ($content === false) {
                continue;
            }

            $manifest = json_decode($content, true);
            if (! $manifest) {
                continue;
            }

            $pluginDir = dirname($manifestFile);
            $pluginName = $manifest['name'] ?? basename($pluginDir);
            $backendDir = "$pluginDir/backend";

            // 动态注册 PSR-4 命名空间
            $namespace = null;
            if (is_dir($backendDir)) {
                $namespace = 'Plugins\\'.Str::studly($pluginName).'\\';
                $this->registerAutoload($namespace, $backendDir);
            }

            $this->plugins[$pluginName] = [
                'manifest' => $manifest,
                'path' => $pluginDir,
                'provider' => ($namespace && isset($manifest['provider']) && preg_match('/^[A-Za-z0-9\\\\]+$/', $manifest['provider']))
                    ? $namespace.$manifest['provider']
                    : null,
            ];
        }
    }

    protected function registerAutoload(string $namespace, string $path): void
    {
        $realBasePath = realpath($path);
        if ($realBasePath === false) {
            return;
        }

        spl_autoload_register(function ($class) use ($namespace, $realBasePath) {
            if (! str_starts_with($class, $namespace)) {
                return;
            }

            $relativeClass = substr($class, strlen($namespace));
            $file = $realBasePath.'/'.str_replace('\\', '/', $relativeClass).'.php';

            $realFile = realpath($file);
            if ($realFile === false || ! str_starts_with($realFile, $realBasePath.'/')) {
                return;
            }

            require_once $realFile;
        });
    }

    protected function getPublicPluginManifests(): array
    {
        $result = [];
        foreach ($this->plugins as $name => $plugin) {
            $manifest = $plugin['manifest'];
            $entry = ['name' => $name];

            $adminBundle = $this->buildBundleUrl($name, $manifest['admin_bundle'] ?? null);
            if ($adminBundle) {
                $entry['admin'] = ['bundle' => $adminBundle];
                $adminCss = $this->buildBundleUrl($name, $manifest['admin_css'] ?? null);
                if ($adminCss) {
                    $entry['admin']['css'] = $adminCss;
                }
            }

            $userBundle = $this->buildBundleUrl($name, $manifest['user_bundle'] ?? null);
            if ($userBundle) {
                $entry['user'] = ['bundle' => $userBundle];
                $userCss = $this->buildBundleUrl($name, $manifest['user_css'] ?? null);
                if ($userCss) {
                    $entry['user']['css'] = $userCss;
                }
            }

            $result[] = $entry;
        }

        return $result;
    }

    protected function getPluginManifests(): array
    {
        $result = [];
        foreach ($this->plugins as $name => $plugin) {
            $manifest = $plugin['manifest'];
            $entry = [
                'name' => $name,
                'version' => $manifest['version'] ?? '0.0.0',
            ];

            $adminBundle = $this->buildBundleUrl($name, $manifest['admin_bundle'] ?? null);
            if ($adminBundle) {
                $entry['admin'] = ['bundle' => $adminBundle];
                $adminCss = $this->buildBundleUrl($name, $manifest['admin_css'] ?? null);
                if ($adminCss) {
                    $entry['admin']['css'] = $adminCss;
                }
            }

            $userBundle = $this->buildBundleUrl($name, $manifest['user_bundle'] ?? null);
            if ($userBundle) {
                $entry['user'] = ['bundle' => $userBundle];
                $userCss = $this->buildBundleUrl($name, $manifest['user_css'] ?? null);
                if ($userCss) {
                    $entry['user']['css'] = $userCss;
                }
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * 将 plugin.json 中的 bundle 路径转为 URL
     * 支持相对路径（admin/file.js）和旧格式绝对路径（/plugins/name/admin/file.js）
     */
    protected function buildBundleUrl(string $pluginName, mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || str_contains($path, '://')) {
            return null;
        }

        // 旧格式：/plugins/easy/admin/file.js → 提取相对路径
        $prefix = "/plugins/$pluginName/";
        if (str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix));
        }

        // 去掉开头的 /
        $path = ltrim($path, '/');

        // 验证：允许 frontend/(admin|user)/ 下的静态文件
        if (! preg_match('#^frontend/(admin|user)/[a-zA-Z0-9._-]+\.(js|css)$#', $path)) {
            return null;
        }

        return "/plugins/$pluginName/$path";
    }
}
