<?php

use App\Exceptions\ApiResponseException;
use App\Models\Admin;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Action;
use App\Services\Order\Api\Api;
use Illuminate\Support\Facades\Cache;

afterEach(function () {
    Mockery::close();
});

function importSetupAdmin(): void
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

function importCaptureCenter(): object
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

/**
 * 绑定 Order\Api mock：getProducts 由 $handler(source) 决定返回/抛出。
 * Action 构造时 app(Api::class) 取得此 mock（default\Api 内部 new Sdk 不走容器，故须在 Api 层 mock）。
 */
function bindImportApi(Closure $handler): void
{
    $mock = Mockery::mock(Api::class);
    $mock->shouldReceive('getProducts')->andReturnUsing($handler);
    app()->instance(Api::class, $mock);
}

beforeEach(function () {
    importSetupAdmin();
    config()->set('monitoring.import_product.enabled', true);
    config()->set('monitoring.import_product.dedupe_ttl_hours', 72);
});

test('② 全部成功运行 → 零告警（I1 回归锁定：resilient 终态不调 success）', function () {
    $product = Product::factory()->create(['source' => 'test', 'api_id' => 'P1', 'weight' => 0]);
    bindImportApi(fn ($source) => ['code' => 1, 'data' => [['code' => 'P1', 'weight' => 9]]]);
    $state = importCaptureCenter();

    $this->artisan('schedule:import-product')->assertSuccessful();

    expect($state->count)->toBe(0)
        ->and($product->fresh()->weight)->toBe(9); // 正常更新
});

test('① 含脏产品（code 空）时正常产品仍被更新 + 发告警（产品级收集）', function () {
    $product = Product::factory()->create(['source' => 'test', 'api_id' => 'P1', 'weight' => 0]);
    bindImportApi(fn ($source) => ['code' => 1, 'data' => [
        ['code' => 'P1', 'weight' => 7],
        ['code' => ''], // 脏产品：code 空 → importProductItem 抛错
    ]]);
    $state = importCaptureCenter();

    $this->artisan('schedule:import-product')->assertSuccessful();

    expect($product->fresh()->weight)->toBe(7) // 脏产品未中断正常产品
        ->and($state->count)->toBe(1) // 产品级失败 → 告警
        ->and($state->captured->context['category'])->toBe('import_product');
});

test('③ 某来源抛异常时其余来源仍处理 + 发告警', function () {
    $good = Product::factory()->create(['source' => 'good', 'api_id' => 'G1', 'weight' => 0]);
    Product::factory()->create(['source' => 'bad', 'api_id' => 'B1', 'weight' => 0]);
    bindImportApi(function ($source) {
        if ($source === 'bad') {
            throw new RuntimeException('source bad crashed');
        }

        return ['code' => 1, 'data' => [['code' => 'G1', 'weight' => 5]]];
    });
    $state = importCaptureCenter();

    $this->artisan('schedule:import-product')->assertSuccessful();

    expect($good->fresh()->weight)->toBe(5) // good 来源仍处理
        ->and($state->count)->toBe(1); // bad 来源崩溃 → 告警
});

test('④ type=update 不 create 新产品', function () {
    Product::factory()->create(['source' => 'test', 'api_id' => 'EXIST']);
    bindImportApi(fn ($source) => ['code' => 1, 'data' => [['code' => 'NEW1', 'weight' => 3]]]);
    importCaptureCenter();

    $this->artisan('schedule:import-product')->assertSuccessful();

    expect(Product::where('source', 'test')->where('api_id', 'NEW1')->exists())->toBeFalse();
});

test('⑤ 人工路径 resilient=false 遇脏产品仍抛（回归保护）', function () {
    Product::factory()->create(['source' => 'test', 'api_id' => 'P1']);
    bindImportApi(fn ($source) => ['code' => 1, 'data' => [['code' => '']]]); // 脏产品

    $action = app(Action::class);

    $caught = null;
    try {
        $action->importProduct('test', '', '', 'update', false);
    } catch (ApiResponseException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->getApiResponse()['code'])->toBe(0)
        ->and($caught->getApiResponse()['msg'])->toBe('产品 code 不能为空')
        ->and($action->getImportIssues())->toBe([]); // 人工路径不收集
});

test('⑥ 本地 name/weight 不被上游覆盖', function () {
    $product = Product::factory()->create([
        'source' => 'test', 'api_id' => 'P1', 'name' => '本地名', 'weight' => 5,
    ]);
    bindImportApi(fn ($source) => ['code' => 1, 'data' => [
        ['code' => 'P1', 'name' => '上游名', 'weight' => 99],
    ]]);
    importCaptureCenter();

    $this->artisan('schedule:import-product')->assertSuccessful();

    $fresh = $product->fresh();
    expect($fresh->name)->toBe('本地名')
        ->and($fresh->weight)->toBe(5);
});

test('⑦ 同样失败次日不重发（指纹）、零失败日清键', function () {
    Product::factory()->create(['source' => 'test', 'api_id' => 'P1']);
    bindImportApi(fn ($source) => ['code' => 1, 'data' => [['code' => '']]]); // 持续脏产品
    $state = importCaptureCenter();

    $this->artisan('schedule:import-product')->assertSuccessful();
    $this->artisan('schedule:import-product')->assertSuccessful();
    expect($state->count)->toBe(1); // 同样失败去重

    // 恢复（无脏产品）→ 清键
    bindImportApi(fn ($source) => ['code' => 1, 'data' => [['code' => 'P1', 'weight' => 1]]]);
    $this->artisan('schedule:import-product')->assertSuccessful();
    expect(Cache::has('system_alert:import_product'))->toBeFalse();
});

test('⑧ enabled=false → 不同步不告警', function () {
    config()->set('monitoring.import_product.enabled', false);
    $product = Product::factory()->create(['source' => 'test', 'api_id' => 'P1', 'weight' => 0]);
    // 绑一个「被调用即失败」的 Api：enabled=false 时根本不该调 getProducts
    $mock = Mockery::mock(Api::class);
    $mock->shouldReceive('getProducts')->never();
    app()->instance(Api::class, $mock);
    $state = importCaptureCenter();

    $this->artisan('schedule:import-product')->assertSuccessful();

    expect($state->count)->toBe(0)
        ->and($product->fresh()->weight)->toBe(0);
});
