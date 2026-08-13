<?php

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Plugins\CloudDeploy\Deployers\Yandexcloud\YandexcloudApiException;
use Plugins\CloudDeploy\Deployers\Yandexcloud\YandexcloudClient;
use Tests\TestCase;

uses(TestCase::class);

test('证书列表请求透传 folder、分页 token 和 BASIC view', function () {
    $http = Mockery::mock(ClientInterface::class);
    $operations = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->once()->andReturnUsing(function (string $method, string $uri, array $options) {
        expect($method)->toBe('GET');
        expect($uri)->toBe('certificate-manager/v1/certificates');
        expect($options['query'])->toBe([
            'folderId' => 'folder-id',
            'pageSize' => 100,
            'view' => 'BASIC',
            'pageToken' => 'next-token',
        ]);

        return new Response(200, [], '{"certificates":[],"nextPageToken":""}');
    });

    $client = new YandexcloudClient($http, $operations);
    expect($client->listCertificates('folder-id', 'next-token'))->toBe([
        'certificates' => [],
        'nextPageToken' => '',
    ]);
});

test('任意 2xx 损坏或非对象 JSON 均 fail-closed 为 InvalidResponse', function (string $method, string $body, Closure $call) {
    $http = Mockery::mock(ClientInterface::class);
    $operations = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->once()->withArgs(fn (string $actual) => $actual === $method)
        ->andReturn(new Response(200, [], $body));
    $operations->shouldNotReceive('request');
    $client = new YandexcloudClient($http, $operations);

    expect(fn () => $call($client))->toThrow(YandexcloudApiException::class, 'InvalidResponse');
})->with([
    'list 损坏 JSON 不得误判空列表后创建' => ['GET', '{broken', fn (YandexcloudClient $client) => $client->listCertificates('folder-id')],
    'get JSON 数组不得变为空元数据后 update' => ['GET', '[]', fn (YandexcloudClient $client) => $client->getCertificate('cert-id')],
    'create 标量 JSON 不得变为空 operation' => ['POST', 'true', fn (YandexcloudClient $client) => $client->createCertificate([])],
]);

test('创建证书轮询 LRO 到 done 后才返回证书响应', function () {
    $sleeps = [];
    $http = Mockery::mock(ClientInterface::class);
    $operations = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->once()->andReturnUsing(function (string $method, string $uri, array $options) {
        expect($method)->toBe('POST');
        expect($uri)->toBe('certificate-manager/v1/certificates');
        expect($options['json'])->toMatchArray([
            'folderId' => 'folder-id',
            'certificate' => 'LEAF',
            'chain' => 'CHAIN',
            'privateKey' => 'KEY',
        ]);

        return new Response(200, [], '{"id":"operation-id","done":false}');
    });
    $operations->shouldReceive('request')->once()->andReturnUsing(function (string $method, string $uri) {
        expect($method)->toBe('GET');
        expect($uri)->toBe('operations/operation-id');

        return new Response(200, [], '{"id":"operation-id","done":true,"response":{"id":"cert-id","name":"cert-name"}}');
    });

    $client = new YandexcloudClient($http, $operations, function (int $milliseconds) use (&$sleeps): void {
        $sleeps[] = $milliseconds;
    });
    $result = $client->createCertificate([
        'folderId' => 'folder-id',
        'certificate' => 'LEAF',
        'chain' => 'CHAIN',
        'privateKey' => 'KEY',
    ]);

    expect($result)->toMatchArray(['id' => 'cert-id', 'name' => 'cert-name']);
    expect($sleeps)->toBe([1000]);
});

test('LRO 轮询收到 2xx 损坏 JSON 时 fail-closed 为 InvalidResponse', function () {
    $http = Mockery::mock(ClientInterface::class);
    $operations = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->once()->andReturn(new Response(200, [], '{"id":"operation-id","done":false}'));
    $operations->shouldReceive('request')->once()->andReturn(new Response(200, [], '{broken'));

    $client = new YandexcloudClient($http, $operations, fn () => null);
    expect(fn () => $client->createCertificate([]))
        ->toThrow(YandexcloudApiException::class, 'InvalidResponse');
});

