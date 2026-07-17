<?php

use App\Exceptions\ApiResponseException;
use App\Jobs\TaskJob;
use App\Models\Acme;
use App\Models\Admin;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\Task;
use App\Services\Acme\Action;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Traits\CreatesTestData;

// Feature/Commands 目录已默认 uses(TestCase + RefreshDatabase)（tests/Pest.php），此处仅追加 CreatesTestData
uses(CreatesTestData::class)->group('database');

beforeEach(function () {
    $this->seed = true;
    $this->seeder = DatabaseSeeder::class;
    Queue::fake();
    Cache::flush(); // SystemAlert 固定指纹去重走 Cache，跨 test 隔离
    config([
        'reconcile.acme_cutoff_minutes' => 15,
        'reconcile.acme_max_ids' => 20,
        'reconcile.acme_max_attempts' => 3,
        'reconcile.acme_retry_delay_minutes' => 10,
    ]);
});

function makePendingAcme(array $overrides = []): Acme
{
    return Acme::factory()->create(array_merge([
        'status' => 'pending',
        'api_id' => null,
        'created_at' => now()->subMinutes(20),
    ], $overrides));
}

function makeFailedAcmeCommit(int $acmeId, int $count): void
{
    for ($i = $count; $i >= 1; $i--) {
        Task::factory()->failed()->create([
            'order_id' => $acmeId,
            'action' => 'commit_acme',
            'attempts' => 1,
            'last_execute_at' => now()->subMinutes($i * 3), // 均晚于 acme.created_at（subMinutes 20）
        ]);
    }
}

function hasAcmeCommitTask(int $acmeId): bool
{
    return Task::where('order_id', $acmeId)->where('action', 'commit_acme')->where('status', 'executing')->exists();
}

function setupAcmeGatewaySettings(): void
{
    $group = SettingGroup::firstOrCreate(['name' => 'ca'], ['title' => '证书接口', 'weight' => 2]);
    foreach (['url' => 'https://fake-gateway.test/api/v2', 'token' => 'fake-key'] as $key => $value) {
        $setting = Setting::firstOrCreate(
            ['group_id' => $group->id, 'key' => $key],
            ['type' => 'string', 'value' => null, 'weight' => 0]
        );
        $setting->value = $value;
        $setting->save();
    }
}

test('T6：pending + null api_id + 过 cutoff 的 ACME 建 commit_acme task', function () {
    $acme = makePendingAcme();

    $this->artisan('schedule:reconcile-acme')->assertSuccessful();

    expect(hasAcmeCommitTask($acme->id))->toBeTrue();
    Queue::assertPushed(TaskJob::class, fn (TaskJob $job) => $job->queue === config('queue.names.tasks'));
});

test('T6：未过 cutoff 或已有 api_id 的 ACME 不处理', function () {
    $fresh = makePendingAcme(['created_at' => now()->subMinutes(2)]);
    $withApiId = makePendingAcme(['api_id' => 'gw-x']);

    $this->artisan('schedule:reconcile-acme')->assertSuccessful();

    expect(hasAcmeCommitTask($fresh->id))->toBeFalse()
        ->and(hasAcmeCommitTask($withApiId->id))->toBeFalse();
});

test('T6：重复执行不重复建 commit_acme task', function () {
    $acme = makePendingAcme();

    $this->artisan('schedule:reconcile-acme')->assertSuccessful();
    $this->artisan('schedule:reconcile-acme')->assertSuccessful();

    expect(Task::where('order_id', $acme->id)->where('action', 'commit_acme')->count())->toBe(1);
});

test('T6：超 max_attempts 的 ACME 转人工 SystemAlert，不再建 task', function () {
    Admin::factory()->create(['email' => 'ops@example.test']);
    $acme = makePendingAcme();
    makeFailedAcmeCommit($acme->id, 3); // 3 失败 commit_acme → 到顶

    $intents = [];
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->once()->with(Mockery::on(function (NotificationIntent $intent) use (&$intents) {
        $intents[] = $intent;

        return true;
    }));
    $this->app->instance(NotificationCenter::class, $mock);

    $this->artisan('schedule:reconcile-acme')->assertSuccessful();

    expect(hasAcmeCommitTask($acme->id))->toBeFalse(); // 到顶不再建 task
    expect($intents)->toHaveCount(1)
        ->and($intents[0]->code)->toBe('system_alert')
        ->and($intents[0]->context['category'])->toBe('acme_reconcile')
        ->and($intents[0]->context['details']['acme_id'])->toBe($acme->id);
});

test('T6：到顶 ACME 不占 limit（新卡单进窗）且仍触发告警（reachability N-1 两段式非占位）', function () {
    config(['reconcile.acme_max_ids' => 1]); // limit=1，凸显名额占用
    Admin::factory()->create(['email' => 'ops@example.test']);
    $maxed = makePendingAcme();
    makeFailedAcmeCommit($maxed->id, 3); // 到顶
    $fresh = makePendingAcme(); // 无 task
    expect($maxed->id)->toBeLessThan($fresh->id);

    $intents = [];
    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->with(Mockery::on(function (NotificationIntent $intent) use (&$intents) {
        $intents[] = $intent;

        return true;
    }));
    $this->app->instance(NotificationCenter::class, $mock);

    $this->artisan('schedule:reconcile-acme')->assertSuccessful();

    // 到顶单被主扫描排除、让出 limit(1) → 新卡单进窗建 task（不占位、不队头阻塞）
    expect(hasAcmeCommitTask($fresh->id))->toBeTrue()
        ->and(hasAcmeCommitTask($maxed->id))->toBeFalse();
    // 且到顶单仍由转人工扫描告警（两段式，非"不排除+循环内告警"的另一解复刻队头阻塞）
    $alerted = collect($intents)->contains(
        fn (NotificationIntent $i) => $i->code === 'system_alert'
            && ($i->context['details']['acme_id'] ?? null) === $maxed->id
    );
    expect($alerted)->toBeTrue();
});

test('T6：commit 回填 api_id 后 reconcile 不再处理（自愈闭环）', function () {
    $user = $this->createTestUser(['balance' => '500.00']);
    $product = $this->createTestProduct(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    $acme = makePendingAcme([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'contact_email' => 'test@example.com',
        'refer_id' => 'refer-selfheal',
    ]);

    setupAcmeGatewaySettings();
    Http::fake([
        'fake-gateway.test/*' => Http::response([
            'code' => 1,
            'data' => ['order_id' => 'gw-selfheal', 'eab_kid' => 'kid', 'eab_hmac' => 'hmac'],
        ]),
    ]);
    Http::preventStrayRequests();

    // 第一轮 reconcile：建 commit_acme task
    $this->artisan('schedule:reconcile-acme')->assertSuccessful();
    expect(hasAcmeCommitTask($acme->id))->toBeTrue();

    // 模拟 TaskJob 执行 = Action::commit（上游幂等 Case A 返回 order+EAB → 回填 api_id/active 自愈）
    try {
        (new Action)->commit($acme->id);
    } catch (ApiResponseException $e) {
        expect($e->getApiResponse()['code'])->toBe(1);
    }
    $acme->refresh();
    expect($acme->api_id)->toBe('gw-selfheal')
        ->and($acme->status)->toBe(Acme::STATUS_ACTIVE);

    // 消费掉 executing task，再 reconcile：whereNull('api_id') 排除已自愈单 → 零新建
    Task::where('order_id', $acme->id)->update(['status' => 'successful']);
    $this->artisan('schedule:reconcile-acme')->assertSuccessful();
    expect(hasAcmeCommitTask($acme->id))->toBeFalse();
});
