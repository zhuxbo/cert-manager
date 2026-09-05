<?php

use App\Models\Cert;
use App\Models\DomainValidationRecord;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\Task;
use App\Models\User;
use App\Services\Delegation\DnsResolver;
use App\Services\Notification\SystemAlert;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * F2-1 ValidateCommand dnsTools 全挂安全网（连续 N 建 sync）。
 */

/** 配置 site.dnsTools */
function setupDnsTools(): void
{
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => '站点', 'weight' => 1]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'dnsTools'],
        ['type' => 'array', 'value' => ['http://dnstool1.test'], 'weight' => 0]
    );
    Setting::clearGroupCache($group->id);
    Cache::store('runtime')->flush();
}

/** dnsTools 全连接异常 */
function fakeDnsToolsDown(): void
{
    Http::preventStrayRequests(); // 硬化：漏网 URL 直接报错，与 VerifyValidationTest 对齐
    Http::fake(['dnstool1.test/*' => fn () => throw new ConnectionException('down')]);
}

/** 建一个 processing 态 txt 验证订单（validation 就绪，非委托），并预置到点的验证记录 */
function makeProcessingTxtOrder(): Order
{
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'processing',
        'dcv' => ['method' => 'txt'],
        'validation' => [
            ['domain' => 'example.com', 'method' => 'txt', 'host' => '_dnsauth.example.com', 'value' => 'tok'],
        ],
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    DomainValidationRecord::create([
        'order_id' => $order->id,
        'last_check_at' => now()->subMinutes(5),
        'next_check_at' => now()->subMinute(),
    ]);

    return $order;
}

beforeEach(function () {
    Queue::fake(); // 拦截 createTask 派发的 TaskJob，只留 Task 行供断言
    setupDnsTools();
});

afterEach(function () {
    Mockery::close();
});

// N 安全网：连续 3 轮 infra-down 后建 sync；未达 3 不建
test('dnsTools 全挂 + 本地不可判定 → 连续 3 轮后建 sync 任务，未达不建', function () {
    fakeDnsToolsDown();
    // 本地不可判定
    $resolver = Mockery::mock(DnsResolver::class);
    $resolver->shouldReceive('txt')->andReturn([]);
    $resolver->shouldReceive('cname')->andReturn([]);
    app()->instance(DnsResolver::class, $resolver);

    $order = makeProcessingTxtOrder();

    $runDue = function () use ($order) {
        DomainValidationRecord::where('order_id', $order->id)->update(['next_check_at' => now()->subMinute()]);
        $this->artisan('schedule:validate')->assertSuccessful();
    };

    // 第 1、2 轮：不建 sync
    $runDue();
    expect(Task::where('order_id', $order->id)->where('action', 'sync')->exists())->toBeFalse();
    $runDue();
    expect(Task::where('order_id', $order->id)->where('action', 'sync')->exists())->toBeFalse();

    // 第 3 轮：达阈值 → 建 sync
    $runDue();
    expect(Task::where('order_id', $order->id)->where('action', 'sync')->exists())->toBeTrue();
});

// 护栏：dnsTools 应答但校验失败（code=0 无 infra-down）→ 不建 sync、不计数
test('dnsTools 应答但校验失败 → 多轮也不建 sync（回归护栏）', function () {
    Http::fake(['dnstool1.test/*' => Http::response(['code' => 0, 'msg' => 'DNS 未就绪', 'errors' => []], 200)]);
    $resolver = Mockery::mock(DnsResolver::class);
    $resolver->shouldReceive('txt')->times(4)->andReturn([]);
    app()->instance(DnsResolver::class, $resolver);

    $order = makeProcessingTxtOrder();

    $runDue = function () use ($order) {
        DomainValidationRecord::where('order_id', $order->id)->update(['next_check_at' => now()->subMinute()]);
        $this->artisan('schedule:validate')->assertSuccessful();
    };

    $runDue();
    $runDue();
    $runDue();
    $runDue();

    expect(Task::where('order_id', $order->id)->where('action', 'sync')->exists())->toBeFalse();
});

test('dnsTools 未配置且本地未命中 → 不按节点故障累计 sync 安全网', function () {
    $siteGroup = SettingGroup::where('name', 'site')->firstOrFail();
    $dnsTools = Setting::where('group_id', $siteGroup->id)
        ->where('key', 'dnsTools')
        ->firstOrFail();
    $dnsTools->value = [];
    $dnsTools->save();
    Setting::clearGroupCache($siteGroup->id);
    Cache::store('runtime')->flush();
    Http::preventStrayRequests();

    $resolver = Mockery::mock(DnsResolver::class);
    $resolver->shouldReceive('txt')->times(4)->andReturn([]);
    app()->instance(DnsResolver::class, $resolver);

    $order = makeProcessingTxtOrder();

    for ($i = 0; $i < 4; $i++) {
        DomainValidationRecord::where('order_id', $order->id)->update(['next_check_at' => now()->subMinute()]);
        $this->artisan('schedule:validate')->assertSuccessful();
    }

    expect(Task::where('order_id', $order->id)->where('action', 'sync')->exists())->toBeFalse()
        ->and(Cache::store('runtime')->has("validate:dnstools_down:{$order->id}"))->toBeFalse();
    Http::assertNothingSent();
});

test('dnsTools 持续全挂也不发送管理员告警', function () {
    $alert = Mockery::mock(SystemAlert::class);
    $alert->shouldNotReceive('send');
    $alert->shouldNotReceive('clearDedupe');
    app()->instance(SystemAlert::class, $alert);

    fakeDnsToolsDown();
    $resolver = Mockery::mock(DnsResolver::class);
    $resolver->shouldReceive('txt')->andReturn([]);
    $resolver->shouldReceive('cname')->andReturn([]);
    app()->instance(DnsResolver::class, $resolver);

    $order = makeProcessingTxtOrder();

    for ($i = 0; $i < 6; $i++) {
        DomainValidationRecord::where('order_id', $order->id)->update(['next_check_at' => now()->subMinute()]);
        $this->artisan('schedule:validate')->assertSuccessful();
    }
});

test('邮箱验证不请求 dnsTools 而是直接创建 sync 任务读取 CA 状态', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => 'processing',
        'dcv' => ['method' => 'admin'],
        'validation' => [
            ['domain' => 'example.com', 'method' => 'admin', 'email' => 'admin@example.com'],
        ],
    ]);
    $order->update(['latest_cert_id' => $cert->id]);
    DomainValidationRecord::create([
        'order_id' => $order->id,
        'last_check_at' => now()->subMinutes(5),
        'next_check_at' => now()->subMinute(),
    ]);

    $this->artisan('schedule:validate')->assertSuccessful();

    Http::assertNothingSent();
    expect(Task::where('order_id', $order->id)->where('action', 'sync')->exists())->toBeTrue();
});
