<?php

use App\Models\Chain;
use App\Services\Order\Action;
use Tests\Traits\CreatesTestData;

uses(CreatesTestData::class);

// B5：下载建包相位（mkdir 之后、downFlow 之前）若抛异常（如 addCertToZip 内 SM2 openssl 缺失），
// downFlow 不会被调用、其内部 exit 前的 deleteDirectory 也跑不到，tempDir（含私钥）泄漏。
// download/downloadValidateFile 用 try/finally 包裹建包相位，finally 删除残留 tempDir。
test('下载建包相位抛异常时 finally 清理残留 tempDir（不泄漏私钥）', function () {
    $user = $this->createTestUser();
    $product = $this->createTestProduct();
    $order = $this->createTestOrder($user, $product);

    // intermediate_cert 是基于 Chain 表（按 issuer）的虚拟访问器：直接建 Chain 行 + cert.issuer
    // 让 download() 的 `! empty(intermediate_cert)` 过滤通过（否则 orders 为空、download 走 exit）
    Chain::create([
        'common_name' => 'Test Intermediate CA',
        'intermediate_cert' => "-----BEGIN CERTIFICATE-----\nINTER\n-----END CERTIFICATE-----",
    ]);
    $this->createTestCert($order, [
        'status' => 'active',
        'issuer' => 'Test Intermediate CA',
        'cert' => "-----BEGIN CERTIFICATE-----\nLEAF\n-----END CERTIFICATE-----",
        'private_key' => "-----BEGIN PRIVATE KEY-----\nSECRET\n-----END PRIVATE KEY-----",
    ]);

    // 确定性注入（评审 N-1）：不依赖 mt_srand 对齐预测目录名——mock 的 addCertToZip 直接捕获
    // download() 实际创建的 tempDir（第 3 参），并在抛异常前断言目录确实已创建（mkdir 已执行）。
    // 失配/未创建时此处即红，杜绝「预测目录从未创建 → is_dir 恒 false → 假绿空过」。
    // 只断言捕获到的实际目录生命周期，天然不受 paratest 跨 worker 其他下载目录影响（反模式 14）。
    $capturedTempDir = null;
    $action = Mockery::mock(Action::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $action->shouldReceive('addCertToZip')
        ->andReturnUsing(function ($order, $zip, $tempDir) use (&$capturedTempDir) {
            $capturedTempDir = $tempDir;
            // 前置断言：抛异常时刻 tempDir 确实存在（建包相位已进入、mkdir 已执行）
            expect(is_dir($tempDir))->toBeTrue();
            throw new RuntimeException('build phase boom');
        });

    try {
        $action->download($order->id, 'all');
        $this->fail('期望建包相位抛出异常');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('build phase boom');
    }

    // 建包相位确实被走到（mock 被调用、tempDir 已捕获）
    expect($capturedTempDir)->not->toBeNull();

    // finally 已清理建包相位残留的实际 tempDir（含私钥）
    expect(is_dir($capturedTempDir))->toBeFalse();
});
