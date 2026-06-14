<?php

use App\Services\Order\Api\default\Api;

// 锁住 source 层 uploadDocument 契约：api_id 作为独立首参传入，Sdk 仍把它放进线协议 body 的 order_id。
// 旧版 default/Sdk::uploadDocument(array $data) 是单参、api_id 埋在 $data['order_id']，
// 不在 OrderSourceApiInterface 内（靠 @phpstan-ignore 压）；本测试钉死对齐后的双参契约。

afterEach(function () {
    Mockery::close();
});

test('default Sdk::uploadDocument 把 api_id 作为 order_id 放入 JSON body，其余字段透传', function () {
    // Sdk 的 call() 为 protected，partial mock 拦截以断言线协议入参（不发真实 HTTP）
    $sdk = Mockery::mock('App\Services\Order\Api\default\Sdk')
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $sdk->shouldReceive('call')
        ->once()
        ->withArgs(function (string $uri, array $data, $method) {
            return $uri === 'upload-document'
                && $method === 'json'
                && $data === [
                    'order_id' => 'UP123',          // ← api_id 进 body 的 order_id（线协议字段名不变）
                    'type' => 'APPLICANT',
                    'fileName' => 'a.pdf',
                    'document_content' => 'QkFTRTY0',
                ];
        })
        ->andReturn(['code' => 1]);

    $result = $sdk->uploadDocument('UP123', [
        'type' => 'APPLICANT',
        'fileName' => 'a.pdf',
        'document_content' => 'QkFTRTY0',
    ]);

    expect($result)->toBe(['code' => 1]);
});

test('default Api::uploadDocument 把 api_id 透传给 Sdk（双参签名对齐接口约定）', function () {
    $sdk = Mockery::mock('App\Services\Order\Api\default\Sdk');
    $sdk->shouldReceive('uploadDocument')
        ->once()
        ->with('UP123', ['type' => 'APPLICANT'])
        ->andReturn(['code' => 1]);

    $api = new Api;
    // default/Api 构造内 new Sdk，注入 mock
    (function () use ($sdk) {
        $this->sdk = $sdk;
    })->call($api);

    expect($api->uploadDocument('UP123', ['type' => 'APPLICANT']))->toBe(['code' => 1]);
});