test('LRO 完成态必须在结构化 error 与对象型 response 中二选一', function (string $method, string $body, Closure $call) {
    $http = Mockery::mock(ClientInterface::class);
    $operations = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->once()->withArgs(fn (string $actual) => $actual === $method)
        ->andReturn(new Response(200, [], $body));
    $operations->shouldNotReceive('request');

    $client = new YandexcloudClient($http, $operations);
    expect(fn () => $call($client))
        ->toThrow(YandexcloudApiException::class, 'InvalidResponse');
})->with([
    'create 完成态缺少 response 不得成功' => [
        'POST',
        '{"id":"operation-id","done":true}',
        fn (YandexcloudClient $client) => $client->createCertificate([]),
    ],
    'update 完成态数组 response 不得成功' => [
        'PATCH',
        '{"id":"operation-id","done":true,"response":[]}',
        fn (YandexcloudClient $client) => $client->updateCertificate('cert-id', []),
    ],
    'create 完成态 error 与 response 同时存在不得成功' => [
        'POST',
        '{"id":"operation-id","done":true,"error":{"code":7},"response":{}}',
        fn (YandexcloudClient $client) => $client->createCertificate([]),
    ],
    'update 完成态非对象 error 不得成功' => [
        'PATCH',
        '{"id":"operation-id","done":true,"error":"opaque"}',
        fn (YandexcloudClient $client) => $client->updateCertificate('cert-id', []),
    ],
]);

test('更新证书使用 PATCH、精确 updateMask，并轮询到 done', function () {
    $http = Mockery::mock(ClientInterface::class);
    $operations = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->once()->andReturnUsing(function (string $method, string $uri, array $options) {
        expect($method)->toBe('PATCH');
        expect($uri)->toBe('certificate-manager/v1/certificates/cert-id');
        expect(explode(',', $options['json']['updateMask']))
            ->toBe(['name', 'description', 'labels', 'certificate', 'chain', 'privateKey', 'deletionProtection']);
        expect($options['json'])->toMatchArray([
            'name' => 'preserved-name',
            'description' => 'preserved-description',
            'labels' => ['owner' => 'ops'],
            'certificate' => 'LEAF',
            'chain' => 'CHAIN',
            'privateKey' => 'KEY',
            'deletionProtection' => true,
        ]);

        return new Response(200, [], '{"id":"operation-id","done":true,"response":{}}');
    });

    $client = new YandexcloudClient($http, $operations);
    expect($client->updateCertificate('cert-id', [
        'updateMask' => 'name,description,labels,certificate,chain,privateKey,deletionProtection',
        'name' => 'preserved-name',
        'description' => 'preserved-description',
        'labels' => ['owner' => 'ops'],
        'certificate' => 'LEAF',
        'chain' => 'CHAIN',
        'privateKey' => 'KEY',
        'deletionProtection' => true,
    ]))->toBe([]);
});

test('LRO error 作为安全结构化异常抛出且不继续轮询', function () {
    $http = Mockery::mock(ClientInterface::class);
    $operations = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->once()->andReturn(new Response(200, [], (string) json_encode([
        'id' => 'operation-id',
        'done' => true,
        'error' => ['code' => 7, 'message' => 'PRIVATE-KEY-MATERIAL'],
    ])));
    $operations->shouldNotReceive('request');

    $client = new YandexcloudClient($http, $operations);
    expect(fn () => $client->createCertificate(['privateKey' => 'PRIVATE-KEY-MATERIAL']))
        ->toThrow(YandexcloudApiException::class, 'PermissionDenied');
});

test('LRO error 缺 code 固定归一为 OperationError', function () {
    $http = Mockery::mock(ClientInterface::class);
    $operations = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->once()->andReturn(new Response(200, [], '{"id":"operation-id","done":true,"error":{"message":"opaque"}}'));
    $operations->shouldNotReceive('request');

    $client = new YandexcloudClient($http, $operations);
    expect(fn () => $client->createCertificate([]))
        ->toThrow(YandexcloudApiException::class, 'OperationError');
});

test('LRO 超过轮询预算明确失败', function () {
    $http = Mockery::mock(ClientInterface::class);
    $operations = Mockery::mock(ClientInterface::class);
    $http->shouldReceive('request')->once()->andReturn(new Response(200, [], '{"id":"operation-id","done":false}'));
    $operations->shouldReceive('request')->twice()->andReturn(new Response(200, [], '{"id":"operation-id","done":false}'));

    $client = new YandexcloudClient($http, $operations, fn () => null, 2);
    expect(fn () => $client->createCertificate([]))
        ->toThrow(YandexcloudApiException::class, 'OperationTimeout');
});
