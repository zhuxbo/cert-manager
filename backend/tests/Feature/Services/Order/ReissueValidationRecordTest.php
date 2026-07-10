<?php

use App\Models\Cert;
use App\Models\DomainValidationRecord;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Tests\Traits\ActsAsUser;
use Tests\Traits\CreatesTestData;
use Tests\Traits\MocksExternalApis;

uses(ActsAsUser::class, MocksExternalApis::class, CreatesTestData::class);

// B4：reissue 复用同一 order_id，旧 DomainValidationRecord 的 created_at 为原签发时间，
// 会让 ValidateCommand 的验证节奏（以 created_at 为锚）直接落 12 小时档。Action::reissue
// 事务内删除旧记录，ValidateCommand 在新 cert 进 processing 时重建 created_at=now 恢复快档。
test('reissue 删除该订单旧的 DomainValidationRecord（恢复验证快档）', function () {
    $user = User::factory()->withBalance('1000.00')->create();
    $product = Product::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    $cert = Cert::factory()->active()->create(['order_id' => $order->id]);
    $order->update(['latest_cert_id' => $cert->id]);

    // 旧的域名验证记录（模拟数月前原始签发时的验证记录）
    $record = DomainValidationRecord::create([
        'order_id' => $order->id,
        'last_check_at' => now()->subMonths(2),
        'next_check_at' => now()->subMonths(2)->addMinutes(720),
    ]);
    DomainValidationRecord::where('id', $record->id)
        ->update(['created_at' => now()->subMonths(2)]);

    // 另一订单的验证记录：不应被误删（删除必须按 order_id 精确限定）
    $otherOrder = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    $otherRecord = DomainValidationRecord::create([
        'order_id' => $otherOrder->id,
        'last_check_at' => now()->subMonths(2),
        'next_check_at' => now()->subMonths(2)->addMinutes(720),
    ]);

    $this->mockSdk();

    $this->actingAsUser($user)
        ->postJson('/api/order/reissue', [
            'order_id' => $order->id,
            'domains' => 'example.com',
            'validation_method' => 'txt',
            'csr_generate' => 1,
        ])
        ->assertOk()
        ->assertJson(['code' => 1]);

    // reissue 后本订单旧记录被删（事务内）
    expect(DomainValidationRecord::where('order_id', $order->id)->exists())->toBeFalse();

    // 另一订单记录不受影响
    expect(DomainValidationRecord::where('id', $otherRecord->id)->exists())->toBeTrue();
});
