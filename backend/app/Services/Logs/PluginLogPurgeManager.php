<?php

declare(strict_types=1);

namespace App\Services\Logs;

use App\Contracts\PluginLogPurger;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

final class PluginLogPurgeManager
{
    private const CORE_LOG_TABLES = [
        'admin_logs', 'api_logs', 'callback_logs', 'ca_logs', 'error_logs', 'user_logs',
    ];

    /**
     * @return array{
     *     results: list<LogPurgeResult>,
     *     failures: list<array{plugin: string, error: string}>,
     *     warnings: list<string>,
     *     hasFailures: bool
     * }
     */
    public function purge(LogPurgeContext $context): array
    {
        $purgers = iterator_to_array(app()->tagged('plugin.log_purgers'));
        $validatedPurgers = [];
        $claimedTables = [];
        $failures = [];
        $results = [];
        $warnings = [];

        foreach ($purgers as $purger) {
            $plugin = 'invalid';

            try {
                if (! $purger instanceof PluginLogPurger) {
                    throw new InvalidArgumentException('tagged service must implement PluginLogPurger');
                }

                $plugin = $purger->plugin();
                $this->validatePlugin($plugin);
                foreach ($purger->tables() as $table) {
                    $this->validateTable($table);
                    $claimedTables[] = $table;
                }
                $validatedPurgers[] = ['plugin' => $plugin, 'purger' => $purger];
            } catch (Throwable $e) {
                $failures[] = $this->failure($plugin, $e);
            }
        }

        foreach ($validatedPurgers as $validatedPurger) {
            try {
                $result = $validatedPurger['purger']->purge($context);
                $results[] = $result;
                array_push($warnings, ...$result->warnings);
            } catch (Throwable $e) {
                $failures[] = $this->failure($validatedPurger['plugin'], $e);
            }
        }

        foreach (Schema::getTableListing() as $table) {
            $table = $this->unqualifyTable((string) $table);
            if (str_ends_with($table, '_logs')
                && ! in_array($table, self::CORE_LOG_TABLES, true)
                && ! in_array($table, $claimedTables, true)) {
                $warnings[] = "Unclaimed log table: $table";
            }
        }

        return [
            'results' => $results,
            'failures' => $failures,
            'warnings' => array_values(array_unique($warnings)),
            'hasFailures' => $failures !== [],
        ];
    }

    private function validatePlugin(string $plugin): void
    {
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $plugin) !== 1) {
            throw new InvalidArgumentException('invalid plugin name');
        }
    }

    private function validateTable(string $table): void
    {
        if (preg_match('/^[a-zA-Z0-9_]+$/', $table) !== 1) {
            throw new InvalidArgumentException('invalid log table name');
        }
    }

    /** @return array{plugin: string, error: string} */
    private function failure(string $plugin, Throwable $e): array
    {
        return [
            'plugin' => preg_match('/^[a-zA-Z0-9_-]+$/', $plugin) === 1 ? $plugin : 'invalid',
            'error' => class_basename($e).': plugin log purge failed',
        ];
    }

    private function unqualifyTable(string $table): string
    {
        $parts = preg_split('/[.]/', trim($table, '`'));

        return trim((string) end($parts), '`');
    }
}
