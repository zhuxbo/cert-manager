<?php

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\DeployerInterface;
use Plugins\CloudDeploy\Deployers\Registry;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 华为云 registry 自检：把 registry/huaweicloud.php 的 Closure 应用到一个**全新** Registry（不依赖
 * CloudDeployServiceProvider 的装配数组——该数组是禁改的共享文件，本插件波次尚未把 'huaweicloud' 加入），
 * 断言 provider + 9 端点全部注册、元信息/configSchema/credentialSchema 合法、catalog 输出正确。
 *
 * 与全局 RegistryCompletenessTest（遍历 app(Registry::class)、期望集写死、当前不含华为云）互补：
 * 待主系统把 'huaweicloud' 加入 ServiceProvider 装配数组并把期望集补上 9 端点后，本测试可与之合并。
 */
function huaweicloudRegistry(): Registry
{
    $registry = new Registry;
    (require __DIR__.'/../../../../Deployers/registry/huaweicloud.php')($registry);

    return $registry;
}

/** 期望的 9 端点。 */
function huaweicloudExpectedProducts(): array
{
    return ['scm', 'cdn', 'elb', 'waf', 'live', 'obs', 'apig', 'aad', 'vod'];
}

test('provider huaweicloud 注册 + credentialSchema 合法（AK/SK + 选填企业项目）', function () {
    $registry = huaweicloudRegistry();

    expect($registry->hasProvider('huaweicloud'))->toBeTrue();
    $p = $registry->resolveProvider('huaweicloud');
    expect($p->key())->toBe('huaweicloud');
    expect($p->label())->toBe('华为云');

    $keys = array_column($p->credentialSchema(), 'key');
    expect($keys)->toContain('access_key_id')->toContain('secret_access_key')->toContain('enterprise_project_id');

    foreach ($p->credentialSchema() as $field) {
        expect($field)->toHaveKeys(['key', 'label']);
    }
});

test('9 端点全部注册且元信息 + configSchema 合法', function () {
    $registry = huaweicloudRegistry();

    $actual = [];
    foreach ($registry->allDeployers() as ['provider' => $prov, 'product' => $prod]) {
        if ($prov === 'huaweicloud') {
            $actual[] = $prod;
        }
    }
    sort($actual);
    $expected = huaweicloudExpectedProducts();
    sort($expected);
    expect($actual)->toEqual($expected);

    foreach (huaweicloudExpectedProducts() as $product) {
        $label = "huaweicloud.$product";
        expect($registry->hasDeployer('huaweicloud', $product))->toBeTrue("应注册 $label");

        $deployer = $registry->resolveDeployer('huaweicloud', $product);
        expect($deployer)->toBeInstanceOf(DeployerInterface::class);
        expect($deployer)->toBeInstanceOf(AbstractDeployer::class);
        expect($deployer->provider())->toBe('huaweicloud', "$label provider()");
        expect($deployer->product())->toBe($product, "$label product()");
        expect($deployer->label())->toBeString()->not->toBe('');

        // 证书服务型 → certUploader 非 null + storeKind 非空（空 config 探活不抛）
        expect($deployer->usesRemoteCertStore())->toBeBool();
        if ($deployer->usesRemoteCertStore()) {
            $uploader = $deployer->certUploader([]);
            expect($uploader)->not->toBeNull("$label 证书服务型应有 certUploader");
            expect($uploader->storeKind())->toBeString()->not->toBe('');
        }

        // configSchema 合法 list，每项 key/label 非空、key 唯一
        $schema = $deployer->configSchema();
        expect(array_is_list($schema))->toBeTrue("$label configSchema 应为 list");
        $seen = [];
        foreach ($schema as $i => $field) {
            expect($field)->toHaveKeys(['key', 'label'], "$label configSchema[$i]");
            expect($field['key'])->toBeString()->not->toBe('');
            expect($seen)->not->toContain($field['key'], "$label key 重复");
            $seen[] = $field['key'];
        }
    }
});

test('catalog 输出含华为云 provider + 9 products', function () {
    $registry = huaweicloudRegistry();
    $catalog = $registry->catalog();

    $entry = null;
    foreach ($catalog['providers'] as $providerEntry) {
        if ($providerEntry['key'] === 'huaweicloud') {
            $entry = $providerEntry;
            break;
        }
    }
    expect($entry)->not->toBeNull();
    expect($entry)->toHaveKeys(['key', 'label', 'credentialSchema', 'products']);

    $products = array_column($entry['products'], 'product');
    sort($products);
    $expected = huaweicloudExpectedProducts();
    sort($expected);
    expect($products)->toEqual($expected);
});

test('证书服务型 storeKind 隔离：scm 全局、elb/waf region 维度', function () {
    $registry = huaweicloudRegistry();

    // SCM 系（scm/cdn/live/obs/vod）共用 huawei_scm
    expect($registry->resolveDeployer('huaweicloud', 'scm')->certUploader([])->storeKind())->toBe('huawei_scm');
    expect($registry->resolveDeployer('huaweicloud', 'cdn')->certUploader([])->storeKind())->toBe('huawei_scm');
    expect($registry->resolveDeployer('huaweicloud', 'live')->certUploader([])->storeKind())->toBe('huawei_scm');
    expect($registry->resolveDeployer('huaweicloud', 'obs')->certUploader([])->storeKind())->toBe('huawei_scm');
    expect($registry->resolveDeployer('huaweicloud', 'vod')->certUploader([])->storeKind())->toBe('huawei_scm');

    // ELB / WAF region 维度
    expect($registry->resolveDeployer('huaweicloud', 'elb')->certUploader(['region' => 'cn-east-3'])->storeKind())->toBe('huawei_elb:cn-east-3');
    expect($registry->resolveDeployer('huaweicloud', 'waf')->certUploader(['region' => 'cn-east-3'])->storeKind())->toBe('huawei_waf:cn-east-3');

    // 空 config 探活：region 维度回落 default（不抛）
    expect($registry->resolveDeployer('huaweicloud', 'elb')->certUploader([])->storeKind())->toBe('huawei_elb:default');
    expect($registry->resolveDeployer('huaweicloud', 'waf')->certUploader([])->storeKind())->toBe('huawei_waf:default');

    // 内联型（apig/aad）无 uploader
    expect($registry->resolveDeployer('huaweicloud', 'apig')->usesRemoteCertStore())->toBeFalse();
    expect($registry->resolveDeployer('huaweicloud', 'apig')->certUploader([]))->toBeNull();
    expect($registry->resolveDeployer('huaweicloud', 'aad')->usesRemoteCertStore())->toBeFalse();
    expect($registry->resolveDeployer('huaweicloud', 'aad')->certUploader([]))->toBeNull();
});
