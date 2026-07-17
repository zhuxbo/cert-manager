<?php

use App\Models\Admin;
use App\Models\Cert;
use App\Models\Chain;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Action;
use App\Services\Order\Api\Api;
use App\Services\Order\Utils\ChainVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Traits\CreatesTestData;
use Tests\Traits\GeneratesCertChains;

uses(CreatesTestData::class, GeneratesCertChains::class);

/**
 * F2-4 sync 证书链签名校验门禁行为（锁外 gate）。
 */

/** 绑定捕获 system_alert intent 的假 NotificationCenter，返回记录 system_alert 次数的状态对象 */
function bindChainAlertCenter(): object
{
    $state = new class
    {
        public int $systemAlertCount = 0;

        public ?string $lastReason = null;
    };

    $mock = Mockery::mock(NotificationCenter::class);
    $mock->shouldReceive('dispatch')->andReturnUsing(function ($intent) use ($state) {
        if ($intent->code === 'system_alert') {
            $state->systemAlertCount++;
            $state->lastReason = $intent->context['details']['reason'] ?? null;
        }
    });
    app()->instance(NotificationCenter::class, $mock);

    return $state;
}

/** 配置 admin 邮箱，令 SystemAlert 可解析到 admin 而真正派发 */
function setupChainAlertAdmin(): void
{
    Admin::factory()->create(['email' => 'ops@corp.example']);
    $group = SettingGroup::firstOrCreate(['name' => 'site'], ['title' => '站点', 'weight' => 1]);
    Setting::updateOrCreate(
        ['group_id' => $group->id, 'key' => 'adminEmail'],
        ['type' => 'string', 'value' => 'ops@corp.example', 'weight' => 0]
    );
    Setting::clearGroupCache($group->id);
}

// 9. sync 收坏链 → 拒写 + raw DB active + 模型 approving + 告警一次（dedup 二次不发）
test('sync 收坏链 → chains 无该 issuer、raw active、模型 approving、告警仅一次', function () {
    Cache::flush();
    setupChainAlertAdmin();
    $state = bindChainAlertCenter();

    // 坏链：leaf 由 CA-A 签发，intermediate 为同 CN 不同密钥的 CA-B（签名必不过）
    $sharedCn = 'Shared Bad CA '.bin2hex(random_bytes(4));
    $chain = $this->makeRsaChain('bad.example.com', $sharedCn);
    [, , $wrongCaPem] = $this->makeRsaCa($sharedCn);

    $upstream = [
        'code' => 1,
        'data' => [
            'cert' => $chain['leaf'],
            'intermediate_cert' => $wrongCaPem,
            'status' => 'active',
        ],
    ];

    $mock = Mockery::mock(Api::class);
    $mock->shouldReceive('get')->andReturn($upstream);
    app()->instance(Api::class, $mock);

    $makeCert = fn (string $apiId) => $this->createTestCert(
        $this->createTestOrder(
            $this->createTestUser(['balance' => '100.00']),
            $this->createTestProduct(['source' => 'default']),
            ['amount' => '100.00']
        ),
        ['status' => 'processing', 'action' => 'new', 'api_id' => $apiId]
    );

    $certA = $makeCert('ca-bad-001');
    app(Action::class)->sync($certA->order_id, true);

    // 坏链拒写：chains 表无该 issuer
    expect(Chain::where('common_name', $sharedCn)->exists())->toBeFalse();

    // raw DB status=active（绕 retrieved 钩子）
    expect(DB::table('certs')->where('id', $certA->id)->value('status'))->toBe('active');

    // 模型再检索 → retrieved 缺链翻 approving（自愈闭环可达）
    app()->forgetInstance('cert.chainMap');
    expect(Cert::find($certA->id)->status)->toBe('approving');

    // 告警派发一次，reason=chain_verify_failed
    expect($state->systemAlertCount)->toBe(1)
        ->and($state->lastReason)->toBe('chain_verify_failed');

    // 第二单同 issuer 坏链 → SystemAlert dedup（固定指纹）→ 不再发
    $certB = $makeCert('ca-bad-002');
    app(Action::class)->sync($certB->order_id, true);

    expect($state->systemAlertCount)->toBe(1);
});

// 10. sync 收对链 → chains 写入、无告警
test('sync 收对链 → chains 写入且无告警', function () {
    Cache::flush();
    setupChainAlertAdmin();
    $state = bindChainAlertCenter();

    $chain = $this->makeRsaChain('good.example.com');

    $mock = Mockery::mock(Api::class);
    $mock->shouldReceive('get')->andReturn([
        'code' => 1,
        'data' => [
            'cert' => $chain['leaf'],
            'intermediate_cert' => $chain['ca'],
            'status' => 'active',
        ],
    ]);
    app()->instance(Api::class, $mock);

    $order = $this->createTestOrder(
        $this->createTestUser(['balance' => '100.00']),
        $this->createTestProduct(['source' => 'default']),
        ['amount' => '100.00']
    );
    $cert = $this->createTestCert($order, ['status' => 'processing', 'action' => 'new', 'api_id' => 'ca-good-001']);
    app(Action::class)->sync($cert->order_id, true);

    // 对链写入 chains，值为上游 intermediate 原文
    expect(Chain::where('common_name', $chain['issuer'])->exists())->toBeTrue()
        ->and(Chain::where('common_name', $chain['issuer'])->value('intermediate_cert'))->toBe($chain['ca']);

    // active 且已有链 → 模型不降级
    app()->forgetInstance('cert.chainMap');
    expect(Cert::find($cert->id)->status)->toBe('active');

    // 无告警
    expect($state->systemAlertCount)->toBe(0);
});

// 11. unverifiable → 链照写（fail-open）+ 告警
test('sync openssl 不可用（unverifiable）→ 链照写 fail-open + 告警', function () {
    Cache::flush();
    setupChainAlertAdmin();
    $state = bindChainAlertCenter();

    $chain = $this->makeRsaChain('failopen.example.com');

    // ChainVerifier 强制返回 unverifiable（模拟 openssl 不可用/运行性失败）
    $verifier = Mockery::mock(ChainVerifier::class);
    $verifier->shouldReceive('verifyIssued')->andReturn('unverifiable');
    $verifier->shouldReceive('lastOutput')->andReturn('mock: openssl 不可用');
    app()->instance(ChainVerifier::class, $verifier);

    $mock = Mockery::mock(Api::class);
    $mock->shouldReceive('get')->andReturn([
        'code' => 1,
        'data' => [
            'cert' => $chain['leaf'],
            'intermediate_cert' => $chain['ca'],
            'status' => 'active',
        ],
    ]);
    app()->instance(Api::class, $mock);

    $order = $this->createTestOrder(
        $this->createTestUser(['balance' => '100.00']),
        $this->createTestProduct(['source' => 'default']),
        ['amount' => '100.00']
    );
    $cert = $this->createTestCert($order, ['status' => 'processing', 'action' => 'new', 'api_id' => 'ca-unverif-001']);
    app(Action::class)->sync($cert->order_id, true);

    // fail-open：链照写
    expect(Chain::where('common_name', $chain['issuer'])->exists())->toBeTrue();

    // 告警派发，reason=openssl_unavailable
    expect($state->systemAlertCount)->toBe(1)
        ->and($state->lastReason)->toBe('openssl_unavailable');
});
