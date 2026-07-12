<?php

use App\Models\Admin;
use App\Models\Cert;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Notification\Builders\CertIssuedNotificationBuilder;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\Exceptions\TransientBuildException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('database');

afterEach(function () {
    Mockery::close();
});

/**
 * 创建 SSL 产品的已签发订单（latestCert active），供 build() 的 Order 查询与附件生成路径使用。
 * build() 内 Order::whereHas(latestCert active) 无注入缝，只能靠真实夹具走通。
 *
 * @return array{0: User, 1: Order}
 */
function createActiveSslOrder(): array
{
    $user = User::factory()->create();
    $product = Product::factory()->create(); // product_type=ssl（默认）→ hasAttachment=true
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);
    $order->refresh();

    return [$user, $order];
}

test('接收者非 User 时抛出异常', function () {
    $builder = new CertIssuedNotificationBuilder;
    $intent = new NotificationIntent('cert_issued', 'admin', 1, ['order_id' => 1]);

    $admin = Mockery::mock(Admin::class)->makePartial();

    $builder->build($intent, $admin);
})->throws(RuntimeException::class, '通知接收者必须为用户');

test('order_id 缺失时抛出异常', function () {
    $builder = new CertIssuedNotificationBuilder;
    $intent = new NotificationIntent('cert_issued', 'user', 1, []);

    $user = Mockery::mock(User::class)->makePartial();

    $builder->build($intent, $user);
})->throws(RuntimeException::class, '订单ID不存在');

test('order_id 为 0 时抛出异常', function () {
    $builder = new CertIssuedNotificationBuilder;
    $intent = new NotificationIntent('cert_issued', 'user', 1, ['order_id' => 0]);

    $user = Mockery::mock(User::class)->makePartial();

    $builder->build($intent, $user);
})->throws(RuntimeException::class, '订单ID不存在');

test('包V：ZipArchive close() 返 false → 抛 TransientBuildException 且 tempDir 已清理', function () {
    [$user, $order] = createActiveSslOrder();

    // 子类：makeZip 返回 close() 恒 false 的 ZipArchive 桩（模拟磁盘满写盘期静默失败）；
    // addCertToZip 捕获 tempDir 并加占位条目让 parent::close() 干净 finalize，隔离对 close() 返回值检查的验证。
    $builder = new class extends CertIssuedNotificationBuilder
    {
        public ?string $capturedTempDir = null;

        protected function makeZip(): ZipArchive
        {
            return new class extends ZipArchive
            {
                public function close(): bool
                {
                    parent::close(); // 真正 finalize（有占位条目、写盘成功、无悬挂资源）

                    return false; // 模拟磁盘满写盘期静默返回 false
                }
            };
        }

        protected function addCertToZip(Order $order, ZipArchive $zip, string $tempDir, array $domains = [], string $type = 'all'): void
        {
            $this->capturedTempDir = $tempDir;
            $zip->addFromString('cert.txt', 'x');
        }
    };

    $intent = new NotificationIntent('cert_issued', 'user', $user->id, ['order_id' => $order->id]);

    // 现码检查 close() !== true → 抛瞬态；旧码不检查会静默产残包（此断言在旧码下翻红，防伪绿）
    expect(fn () => $builder->build($intent, $user))->toThrow(TransientBuildException::class);

    // catch 自清 tempDir（含私钥半成品不泄漏）
    expect($builder->capturedTempDir)->not->toBeNull()
        ->and(is_dir($builder->capturedTempDir))->toBeFalse();
});

test('包V：addCertToZip 抛异常 → 抛 TransientBuildException 且 tempDir 已清理', function () {
    [$user, $order] = createActiveSslOrder();

    // 子类覆写 addCertToZip：捕获真实 tempDir 后抛异常 → 真 mkdir 建目录、build catch 真 deleteDirectory 清、重抛瞬态
    $builder = new class extends CertIssuedNotificationBuilder
    {
        public ?string $capturedTempDir = null;

        protected function addCertToZip(Order $order, ZipArchive $zip, string $tempDir, array $domains = [], string $type = 'all'): void
        {
            $this->capturedTempDir = $tempDir;
            throw new RuntimeException('disk full during addFromString');
        }
    };

    $intent = new NotificationIntent('cert_issued', 'user', $user->id, ['order_id' => $order->id]);

    expect(fn () => $builder->build($intent, $user))->toThrow(TransientBuildException::class);

    expect($builder->capturedTempDir)->not->toBeNull()
        ->and(is_dir($builder->capturedTempDir))->toBeFalse();
});
