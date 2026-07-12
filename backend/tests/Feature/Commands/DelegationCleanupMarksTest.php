<?php

use App\Models\Cert;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Delegation\ProxyDNS;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Traits\CreatesTestData;

uses(CreatesTestData::class);

/**
 * F2-3 DelegationCleanup 清理窗口与状态保留集缺陷修复。
 *  缺陷一：cleanDatabaseMarks 只扫 30 天内订单 → >30 天订单 label 被删后标记不清、TXT 永不重写。
 *  缺陷二：keepLabels 仅取 processing、不含 approving → approving 单委托 TXT 被每日误删。
 */
beforeEach(function () {
    $this->proxyDNS = Mockery::mock(ProxyDNS::class);
    app()->instance(ProxyDNS::class, $this->proxyDNS);

    Cache::flush();
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => '站点', 'weight' => 1]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'delegation'],
        ['type' => 'array', 'value' => ['proxyZone' => 'proxy.example.com'], 'weight' => 0]
    );
    Setting::clearGroupCache($group->id);
});

afterEach(fn () => Mockery::close());

// 缺陷一：>30 天非活跃订单 label 被删 → 标记被清（修复前 30 天窗口外不扫 = 复现 bug）
test('>30 天订单 label 落删除集 → auto_txt_written 被清（去 30 天窗口全扫）', function () {
    $user = $this->createTestUser();
    $delegation = $this->createTestDelegation($user, ['zone' => 'oldorder.example.com']);
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $cert = $this->createTestCert($order, [
        'status' => 'cancelled',
        'validation' => [
            ['domain' => 'oldorder.example.com', 'method' => 'txt', 'delegation_id' => $delegation->id,
                'auto_txt_written' => true, 'auto_txt_written_at' => '2020-01-01 00:00:00'],
        ],
    ]);
    // 回拨 40 天（>30 天窗口）
    Cert::where('id', $cert->id)->update(['created_at' => now()->subDays(40)]);

    // 该 label 存在于腾讯云、且订单非 processing/approving → 落删除集
    $this->proxyDNS->shouldReceive('getAllTxtRecords')->andReturn([['id' => 1, 'name' => $delegation->label]]);
    $this->proxyDNS->shouldReceive('batchDeleteRecords')->once();

    $this->artisan('delegation:cleanup')->assertSuccessful();

    $cert->refresh();
    expect($cert->validation[0])->not->toHaveKey('auto_txt_written')
        ->and($cert->validation[0])->not->toHaveKey('delegation_id');
});

