<?php

declare(strict_types=1);

use App\Services\Delegation\Dns\CloudflareDnsProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function cloudflareProvider(string $token = 'api-token'): CloudflareDnsProvider
{
    return new CloudflareDnsProvider([
        'provider' => 'cloudflare',
        'domain' => 'proxy.example.com',
        'zoneId' => 'zone-id',
        'apiToken' => $token,
    ]);
}

function cloudflareListResponse(array $records, int $page = 1, int $totalPages = 1, bool $success = true): array
{
    return [
        'success' => $success,
        'errors' => [],
        'messages' => [],
        'result' => $records,
        'result_info' => [
            'page' => $page,
            'per_page' => 100,
            'count' => count($records),
            'total_count' => count($records),
            'total_pages' => $totalPages,
        ],
    ];
}

test('upsert 使用 Bearer 查询完整 TXT FQDN 并仅创建缺失唯一值', function () {
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(cloudflareListResponse([
                ['id' => 'r1', 'name' => 'label.proxy.example.com', 'type' => 'TXT', 'content' => 'old'],
                ['id' => 'r2', 'name' => 'other.proxy.example.com', 'type' => 'TXT', 'content' => 'new'],
                ['id' => 'r3', 'name' => 'label.proxy.example.com', 'type' => 'A', 'content' => 'new'],
            ]));
        }

        return Http::response([
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => ['id' => 'created-id'],
        ]);
    });

    expect(cloudflareProvider()->upsertTxt('label', ['old', 'new', 'new'], 600))->toBeTrue();

    Http::assertSent(function (Request $request) {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-id/dns_records?type=TXT&name=label.proxy.example.com&page=1&per_page=100'
            && $request->hasHeader('Authorization', 'Bearer api-token')
            && $request->hasHeader('Accept', 'application/json');
    });
    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-id/dns_records'
            && $request->data() === [
                'type' => 'TXT',
                'name' => 'label.proxy.example.com',
                'content' => 'new',
                'ttl' => 600,
                'proxied' => false,
            ];
    });
    Http::assertSentCount(2);
});

test('upsert 在所有值已存在时不发送 POST', function () {
    Http::fake([
        '*' => Http::response(cloudflareListResponse([
            ['id' => 'r1', 'name' => 'label.proxy.example.com', 'type' => 'TXT', 'content' => 'one'],
            ['id' => 'r2', 'name' => 'label.proxy.example.com', 'type' => 'TXT', 'content' => 'two'],
        ])),
    ]);

    expect(cloudflareProvider()->upsertTxt('label', ['one', 'two']))->toBeTrue();

    Http::assertSentCount(1);
});

test('allTxt 分页查询并只返回域内 TXT 的相对记录名', function () {
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['page'] ?? null) === '2'
            ? Http::response(cloudflareListResponse([
                ['id' => 'r2', 'name' => 'proxy.example.com', 'type' => 'TXT', 'content' => 'apex'],
                ['id' => 'r3', 'name' => 'label.proxy.example.com', 'type' => 'A', 'content' => 'ignored'],
                ['id' => 'r4', 'name' => 'outside.example.net', 'type' => 'TXT', 'content' => 'ignored'],
            ], 2, 2))
            : Http::response(cloudflareListResponse([
                ['id' => 'r1', 'name' => 'label.proxy.example.com', 'type' => 'TXT', 'content' => 'value'],
            ], 1, 2));
    });

    expect(cloudflareProvider()->allTxt())->toBe([
        ['id' => 'r1', 'name' => 'label', 'value' => 'value'],
        ['id' => 'r2', 'name' => '@', 'value' => 'apex'],
    ]);
    Http::assertSentCount(2);
});

test('deleteTxt 删除完整 FQDN 下全部 TXT 值', function () {
    Http::fake(function (Request $request) {
        if ($request->method() === 'GET') {
            return Http::response(cloudflareListResponse([
                ['id' => 'r1', 'name' => 'label.proxy.example.com', 'type' => 'TXT', 'content' => 'one'],
                ['id' => 'r2', 'name' => 'label.proxy.example.com', 'type' => 'TXT', 'content' => 'two'],
                ['id' => 'r3', 'name' => 'label.proxy.example.com', 'type' => 'A', 'content' => 'ignored'],
            ]));
        }

        return Http::response([
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => ['id' => basename(parse_url($request->url(), PHP_URL_PATH))],
        ]);
    });

    cloudflareProvider()->deleteTxt('label');

    $deleteUrls = collect(Http::recorded())
        ->map(fn (array $recorded) => $recorded[0])
        ->filter(fn (Request $request) => $request->method() === 'DELETE')
        ->map(fn (Request $request) => $request->url())
        ->values()
        ->all();

    expect($deleteUrls)->toBe([
        'https://api.cloudflare.com/client/v4/zones/zone-id/dns_records/r1',
        'https://api.cloudflare.com/client/v4/zones/zone-id/dns_records/r2',
    ]);
});

test('deleteRecords 删除指定记录 ID', function () {
    Http::fake([
        '*' => Http::response([
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => ['id' => 'deleted-id'],
        ]),
    ]);

    cloudflareProvider()->deleteRecords(['r1', 'r2']);

    Http::assertSentCount(2);
});

test('HTTP 状态失败时抛出不含 token 的异常', function () {
    $token = 'never-expose-api-token';
    Http::fake(['*' => Http::response('gateway failure '.$token, 502)]);

    try {
        cloudflareProvider($token)->allTxt();
        test()->fail('HTTP 失败应抛出异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain($token);
    }
});

test('success=false 时失败关闭', function () {
    Http::fake(['*' => Http::response(cloudflareListResponse([], success: false))]);

    expect(fn () => cloudflareProvider()->allTxt())
        ->toThrow(RuntimeException::class, 'Cloudflare DNS 请求失败');
});

test('列表响应畸形时失败关闭', function () {
    Http::fake(['*' => Http::response([
        'success' => true,
        'errors' => [],
        'messages' => [],
        'result' => [],
    ])]);

    expect(fn () => cloudflareProvider()->allTxt())
        ->toThrow(RuntimeException::class, 'Cloudflare DNS 响应格式无效');
});

test('删除响应畸形时失败关闭', function () {
    Http::fake(['*' => Http::response(['success' => true, 'result' => null])]);

    expect(fn () => cloudflareProvider()->deleteRecords(['r1']))
        ->toThrow(RuntimeException::class, 'Cloudflare DNS 响应格式无效');
});
