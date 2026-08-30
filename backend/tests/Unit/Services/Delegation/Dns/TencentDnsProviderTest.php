<?php

declare(strict_types=1);

use App\Services\Delegation\Dns\TencentDnsProvider;
use App\Services\Delegation\Sdk\TencentCloud\TencentCloudTc3Signer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function tencentProvider(
    string $secretId = 'secret-id',
    string $secretKey = 'secret-key',
): TencentDnsProvider {
    return new TencentDnsProvider([
        'provider' => 'tencent',
        'domain' => 'proxy.example.com',
        'secretId' => $secretId,
        'secretKey' => $secretKey,
    ]);
}

function tencentResponse(array $response): array
{
    return ['Response' => array_merge(['RequestId' => 'request-id'], $response)];
}

function assertValidTencentSignedRequest(Request $request, string $action): array
{
    $timestamp = $request->header('X-TC-Timestamp')[0] ?? null;
    $authorization = $request->header('Authorization')[0] ?? null;
    $expectedBody = json_encode(
        $request->data(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    $expectedAuthorization = TencentCloudTc3Signer::authorization(
        secretId: 'secret-id',
        secretKey: 'secret-key',
        host: 'dnspod.tencentcloudapi.com',
        service: 'dnspod',
        timestamp: (int) $timestamp,
        payload: $request->body(),
    );

    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://dnspod.tencentcloudapi.com/')
        ->and($request->body())->toBe($expectedBody)
        ->and($request->hasHeader('Content-Type', TencentCloudTc3Signer::CONTENT_TYPE))->toBeTrue()
        ->and($request->hasHeader('Host', 'dnspod.tencentcloudapi.com'))->toBeTrue()
        ->and($request->hasHeader('X-TC-Action', $action))->toBeTrue()
        ->and($request->hasHeader('X-TC-Version', '2021-03-23'))->toBeTrue()
        ->and($request->hasHeader('X-TC-Region'))->toBeFalse()
        ->and($timestamp)->toMatch('/^[1-9][0-9]*$/D')
        ->and($authorization)->toBe($expectedAuthorization);

    return $request->data();
}

test('Tencent provider 拒绝缺少必填配置', function (array $config) {
    expect(fn () => new TencentDnsProvider($config))
        ->toThrow(InvalidArgumentException::class, 'Tencent DNS 配置不完整');
})->with([
    '缺少 domain' => [[
        'secretId' => 'secret-id',
        'secretKey' => 'secret-key',
    ]],
    '缺少 secretId' => [[
        'domain' => 'proxy.example.com',
        'secretKey' => 'secret-key',
    ]],
    '缺少 secretKey' => [[
        'domain' => 'proxy.example.com',
        'secretId' => 'secret-id',
    ]],
]);

test('Tencent provider HTTP 请求超时为 15 秒', function () {
    $provider = tencentProvider();
    $clientProperty = new ReflectionProperty(TencentDnsProvider::class, 'client');
    $client = $clientProperty->getValue($provider);
    $optionsProperty = new ReflectionProperty(PendingRequest::class, 'options');
    $options = $optionsProperty->getValue($client);

    expect($options['timeout'] ?? null)->toBe(15);
});

test('Tencent provider 使用 TC3 签名分页读取 TXT', function () {
    Http::fake(function (Request $request) {
        $payload = $request->data();

        return ($payload['Offset'] ?? null) === 3000
            ? Http::response(tencentResponse([
                'RecordCountInfo' => ['TotalCount' => 3001],
                'RecordList' => [[
                    'RecordId' => 202,
                    'Name' => '@',
                    'Value' => 'apex-value',
                    'Type' => 'TXT',
                ]],
            ]))
            : Http::response(tencentResponse([
                'RecordCountInfo' => ['TotalCount' => 3001],
                'RecordList' => [[
                    'RecordId' => 101,
                    'Name' => 'label',
                    'Value' => 'txt-value',
                    'Type' => 'TXT',
                ]],
            ]));
    });

    expect(tencentProvider()->allTxt())->toBe([
        ['id' => '101', 'name' => 'label', 'value' => 'txt-value'],
        ['id' => '202', 'name' => '@', 'value' => 'apex-value'],
    ]);

    Http::assertSent(function (Request $request): bool {
        $payload = assertValidTencentSignedRequest($request, 'DescribeRecordList');
        expect($payload)->toMatchArray([
            'Domain' => 'proxy.example.com',
            'RecordType' => 'TXT',
            'ErrorOnEmpty' => 'no',
            'Limit' => 3000,
        ]);

        return true;
    });
    Http::assertSentCount(2);
});

test('Tencent provider 将无记录响应解释为空列表', function () {
    Http::fake(['*' => Http::response(tencentResponse([
        'RecordCountInfo' => ['TotalCount' => 0],
        'RecordList' => [],
    ]))]);

    expect(tencentProvider()->allTxt())->toBe([]);
});

test('Tencent provider upsert 对唯一值逐条新增并幂等接受已存在错误', function () {
    $call = 0;
    Http::fake(function () use (&$call) {
        return $call++ === 0
            ? Http::response(tencentResponse([
                'Error' => [
                    'Code' => 'InvalidParameter.DomainRecordExist',
                    'Message' => '记录已经存在，无需再次添加。',
                ],
            ]))
            : Http::response(tencentResponse(['RecordId' => 202]));
    });

    expect(tencentProvider()->upsertTxt('label', ['existing', 'existing', 'new'], 900))->toBeTrue();

    $requests = Http::recorded();
    expect($requests)->toHaveCount(2);
    foreach ($requests as $index => [$request]) {
        $payload = assertValidTencentSignedRequest($request, 'CreateTXTRecord');
        expect($payload)->toMatchArray([
            'Domain' => 'proxy.example.com',
            'SubDomain' => 'label',
            'RecordLine' => '默认',
            'Value' => $index === 0 ? 'existing' : 'new',
            'TTL' => 900,
        ]);
    }
    Http::assertSentCount(2);
});

test('Tencent provider 对空值不请求远端并返回 false', function () {
    Http::fake();

    expect(tencentProvider()->upsertTxt('label', []))->toBeFalse();

    Http::assertNothingSent();
});

test('Tencent provider 使用同步 DeleteRecord 去重后逐条确认响应', function () {
    Http::fake(['*' => Http::response(tencentResponse([]))]);

    tencentProvider()->deleteRecords([101, 101, 202]);

    $requests = Http::recorded();
    expect($requests)->toHaveCount(2);
    foreach ($requests as $index => [$request]) {
        $payload = assertValidTencentSignedRequest($request, 'DeleteRecord');
        expect($payload['Domain'] ?? null)->toBe('proxy.example.com')
            ->and($payload['RecordId'] ?? null)->toBe($index === 0 ? 101 : 202);
    }
    Http::assertSentCount(2);
});

test('Tencent provider 请求错误不泄露凭据或远端详情', function () {
    $secretId = 'never-expose-secret-id';
    $secretKey = 'never-expose-secret-key';
    Http::fake(['*' => Http::response(tencentResponse([
        'Error' => [
            'Code' => 'AuthFailure.SecretIdNotFound',
            'Message' => "auth failed {$secretId} {$secretKey}",
        ],
    ]))]);

    try {
        tencentProvider($secretId, $secretKey)->allTxt();
        test()->fail('Tencent API 错误应失败关闭');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Tencent DNS 请求失败')
            ->and($e->getMessage())->not->toContain($secretId, $secretKey);
    }
});

test('Tencent provider 对畸形响应失败关闭', function (array $payload) {
    Http::fake(['*' => Http::response($payload)]);

    expect(fn () => tencentProvider()->allTxt())
        ->toThrow(RuntimeException::class, 'Tencent DNS 响应格式无效');
})->with([
    '缺少 Response' => [[]],
    '缺少 RequestId' => [['Response' => [
        'RecordCountInfo' => ['TotalCount' => 0],
        'RecordList' => [],
    ]]],
    'RecordId 类型错误' => [tencentResponse([
        'RecordCountInfo' => ['TotalCount' => 1],
        'RecordList' => [[
            'RecordId' => ['bad'],
            'Name' => 'label',
            'Value' => 'value',
            'Type' => 'TXT',
        ]],
    ])],
]);
