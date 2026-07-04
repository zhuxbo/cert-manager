<?php

use App\Jobs\Concerns\HasUpgradeFreezeMiddleware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

uses(TestCase::class);

test('插件所有 ShouldQueue Job 都 use HasUpgradeFreezeMiddleware', function () {
    // 本测试位于 backend/tests/Unit/，上溯两级到 backend/，再进 Jobs/
    $jobsDir = dirname(__DIR__, 2).'/Jobs';
    expect(is_dir($jobsDir))->toBeTrue();

    $offenders = [];
    foreach (glob("$jobsDir/*.php") as $file) {
        $class = 'Plugins\\CloudDeploy\\Jobs\\'.basename($file, '.php');
        if (! class_exists($class)) {
            continue;
        }
        $ref = new ReflectionClass($class);
        if ($ref->isAbstract() || ! $ref->implementsInterface(ShouldQueue::class)) {
            continue;
        }
        if (! in_array(HasUpgradeFreezeMiddleware::class, class_uses_recursive($class), true)) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([]);
});
