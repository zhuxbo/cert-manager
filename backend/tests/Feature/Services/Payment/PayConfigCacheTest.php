<?php

use App\Models\Admin;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Models\User;
use App\Services\Payment\PayConfigCache;
use Illuminate\Support\Facades\Cache;
use Tests\Traits\ActsAsAdmin;
use Tests\Traits\ActsAsUser;

uses(ActsAsAdmin::class, ActsAsUser::class);

// 隔离自检放 beforeEach：本文件多个用例在造 Setting 时就会经
// Setting::saved → clearGroupCache → PayConfigCache::forget 删证书，
// 自检若只放在 writeFakePayCerts() 里就来不及守（隔离被摘掉时真实证书已被删）。
beforeEach(function () {
    expect(storage_path())->toContain('framework/testing/worker-');
});

/**
 * 造出 storage/pay 下的落盘证书。
 *
 * 依赖 TestCase::isolateWorkerStorage()：storage 在并行与单进程下都重定向到本进程专属
 * 目录，故这里写/删的是隔离目录，绝不碰开发环境的真实支付证书（隔离本身由
 * TestCaseStorageIsolationTest 守门）。
 *
 * @return array<string, string> 文件名 => 绝对路径
 */
function writeFakePayCerts(): array
{
    // 断言隔离真的生效，避免哪天隔离被摘掉时这个测试静默去删真实证书
    expect(storage_path())->toContain('framework/testing/worker-');

    $files = [
        'alipayAppCertPublicKey.crt',
        'alipayCertPublicKeyRSA2.crt',
        'alipayRootCert.crt',
        'wechatApiclientKey.pem',
        'wechatApiclientCert.pem',
        'wechatPublicKey.pem',
    ];

    if (! is_dir(storage_path('pay'))) {
        mkdir(storage_path('pay'), 0755, true);
    }

    $paths = [];
    foreach ($files as $file) {
        $path = storage_path('pay/'.$file);
        file_put_contents($path, 'fake-'.$file);
        $paths[$file] = $path;
    }

    return $paths;
}

function payCertExists(string $file): bool
{
    return is_file(storage_path('pay/'.$file));
}

test('forget 只清指定支付类型的缓存与落盘证书', function () {
    writeFakePayCerts();
    Cache::put('pay_config_wechat', ['from' => 'cache'], now()->addDay());
    Cache::put('pay_config_alipay', ['from' => 'cache'], now()->addDay());

    PayConfigCache::forget('wechat');

    expect(payCertExists('wechatApiclientKey.pem'))->toBeFalse()
        ->and(payCertExists('wechatApiclientCert.pem'))->toBeFalse()
        ->and(payCertExists('wechatPublicKey.pem'))->toBeFalse()
        ->and(Cache::has('pay_config_wechat'))->toBeFalse()
        // 支付宝一侧不受影响
        ->and(payCertExists('alipayAppCertPublicKey.crt'))->toBeTrue()
        ->and(payCertExists('alipayRootCert.crt'))->toBeTrue()
        ->and(Cache::has('pay_config_alipay'))->toBeTrue();
});

test('forget 传非支付类型时不动任何证书', function () {
    writeFakePayCerts();

    PayConfigCache::forget('site');

    expect(payCertExists('wechatPublicKey.pem'))->toBeTrue()
        ->and(payCertExists('alipayRootCert.crt'))->toBeTrue();
});

test('forgetAll 清掉两类支付的证书与缓存', function () {
    writeFakePayCerts();
    Cache::put('pay_config_wechat', ['from' => 'cache'], now()->addDay());
    Cache::put('pay_config_alipay', ['from' => 'cache'], now()->addDay());

    PayConfigCache::forgetAll();

    expect(payCertExists('wechatPublicKey.pem'))->toBeFalse()
        ->and(payCertExists('alipayRootCert.crt'))->toBeFalse()
        ->and(Cache::has('pay_config_wechat'))->toBeFalse()
        ->and(Cache::has('pay_config_alipay'))->toBeFalse();
});

test('保存微信支付设置后落盘证书同步失效（配置变动即失效）', function () {
    $group = SettingGroup::factory()->create(['name' => 'wechat', 'title' => '微信支付']);
    $setting = Setting::factory()->create([
        'group_id' => $group->id,
        'key' => 'publicKey',
        'type' => 'base64',
        'value' => 'old-public-key',
    ]);

    writeFakePayCerts();
    Cache::put('pay_config_wechat', ['from' => 'cache'], now()->addDay());

    // 换新公钥：Setting::saved → clearGroupCache → PayConfigCache::forget('wechat')
    $setting->update(['value' => 'new-public-key']);

    expect(payCertExists('wechatPublicKey.pem'))->toBeFalse()
        ->and(payCertExists('wechatApiclientCert.pem'))->toBeFalse()
        ->and(Cache::has('pay_config_wechat'))->toBeFalse()
        // 非支付组的证书不该被牵连
        ->and(payCertExists('alipayRootCert.crt'))->toBeTrue();
});

test('保存非支付设置组不动支付证书', function () {
    $group = SettingGroup::factory()->create(['name' => 'site', 'title' => '站点']);
    $setting = Setting::factory()->create([
        'group_id' => $group->id,
        'key' => 'siteName',
        'type' => 'string',
        'value' => 'old',
    ]);

    writeFakePayCerts();

    $setting->update(['value' => 'new']);

    expect(payCertExists('wechatPublicKey.pem'))->toBeTrue()
        ->and(payCertExists('alipayRootCert.crt'))->toBeTrue();
});

