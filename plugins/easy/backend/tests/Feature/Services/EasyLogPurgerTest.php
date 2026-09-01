<?php

use App\Services\Logs\ChunkedLogDeleter;
use App\Services\Logs\LogPurgeContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Plugins\Easy\Services\EasyLogPurger;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function insertEasyLog(array $attributes): int
{
    return (int) DB::table('easy_logs')->insertGetId(array_merge([
        'action' => 'check',
        'method' => 'POST',
        'url' => '/api/easy/check',
        'params' => null,
        'response' => null,
        'ip' => null,
        'status' => 0,
    ], $attributes));
}

test('Easy 日志按动作分层且失败不升级保留级别', function () {
    $recent = insertEasyLog(['action' => 'check', 'created_at' => now()->subDays(6)]);
    $diagnostic = insertEasyLog(['action' => 'revalidate', 'status' => 0, 'created_at' => now()->subDays(8)]);
    $audit = insertEasyLog(['action' => 'apply', 'status' => 0, 'created_at' => now()->subDays(30)]);
    $expired = insertEasyLog(['action' => 'apply', 'created_at' => now()->subDays(181)]);
    $legacyInvoiceAudit = insertEasyLog(['action' => null, 'url' => '/api/easy/invoice/apply', 'created_at' => now()->subDays(30)]);
    $legacyInvoiceDiagnostic = insertEasyLog(['action' => null, 'url' => '/api/easy/invoice/quota', 'created_at' => now()->subDays(30)]);
    $unknown = insertEasyLog(['action' => 'futureAction', 'url' => '/api/easy/future', 'created_at' => now()->subDays(30)]);

    $result = (new EasyLogPurger(new ChunkedLogDeleter))->purge(new LogPurgeContext(
        CarbonImmutable::now()->subDays(7),
        CarbonImmutable::now()->subDays(180),
        2,
        false,
    ));

    expect(DB::table('easy_logs')->where('id', $recent)->exists())->toBeTrue()
        ->and(DB::table('easy_logs')->where('id', $diagnostic)->exists())->toBeFalse()
        ->and(DB::table('easy_logs')->where('id', $audit)->exists())->toBeTrue()
        ->and(DB::table('easy_logs')->where('id', $expired)->exists())->toBeFalse()
        ->and(DB::table('easy_logs')->where('id', $legacyInvoiceAudit)->exists())->toBeTrue()
        ->and(DB::table('easy_logs')->where('id', $legacyInvoiceDiagnostic)->exists())->toBeFalse()
        ->and(DB::table('easy_logs')->where('id', $unknown)->exists())->toBeTrue()
        ->and($result->deletedByTable['easy_logs'])->toBe(3)
        ->and($result->unclassified)->toBe(1);
});
