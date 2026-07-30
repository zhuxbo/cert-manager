<?php

namespace Plugins\CloudDeploy\Seeders;

use Illuminate\Database\Seeder;

class PluginSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            NotificationTemplateSeeder::class,
        ]);
    }

    public function clear(): void
    {
        app(NotificationTemplateSeeder::class)->clear();
    }
}
