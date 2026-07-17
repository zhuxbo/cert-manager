<?php

use App\Jobs\Middleware\SkipWhenUpgradeFrozen;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Plugins\CloudDeploy\Jobs\CloudDeployJob;
use Tests\TestCase;

uses(TestCase::class);

test('CloudDeployJob middleware 同时含 freeze 守卫与 WithoutOverlapping（freeze 在前）', function () {
    $mw = (new CloudDeployJob(1, 1, 'auto'))->middleware();
    expect($mw[0])->toBeInstanceOf(SkipWhenUpgradeFrozen::class);            // freeze 恒在前
    expect(collect($mw)->contains(fn ($m) => $m instanceof WithoutOverlapping))->toBeTrue();
});

test('schedule 已注册 cloud-deploy-reconcile', function () {
    $schedule = app(Schedule::class);
    $names = collect($schedule->events())->map(fn ($e) => $e->description ?? $e->command);
    expect($names->contains(fn ($n) => str_contains((string) $n, 'cloud-deploy:reconcile') || str_contains((string) $n, 'cloud-deploy-reconcile')))->toBeTrue();
});