test('admin 清理支付缓存端点按 type 生效', function () {
    $admin = Admin::factory()->create();
    writeFakePayCerts();

    $this->actingAsAdmin($admin)
        ->postJson('/api/admin/setting/clear-pay-cache', ['type' => 'alipay'])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(payCertExists('alipayAppCertPublicKey.crt'))->toBeFalse()
        ->and(payCertExists('alipayRootCert.crt'))->toBeFalse()
        ->and(payCertExists('wechatPublicKey.pem'))->toBeTrue();
});

test('admin 清理支付缓存端点不传 type 清全部', function () {
    $admin = Admin::factory()->create();
    writeFakePayCerts();

    $this->actingAsAdmin($admin)
        ->postJson('/api/admin/setting/clear-pay-cache')
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(payCertExists('alipayRootCert.crt'))->toBeFalse()
        ->and(payCertExists('wechatPublicKey.pem'))->toBeFalse();
});

test('admin 清理支付缓存端点拒绝非法 type', function () {
    $admin = Admin::factory()->create();
    writeFakePayCerts();

    $this->actingAsAdmin($admin)
        ->postJson('/api/admin/setting/clear-pay-cache', ['type' => 'stripe'])
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect(payCertExists('alipayRootCert.crt'))->toBeTrue()
        ->and(payCertExists('wechatPublicKey.pem'))->toBeTrue();
});

test('清理支付缓存端点未认证不可达且不删证书', function () {
    writeFakePayCerts();

    $this->postJson('/api/admin/setting/clear-pay-cache')->assertUnauthorized();

    expect(payCertExists('alipayRootCert.crt'))->toBeTrue()
        ->and(payCertExists('wechatPublicKey.pem'))->toBeTrue();
});

test('清理支付缓存端点拒绝普通用户 token 且不删证书', function () {
    $user = User::factory()->create();
    writeFakePayCerts();

    $this->actingAsUser($user)
        ->postJson('/api/admin/setting/clear-pay-cache')
        ->assertUnauthorized();

    expect(payCertExists('alipayRootCert.crt'))->toBeTrue()
        ->and(payCertExists('wechatPublicKey.pem'))->toBeTrue();
});

test('清除设置缓存端点会连带清掉支付证书（语义上锁）', function () {
    // Setting::clearAllCache 遍历所有设置组 → 支付组走 PayConfigCache::forget，
    // 故"清除设置缓存"也会删落盘证书（下次支付按当前设置重建）。行为有意，锁死防回归。
    $admin = Admin::factory()->create();
    SettingGroup::factory()->create(['name' => 'wechat', 'title' => '微信支付']);
    SettingGroup::factory()->create(['name' => 'alipay', 'title' => '支付宝']);
    writeFakePayCerts();

    $this->actingAsAdmin($admin)
        ->postJson('/api/admin/setting/clear-cache')
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(payCertExists('wechatPublicKey.pem'))->toBeFalse()
        ->and(payCertExists('alipayRootCert.crt'))->toBeFalse();
});

test('落盘证书只有 PaymentConfigTrait 写、且清单与 PayConfigCache 等价（对称副本守卫）', function () {
    // PayConfigCache 负责删、PaymentConfigTrait::getPayConfig 负责写：写得出而删不掉的
    // 文件会让"换了证书仍读旧文件"复发。此处按源码字面量做等价校验，不靠注释提醒同步。
    // 先钉死写入点只有 trait 一处，否则等价校验的扫描面就漏了新落盘点。
    $writeSites = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (preg_match("/storage_path\(\s*['\"]pay/", $source) === 1) {
            $writeSites[] = str_replace(base_path('app').'/', '', $file->getPathname());
        }
    }
    sort($writeSites);

    expect($writeSites)->toBe([
        'Http/Traits/PaymentConfigTrait.php',
        'Services/Payment/PayConfigCache.php',
    ]);

    $traitSource = (string) file_get_contents(base_path('app/Http/Traits/PaymentConfigTrait.php'));
    preg_match_all("/['\"]([A-Za-z0-9_-]+\.(?:crt|pem))['\"]/", $traitSource, $matches);
    $written = array_values(array_unique($matches[1]));

    $reflection = new ReflectionClass(PayConfigCache::class);
    /** @var array<string, list<string>> $certFiles */
    $certFiles = $reflection->getConstant('CERT_FILES');
    $deleted = array_merge(...array_values($certFiles));

    sort($written);
    sort($deleted);

    expect($written)->not->toBeEmpty()
        ->and($deleted)->toBe($written);
});

test('用户端旧支付缓存清理入口已下线', function () {
    $user = User::factory()->create();
    writeFakePayCerts();

    $this->actingAsUser($user)
        ->postJson('/api/top-up/clear-cache')
        ->assertNotFound();

    $this->actingAsUser($user)
        ->getJson('/api/top-up/clear-pay-config')
        ->assertNotFound();

    expect(payCertExists('alipayRootCert.crt'))->toBeTrue()
        ->and(payCertExists('wechatPublicKey.pem'))->toBeTrue();
});
