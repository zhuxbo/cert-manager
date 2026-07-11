<?php

use App\Models\Admin;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Notification\NotificationCenter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

afterEach(function () {
    Mockery::close();
});

function failedJobsSetupAdmin(): void
{
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => '站点', 'weight' => 1]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'adminEmail'],
        ['type' => 'string', 'value' => 'ops@corp.example', 'weight' => 0]
    );
    Setting::clearGroupCache($group->id);
    Admin::factory()->create(['email' => 'ops@corp.example']);
    Cache::flush();
}

function failedJobsCaptureCenter(): object
{
    $state = new class
    {
        public int $count = 0;

        public mixed $captured = null;
    };
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->andReturnUsing(function ($intent) use ($state) {
        $state->count++;
        $state->captured = $intent;
    });
    app()->instance(NotificationCenter::class, $mock);

    return $state;
}

/** 插入 $n 条 failed_jobs，failed_at=$hoursAgo 小时前 */
function insertFailedJobs(int $n, int $hoursAgo): void
{
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = [
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'tasks',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now()->subHours($hoursAgo),
        ];
    }
    DB::table('failed_jobs')->insert($rows);
}

beforeEach(function () {
    failedJobsSetupAdmin();
    config()->set('monitoring.failed_jobs.enabled', true);
    config()->set('monitoring.failed_jobs.window_hours', 24);
    config()->set('monitoring.failed_jobs.alert_threshold', 3); // 低阈值加速
    config()->set('monitoring.failed_jobs.dedupe_ttl_hours', 72);
});

test('① 窗口内新增 ≤ 阈值 → 无告警 + 清键', function () {
    Cache::put('system_alert:failed_jobs', 'stale', now()->addHours(72));
    insertFailedJobs(3, 1); // 窗口内 3 条 = 阈值，不超
    $state = failedJobsCaptureCenter();

    $this->artisan('schedule:failed-jobs-check')->assertSuccessful();

    expect($state->count)->toBe(0)
        ->and(Cache::has('system_alert:failed_jobs'))->toBeFalse();
});

test('② 窗口内新增 > 阈值 → 告警', function () {
    insertFailedJobs(4, 1); // 窗口内 4 条 > 3
    $state = failedJobsCaptureCenter();

    $this->artisan('schedule:failed-jobs-check')->assertSuccessful();

    expect($state->count)->toBe(1)
        ->and($state->captured->context['category'])->toBe('failed_jobs');
});

test('③ 仅陈旧（>24h 前）堆积超阈 → 不告警（窗口语义）', function () {
    insertFailedJobs(10, 48); // 全部 48h 前，窗口外
    $state = failedJobsCaptureCenter();

    $this->artisan('schedule:failed-jobs-check')->assertSuccessful();

    expect($state->count)->toBe(0);
});

test('④ 连续两日持续超阈 → 第二日不重发（固定指纹）', function () {
    insertFailedJobs(4, 1);
    $state = failedJobsCaptureCenter();

    $this->artisan('schedule:failed-jobs-check')->assertSuccessful();
    // 再插更多（计数变化），固定指纹仍去重
    insertFailedJobs(2, 1);
    $this->artisan('schedule:failed-jobs-check')->assertSuccessful();

    expect($state->count)->toBe(1);
});

test('⑤ 恢复后清键、再超阈立即发', function () {
    insertFailedJobs(4, 1);
    $state = failedJobsCaptureCenter();

    $this->artisan('schedule:failed-jobs-check')->assertSuccessful();
    expect($state->count)->toBe(1);

    // 窗口滑过（把行改到窗口外）→ 计数归零 → 清键
    DB::table('failed_jobs')->update(['failed_at' => now()->subHours(48)]);
    $this->artisan('schedule:failed-jobs-check')->assertSuccessful();
    expect(Cache::has('system_alert:failed_jobs'))->toBeFalse();

    // 再次窗口内超阈 → 立即发（键已清）
    insertFailedJobs(4, 1);
    $this->artisan('schedule:failed-jobs-check')->assertSuccessful();
    expect($state->count)->toBe(2);
});

test('⑥ enabled=false → 不告警', function () {
    config()->set('monitoring.failed_jobs.enabled', false);
    insertFailedJobs(10, 1);
    $state = failedJobsCaptureCenter();

    $this->artisan('schedule:failed-jobs-check')->assertSuccessful();

    expect($state->count)->toBe(0);
});

test('⑦ queue:prune-failed 调度已注册', function () {
    /** @var Schedule $schedule */
    $schedule = $this->app->make(Schedule::class);
    $commands = collect($schedule->events())->map(fn ($e) => $e->command ?? '')->all();

    $hasPrune = collect($commands)->contains(fn ($c) => str_contains($c, 'queue:prune-failed'));
    expect($hasPrune)->toBeTrue();
});
