<?php

use App\Services\Delegation\DelegationDnsService;
use App\Services\Order\Action;
use Tests\Traits\CreatesTestData;

uses(CreatesTestData::class);

/**
 * F2-2 上游 DCV token 轮换时 mergeValidation 保留旧 auto_txt_written 缺陷修复。
 *
 * 前提：本条正确性依赖「上游 get/commit 对同一 DCV token 返回稳定 value，仅真轮换才变」。
 * value 未变严格 no-op（护栏，防上游 value 不稳定时每轮 append 风暴）。
 */

/** 反射调用受保护的 mergeValidation */
function callMergeValidation(array $api, array $cert): array
{
    $action = app(Action::class);
    $ref = new ReflectionMethod($action, 'mergeValidation');
    $ref->setAccessible(true);

    return $ref->invoke($action, $api, $cert);
}

// 1. value 轮换 → 剔除 auto_txt_written 标记、保留 delegation_*、取新 value
test('token 轮换（value 不等）→ 剔除 auto_txt_written、保留 delegation_*', function () {
    $api = [
        ['domain' => 'example.com', 'method' => 'txt', 'host' => '_dnsauth.example.com', 'value' => 'NEW-TOKEN'],
    ];
    $cert = [
        [
            'domain' => 'example.com', 'method' => 'txt', 'host' => '_dnsauth.example.com', 'value' => 'OLD-TOKEN',
            'auto_txt_written' => true, 'auto_txt_written_at' => '2020-01-01 00:00:00',
            'delegation_id' => 42, 'delegation_valid' => true, 'delegation_zone' => 'example.com',
        ],
    ];

    $merged = callMergeValidation($api, $cert);

    expect($merged[0]['value'])->toBe('NEW-TOKEN')
        ->and($merged[0])->not->toHaveKey('auto_txt_written')
        ->and($merged[0])->not->toHaveKey('auto_txt_written_at')
        // 委托信息保留（委托未变，仅 token 变）
        ->and($merged[0]['delegation_id'])->toBe(42)
        ->and($merged[0]['delegation_valid'])->toBeTrue();
});

test('上游不能覆盖本地委托字段', function () {
    $api = [[
        'domain' => 'example.com',
        'method' => 'txt',
        'value' => 'SAME-TOKEN',
        'delegation_id' => 999,
        'delegation_target' => 'attacker.other.example',
        'delegation_zone' => 'other.example',
        'delegation_valid' => false,
        'auto_txt_written' => false,
        'auto_txt_written_at' => '2099-01-01 00:00:00',
    ]];
    $cert = [[
        'domain' => 'example.com',
        'method' => 'txt',
        'value' => 'SAME-TOKEN',
        'delegation_id' => 42,
        'delegation_target' => 'label.proxy.example',
        'delegation_zone' => 'example.com',
        'delegation_valid' => true,
        'auto_txt_written' => true,
        'auto_txt_written_at' => '2020-01-01 00:00:00',
    ]];

    $merged = callMergeValidation($api, $cert);

    expect($merged[0]['delegation_id'])->toBe(42)
        ->and($merged[0]['delegation_target'])->toBe('label.proxy.example')
        ->and($merged[0]['delegation_zone'])->toBe('example.com')
        ->and($merged[0]['delegation_valid'])->toBeTrue()
        ->and($merged[0]['auto_txt_written'])->toBeTrue()
        ->and($merged[0]['auto_txt_written_at'])->toBe('2020-01-01 00:00:00');
});

test('本地无委托信息时忽略上游注入的委托字段', function () {
    $api = [[
        'domain' => 'example.com',
        'method' => 'txt',
        'value' => 'TOKEN',
        'delegation_id' => 999,
        'delegation_target' => 'attacker.other.example',
        'delegation_zone' => 'other.example',
        'delegation_valid' => true,
        'auto_txt_written' => true,
        'auto_txt_written_at' => '2099-01-01 00:00:00',
    ]];

    $merged = callMergeValidation($api, []);

    expect($merged[0])->not->toHaveKeys([
        'delegation_id',
        'delegation_target',
        'delegation_zone',
        'delegation_valid',
        'auto_txt_written',
        'auto_txt_written_at',
    ]);
});

// 2. 护栏：value 相同 → 标记保留、无剔除
test('value 相同 → 保留 auto_txt_written 标记（no-op 护栏，防 append 风暴）', function () {
    $api = [
        ['domain' => 'example.com', 'method' => 'txt', 'host' => '_dnsauth.example.com', 'value' => 'SAME-TOKEN'],
    ];
    $cert = [
        [
            'domain' => 'example.com', 'method' => 'txt', 'host' => '_dnsauth.example.com', 'value' => 'SAME-TOKEN',
            'auto_txt_written' => true, 'auto_txt_written_at' => '2020-01-01 00:00:00',
            'delegation_id' => 42, 'delegation_valid' => true,
        ],
    ];

    $merged = callMergeValidation($api, $cert);

    expect($merged[0]['auto_txt_written'])->toBeTrue()
        ->and($merged[0]['auto_txt_written_at'])->toBe('2020-01-01 00:00:00');
});

// 2b. file 方法项无 value 键 → 判据取不到 → 不剔除（安全，file 不涉委托 txt 标记）
test('file 项无 value 键 → 不剔除（缺判据）', function () {
    $api = [
        ['domain' => 'example.com', 'method' => 'file', 'name' => 'x.txt', 'content' => 'c'],
    ];
    $cert = [
        [
            'domain' => 'example.com', 'method' => 'file',
            'auto_txt_written' => true, 'auto_txt_written_at' => '2020-01-01 00:00:00',
        ],
    ];

    $merged = callMergeValidation($api, $cert);

    expect($merged[0]['auto_txt_written'])->toBeTrue();
});

// 3. 端到端：merge 清标记后 writeDelegationTxtRecords 触发 setTxtByLabel 重写新 token
test('token 轮换后 writeDelegationTxtRecords 重写新 token（setTxtByLabel 被调用）', function () {
    $user = $this->createTestUser();
    $delegation = $this->createTestDelegation($user, [
        'zone' => 'example.com',
        'valid' => true,
    ]);

    $api = [
        ['domain' => 'example.com', 'method' => 'txt', 'host' => '_dnsauth.example.com', 'value' => 'NEW-TOKEN'],
    ];
    $cert = [
        [
            'domain' => 'example.com', 'method' => 'txt', 'host' => '_dnsauth.example.com', 'value' => 'OLD-TOKEN',
            'auto_txt_written' => true, 'auto_txt_written_at' => '2020-01-01 00:00:00',
            'delegation_id' => $delegation->id, 'delegation_valid' => true,
        ],
    ];

    $merged = callMergeValidation($api, $cert);
    // 轮换后标记已清
    expect($merged[0])->not->toHaveKey('auto_txt_written');

    // stub DelegationDnsService：断言 setTxtByLabel 以新 token 被调用一次
    $dns = Mockery::mock(DelegationDnsService::class);
    $dns->shouldReceive('setTxtByLabel')
        ->once()
        ->with($delegation->proxy_domain, $delegation->label, ['NEW-TOKEN'])
        ->andReturnTrue();
    app()->instance(DelegationDnsService::class, $dns);

    $action = app(Action::class);
    $ref = new ReflectionMethod($action, 'writeDelegationTxtRecords');
    $ref->setAccessible(true);
    $result = $ref->invoke($action, $merged);

    // 写入后重新标记 auto_txt_written
    expect($result[0]['auto_txt_written'])->toBeTrue();

    Mockery::close();
});
