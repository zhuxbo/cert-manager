<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Upgrade\SmokeChecker;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;

/**
 * 升级 freeze 内部 smoke test 命令
 *
 * 与 POST /api/admin/upgrade/smoke 路由共享 SmokeChecker 服务类。
 * 跑完 DB / jobs 表 / 关键路由 三项检查；任一失败 exit 1 + 打印失败原因。
 *
 * 由 upgrade.sh 在切完代码 / migrate 后、unfreeze 前调用，作为脚本流程的最后兜底。
 */
class UpgradeSmokeCommand extends Command
{
    protected $signature = 'upgrade:smoke';

    protected $description = '升级 freeze 内部健康检查（DB / jobs 表 / 关键路由）';

    public function handle(SmokeChecker $checker): int
    {
        $result = $checker->run();
        $checks = $result['checks'];

        if ($result['ok']) {
            $this->info('smoke test 通过');
            $this->line('  db              : ok');
            $this->line('  jobs_table      : ok');
            $this->line('  critical_routes : ok');

            return CommandAlias::SUCCESS;
        }

        $this->error('smoke test 失败');

        $db = $checks['db'];
        $this->line('  db              : '.($db['ok'] ? 'ok' : 'fail'.(isset($db['message']) ? " ({$db['message']})" : '')));

        $jobs = $checks['jobs_table'];
        $this->line('  jobs_table      : '.($jobs['ok'] ? 'ok' : 'fail'.(isset($jobs['message']) ? " ({$jobs['message']})" : '')));

        $routes = $checks['critical_routes'];
        if ($routes['ok']) {
            $this->line('  critical_routes : ok');
        } else {
            $this->line('  critical_routes : fail');
            foreach ($routes['missing'] ?? [] as $miss) {
                $this->line("    missing: $miss");
            }
        }

        return CommandAlias::FAILURE;
    }
}
