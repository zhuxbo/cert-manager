<?php

use App\Services\ProductPrice\ProductPricePreviewToken;
use Carbon\Carbon;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-16 10:00:00'));
    config(['app.key' => 'task-three-preview-token-key']);
});

afterEach(function () {
    Carbon::setTestNow();
});

function taskThreeTokenParams(array $overrides = []): array
{
    return array_replace([
        'levels' => [
            ['code' => 'standard', 'cost_rate' => '1.2000'],
            ['code' => 'gold', 'cost_rate' => '1.1000'],
        ],
        'precision' => 2,
        'force' => false,
        'sync_cost_rates' => false,
    ], $overrides);
}

test('有效令牌绑定管理员参数状态和过期时间', function () {
    $service = app(ProductPricePreviewToken::class);
    $params = taskThreeTokenParams();
    $expiresAt = now()->addMinutes(10)->timestamp;

    $token = $service->issue(7, $params, 'state-fingerprint', $expiresAt);
    $verified = $service->verify($token, 7, $params);

    expect($verified)->toBe([
        'state_fingerprint' => 'state-fingerprint',
        'expires_at' => $expiresAt,
    ]);
});

test('管理员或业务参数不一致时拒绝令牌', function (int $adminId, array $params) {
    $service = app(ProductPricePreviewToken::class);
    $token = $service->issue(7, taskThreeTokenParams(), 'fingerprint', now()->addMinutes(10)->timestamp);

    expect(fn () => $service->verify($token, $adminId, $params))
        ->toThrow(InvalidArgumentException::class);
})->with([
    '管理员不一致' => [8, taskThreeTokenParams()],
    '精度不一致' => [7, taskThreeTokenParams(['precision' => 1])],
    '倍率不一致' => [7, taskThreeTokenParams(['levels' => [
        ['code' => 'standard', 'cost_rate' => '1.3000'],
        ['code' => 'gold', 'cost_rate' => '1.1000'],
    ]])],
]);

test('过期令牌被拒绝', function () {
    $service = app(ProductPricePreviewToken::class);
    $params = taskThreeTokenParams();
    $token = $service->issue(7, $params, 'fingerprint', now()->addMinutes(10)->timestamp);

    Carbon::setTestNow(now()->addMinutes(10));

    expect(fn () => $service->verify($token, 7, $params))
        ->toThrow(InvalidArgumentException::class);
});

test('签名或载荷篡改被拒绝', function (string $part) {
    $service = app(ProductPricePreviewToken::class);
    $params = taskThreeTokenParams();
    [$payload, $signature] = explode('.', $service->issue(
        7,
        $params,
        'fingerprint',
        now()->addMinutes(10)->timestamp,
    ));

    $token = $part === 'payload'
        ? substr($payload, 0, -1).($payload[-1] === 'A' ? 'B' : 'A').'.'.$signature
        : $payload.'.'.substr($signature, 0, -1).($signature[-1] === 'A' ? 'B' : 'A');

    expect(fn () => $service->verify($token, 7, $params))
        ->toThrow(InvalidArgumentException::class);
})->with(['payload', 'signature']);

test('非法 base64 json 和 token 结构被拒绝', function (string $token) {
    expect(fn () => app(ProductPricePreviewToken::class)->verify($token, 7, taskThreeTokenParams()))
        ->toThrow(InvalidArgumentException::class);
})->with([
    '缺少分隔符' => 'invalid',
    '分段过多' => 'a.b.c',
    '非法 base64' => '*.AA',
]);

test('合法 HMAC 签名的非 JSON 载荷进入 JSON 解码分支并被拒绝', function () {
    $encodedPayload = rtrim(strtr(base64_encode('not-json'), '+/', '-_'), '=');
    $signature = hash_hmac('sha256', $encodedPayload, (string) config('app.key'), true);
    $encodedSignature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    expect(fn () => app(ProductPricePreviewToken::class)->verify(
        "$encodedPayload.$encodedSignature",
        7,
        taskThreeTokenParams(),
    ))->toThrow(InvalidArgumentException::class, '预览令牌无效');
});

test('传输字段不参与参数哈希且 levels 乱序等价', function () {
    $service = app(ProductPricePreviewToken::class);
    $params = taskThreeTokenParams();
    $token = $service->issue(7, [
        ...$params,
        'preview' => true,
        'preview_token' => 'ignored-at-issue',
    ], 'fingerprint', now()->addMinutes(10)->timestamp);

    $reordered = [
        ...$params,
        'levels' => array_reverse($params['levels']),
        'preview' => false,
        'preview_token' => 'ignored-at-verify',
        'unexpected_transport_field' => 'ignored',
    ];

    expect($service->verify($token, 7, $reordered)['state_fingerprint'])
        ->toBe('fingerprint');
});
