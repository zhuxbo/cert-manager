<?php

namespace Plugins\Easy\Services;

use App\Contracts\PluginLogPurger;
use App\Services\Logs\ChunkedLogDeleter;
use App\Services\Logs\LogPurgeContext;
use App\Services\Logs\LogPurgeResult;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EasyLogPurger implements PluginLogPurger
{
    private const AUDIT_ACTIONS = ['apply', 'updateValidationMethod'];

    private const DIAGNOSTIC_ACTIONS = ['check', 'revalidate', 'sync', 'validateFile', 'download'];

    public function __construct(private readonly ChunkedLogDeleter $deleter) {}

    public function plugin(): string
    {
        return 'easy';
    }

    public function tables(): array
    {
        return ['easy_logs'];
    }

    public function purge(LogPurgeContext $context): LogPurgeResult
    {
        if (! Schema::hasTable('easy_logs')) {
            return new LogPurgeResult('easy', [], 0, ['easy_logs table does not exist; skipped']);
        }

        $expired = $this->deleter->delete(
            fn () => DB::table('easy_logs')->where('created_at', '<', $context->auditCutoff),
            $context->chunkSize,
            $context->dryRun,
        );
        $diagnostic = $this->deleter->delete(
            fn () => $this->auditWindow($context)->where(fn (Builder $query) => $this->diagnostic($query)),
            $context->chunkSize,
            $context->dryRun,
        );

        $windowCount = (int) $this->auditWindow($context)->count();
        $classifiedCount = (int) $this->auditWindow($context)
            ->where(fn (Builder $query) => $this->known($query))
            ->count();

        return new LogPurgeResult(
            'easy',
            ['easy_logs' => $expired + $diagnostic],
            max(0, $windowCount - $classifiedCount),
        );
    }

    private function auditWindow(LogPurgeContext $context): Builder
    {
        return DB::table('easy_logs')
            ->where('created_at', '<', $context->fullCutoff)
            ->where('created_at', '>=', $context->auditCutoff);
    }

    private function diagnostic(Builder $query): void
    {
        $query->whereIn('action', self::DIAGNOSTIC_ACTIONS)
            ->orWhere(fn (Builder $legacy) => $legacy
                ->whereNull('action')
                ->where(function (Builder $paths) {
                    foreach (['check', 'revalidate', 'sync', 'validate-file', 'download', 'invoice/ping', 'invoice/quota'] as $path) {
                        $paths->orWhere('url', 'like', "%/api/easy/$path%");
                    }
                }));
    }

    private function known(Builder $query): void
    {
        $this->diagnostic($query);
        $query->orWhereIn('action', self::AUDIT_ACTIONS)
            ->orWhere(fn (Builder $legacy) => $legacy
                ->whereNull('action')
                ->where(function (Builder $paths) {
                    foreach (['apply', 'update-validation-method', 'invoice/apply'] as $path) {
                        $paths->orWhere('url', 'like', "%/api/easy/$path%");
                    }
                }));
    }
}
