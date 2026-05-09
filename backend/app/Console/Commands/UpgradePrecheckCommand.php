<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Throwable;

/**
 * 升级预检命令：跑迁移预演 + 磁盘空间检查
 *
 * 由 upgrade.sh / 后台覆盖式升级流程在切代码、跑 migrate 之前调用，用于尽早发现问题：
 * 1. `php artisan migrate --pretend` —— 仅打印将要执行的 SQL，不实际执行
 * 2. 磁盘剩余空间检查 —— 必须 ≥ 阈值（默认 1 GB），否则报错
 *
 * 任一项失败 exit 1，调用方据此决定是否继续后续步骤。
 */
class UpgradePrecheckCommand extends Command
{
    protected $signature = 'upgrade:precheck
                            {--min-disk-gb=1 : 最低磁盘剩余空间 (GB)，低于该值视为失败}';

    protected $description = '升级预检：迁移预演 + 磁盘空间检查';

    public function handle(): int
    {
        $minDiskRaw = $this->option('min-disk-gb');
        if (! is_numeric($minDiskRaw)) {
            $this->error('--min-disk-gb 必须是数值');

            return CommandAlias::FAILURE;
        }

        $minDiskGb = (float) $minDiskRaw;

        $this->info('升级预检开始');

        // 1) 迁移预演（不实际执行）
        $this->line('迁移预演（migrate --pretend）...');
        try {
            $exit = Artisan::call('migrate', ['--pretend' => true, '--force' => true]);
            $output = trim((string) Artisan::output());
            if ($exit !== 0) {
                $this->error("migrate --pretend 失败 (exit=$exit)");
                if ($output !== '') {
                    $this->line($output);
                }

                return CommandAlias::FAILURE;
            }

            if ($output === '') {
                $this->line('  (无待执行迁移)');
            } else {
                foreach (explode("\n", $output) as $line) {
                    $this->line('  '.$line);
                }
            }
        } catch (Throwable $e) {
            $this->error('migrate --pretend 异常：'.$e->getMessage());

            return CommandAlias::FAILURE;
        }

        // 2) 磁盘空间检查
        $this->line('磁盘空间检查...');
        $diskFreeGb = $this->diskFreeGb();
        $this->line('  storage_path 所在卷剩余: '.number_format($diskFreeGb, 1).' GB（阈值 '.number_format($minDiskGb, 1).' GB）');

        if ($diskFreeGb < $minDiskGb) {
            $this->error("磁盘剩余空间不足 ($diskFreeGb GB < $minDiskGb GB)");

            return CommandAlias::FAILURE;
        }

        $this->info('升级预检通过');

        return CommandAlias::SUCCESS;
    }

    /**
     * 计算 storage_path 所在卷剩余空间（GB，保留 1 位小数）
     *
     * 此方法非 final 是为了便于测试覆盖（mock 磁盘不足场景）。
     */
    protected function diskFreeGb(): float
    {
        $bytes = @disk_free_space(storage_path());
        if ($bytes === false) {
            return 0.0;
        }

        return round($bytes / (1024 ** 3), 1);
    }
}
