<?php

declare(strict_types=1);

use App\Services\Delegation\Dns\AliyunDnsProvider;
use App\Services\Delegation\Sdk\Aliyun\AliyunRpcSigner;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function aliyunProvider(
    string $accessKeyId = 'access-key-id',
    string $accessKeySecret = 'access-key-secret',
): AliyunDnsProvider {
    return new AliyunDnsProvider([
        'provider' => 'aliyun',
        'domain' => 'proxy.example.com',
        'accessKeyId' => $accessKeyId,
        'accessKeySecret' => $accessKeySecret,
    ]);
}

function aliyunListResponse(array $records, int $page = 1, int $pageSize = 500, int $total = 0): array
{
    return [
        'TotalCount' => $total,
        'PageNumber' => $page,
        'PageSize' => $pageSize,
        'RequestId' => 'request-id-'.$page,
        'DomainRecords' => ['Record' => $records],
    ];
}

function assertValidAliyunSignedQuery(Request $request, string $action): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
    $signature = $query['Signature'] ?? null;
    unset($query['Signature']);

    expect($request->method())->toBe('GET')
        ->and(parse_url($request->url(), PHP_URL_SCHEME).'://'.parse_url($request->url(), PHP_URL_HOST).parse_url($request->url(), PHP_URL_PATH))
        ->toBe('https://alidns.aliyuncs.com/')
        ->and($query)->toMatchArray([
            'Format' => 'JSON',
            'Version' => '2015-01-09',
            'AccessKeyId' => 'access-key-id',
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureVersion' => '1.0',
            'Action' => $action,
        ])
        ->and($query['Timestamp'] ?? null)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D')
        ->and($query['SignatureNonce'] ?? null)->toMatch('/^[0-9a-f-]{36}$/D')
        ->and($signature)->toBe(AliyunRpcSigner::sign($query, 'access-key-secret'));

    return $query;
}

test('Aliyun provider 拒绝缺少必填配置', function (array $config) {
    expect(fn () => new AliyunDnsProvider($config))
        ->toThrow(InvalidArgumentException::class, 'Aliyun DNS 配置不完整');
})->with([
    '缺少 domain' => [[
        'accessKeyId' => 'access-key-id',
        'accessKeySecret' => 'access-key-secret',
    ]],
    '缺少 accessKeyId' => [[
        'domain' => 'proxy.example.com',
        'accessKeySecret' => 'access-key-secret',
    ]],
    '缺少 accessKeySecret' => [[
        'domain' => 'proxy.example.com',
        'accessKeyId' => 'access-key-id',
    ]],
]);

test('Aliyun provider 对空值不请求远端并返回 false', function () {
    Http::fake();

    expect(aliyunProvider()->upsertTxt('label', []))->toBeFalse();

    Http::assertNothingSent();
});

test('Aliyun provider HTTP 请求超时为 15 秒', function () {
    $provider = aliyunProvider();
    $clientProperty = new ReflectionProperty(AliyunDnsProvider::class, 'client');
    $client = $clientProperty->getValue($provider);
    $optionsProperty = new ReflectionProperty(PendingRequest::class, 'options');
    $options = $optionsProperty->getValue($client);

    expect($options['timeout'] ?? null)->toBe(15);
});

test('Aliyun provider 分页读取并只返回格式正确的 TXT 记录', function () {
    Http::fake(function (Request $request) {
        $query = assertValidAliyunSignedQuery($request, 'DescribeDomainRecords');

        expect($query)->toMatchArray([
            'DomainName' => 'proxy.example.com',
            'Type' => 'TXT',
            'PageSize' => '500',
        ]);

        if (($query['PageNumber'] ?? null) === '2') {
            return Http::response(aliyunListResponse([
                [
                    'RecordId' => '202',
                    'RR' => '@',
                    'Type' => 'TXT',
                    'Value' => 'apex-value',
                    'DomainName' => 'proxy.example.com',
                    'TTL' => 600,
                    'Status' => 'Enable',
                    'Line' => 'default',
                    'Locked' => false,
                ],
            ], 2, 500, 501));
        }

        return Http::response(aliyunListResponse([
            [
                'RecordId' => '101',
                'RR' => 'label',
                'Type' => 'TXT',
                'Value' => 'txt-value',
                'DomainName' => 'proxy.example.com',
                'TTL' => 600,
                'Status' => 'Enable',
                'Line' => 'default',
                'Locked' => false,
            ],
            [
                'RecordId' => '102',
                'RR' => 'ignored',
                'Type' => 'A',
                'Value' => '192.0.2.1',
                'DomainName' => 'proxy.example.com',
                'TTL' => 600,
                'Status' => 'Enable',
                'Line' => 'default',
                'Locked' => false,
            ],
        ], 1, 500, 501));
    });

    expect(aliyunProvider()->allTxt())->toBe([
        ['id' => '101', 'name' => 'label', 'value' => 'txt-value'],
        ['id' => '202', 'name' => '@', 'value' => 'apex-value'],
    ]);

    Http::assertSentCount(2);
});

