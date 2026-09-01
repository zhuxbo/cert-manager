<?php

declare(strict_types=1);

namespace App\Services\Logs;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CoreLogPurger
{
    private const TABLES = ['admin_logs', 'user_logs', 'api_logs', 'callback_logs', 'ca_logs', 'error_logs'];

    public function __construct(
        private readonly ChunkedLogDeleter $deleter,
        private readonly CoreLogRetentionPolicy $policy,
    ) {}

    /**
     * @return array{
     *     tables: array<string, array{expired: int, diagnostic: int, unclassified: int}>,
     *     failures: list<array{table: string, error: string}>,
     *     hasFailures: bool
     * }
     */
    public function purge(LogPurgeContext $context): array
    {
        $tables = [];
        $failures = [];

        foreach (self::TABLES as $table) {
            try {
                $expired = $this->deleter->delete(
                    fn () => DB::table($table)->where('created_at', '<', $context->auditCutoff),
                    $context->chunkSize,
                    $context->dryRun,
                );
                $diagnostic = $this->deleter->delete(
                    fn () => $this->auditWindow($table, $context)->where(
                        fn (Builder $query) => $this->applyDiagnosticPredicate($query, $table)
                    ),
                    $context->chunkSize,
                    $context->dryRun,
                );

                $windowCount = (int) $this->auditWindow($table, $context)->count();
                $classifiedCount = (int) $this->auditWindow($table, $context)
                    ->where(fn (Builder $query) => $this->applyKnownPredicate($query, $table))
                    ->count();

                $tables[$table] = [
                    'expired' => $expired,
                    'diagnostic' => $diagnostic,
                    'unclassified' => max(0, $windowCount - $classifiedCount),
                ];
            } catch (Throwable $e) {
                $failures[] = [
                    'table' => $table,
                    'error' => class_basename($e).': core log purge failed',
                ];
            }
        }

        return ['tables' => $tables, 'failures' => $failures, 'hasFailures' => $failures !== []];
    }

    private function auditWindow(string $table, LogPurgeContext $context): Builder
    {
        return DB::table($table)
            ->where('created_at', '<', $context->fullCutoff)
            ->where('created_at', '>=', $context->auditCutoff);
    }

    private function applyDiagnosticPredicate(Builder $query, string $table): void
    {
        match ($table) {
            'admin_logs' => $query
                ->whereIn('method', ['GET', 'HEAD', 'OPTIONS'])
                ->orWhereIn('action', $this->policy->adminDiagnosticActions()),
            'user_logs' => $query
                ->whereIn('method', ['GET', 'HEAD', 'OPTIONS'])
                ->orWhereIn('action', $this->policy->userDiagnosticActions()),
            'api_logs' => $query
                ->whereIn('action', $this->policy->apiDiagnosticActions())
                ->orWhere(fn (Builder $fallback) => $this->applyHistoricalPaths($fallback, $this->policy->apiDiagnosticActions())),
            'callback_logs' => $query->where('status', '!=', 1),
            'ca_logs' => $query->whereIn('api', $this->policy->caDiagnosticActions()),
            'error_logs' => $query
                ->whereIn('action', $this->policy->errorDiagnosticActions())
                ->orWhere(fn (Builder $fallback) => $this->applyHistoricalPaths($fallback, $this->policy->errorDiagnosticActions())),
            default => null,
        };
    }

    private function applyKnownPredicate(Builder $query, string $table): void
    {
        $this->applyDiagnosticPredicate($query, $table);

        match ($table) {
            'admin_logs' => $query->orWhereIn('action', $this->policy->adminAuditActions()),
            'user_logs' => $this->applyUserAuditPredicate($query),
            'api_logs' => $query
                ->orWhereIn('action', $this->policy->apiAuditActions())
                ->orWhere(fn (Builder $fallback) => $this->applyHistoricalPaths($fallback, $this->policy->apiAuditActions())),
            'callback_logs' => $query->orWhere(fn (Builder $audit) => $audit
                ->where('status', 1)
                ->whereIn('action', $this->policy->callbackAuditActions())),
            'ca_logs' => $query->orWhereIn('api', $this->policy->caAuditActions()),
            'error_logs' => null,
            default => null,
        };
    }

    private function applyUserAuditPredicate(Builder $query): void
    {
        foreach ($this->policy->userAuditActions() as $module => $actions) {
            $query->orWhere(fn (Builder $audit) => $audit
                ->where('module', $module)
                ->whereIn('action', $actions));
        }
    }

    /** @param list<string> $actions */
    private function applyHistoricalPaths(Builder $query, array $actions): void
    {
        $query->whereNull('action')->where(function (Builder $paths) use ($actions) {
            foreach ($actions as $action) {
                foreach ($this->historicalPaths($action) as $path) {
                    $paths->orWhere('url', 'like', '%/'.$path.'%');
                }
            }
        });
    }

    /** @return list<string> */
    private function historicalPaths(string $action): array
    {
        return match ($action) {
            'getProducts' => ['product', 'get-products'],
            'getOrderIdByReferId' => ['getOidByReferId', 'get-order-id-by-refer-id'],
            'updateDCV' => ['updateDCV', 'update-dcv'],
            default => [strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $action))],
        };
    }
}
