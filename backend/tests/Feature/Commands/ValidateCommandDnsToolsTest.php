<?php

use App\Models\Admin;
use App\Models\Cert;
use App\Models\DomainValidationRecord;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\Task;
use App\Models\User;
use App\Services\Delegation\DnsResolver;
use App\Services\Notification\NotificationCenter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * F2-1 ValidateCommand dnsTools 全挂安全网（连续 N 建 sync）+ 连挂 admin 告警（连续 M）。
 */

/** 配置 site.dnsTools + adminEmail */
function setupDnsToolsAndAdmin(): void
{
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => '站点', 'weight' => 1]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'dnsTools'],
        ['type' => 'array', 'value' => ['http://dnstool1.test'], 'weight' => 0]
    );
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'adminEmail'],
        ['type' => 'string', 'value' => 'ops@corp.example', 'weight' => 0]
    );
    Setting::clearGroupCache($group->id);
    Admin::factory()->create(['email' => 'ops@corp.example']);
    Cache::flush();
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
    setupDnsToolsAndAdmin();
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

// M 告警：连续 5 轮 infra-down → 派 system_alert 一次；恢复应答 → 清零
test('dnsTools 连续 5 轮全挂 → 派 system_alert 一次，恢复应答后清零', function () {
    $state = new class
    {
        public int $systemAlertCount = 0;
    };
    $center = Mockery::mock(NotificationCenter::class);
    $center->shouldReceive('dispatch')->andReturnUsing(function ($intent) use ($state) {
        if ($intent->code === 'system_alert' && ($intent->context['details']['reason'] ?? null) === 'dnstools_outage') {
            $state->systemAlertCount++;
        }
    });
    app()->instance(NotificationCenter::class, $center);

    fakeDnsToolsDown();
    $resolver = Mockery::mock(DnsResolver::class);
    $resolver->shouldReceive('txt')->andReturn([]);
    $resolver->shouldReceive('cname')->andReturn([]);
    app()->instance(DnsResolver::class, $resolver);

    $order = makeProcessingTxtOrder();

    $runDue = function () use ($order) {
        DomainValidationRecord::where('order_id', $order->id)->update(['next_check_at' => now()->subMinute()]);
        $this->artisan('schedule:validate')->assertSuccessful();
    };

    // 前 4 轮：未达 M=5，不告警
    for ($i = 0; $i < 4; $i++) {
        $runDue();
    }
    expect($state->systemAlertCount)->toBe(0);

    // 第 5 轮：达 M → 告警一次
    $runDue();
    expect($state->systemAlertCount)->toBe(1);

    // 第 6 轮仍 infra-down：dedup（固定指纹）→ 不再发
    $runDue();
    expect($state->systemAlertCount)->toBe(1);

    // 恢复：dnsTools 应答（code=0，有节点应答）→ 清零 + 清去重
    Http::fake(['dnstool1.test/*' => Http::response(['code' => 0, 'msg' => 'DNS 未就绪', 'errors' => []], 200)]);
    $runDue();

    // 再次全挂：清零后需重新累计，单轮不触发
    fakeDnsToolsDown();
    $runDue();
    expect($state->systemAlertCount)->toBe(1);
});