test('Aliyun provider 对畸形列表响应失败关闭', function (array $payload) {
    Http::fake(['*' => Http::response($payload)]);

    expect(fn () => aliyunProvider()->allTxt())
        ->toThrow(RuntimeException::class, 'Aliyun DNS 响应格式无效');
})->with([
    '缺少记录容器' => [[
        'TotalCount' => 0,
        'PageNumber' => 1,
        'PageSize' => 500,
        'RequestId' => 'request-id',
    ]],
    '页码不匹配' => [aliyunListResponse([], 2, 500, 0)],
    '记录字段类型错误' => [aliyunListResponse([[
        'RecordId' => 101,
        'RR' => 'label',
        'Type' => 'TXT',
        'Value' => 'value',
        'Status' => 'Enable',
    ]], 1, 500, 1)],
    '记录状态无效' => [aliyunListResponse([[
        'RecordId' => '101',
        'RR' => 'label',
        'Type' => 'TXT',
        'Value' => 'value',
        'Status' => 'Unknown',
        'Line' => 'default',
    ]], 1, 500, 1)],
    '记录线路无效' => [aliyunListResponse([[
        'RecordId' => '101',
        'RR' => 'label',
        'Type' => 'TXT',
        'Value' => 'value',
        'Status' => 'Enable',
        'Line' => '',
    ]], 1, 500, 1)],
]);

test('Aliyun provider upsert 精确匹配 RR 并仅创建缺失唯一值', function () {
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if (($query['Action'] ?? null) === 'DescribeDomainRecords') {
            assertValidAliyunSignedQuery($request, 'DescribeDomainRecords');
            expect($query)->toMatchArray([
                'DomainName' => 'proxy.example.com',
                'RRKeyWord' => 'label',
                'SearchMode' => 'COMBINATION',
                'Type' => 'TXT',
                'PageNumber' => '1',
                'PageSize' => '500',
            ]);

            return Http::response(aliyunListResponse([
                [
                    'RecordId' => '101',
                    'RR' => 'label',
                    'Type' => 'TXT',
                    'Value' => 'old',
                    'DomainName' => 'proxy.example.com',
                    'TTL' => 600,
                    'Status' => 'Enable',
                    'Line' => 'default',
                    'Locked' => false,
                ],
                [
                    'RecordId' => '102',
                    'RR' => 'label-other',
                    'Type' => 'TXT',
                    'Value' => 'new',
                    'DomainName' => 'proxy.example.com',
                    'TTL' => 600,
                    'Status' => 'Enable',
                    'Line' => 'default',
                    'Locked' => false,
                ],
                [
                    'RecordId' => '103',
                    'RR' => 'label',
                    'Type' => 'A',
                    'Value' => 'new',
                    'DomainName' => 'proxy.example.com',
                    'TTL' => 600,
                    'Status' => 'Enable',
                    'Line' => 'default',
                    'Locked' => false,
                ],
            ], 1, 500, 3));
        }

        assertValidAliyunSignedQuery($request, 'AddDomainRecord');
        expect($query)->toMatchArray([
            'DomainName' => 'proxy.example.com',
            'RR' => 'label',
            'Type' => 'TXT',
            'Value' => 'new',
            'TTL' => '900',
        ]);

        return Http::response([
            'RequestId' => 'add-request-id',
            'RecordId' => '201',
        ]);
    });

    expect(aliyunProvider()->upsertTxt('label', ['old', 'new', 'new'], 900))->toBeTrue();

    Http::assertSentCount(2);
});

