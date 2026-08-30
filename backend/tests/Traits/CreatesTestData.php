<?php

namespace Tests\Traits;

use App\Models\Cert;
use App\Models\CnameDelegation;
use App\Models\Fund;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\User;
use App\Services\Delegation\DelegationConfigService;
use Illuminate\Support\Facades\DB;

/**
 * 测试数据创建辅助 Trait
 */
trait CreatesTestData
{
    /**
     * 创建测试用户（balance 走 Fund::create 钩子链而非直写，满足资金审计）
     */
    protected function createTestUser(array $overrides = []): User
    {
        $email = $overrides['email'] ?? uniqid().'@test.com';
        unset($overrides['email']);

        $balance = $overrides['balance'] ?? '100.00';
        // user 创建时 balance=0，由后续 Fund::create 钩子加到目标值
        $defaults = [
            'username' => 'test_'.uniqid(),
            'password' => 'password',
            'join_at' => now(),
            'balance' => '0.00',
            'level_code' => 'standard',
            'auto_settings' => ['auto_renew' => false, 'auto_reissue' => false],
        ];
        unset($overrides['balance']);

        $user = User::firstOrCreate(
            ['email' => $email],
            array_merge($defaults, $overrides)
        );

        if (bccomp((string) $balance, (string) $user->balance, 2) !== 0) {
            $delta = bcsub((string) $balance, (string) $user->balance, 2);
            $isAdd = bccomp($delta, '0', 2) > 0;
            DB::transaction(fn () => Fund::create([
                'user_id' => $user->id,
                'amount' => $isAdd ? $delta : bcsub('0', $delta, 2),
                'type' => $isAdd ? 'addfunds' : 'deduct',
                'pay_method' => 'admin',
                'pay_sn' => null,
                'remark' => 'createTestUser',
                'status' => 1,
            ]));
            $user->refresh();
        }

        return $user;
    }

    /**
     * 创建测试产品
     */
    protected function createTestProduct(array $overrides = []): Product
    {
        return Product::factory()->create($overrides);
    }

    /**
     * 创建测试订单
     */
    protected function createTestOrder(User $user, Product $product, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'brand' => $product->brand ?? 'Test Brand',
            'period' => $overrides['period'] ?? 12,
            'amount' => $overrides['amount'] ?? '100.00',
            'period_from' => $overrides['period_from'] ?? now(),
            'period_till' => $overrides['period_till'] ?? now()->addYear(),
        ], $overrides));
    }

    /**
     * 创建测试证书
     */
    protected function createTestCert(Order $order, array $overrides = []): Cert
    {
        $cert = Cert::create(array_merge([
            'order_id' => $order->id,
            'action' => $overrides['action'] ?? 'new',
            'channel' => $overrides['channel'] ?? 'api',
            'status' => $overrides['status'] ?? 'pending',
            'common_name' => $overrides['common_name'] ?? 'example.com',
            'alternative_names' => $overrides['alternative_names'] ?? 'example.com',
            'standard_count' => $overrides['standard_count'] ?? 1,
            'wildcard_count' => $overrides['wildcard_count'] ?? 0,
            'csr' => $overrides['csr'] ?? $this->generateTestCsr(),
            'dcv' => $overrides['dcv'] ?? ['method' => 'txt', 'dns' => ['host' => '_dnsauth']],
            'validation' => $overrides['validation'] ?? [],
            'expires_at' => $overrides['expires_at'] ?? now()->addDays(90),
        ], $overrides));

        // 更新订单的 latest_cert_id
        $order->update(['latest_cert_id' => $cert->id]);

        return $cert;
    }

    /**
     * 创建测试委托记录
     */
    protected function createTestDelegation(User $user, array $overrides = []): CnameDelegation
    {
        $zone = $overrides['zone'] ?? 'example.com';
        $prefix = $overrides['prefix'] ?? '_dnsauth';

        return CnameDelegation::create(array_merge([
            'user_id' => $user->id,
            'zone' => $zone,
            'prefix' => $prefix,
            'label' => substr(hash('sha256', "$user->id:$prefix.$zone"), 0, 32),
            'proxy_domain' => 'proxy.example.com',
            'valid' => true,
            'fail_count' => 0,
            'last_error' => '',
        ], $overrides));
    }

    /**
     * 建立可供真实委托创建链路读取的默认代理域配置。
     */
    protected function configureTestDelegationProxyDomain(string $domain = 'proxy.example.com'): void
    {
        $configService = app(DelegationConfigService::class);
        $domain = $configService->normalizeDomain($domain);
        $group = SettingGroup::firstOrCreate(
            ['name' => 'delegation'],
            ['title' => '委托设置', 'description' => null, 'weight' => 1],
        );

        Setting::updateOrCreate(
            ['group_id' => $group->id, 'key' => 'defaultDomain'],
            [
                'type' => 'string',
                'options' => null,
                'is_multiple' => false,
                'value' => $domain,
                'description' => '默认代理域',
                'weight' => 1,
            ],
        );
        Setting::updateOrCreate(
            ['group_id' => $group->id, 'key' => $configService->keyForDomain($domain)],
            [
                'type' => 'array',
                'options' => null,
                'is_multiple' => false,
                'value' => [
                    'domain' => $domain,
                    'provider' => 'cloudflare',
                    'apiToken' => 'test-token',
                    'zoneId' => 'test-zone',
                ],
                'description' => '测试委托代理域',
                'weight' => 2,
            ],
        );
        Setting::clearGroupCache($group->id);
    }

    /**
     * 生成测试用 CSR
     */
    protected function generateTestCsr(string $commonName = 'example.com'): string
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        $csr = openssl_csr_new([
            'commonName' => $commonName,
            'countryName' => 'CN',
            'stateOrProvinceName' => 'Shanghai',
            'localityName' => 'Shanghai',
        ], $privateKey);

        openssl_csr_export($csr, $csrOut);

        return $csrOut;
    }

    /**
     * 生成自签名证书（用于测试 finalize 等需要证书数据的场景）
     */
    protected function generateSelfSignedCert(string $commonName = 'example.com'): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        $csr = openssl_csr_new([
            'commonName' => $commonName,
            'countryName' => 'CN',
            'stateOrProvinceName' => 'Shanghai',
            'localityName' => 'Shanghai',
        ], $privateKey);

        $cert = openssl_csr_sign($csr, null, $privateKey, 365);

        openssl_x509_export($cert, $certOut);
        openssl_csr_export($csr, $csrOut);

        return [
            'certificate' => $certOut,
            'chain' => $certOut,
        ];
    }

    /**
     * PEM 转 DER 格式
     */
    protected function pemToDer(string $pem): string
    {
        $pem = preg_replace('/-----[A-Z ]+-----/', '', $pem);
        $pem = str_replace(["\r", "\n", ' '], '', $pem);

        return base64_decode($pem);
    }
}
