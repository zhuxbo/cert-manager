<?php

use App\Models\Cert;
use App\Models\Chain;
use App\Services\Order\Action;
use App\Services\Order\Api\Api;
use Tests\Traits\CreatesTestData;
use Tests\Traits\GeneratesCertChains;

uses(CreatesTestData::class, GeneratesCertChains::class);

/**
 * 国密(SM2)证书 sync：上游返回真实签发关系的 intermediate_cert 时应在签发轮确定性写入 chains 表。
 *
 * 缺陷复现：$cert->update($data) 的 Eloquent fill 顺序不保证 issuer 早于 intermediate_cert。
 * 若上游响应里 intermediate_cert 键排在 cert/issuer 之前，setIntermediateCertAttribute 被触发时
 * $this->issuer 仍为空 → 跳过写 chains，故 Action::sync 在写回前强制 issuer 先落。
 *
 * 需求变更（F2-4 证书链门禁上线）：本测试原用 leaf 自身作占位 intermediate（自签证书 verify(leaf,leaf)
 * 恰好自签成立），并未验证真实 CA→leaf 签发关系。改用 gmOpenssl 现场生成的真实 SM2 CA→leaf 链
 * （CA 签 leaf、distid 一致），使证书链门禁以「SM2 正链→'ok'」放行写入，断言在真实签发关系下成立。
 * Cert::retrieved 缺链降级 approving→次轮补写对全算法适用、无 enc_cert 门控（是兜底而非本轮依赖），
 * 本测试靠链已确定性写入使 status 保持 active。
 */
test('sync 国密真实链（SM2 CA 签 SM2 leaf）在签发轮即写入 chains 并可透传', function () {
    // gmOpenssl 现场生成真实 SM2 CA→leaf 链（distid=1234567812345678 三处一致）
    $chain = $this->makeSm2Chain('sm2leaf.example.com');
    $sm2LeafPem = $chain['leaf'];
    $sm2CaPem = $chain['ca'];
    $issuerCn = $chain['issuer'];

    // chains 表此刻应无该 issuer 行（确保断言变化由本次 sync 引入）
    expect(Chain::where('common_name', $issuerCn)->exists())->toBeFalse();

    $user = $this->createTestUser(['balance' => '100.00']);
    $product = $this->createTestProduct(['source' => 'default']);
    $order = $this->createTestOrder($user, $product, ['amount' => '100.00']);

    // 国密订单：enc_cert 非空 = 国密透传证书；status=processing、有 api_id
    $cert = $this->createTestCert($order, [
        'status' => 'processing',
        'action' => 'new',
        'api_id' => 'ca-sm2-chain-001',
        'enc_cert' => 'SM2_ENC_CERT_PLACEHOLDER',
    ]);

    // mock 上游 get：intermediate_cert 键故意排在 cert/enc 之前，复现 fill 时序缺陷
    $mock = Mockery::mock(Api::class);
    $mock->shouldReceive('get')->once()->with($order->id)->andReturn([
        'code' => 1,
        'data' => [
            // intermediate_cert 排在最前，制造 issuer 尚未 fill 的时序场景
            'intermediate_cert' => $sm2CaPem,
            'cert' => $sm2LeafPem,
            'enc_cert' => 'SM2_ENC_CERT_REAL',
            'enc_key' => 'SM2_ENC_KEY',
            'enc_key2' => 'SM2_ENC_KEY2',
            'status' => 'active',
        ],
    ]);
    app()->instance(Api::class, $mock);

    // sync：force=true 模拟 V2 get 真实路径（国密 enc_cert 非终态不剥离）
    app(Action::class)->sync($order->id, true);

    // 断言 1：证书链门禁验签通过（真实签发关系）→ chains 表已写入，键 = issuer CN，值为上游中间证书原文
    expect(Chain::where('common_name', $issuerCn)->exists())->toBeTrue(
        "国密 sync 后 chains 表应有 common_name={$issuerCn} 的行"
    );
    expect(Chain::where('common_name', $issuerCn)->value('intermediate_cert'))
        ->toBe($sm2CaPem);

    // 断言 2：刷新 cert，intermediate_cert 访问器能从 chains 透传中间证书；链已写入故 status 保持 active
    app()->forgetInstance('cert.chainMap'); // 清进程内缓存，确保从数据库读
    $reloaded = Cert::find($cert->id);
    expect($reloaded->intermediate_cert)->toBe($sm2CaPem,
        'active 状态国密证书应能经 intermediate_cert 访问器透传中间证书'
    );
    expect($reloaded->status)->toBe('active');

    // 断言 3：enc 字段正常写入（透传完整性）
    expect($reloaded->enc_cert)->toBe('SM2_ENC_CERT_REAL')
        ->and($reloaded->enc_key)->toBe('SM2_ENC_KEY')
        ->and($reloaded->enc_key2)->toBe('SM2_ENC_KEY2');
});
