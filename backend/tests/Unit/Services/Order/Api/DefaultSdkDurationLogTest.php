<?php

use App\Models\CaLog;
use App\Models\ErrorLog;
use App\Services\LogBuffer;
use App\Services\Order\Api\default\Sdk;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// 锁定 Order default Sdk 的 ca_logs 请求耗时记录（修复「ca_logs.duration 恒为 0」）。
//
// 背景：ca_logs 表有 duration 列（decimal 默认 0，注释「耗时(秒)」），CaLog 模型 fillable/casts 也含 duration，
// 但 Order Sdk::call() 从不测量 startTime、也不把 duration 写进 LogBuffer::add —— 于是 Order 侧（占绝大多数）
// 的 ca_logs 行 duration 恒为默认 0；ACME Sdk 早已正确记录，二者不对称。此外超时/连接失败在 catch 里提前
// return，完全不写 ca_logs —— 上游变慢/挂起在 ca_logs 里彻底不可见，恰是「耗时」最该被看见的场景。
// 修复：call() 全路径（成功/超时/失败）经 logCall() 统一写 ca_logs 并带 duration（对齐 ACME Sdk）。

beforeEach(function () {
    Cache::put('setting:group_name:ca', ['url' => 'https://internal.example.test/api/v2', 'token' => 'tok'], 60);
    LogBuffer::clear();
});

afterEach(fn () => LogBuffer::clear());

/** 完成的请求：handler sleep 20ms，保证 duration 明显 > 0（证明真测量而非默认 0） */
class DurationCompletedSdk extends Sdk
{
    protected function makeClient(array $config = []): Client
    {
        return new Client([
            'handler' => function ($request, $options) {
                usleep(20000);

                return new FulfilledPromise(new Response(200, [], json_encode(['code' => 1, 'data' => ['order_id' => 'x']])));
            },
        ]);
    }
}

/** 一请求即抛指定异常（模拟超时 / 连接失败） */
class DurationThrowingSdk extends Sdk
{
    public function __construct(private Throwable $toThrow) {}

    protected function makeClient(array $config = []): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler([$this->toThrow]))]);
    }
}

test('完成的上游请求：ca_logs 记录非零 duration（不再恒为 0）', function () {
    (new DurationCompletedSdk)->get('some-api-id');
    LogBuffer::flush();

    $log = CaLog::query()->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->api)->toBe('get')
        ->and($log->status)->toBe(1)
        ->and((float) $log->duration)->toBeGreaterThan(0);
});

test('上游超时/连接失败：只写 ca_logs，不重复写 error_logs', function () {
    $before = CaLog::query()->count();
    $beforeErrors = ErrorLog::query()->count();

    $e = new ConnectException(
        'cURL error 28: Operation timed out (see https://internal.example.test/api/v2/new)',
        new Request('POST', 'https://internal.example.test/api/v2/new')
    );

    $result = (new DurationThrowingSdk($e))->new(['refer_id' => 'a']);
    LogBuffer::flush();

    expect($result['code'])->toBe(0);
    // 此前超时完全不写 ca_logs；修复后必 +1，且带 duration
    expect(CaLog::query()->count())->toBe($before + 1);
    expect(ErrorLog::query()->count())->toBe($beforeErrors);

    $log = CaLog::query()->latest('id')->first();
    expect($log->api)->toBe('new')
        ->and($log->status)->toBe(0)
        ->and($log->duration)->not->toBeNull()
        // ca_logs 的 response 是脱敏后的通用文案，绝不能带异常原文里的内部地址
        ->and(json_encode($log->response, JSON_UNESCAPED_UNICODE))->not->toContain('internal.example.test');
});
