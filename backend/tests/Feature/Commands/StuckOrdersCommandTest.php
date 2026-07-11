<?php

use App\Models\Admin;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\User;
use App\Services\Notification\NotificationCenter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

afterEach(function () {
    Mockery::close();
});

function stuckSetupAdmin(): void
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

function stuckCaptureCenter(): object
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

/** 造一个卡单：product.validation_type=$vt，cert 状态=$status，cert.created_at=$daysAgo 天前 */
function makeStuckOrder(?string $vt, int $daysAgo, string $status = 'processing'): Order
{
    $user = User::factory()->create();
    $product = Product::factory()->create(['validation_type' => $vt]);
    $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => $status,
        'common_name' => 'stuck-'.$order->id.'.example.com',
        'created_at' => now()->subDays($daysAgo),
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    return $order;
}

/**
 * 造一个「other 档」卡单：validation_type 越界值（非 dv/ov/ev）。
 *
 * products.validation_type 是 enum('dv','ov','ev') NOT NULL：STRICT 模式下 null/越界均被拒，
 * 但非 strict 生产（MySQL 5.7 常态）中损坏/越界值会落 ''。此 helper 临时放宽 sql_mode 落一个
 * '' 值，复现非 strict 生产的「未知 validation_type」——正是命令第 4 条 grouped whereNotIn
 * 分支要兜住的场景（whereNull 对 NOT NULL 列属 belt-and-suspenders 防御，无法在 schema 下构造）。
 */
function makeStuckOrderOtherTier(int $daysAgo): Order
{
    $original = DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m;
    DB::statement("SET SESSION sql_mode=''");
    try {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        DB::table('products')->where('id', $product->id)->update(['validation_type' => '']);
        $order = Order::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);
        $cert = Cert::factory()->create([
            'order_id' => $order->id,
            'status' => 'processing',
            'common_name' => 'other-'.$order->id.'.example.com',
            'created_at' => now()->subDays($daysAgo),
        ]);
        $order->update(['latest_cert_id' => $cert->id]);

        return $order;
    } finally {
        DB::statement('SET SESSION sql_mode='.DB::getPdo()->quote($original));
    }
}

beforeEach(function () {
    stuckSetupAdmin();
    config()->set('monitoring.stuck_orders.enabled', true);
    config()->set('monitoring.stuck_orders.stuck_days', ['dv' => 7, 'ov' => 14, 'ev' => 21]);
    config()->set('monitoring.stuck_orders.dedupe_ttl_hours', 168);
});

test('① dv 卡 8 天 → 告警', function () {
    makeStuckOrder('dv', 8);
    $state = stuckCaptureCenter();

    $this->artisan('schedule:stuck-orders')->assertSuccessful();

    expect($state->count)->toBe(1)
        ->and($state->captured->context['category'])->toBe('stuck_orders');
});

test('② ov 卡 8 天 → 不告警（分档：ov 阈值 14 天）', function () {
    makeStuckOrder('ov', 8);
    $state = stuckCaptureCenter();

    $this->artisan('schedule:stuck-orders')->assertSuccessful();

    expect($state->count)->toBe(0);
});

test('③ ov 卡 15 天 → 告警', function () {
    makeStuckOrder('ov', 15);
    $state = stuckCaptureCenter();

    $this->artisan('schedule:stuck-orders')->assertSuccessful();

    expect($state->count)->toBe(1);
});

test('④ 全新鲜/终态 → 无告警 + 清键', function () {
    Cache::put('system_alert:stuck_orders', 'stale', now()->addHours(168));
    makeStuckOrder('dv', 2); // 新鲜（<7 天）
    makeStuckOrder('ev', 30, 'active'); // 终态
    $state = stuckCaptureCenter();

    $this->artisan('schedule:stuck-orders')->assertSuccessful();

    expect($state->count)->toBe(0)
        ->and(Cache::has('system_alert:stuck_orders'))->toBeFalse();
});

test('⑤ 连续两次同批卡单 → 第二次不重发（固定指纹）', function () {
    makeStuckOrder('dv', 8);
    $state = stuckCaptureCenter();

    $this->artisan('schedule:stuck-orders')->assertSuccessful();
    $this->artisan('schedule:stuck-orders')->assertSuccessful();

    expect($state->count)->toBe(1);
});

test('⑥ 未知 validation_type（越界值）卡 22 天 → 按 21 天档命中（第 4 条 grouped 分支）', function () {
    makeStuckOrderOtherTier(22);
    makeStuckOrderOtherTier(22);
    $state = stuckCaptureCenter();

    $this->artisan('schedule:stuck-orders')->assertSuccessful();

    // 聚合一封，details 计入 other 档 2 单
    expect($state->count)->toBe(1)
        ->and($state->captured->context['details']['other'])->toBe(2);
});

test('⑥b 未知 validation_type 卡 15 天（<21）→ 不告警', function () {
    makeStuckOrderOtherTier(15);
    $state = stuckCaptureCenter();

    $this->artisan('schedule:stuck-orders')->assertSuccessful();

    expect($state->count)->toBe(0);
});

test('details 全为标量（拍平，无嵌套）', function () {
    makeStuckOrder('dv', 8);
    $state = stuckCaptureCenter();

    $this->artisan('schedule:stuck-orders')->assertSuccessful();

    foreach ($state->captured->context['details'] as $value) {
        expect(is_scalar($value))->toBeTrue();
    }
});
