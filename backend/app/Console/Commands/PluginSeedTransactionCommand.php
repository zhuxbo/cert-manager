<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PluginSeedTransactionCommand extends Command
{
    protected $signature = 'plugin:seed-transaction {class : 插件 Seeder 完整类名}';

    protected $description = '在数据库事务中运行插件 Seeder';

    public function handle(): int
    {
        $class = (string) $this->argument('class');
        if (! class_exists($class)) {
            throw new RuntimeException("插件 Seeder 类不存在: $class");
        }

        DB::transaction(function () use ($class) {
            $seeder = app($class);
            if ($seeder instanceof Seeder) {
                $seeder->setContainer(app())->setCommand($this);
                $seeder->__invoke();

                return;
            }

            if (! method_exists($seeder, 'run')) {
                throw new RuntimeException("插件 Seeder 缺少 run 方法: $class");
            }

            app()->call([$seeder, 'run']);
        });

        return self::SUCCESS;
    }
}