// 缺陷二：approving 单 label 进 keepLabels → 不被删
test('approving 单 label 进 keepLabels → 不删除（保留集纳入 approving）', function () {
    $user = $this->createTestUser();
    $delegation = $this->createTestDelegation($user, ['zone' => 'approving.example.com']);
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $cert = $this->createTestCert($order, [
        'status' => 'approving',
        'validation' => [
            ['domain' => 'approving.example.com', 'method' => 'txt', 'delegation_id' => $delegation->id,
                'auto_txt_written' => true],
        ],
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    // 腾讯云仅有该 approving label；修复后进 keepLabels → 无需删除
    $this->proxyDNS->shouldReceive('getAllTxtRecords')->andReturn([['id' => 1, 'name' => $delegation->label]]);
    $this->proxyDNS->shouldReceive('batchDeleteRecords')->never();

    $this->artisan('delegation:cleanup')
        ->expectsOutputToContain('没有需要清理的记录')
        ->assertSuccessful();
});

// 护栏：processing 单 label 保留、标记不误清
test('processing 单 label 保留、标记不被误清（护栏）', function () {
    $user = $this->createTestUser();
    $delegation = $this->createTestDelegation($user, ['zone' => 'processing.example.com']);
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $cert = $this->createTestCert($order, [
        'status' => 'processing',
        'validation' => [
            ['domain' => 'processing.example.com', 'method' => 'txt', 'delegation_id' => $delegation->id,
                'auto_txt_written' => true],
        ],
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $this->proxyDNS->shouldReceive('getAllTxtRecords')->andReturn([['id' => 1, 'name' => $delegation->label]]);
    $this->proxyDNS->shouldReceive('batchDeleteRecords')->never();

    $this->artisan('delegation:cleanup')->assertSuccessful();

    $cert->refresh();
    expect($cert->validation[0]['auto_txt_written'])->toBeTrue();
});

// F2-3 性能：cleanDatabaseMarks 批量加载委托消除 N+1 + certs 预加载 select 精简（不水合宽列）
test('cleanDatabaseMarks 批量加载委托消除 N+1 + certs 预加载 select 精简', function () {
    // beforeEach 已造 site.delegation.proxyZone=proxy.example.com
    $user = $this->createTestUser();

    // 3 个非 processing 订单，各带 auto_txt_written 标记引用不同的存在委托（label 均不在删除集 → 不清但需加载）
    for ($i = 0; $i < 3; $i++) {
        $d = $this->createTestDelegation($user, ['zone' => "keep$i.example.com"]);
        $order = $this->createTestOrder($user, $this->createTestProduct());
        $this->createTestCert($order, [
            'status' => 'cancelled',
            'validation' => [
                ['domain' => "keep$i.example.com", 'method' => 'txt', 'delegation_id' => $d->id,
                    'auto_txt_written' => true],
            ],
        ]);
    }

    // 腾讯云仅 1 条 hex 孤儿 → 触发删除进入 cleanDatabaseMarks（否则 recordsToDelete 空会 early return）
    $orphanHex = str_repeat('d', 32);
    $this->proxyDNS->shouldReceive('getAllTxtRecords')->andReturn([['id' => 1, 'name' => $orphanHex]]);
    $this->proxyDNS->shouldReceive('batchDeleteRecords')->once();

    $delegationSelects = 0;
    $certPreloadSql = null;
    DB::listen(function ($q) use (&$delegationSelects, &$certPreloadSql) {
        $sql = strtolower($q->sql);
        if (str_starts_with($sql, 'select') && str_contains($sql, 'from `cname_delegations`')) {
            $delegationSelects++;
        }
        // certs 预加载（独立 select ... from certs where id in (...)），区别于 whereHas 的 exists 相关子查询
        if (str_starts_with($sql, 'select') && str_contains($sql, 'from `certs`') && str_contains($sql, '`id` in (')) {
            $certPreloadSql = $sql;
        }
    });

    $this->artisan('delegation:cleanup')->assertSuccessful();

    // N+1 消除：本 chunk 委托一次 whereIn 批量加载（≤1），非逐条 find（修复前=3 次）
    expect($delegationSelects)->toBeLessThanOrEqual(1);
    // select 精简：certs 预加载显式列出 validation 列（非 select *，不水合 csr/private_key 宽列）
    expect($certPreloadSql)->not->toBeNull()
        ->and($certPreloadSql)->toContain('validation')
        ->and($certPreloadSql)->not->toContain('private_key');
});

// F2-3 行为等价：LIKE 粗筛 + 批量加载不改结果——孤儿清、被删清、未删留、无标记不动
test('cleanDatabaseMarks LIKE 粗筛 + 批量加载行为等价', function () {
    $user = $this->createTestUser();

    // A. 孤儿标记（delegation_id 指向不存在）→ 应清
    $orderA = $this->createTestOrder($user, $this->createTestProduct());
    $certA = $this->createTestCert($orderA, ['status' => 'cancelled', 'validation' => [
        ['domain' => 'a.example.com', 'method' => 'txt', 'delegation_id' => 888888, 'auto_txt_written' => true],
    ]]);

    // B. 有效标记且 label 在删除集 → 应清
    $delB = $this->createTestDelegation($user, ['zone' => 'b.example.com']);
    $orderB = $this->createTestOrder($user, $this->createTestProduct());
    $certB = $this->createTestCert($orderB, ['status' => 'cancelled', 'validation' => [
        ['domain' => 'b.example.com', 'method' => 'txt', 'delegation_id' => $delB->id, 'auto_txt_written' => true],
    ]]);

    // C. 有效标记但 label 不在删除集 → 应留
    $delC = $this->createTestDelegation($user, ['zone' => 'c.example.com']);
    $orderC = $this->createTestOrder($user, $this->createTestProduct());
    $certC = $this->createTestCert($orderC, ['status' => 'cancelled', 'validation' => [
        ['domain' => 'c.example.com', 'method' => 'txt', 'delegation_id' => $delC->id, 'auto_txt_written' => true],
    ]]);

    // D. 无 auto_txt_written 标记（LIKE 粗筛应排除、原样不动）
    $orderD = $this->createTestOrder($user, $this->createTestProduct());
    $certD = $this->createTestCert($orderD, ['status' => 'cancelled', 'validation' => [
        ['domain' => 'd.example.com', 'method' => 'txt'],
    ]]);

    // 腾讯云返回 delB.label（触发删除 + 进入 cleanDatabaseMarks）
    $this->proxyDNS->shouldReceive('getAllTxtRecords')->andReturn([['id' => 1, 'name' => $delB->label]]);
    $this->proxyDNS->shouldReceive('batchDeleteRecords')->once();

    $this->artisan('delegation:cleanup')->assertSuccessful();

    $certA->refresh();
    $certB->refresh();
    $certC->refresh();
    $certD->refresh();

    expect($certA->validation[0])->not->toHaveKey('auto_txt_written')      // A 孤儿 → 清
        ->and($certB->validation[0])->not->toHaveKey('auto_txt_written')   // B label 被删 → 清
        ->and($certC->validation[0]['auto_txt_written'])->toBeTrue()       // C label 未删 → 留
        ->and($certD->validation[0])->not->toHaveKey('auto_txt_written');  // D 无标记 → 原样
});

// 孤儿标记：delegation_id 指向不存在 → 清标记 + 清 delegation_id
test('委托已删孤儿标记（delegation_id 指向不存在）→ 清标记 + 清 delegation_id', function () {
    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $cert = $this->createTestCert($order, [
        'status' => 'cancelled',
        'validation' => [
            ['domain' => 'orphan.example.com', 'method' => 'txt', 'delegation_id' => 999999,
                'auto_txt_written' => true, 'auto_txt_written_at' => '2020-01-01 00:00:00'],
        ],
    ]);

    // 有一条委托格式（hex）孤儿 label 被删 → 触发 cleanDatabaseMarks 全扫；孤儿单 guard(!$delegation) 清标记。
    // 注：删除判据仅收委托格式（32/64-hex），生产中孤儿委托 TXT 恒为 hex label，故触发记录用 hex。
    $orphanHexLabel = str_repeat('c', 32);
    $this->proxyDNS->shouldReceive('getAllTxtRecords')->andReturn([['id' => 1, 'name' => $orphanHexLabel]]);
    $this->proxyDNS->shouldReceive('batchDeleteRecords')->once();

    $this->artisan('delegation:cleanup')->assertSuccessful();

    $cert->refresh();
    expect($cert->validation[0])->not->toHaveKey('auto_txt_written')
        ->and($cert->validation[0])->not->toHaveKey('delegation_id');
});
