<?php

use App\Contracts\PluginLogPurger;
use App\Services\Logs\LogPurgeContext;
use App\Services\Logs\LogPurgeResult;
use App\Services\Logs\PluginLogPurgeManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class FailingPluginLogPurger implements PluginLogPurger
{
    public function plugin(): string
    {
        return 'failing';
    }

    public function tables(): array
    {
        return ['failing_logs'];
    }

    public function purge(LogPurgeContext $context): LogPurgeResult
    {
        throw new RuntimeException('mysql://secret@host/payload');
    }
}

class WorkingPluginLogPurger implements PluginLogPurger
{
    public static bool $ran = false;

    public function plugin(): string
    {
        return 'working';
    }

    public function tables(): array
    {
        return ['working_logs'];
    }

    public function purge(LogPurgeContext $context): LogPurgeResult
    {
        self::$ran = true;

        return new LogPurgeResult('working', ['working_logs' => 3], 1, ['table skipped']);
    }
}

class MetadataFailingPluginLogPurger implements PluginLogPurger
{
    public function plugin(): string
    {
        throw new RuntimeException('plugin metadata unavailable');
    }

    public function tables(): array
    {
        return [];
    }

    public function purge(LogPurgeContext $context): LogPurgeResult
    {
        throw new LogicException('purge should not run');
    }
}

beforeEach(function () {
    WorkingPluginLogPurger::$ran = false;
    app()->bind(FailingPluginLogPurger::class);
    app()->bind(WorkingPluginLogPurger::class);
    app()->tag([FailingPluginLogPurger::class, WorkingPluginLogPurger::class], 'plugin.log_purgers');

    Schema::create('unknown_plugin_logs', function (Blueprint $table) {
        $table->id();
        $table->timestamp('created_at')->nullable();
    });
});

afterEach(fn () => Schema::dropIfExists('unknown_plugin_logs'));

test('插件清理失败彼此隔离且错误摘要不泄露异常正文', function () {
    $summary = (new PluginLogPurgeManager)->purge(new LogPurgeContext(
        CarbonImmutable::now()->subDays(7),
        CarbonImmutable::now()->subDays(180),
        1000,
        false,
    ));
    $workingResult = collect($summary['results'])->firstWhere('owner', 'working');

    expect(WorkingPluginLogPurger::$ran)->toBeTrue()
        ->and($summary['hasFailures'])->toBeTrue()
        ->and($summary['failures'][0]['plugin'])->toBe('failing')
        ->and($summary['failures'][0]['error'])->toContain('RuntimeException')
        ->and($summary['failures'][0]['error'])->not->toContain('secret')
        ->and($workingResult?->deletedByTable)->toBe(['working_logs' => 3]);
});

test('插件元数据异常不会阻断其他插件清理', function () {
    app()->bind(MetadataFailingPluginLogPurger::class);
    app()->tag(MetadataFailingPluginLogPurger::class, 'plugin.log_purgers');

    $summary = (new PluginLogPurgeManager)->purge(new LogPurgeContext(
        CarbonImmutable::now()->subDays(7),
        CarbonImmutable::now()->subDays(180),
        1000,
        false,
    ));

    expect(WorkingPluginLogPurger::$ran)->toBeTrue()
        ->and($summary['hasFailures'])->toBeTrue()
        ->and($summary['failures'])->toContain([
            'plugin' => 'invalid',
            'error' => 'RuntimeException: plugin log purge failed',
        ]);
});

test('未声明的插件日志表只告警且不删除', function () {
    $summary = (new PluginLogPurgeManager)->purge(new LogPurgeContext(
        CarbonImmutable::now()->subDays(7),
        CarbonImmutable::now()->subDays(180),
        1000,
        false,
    ));

    expect($summary['warnings'])->toContain('Unclaimed log table: unknown_plugin_logs')
        ->and(Schema::hasTable('unknown_plugin_logs'))->toBeTrue();
});
