<?php

use App\Exceptions\ApiResponseException;
use App\Models\Order;
use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use App\Services\Order\Action;
use Illuminate\Support\Facades\DB;

// Feature/Services 目录全局 uses(TestCase) + RefreshDatabase（见 tests/Pest.php），无需在此重复声明。
// batchNew 的 SM2 能力 gate 应与 new/renew/reissue 对称：在 DB::beginTransaction 之前拦截。
// 在探测回调里捕获 DB::transactionLevel() 区分"事务前 vs 事务内"——gate 在事务内时层级比基线 +1，
// 前移前此断言会红。（不用 Event::fake：DB 事务事件走 connection 自己的 dispatcher，fake 收不到，是伪绿。）
test('batchNew SM2 探测失败 → 事务前拒绝（gate 时未开启额外事务、零建单）', function () {
    $baseline = DB::transactionLevel(); // RefreshDatabase 包裹测试的基线层级
    $levelAtGate = null;

    $mock = Mockery::mock(BinaryLocator::class);
    $mock->shouldReceive('gmOpenssl')->once()
        ->andReturnUsing(function () use (&$levelAtGate) {
            $levelAtGate = DB::transactionLevel();
            throw new BinaryNotFoundException(tool: 'gmopenssl', triedPaths: ['/nonexistent']);
        });
    $this->app->instance(BinaryLocator::class, $mock);

    $action = app(Action::class);
    try {
        $action->batchNew(['domains' => 'a.example.com,b.example.com', 'encryption' => ['alg' => 'sm2']]);
        test()->fail('应抛 ApiResponseException（国密环境不可用）');
    } catch (ApiResponseException $e) {
        expect($e->getApiResponse()['msg'])->toContain('国密');
    }

    // 事务前 gate：探测发生时事务层级 == 基线（未额外 beginTransaction）。前移前 gate 在事务内 → +1 → 红
    expect($levelAtGate)->toBe($baseline);
    expect(Order::count())->toBe(0); // 零建单
});
