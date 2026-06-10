<?php

use App\Models\Cert;
use App\Models\Chain;
use App\Services\Order\Action;
use App\Services\Order\Api\Api;
use Tests\Traits\CreatesTestData;

uses(CreatesTestData::class);

/**
 * 国密(SM2)证书 sync：上游返回非空 intermediate_cert 时应在签发轮确定性写入 chains 表。
 *
 * 缺陷复现：$cert->update($data) 的 Eloquent fill 顺序不保证 issuer 早于 intermediate_cert。
 * 若上游响应里 intermediate_cert 键排在 cert/issuer 之前，setIntermediateCertAttribute 被触发时
 * $this->issuer 仍为空 → 跳过写 chains。普通证书靠 Cert::retrieved「降级 approving→次轮补写」兜底，
 * 但国密证书（enc_cert 非空）已短路 retrieved 钩子，必须在本轮确定性入 chains，否则中间证书永不入库。
 */
test('sync 国密证书收到非空 intermediate_cert 时在签发轮即写入 chains 并可透传', function () {
    // 国密 sm2_cert.pem：issuer CN 动态读取，不硬编码
    $sm2CertPem = file_get_contents(base_path('tests/Fixtures/sm2_cert.pem'));
    $issuerCn = openssl_x509_parse($sm2CertPem)['issuer']['CN'];

    // 中间证书 PEM：用 sm2_cert.pem 内容作占位（任意合法非空 PEM 即可，断言比对原文）
    $intermediatePem = $sm2CertPem;

    // chains 表此刻应无该 issuer 行（确保断言变化由本次 sync 引入）
    expect(Chain::where('common_name', $issuerCn)->exists())->toBeFalse();

    $user = $this->createTestUser(['balance' => '100.00']);
    $product = $this->createTestProduct(['source' => 'default']);
    $order = $this->createTestOrder($user, $product, ['amount' => '100.00']);

    // 国密订单：enc_cert 非空 = 标识国密透传证书（短路 retrieved 钩子）；status=processing、有 api_id
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
            'intermediate_cert' => $intermediatePem,
            'cert' => $sm2CertPem,
            'enc_cert' => 'SM2_ENC_CERT_REAL',
            'enc_key' => 'SM2_ENC_KEY',
            'enc_key2' => 'SM2_ENC_KEY2',
            'status' => 'active',
        ],
    ]);
    app()->instance(Api::class, $mock);

    // sync：force=true 模拟 V2 get 真实路径（国密 enc_cert 非终态不剥离）
    app(Action::class)->sync($order->id, true);

    // 断言 1：chains 表已写入，键 = issuer CN，值为上游中间证书原文
    expect(Chain::where('common_name', $issuerCn)->exists())->toBeTrue(
        "国密 sync 后 chains 表应有 common_name={$issuerCn} 的行"
    );
    expect(Chain::where('common_name', $issuerCn)->value('intermediate_cert'))
        ->toBe($intermediatePem);

    // 断言 2：刷新 cert，intermediate_cert 访问器能从 chains 透传中间证书
    // 国密（enc_cert 非空）短路 retrieved 钩子，status 不被降级为 approving
    app()->forgetInstance('cert.chainMap'); // 清进程内缓存，确保从数据库读
    $reloaded = Cert::find($cert->id);
    expect($reloaded->intermediate_cert)->toBe($intermediatePem,
        'active 状态国密证书应能经 intermediate_cert 访问器透传中间证书'
    );
    expect($reloaded->status)->toBe('active');

    // 断言 3：enc 字段正常写入（透传完整性）
    expect($reloaded->enc_cert)->toBe('SM2_ENC_CERT_REAL')
        ->and($reloaded->enc_key)->toBe('SM2_ENC_KEY')
        ->and($reloaded->enc_key2)->toBe('SM2_ENC_KEY2');
});
