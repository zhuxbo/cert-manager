<?php

use App\Models\Admin;
use App\Models\Cert;
use App\Models\Chain;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Notification\Builders\CertIssuedNotificationBuilder;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\Exceptions\TransientBuildException;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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

/**
 * @return array{0: User, 1: Order}
 */
function createActiveSmimeOrderForNotification(): array
{
    $user = User::factory()->create();
    $product = Product::factory()->create(['product_type' => Product::TYPE_SMIME]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($key, $privateKey);
    $csr = openssl_csr_new(['commonName' => 'mail@example.test'], $key, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($certificate, $certificatePem);

    $caKey = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    $caCsr = openssl_csr_new(['commonName' => 'Mail Test CA'], $caKey, ['digest_alg' => 'sha256']);
    $caCertificate = openssl_csr_sign($caCsr, null, $caKey, 1, ['digest_alg' => 'sha256']);
    openssl_x509_export($caCertificate, $caPem);
    Chain::create([
        'common_name' => 'Mail Test CA',
        'intermediate_cert' => $caPem,
    ]);
    app()->forgetInstance('cert.chainMap');

    $cert = Cert::factory()->active()->create([
        'order_id' => $order->id,
        'common_name' => 'mail@example.test',
        'issuer' => 'Mail Test CA',
        'cert' => $certificatePem,
        'private_key' => $privateKey,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    return [$user, $order->refresh()];
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

test('SSL 签发通知展示 SSL 类型与证书标识', function () {
    [$user, $order] = createActiveSslOrder();
    $builder = new class extends CertIssuedNotificationBuilder
    {
        protected function addCertToZip(Order $order, ZipArchive $zip, string $tempDir, array $domains = [], string $type = 'all'): string
        {
            $zip->addFromString('cert.txt', 'placeholder');

            return 'cert';
        }
    };
    $intent = new NotificationIntent('cert_issued', 'user', $user->id, ['order_id' => $order->id]);

    $payload = $builder->build($intent, $user);
    $cleanupPath = $payload->data['_meta']['cleanup_paths'][0] ?? null;

    try {
        expect($payload->data['product_type'])->toBe(Product::TYPE_SSL)
            ->and($payload->data['product_type_label'])->toBe('SSL')
            ->and($payload->data['subject'])->toContain('SSL 证书已签发');

        $this->seed(NotificationTemplateSeeder::class);
        $rendered = NotificationTemplate::where('code', 'cert_issued')->firstOrFail()->render($payload->data);
        expect($rendered)
            ->toContain('SSL 证书已成功签发')
            ->toContain($order->latestCert->common_name)
            ->not->toContain('证书类型');
    } finally {
        $cleanupPath && File::deleteDirectory($cleanupPath);
    }
});

test('S/MIME 签发通知生成 PEM PFX 附件且密码只存在 ZIP 的 password.txt', function () {
    [$user, $order] = createActiveSmimeOrderForNotification();
    $intent = new NotificationIntent('cert_issued', 'user', $user->id, ['order_id' => $order->id]);

    $payload = (new CertIssuedNotificationBuilder)->build($intent, $user);
    $attachment = $payload->data['_meta']['attachments'][0] ?? null;
    $cleanupPath = $payload->data['_meta']['cleanup_paths'][0] ?? null;

    try {
        expect($payload->data['has_attachment'])->toBeTrue()
            ->and($payload->data['product_type'])->toBe(Product::TYPE_SMIME)
            ->and($payload->data['product_type_label'])->toBe('S/MIME')
            ->and($payload->data['subject'])->toContain('S/MIME 证书已签发')
            ->and($attachment)->not->toBeNull()
            ->and($payload->transient)->toBe([]);

        $this->seed(NotificationTemplateSeeder::class);
        $rendered = NotificationTemplate::where('code', 'cert_issued')->firstOrFail()->render($payload->data);
        expect($rendered)
            ->toContain('S/MIME 证书已成功签发')
            ->toContain('mail@example.test')
            ->not->toContain('证书类型')
            ->toContain('申请的证书审核通过')
            ->not->toContain('申请的 SSL 证书审核通过')
            ->not->toContain('浏览器')
            ->not->toContain('HTTPS')
            ->not->toContain('域名验证');

        $zip = new ZipArchive;
        expect($zip->open($attachment['path']))->toBeTrue();
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $names[] = $zip->getNameIndex($index);
        }
        sort($names);
        expect($names)->toBe([
            'mail@example.test/pem/mail@example.test.key',
            'mail@example.test/pem/mail@example.test.pem',
            'mail@example.test/pfx/mail@example.test.pfx',
            'mail@example.test/pfx/password.txt',
        ]);
        $passwordFile = $zip->getFromName('mail@example.test/pfx/password.txt');
        expect($passwordFile)->toMatch('/^[ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789]{6}\R$/');
        $password = trim($passwordFile);

        $parsed = [];
        expect(openssl_pkcs12_read(
            $zip->getFromName('mail@example.test/pfx/mail@example.test.pfx'),
            $parsed,
            $password
        ))->toBeTrue();
        $zip->close();
    } finally {
        $cleanupPath && File::deleteDirectory($cleanupPath);
    }
});

test('CodeSign 和 DocSign 旧队列由 Builder 安全跳过', function (string $productType, string $status) {
    $user = User::factory()->create();
    $product = Product::factory()->create(['product_type' => $productType]);
    $order = Order::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
    $cert = Cert::factory()->create([
        'order_id' => $order->id,
        'status' => $status,
    ]);
    $order->update(['latest_cert_id' => $cert->id]);

    $intent = new NotificationIntent('cert_issued', 'user', $user->id, ['order_id' => $order->id]);
    expect((new CertIssuedNotificationBuilder)->build($intent, $user))->toBeNull();
})->with([
    'CodeSign active' => [Product::TYPE_CODESIGN, 'active'],
    'CodeSign processing' => [Product::TYPE_CODESIGN, 'processing'],
    'DocSign active' => [Product::TYPE_DOCSIGN, 'active'],
    'DocSign processing' => [Product::TYPE_DOCSIGN, 'processing'],
]);

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

        protected function addCertToZip(Order $order, ZipArchive $zip, string $tempDir, array $domains = [], string $type = 'all'): string
        {
            $this->capturedTempDir = $tempDir;
            $zip->addFromString('cert.txt', 'x');

            return 'cert';
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

        public ?ZipArchive $capturedZip = null;

        protected function makeZip(): ZipArchive
        {
            $this->capturedZip = new class extends ZipArchive
            {
                public int $closeCalls = 0;

                public function close(): bool
                {
                    $this->closeCalls++;

                    return parent::close();
                }
            };

            return $this->capturedZip;
        }

        protected function addCertToZip(Order $order, ZipArchive $zip, string $tempDir, array $domains = [], string $type = 'all'): string
        {
            $this->capturedTempDir = $tempDir;
            throw new TransientBuildException('disk full during addFromString');
        }
    };

    $intent = new NotificationIntent('cert_issued', 'user', $user->id, ['order_id' => $order->id]);

    expect(fn () => $builder->build($intent, $user))->toThrow(TransientBuildException::class);

    expect($builder->capturedTempDir)->not->toBeNull()
        ->and(is_dir($builder->capturedTempDir))->toBeFalse()
        ->and($builder->capturedZip)->not->toBeNull()
        ->and($builder->capturedZip->closeCalls)->toBe(1);
});
