<?php

namespace App\Console\Commands;

use App\Services\Logs\CoreLogPurger;
use App\Services\Logs\LogPurgeContext;
use App\Services\Logs\PluginLogPurgeManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PurgeLogsCommand extends Command
{
    protected $signature = 'logs:purge {--dry-run : Count matching rows without deleting them}';

    protected $description = 'Purge core and plugin logs using tiered retention rules';

    public function handle(CoreLogPurger $core, PluginLogPurgeManager $plugins): int
    {
        $fullDays = (int) config('logs.retention.full_days', 7);
        $auditDays = (int) config('logs.retention.audit_days', 180);
        $chunkSize = (int) config('purge.chunk', 1000);
        if ($fullDays <= 0 || $auditDays <= $fullDays || $chunkSize <= 0) {
            $this->error('Invalid log retention configuration');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $context = new LogPurgeContext(
            $now->subDays($fullDays),
            $now->subDays($auditDays),
            $chunkSize,
            (bool) $this->option('dry-run'),
        );

        $coreSummary = $core->purge($context);
        foreach ($coreSummary['tables'] as $table => $result) {
            $this->info(sprintf(
                '%s expired=%d diagnostic=%d unclassified=%d',
                $table,
                $result['expired'],
                $result['diagnostic'],
                $result['unclassified'],
            ));
            if ($result['unclassified'] > 0) {
                $this->warn("$table unclassified={$result['unclassified']}");
            }
        }
        foreach ($coreSummary['failures'] as $failure) {
            $this->error($failure['table'].' '.$failure['error']);
        }

        $pluginSummary = $plugins->purge($context);
        foreach ($pluginSummary['results'] as $result) {
            foreach ($result->deletedByTable as $table => $deleted) {
                $this->info("{$result->owner} $table deleted=$deleted unclassified={$result->unclassified}");
            }
        }
        foreach ($pluginSummary['warnings'] as $warning) {
            $this->warn($warning);
        }
        foreach ($pluginSummary['failures'] as $failure) {
            $this->error($failure['plugin'].' '.$failure['error']);
        }

        return $coreSummary['hasFailures'] || $pluginSummary['hasFailures']
            ? self::FAILURE
            : self::SUCCESS;
    }
}
