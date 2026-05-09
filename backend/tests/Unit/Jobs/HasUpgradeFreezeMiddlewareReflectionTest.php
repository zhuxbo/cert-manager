<?php

use App\Jobs\Concerns\HasUpgradeFreezeMiddleware;
use Illuminate\Contracts\Queue\ShouldQueue;

uses(Tests\TestCase::class);

/**
 * 收集 app/Jobs/ 下所有实现 ShouldQueue 的具象类。
 *
 * - 递归扫描 app/Jobs/，按 PSR-4 推断类名
 * - 跳过抽象类、接口、trait
 * - 仅保留 implements ShouldQueue 的具象类
 *
 * @return list<class-string>
 */
function collectAllShouldQueueClasses(): array
{
    $jobsDir = app_path('Jobs');
    if (! is_dir($jobsDir)) {
        return [];
    }

    /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $iter */
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($jobsDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $classes = [];
    foreach ($iter as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = ltrim(str_replace($jobsDir, '', $file->getPathname()), DIRECTORY_SEPARATOR);
        $relative = str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        $class = 'App\\Jobs\\'.preg_replace('/\.php$/', '', $relative);

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
            continue;
        }

        if (! $reflection->implementsInterface(ShouldQueue::class)) {
            continue;
        }

        $classes[] = $class;
    }

    sort($classes);

    return $classes;
}

test('扫描函数能找到所有 4 个内置 ShouldQueue Job', function () {
    $classes = collectAllShouldQueueClasses();

    expect($classes)->toContain(
        \App\Jobs\TaskJob::class,
        \App\Jobs\NotificationJob::class,
        \App\Jobs\CreateBackupJob::class,
        \App\Jobs\RestoreBackupJob::class,
    );
});

test('所有 ShouldQueue 实现都使用 HasUpgradeFreezeMiddleware trait', function () {
    $classes = collectAllShouldQueueClasses();

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);

        // 收集所有层级使用的 trait（包括父类继承的）
        $allTraits = [];
        $current = $reflection;
        while ($current) {
            foreach ($current->getTraitNames() as $trait) {
                $allTraits[] = $trait;
            }
            $current = $current->getParentClass() ?: null;
        }

        // 用 PHPUnit assertContains（带 message 参数）输出可读的失败信息
        \PHPUnit\Framework\Assert::assertContains(
            HasUpgradeFreezeMiddleware::class,
            $allTraits,
            "Job $class 缺少 HasUpgradeFreezeMiddleware trait（升级冻结契约要求）"
        );
    }
});