test('Aliyun provider upsert 在全部值已存在时不调用新增接口', function () {
    Http::fake(['*' => Http::response(aliyunListResponse([
        [
            'RecordId' => '101',
            'RR' => 'label',
            'Type' => 'TXT',
            'Value' => 'one',
            'DomainName' => 'proxy.example.com',
            'TTL' => 600,
            'Status' => 'Enable',
            'Line' => 'default',
            'Locked' => false,
        ],
        [
            'RecordId' => '102',
            'RR' => 'label',
            'Type' => 'TXT',
            'Value' => 'two',
            'DomainName' => 'proxy.example.com',
            'TTL' => 600,
            'Status' => 'Enable',
            'Line' => 'default',
            'Locked' => false,
        ],
    ], 1, 500, 2))]);

    expect(aliyunProvider()->upsertTxt('label', ['one', 'two']))->toBeTrue();

    Http::assertSentCount(1);
});

test('Aliyun provider upsert 不把同值的禁用 TXT 视为已存在', function () {
    Http::fake(function (Request $request) {
        $query = assertValidAliyunSignedQuery($request, match (count(Http::recorded())) {
            0 => 'DescribeDomainRecords',
            1 => 'DeleteDomainRecord',
            default => 'AddDomainRecord',
        });

        return match ($query['Action']) {
            'DescribeDomainRecords' => Http::response(aliyunListResponse([
                [
                    'RecordId' => '101',
                    'RR' => 'label',
                    'Type' => 'TXT',
                    'Value' => 'token',
                    'DomainName' => 'proxy.example.com',
                    'TTL' => 600,
                    'Status' => 'Disable',
                    'Line' => 'default',
                    'Locked' => false,
                ],
            ], 1, 500, 1)),
            'DeleteDomainRecord' => Http::response([
                'RequestId' => 'delete-request-id',
                'RecordId' => $query['RecordId'],
            ]),
            'AddDomainRecord' => Http::response([
                'RequestId' => 'add-request-id',
                'RecordId' => '201',
            ]),
            default => throw new RuntimeException('Aliyun DNS 测试收到未预期操作'),
        };
    });

    expect(aliyunProvider()->upsertTxt('label', ['token']))->toBeTrue();

    $queries = collect(Http::recorded())
        ->map(function (array $recorded) {
            parse_str((string) parse_url($recorded[0]->url(), PHP_URL_QUERY), $query);

            return $query;
        })
        ->all();

    expect($queries)->toHaveCount(3)
        ->and(array_column($queries, 'Action'))->toBe([
            'DescribeDomainRecords',
            'DeleteDomainRecord',
            'AddDomainRecord',
        ])
        ->and($queries[1]['RecordId'])->toBe('101')
        ->and($queries[2])->toMatchArray([
            'DomainName' => 'proxy.example.com',
            'RR' => 'label',
            'Type' => 'TXT',
            'Value' => 'token',
            'TTL' => '600',
        ]);
});

test('Aliyun provider upsert 不把非默认线路的同值 TXT 视为已存在', function () {
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if (($query['Action'] ?? null) === 'DescribeDomainRecords') {
            assertValidAliyunSignedQuery($request, 'DescribeDomainRecords');

            return Http::response(aliyunListResponse([
                [
                    'RecordId' => '101',
                    'RR' => 'label',
                    'Type' => 'TXT',
                    'Value' => 'token',
                    'DomainName' => 'proxy.example.com',
                    'TTL' => 600,
                    'Status' => 'Enable',
                    'Line' => 'cn_mobile',
                    'Locked' => false,
                ],
            ], 1, 500, 1));
        }

        assertValidAliyunSignedQuery($request, 'AddDomainRecord');
        expect($query)->toMatchArray([
            'DomainName' => 'proxy.example.com',
            'RR' => 'label',
            'Type' => 'TXT',
            'Value' => 'token',
            'TTL' => '600',
        ]);

        return Http::response([
            'RequestId' => 'add-request-id',
            'RecordId' => '201',
        ]);
    });

    expect(aliyunProvider()->upsertTxt('label', ['token']))->toBeTrue();

    $actions = collect(Http::recorded())
        ->map(function (array $recorded) {
            parse_str((string) parse_url($recorded[0]->url(), PHP_URL_QUERY), $query);

            return $query['Action'] ?? null;
        })
        ->all();

    expect($actions)->toBe(['DescribeDomainRecords', 'AddDomainRecord']);
});

