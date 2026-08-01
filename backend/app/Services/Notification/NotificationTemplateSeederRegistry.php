<?php

namespace App\Services\Notification;

use App\Contracts\ProvidesNotificationTemplateDefaults;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class NotificationTemplateSeederRegistry
{
    /**
     * 扫描主系统及已安装插件的通知模板 Seeder，汇总其默认定义。
     *
     * @return array<string, array{
     *     name: string,
     *     content: string,
     *     variables: array<int, string>,
     *     example: string|null,
     *     status: int
     * }>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach ($this->seederClasses() as $class) {
            $seeder = app($class);
            if (! $seeder instanceof ProvidesNotificationTemplateDefaults) {
                throw new RuntimeException("通知模板 Seeder 必须提供默认定义: $class");
            }

            foreach ($seeder->notificationTemplateDefaults() as $template) {
                $code = $template['code'];
                if (isset($defaults[$code])) {
                    throw new RuntimeException("通知模板 Seeder 存在重复编码: $code");
                }

                $defaults[$code] = [
                    'name' => $template['name'],
                    'content' => $template['content'],
                    'variables' => $template['variables'],
                    'example' => $template['example'] ?? null,
                    'status' => $template['status'] ?? 1,
                ];
            }
        }

        return $defaults;
    }

    /**
     * @return array<int, class-string<Seeder>>
     */
    private function seederClasses(): array
    {
        $classes = [];

        foreach (File::glob(database_path('seeders/*NotificationTemplateSeeder.php')) ?: [] as $path) {
            $classes[] = $this->loadSeederClass(
                $path,
                'Database\\Seeders\\'.pathinfo($path, PATHINFO_FILENAME)
            );
        }

        $pluginsPath = base_path('../plugins');
        if (! is_dir($pluginsPath)) {
            return $classes;
        }

        foreach (File::directories($pluginsPath) as $pluginPath) {
            if (! is_file($pluginPath.'/plugin.json')) {
                continue;
            }

            $pluginName = basename($pluginPath);
            $backendPath = $pluginPath.'/backend';
            if (! is_dir($backendPath)) {
                continue;
            }

            foreach (File::directories($backendPath) as $directory) {
                if (strcasecmp(basename($directory), 'seeders') !== 0) {
                    continue;
                }

                $pattern = "$directory/*NotificationTemplateSeeder.php";
                foreach (File::glob($pattern) ?: [] as $path) {
                    $classes[] = $this->loadSeederClass(
                        $path,
                        'Plugins\\'.Str::studly($pluginName).'\\Seeders\\'.pathinfo($path, PATHINFO_FILENAME)
                    );
                }
            }
        }

        $classes = array_values(array_unique($classes));
        sort($classes);

        return $classes;
    }

    /**
     * @return class-string<Seeder>
     */
    private function loadSeederClass(string $path, string $class): string
    {
        require_once $path;

        if (! class_exists($class) || ! is_subclass_of($class, Seeder::class)) {
            throw new RuntimeException("通知模板 Seeder 类无效: $class");
        }

        /** @var class-string<Seeder> $class */
        return $class;
    }
}
