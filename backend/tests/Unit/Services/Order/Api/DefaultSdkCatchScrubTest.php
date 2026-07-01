<?php

use App\Bootstrap\ApiExceptions;
use App\Services\LogBuffer;
use App\Services\Order\Api\default\Sdk;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

// 锁定 Order default Sdk 的 catch 脱敏契约（修复项 M2）。
//
// 背景：2026-06-30 生产事故 —— manager 调上游超时（cURL error 28），Guzzle 原文（含内部地址
// https://upstream.test/...）被 catch 分支原样 `'Request failed: '.$e->getMessage()` 返回，
// 经 Api→ApiResponseException（status=200）→ ApiExceptions 第一分支绕过脱敏 match → 以 HTTP200 泄露
// 给下游客户端，暴露内部架构。修复：catch 按异常子类返通用文案，原文只进 error_logs 供排障。
//
// Order Sdk 用 Guzzle（非 Laravel Http facade），Http::fake 拦不到；故经 makeClient() 注入缝注入
// 抛异常的 MockHandler，断言 call() 返回的 msg 已脱敏、不含 URL / http / 上游内部地址；并用 ApiExceptions
// spy 断言原始异常（含内部 URL）仍被 logException 记录。配置走 setting 缓存注入（array driver），
// 不碰 DB，无需 RefreshDatabase。

/**
 * 让 makeClient 注入一个「一请求即抛指定异常」的 Guzzle 客户端。
 */
class ThrowingOrderSdk extends Sdk
{
    public function __construct(private Throwable $toThrow) {}

    protected function makeClient(array $config = []): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler([$this->toThrow]))]);
    }
}

// get_system_setting('ca',$k) → Setting::getValue → Cache::remember('setting:group_name:ca', ...)
// 直接 put 该缓存键即可命中、不查 DB。url 用内部风格域名以复现事故泄露源。
beforeEach(function () {
    Cache::put('setting:group_name:ca', ['url' => 'https://upstream.test/api/v2', 'token' => 'tok'], 60);
    // catch 路径现在会 LogBuffer::add 一条 ca_logs（含耗时）；本文件不碰 DB，清空缓冲避免泄漏到后续 flush
    LogBuffer::clear();
});

afterEach(fn () => LogBuffer::clear());

// 假的内部 URL —— 出现在异常 message 里，脱敏后绝不能出现在对外 msg 中
const LEAK_URL = 'https://internal.example.test/api/v2/new';
const LEAK_HOST = 'internal.example.test';

/**
 * 安装一个 ApiExceptions spy，捕获 logException 收到的异常。返回 &$captured 引用容器。
 */
function spyApiExceptions(): array
{
    $captured = ['count' => 0, 'exception' => null];

    $spy = new class($captured) extends ApiExceptions
    {
        public function __construct(private array &$captured) {}

        public function logException(Throwable $e): void
        {
            $this->captured['count']++;
            $this->captured['exception'] = $e;
        }
    };

    app()->instance(ApiExceptions::class, $spy);

    return ['captured' => &$captured];
}

test('ConnectException（含 cURL 28 内部 URL）→ 通用超时文案，不泄露 URL', function () {
    $ref = spyApiExceptions();

    // ConnectException 是超时/连接失败（cURL 28）的类型；message 含内部 URL 模拟事故原文
    $e = new ConnectException(
        'cURL error 28: Operation timed out after 28000 milliseconds (see '.LEAK_URL.')',
        new Request('POST', LEAK_URL)
    );

    $result = (new ThrowingOrderSdk($e))->new(['refer_id' => 'a']);

    // 对外文案：通用、脱敏
    expect($result['code'])->toBe(0)
        ->and($result['msg'])->toBe('上游连接超时，请稍后重试')
        ->and($result['msg'])->not->toContain(LEAK_HOST)
        ->and($result['msg'])->not->toContain('http');

    // 原文（含内部 URL）仍进 error_logs 供排障
    expect($ref['captured']['count'])->toBe(1)
        ->and($ref['captured']['exception'])->toBe($e)
        ->and($ref['captured']['exception']->getMessage())->toContain(LEAK_HOST);
});

test('普通 GuzzleException（RequestException）→ 通用请求失败文案，不泄露 URL', function () {
    $ref = spyApiExceptions();

    // RequestException 是 GuzzleException 但非 ConnectException，走「其余」分支
    $e = new RequestException(
        'Error connecting to '.LEAK_URL.' : something went wrong',
        new Request('POST', LEAK_URL)
    );

    $result = (new ThrowingOrderSdk($e))->new(['refer_id' => 'a']);

    // 对外文案：与超时分支不同、同样脱敏
    expect($result['code'])->toBe(0)
        ->and($result['msg'])->toBe('上游请求失败，请稍后重试')
        ->and($result['msg'])->not->toContain(LEAK_HOST)
        ->and($result['msg'])->not->toContain('http');

    // 原文仍被记录
    expect($ref['captured']['count'])->toBe(1)
        ->and($ref['captured']['exception'])->toBe($e)
        ->and($ref['captured']['exception']->getMessage())->toContain(LEAK_HOST);
});

// 边界：ConnectException 继承 RequestException（后者继承 GuzzleException），
// catch 顺序必须先子类后父类 —— 若写反，超时会掉进「请求失败」分支。此用例锁死区分。
test('ConnectException 与普通 GuzzleException 文案互不串（catch 顺序正确）', function () {
    $connect = new ConnectException('cURL error 28', new Request('POST', LEAK_URL));
    $request = new RequestException('boom', new Request('POST', LEAK_URL));

    $connectMsg = (new ThrowingOrderSdk($connect))->new(['refer_id' => 'a'])['msg'];
    $requestMsg = (new ThrowingOrderSdk($request))->new(['refer_id' => 'a'])['msg'];

    expect($connectMsg)->toBe('上游连接超时，请稍后重试')
        ->and($requestMsg)->toBe('上游请求失败，请稍后重试')
        ->and($connectMsg)->not->toBe($requestMsg);
});