test('Aliyun provider deleteTxt 精确查找并逐条删除同名 TXT', function () {
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if (($query['Action'] ?? null) === 'DescribeDomainRecords') {
            return Http::response(aliyunListResponse([
                [
                    'RecordId' => '101',
                    'RR' => 'label',
                    'Type' => 'TXT',
                    'Value' => 'one',
                    'DomainName' => 'proxy.example.com',
                    'TTL' => 600,
                    'Status' => 'Enable',
                    'Line' => 'default',
                    'Locked' => false,
                ],
                [
                    'RecordId' => '102',
                    'RR' => 'label',
                    'Type' => 'TXT',
                    'Value' => 'two',
                    'DomainName' => 'proxy.example.com',
                    'TTL' => 600,
                    'Status' => 'Enable',
                    'Line' => 'default',
                    'Locked' => false,
                ],
                [
                    'RecordId' => '103',
                    'RR' => 'label-other',
                    'Type' => 'TXT',
                    'Value' => 'ignored',
                    'DomainName' => 'proxy.example.com',
                    'TTL' => 600,
                    'Status' => 'Enable',
                    'Line' => 'default',
                    'Locked' => false,
                ],
            ], 1, 500, 3));
        }

        assertValidAliyunSignedQuery($request, 'DeleteDomainRecord');

        return Http::response([
            'RequestId' => 'delete-request-'.$query['RecordId'],
            'RecordId' => $query['RecordId'],
        ]);
    });

    aliyunProvider()->deleteTxt('label');

    $deletedIds = collect(Http::recorded())
        ->map(function (array $recorded) {
            parse_str((string) parse_url($recorded[0]->url(), PHP_URL_QUERY), $query);

            return ($query['Action'] ?? null) === 'DeleteDomainRecord'
                ? ($query['RecordId'] ?? null)
                : null;
        })
        ->filter()
        ->values()
        ->all();

    expect($deletedIds)->toBe(['101', '102']);
});

test('Aliyun provider deleteRecords 去重记录 ID 并校验删除响应', function () {
    Http::fake(function (Request $request) {
        $query = assertValidAliyunSignedQuery($request, 'DeleteDomainRecord');

        return Http::response([
            'RequestId' => 'delete-request-'.$query['RecordId'],
            'RecordId' => $query['RecordId'],
        ]);
    });

    aliyunProvider()->deleteRecords(['101', 101, '202']);

    Http::assertSentCount(2);
});

test('Aliyun provider 拒绝无效记录 ID', function (mixed $recordId) {
    Http::fake();

    expect(fn () => aliyunProvider()->deleteRecords([$recordId]))
        ->toThrow(InvalidArgumentException::class, 'Aliyun DNS 记录 ID 无效');

    Http::assertNothingSent();
})->with([
    '零' => [0],
    '负数' => ['-1'],
    '空字符串' => [''],
    '非数字' => ['record-id'],
    '数组' => [['101']],
]);

test('Aliyun provider 对 HTTP 失败和 API 错误均返回固定脱敏异常', function (array|string $body, int $status) {
    $accessKeyId = 'never-expose-access-key-id';
    $accessKeySecret = 'never-expose-access-key-secret';
    Http::fake(['*' => Http::response($body, $status)]);

    try {
        aliyunProvider($accessKeyId, $accessKeySecret)->allTxt();
        throw new RuntimeException('远端失败未抛出预期异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Aliyun DNS 请求失败')
            ->and($e->getMessage())->not->toContain($accessKeyId)
            ->and($e->getMessage())->not->toContain($accessKeySecret);
    }
})->with([
    'HTTP 失败' => ['gateway failure never-expose-access-key-secret', 502],
    'API 错误' => [[
        'RequestId' => 'request-id',
        'Code' => 'InvalidAccessKeyId.NotFound',
        'Message' => 'never-expose-access-key-id never-expose-access-key-secret',
    ], 200],
]);

test('Aliyun provider 对畸形新增或删除响应失败关闭', function (Closure $operation, array $responses) {
    $sequence = Http::fakeSequence();
    foreach ($responses as $response) {
        $sequence->push($response);
    }

    expect(fn () => $operation(aliyunProvider()))
        ->toThrow(RuntimeException::class, 'Aliyun DNS 响应格式无效');
})->with([
    '新增缺少 RecordId' => [
        fn (AliyunDnsProvider $provider) => $provider->upsertTxt('label', ['value']),
        [aliyunListResponse([], 1, 500, 0), ['RequestId' => 'request-id']],
    ],
    '删除 RecordId 不匹配' => [
        fn (AliyunDnsProvider $provider) => $provider->deleteRecords(['101']),
        [['RequestId' => 'request-id', 'RecordId' => '202']],
    ],
]);
