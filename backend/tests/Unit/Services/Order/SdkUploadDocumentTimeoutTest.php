<?php

declare(strict_types=1);

use App\Services\Order\Api\default\Sdk;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

// get_system_setting('ca',$k) → Setting::getValue → getByGroupName('ca')
//   → Cache::remember('setting:group_name:ca', ...)（Setting::CACHE_PREFIX = 'setting:'）
// 直接 put 该缓存键即可命中、不查 DB（与 DefaultSdkTimeoutTest.php 同一手法）。
beforeEach(function () {
    Cache::put('setting:group_name:ca', ['url' => 'http://upstream.test/api/v2', 'token' => 'test-token'], 60);
});

it('uploadDocument 用 55s 超时（对齐 worker --timeout 60，让 Guzzle 先干净断）', function () {
    $captured = [];

    // 匿名子类 override makeClient 注入缝：捕获 config、返回一个立即失败的 client（不真打网络）
    $sdk = new class($captured) extends Sdk
    {
        public function __construct(public array &$captured) {}

        protected function makeClient(array $config = []): Client
        {
            $this->captured = $config;

            // ConnectException 会被 call() catch 成 code=0，不抛出、不影响本测断言
            $handler = HandlerStack::create(new MockHandler([
                new ConnectException('mock', new Request('POST', 'x')),
            ]));

            return new Client(['handler' => $handler] + $config);
        }
    };

    $sdk->uploadDocument(123, ['type' => 'pdf', 'file' => 'AAAA']);

    expect($captured['timeout'] ?? null)->toBe(55)
        ->and($captured['connect_timeout'] ?? null)->toBe(10); // min(10,55)=10
});
