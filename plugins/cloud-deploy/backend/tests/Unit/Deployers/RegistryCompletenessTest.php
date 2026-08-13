<?php

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\DeployerInterface;
use Plugins\CloudDeploy\Deployers\Contracts\UploadOnlyDeployerInterface;
use Plugins\CloudDeploy\Deployers\Registry;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Registry 完备性：遍历**真实**注册表（经 ServiceProvider 装配的 app(Registry::class)），
 * 系统性断言当前全量注册端点的元信息 / configSchema / credentialSchema 完整、
 * 且 catalog() 输出与实际注册集逐键一致。新增/删除端点忘了同步注册或 schema 漂移在此一律暴露。
 *
 * 期望集**显式写死**（非从 registry 反推），这样：
 *   - 漏注册某端点 → 期望集多出该 key，resolveDeployer 断言失败
 *   - 误删/改名某端点 → allDeployers() 多/少 key，与期望集 diff 失败
 *   - 数量错位 → count 断言失败
 */

/** 期望注册的全部 (provider → products)。改动端点必须同步这里（守门）。 */
function expectedCloudDeployCatalog(): array
{
    return [
        'aliyun' => ['cdn', 'dcdn', 'live', 'vod', 'alb', 'nlb', 'clb', 'ga', 'waf', 'oss', 'fc', 'apigw', 'ddospro', 'esa', 'cas', 'casdeploy', 'esasaas'],
        'tencent' => ['cdn', 'ecdn', 'eo', 'eo-makers', 'css', 'vod', 'clb', 'scf', 'waf', 'cos', 'gaap', 'ssl-deploy', 'ssl', 'ssl-update', 'tse', 'ga2'],
        'qiniu' => ['cdn', 'kodo', 'pili'],
        'baidu' => ['cdn', 'blb', 'appblb', 'cert'],
        'cloudflare' => ['ssl'],
        'aws' => ['acm', 'iam', 'alb', 'nlb', 'clb', 'cloudfront', 'amplify', 'apigateway'],
        'upyun' => ['cdn', 'file'],
        'digitalocean' => ['certificate'],
        'ksyun' => ['cdn', 'kcm', 'slb'],
        'volcengine' => ['cdn', 'dcdn', 'alb', 'clb', 'apig', 'certcenter', 'imagex', 'live', 'tos', 'vod', 'waf'],
        'jdcloud' => ['ssl', 'cdn', 'live', 'vod', 'waf', 'alb'],
        'byteplus' => ['cdn', 'alb', 'clb', 'apig', 'certcenter', 'medialive', 'tos'],
        'ucloud' => ['ualb', 'ucdn', 'uclb', 'uewaf', 'pathx', 'us3'],
        'vercel' => ['certificate'],
        'netlify' => ['website'],
        'bunny' => ['cdn'],
        'gcore' => ['cdn'],
        'linode' => ['los'],
        'wangsu' => ['cdn', 'cdnpro', 'certificate'],
        'ctcccloud' => ['ao', 'cdn', 'cms', 'elb', 'faas', 'icdn', 'lvdn'],
        'huaweicloud' => ['scm', 'cdn', 'elb', 'waf', 'live', 'vod', 'obs', 'apig', 'aad'],
        'rainyun' => ['rcdn', 'sslcenter'],
        'mohua' => ['mvh'],
        'unicloud' => ['webhost'],
        'cachefly' => ['certificate'],
        'cdnfly' => ['cdn'],
        'flyio' => ['certificate'],
        'googlecloud' => ['certificatemanager'],
        'azure' => ['keyvault'],
        'oraclecloud' => ['certificatesmgmt'],
        's3' => ['s3'],
        'cmcccloud' => ['cdn', 'vlb'],
        'zenlayer' => ['cdn', 'ga'],
        'qingcloud' => ['lb'],
        'baishan' => ['cdn'],
        'dogecloud' => ['cdn'],
        // Wave5 非云清洁 + 面板（ssh/ftp/local 经产品决策不实现，见 development.md）
        'k8s' => ['secret'],
        'webhook' => ['webhook'],
        'onepanel' => ['site', 'console'],
        'baotapanel' => ['site', 'console'],
        'baotapanelgo' => ['site', 'console'],
        'baotawaf' => ['site', 'console'],
        'ratpanel' => ['site', 'console'],
        'cpanel' => ['cpanel'],
        'safeline' => ['safeline'],
        'samwaf' => ['samwaf', 'console'],
        'goedge' => ['goedge'],
        'flexcdn' => ['flexcdn'],
        'lecdn' => ['lecdn'],
        'nginxproxymanager' => ['certificate'],
        'synologydsm' => ['certificate'],
        'proxmoxve' => ['node'],
        'proxmoxbs' => ['node'],
        'huaweiibmc' => ['console'],
        'axisnow' => ['certificate'],
        'yandexcloud' => ['certificatemanager'],
        'dokploy' => ['certificate'],
        'kong' => ['certificate'],
        'apisix' => ['certificate'],
    ];
}

test('注册端点总数为 156（非云清洁 + 面板 31；ssh/ftp/local 不实现）', function () {
    $registry = app(Registry::class);
    $all = $registry->allDeployers();

    expect($all)->toHaveCount(156);

    $expected = expectedCloudDeployCatalog();
    expect(count($expected['aliyun']))->toBe(17);
    expect(count($expected['tencent']))->toBe(16);
    expect(count($expected['qiniu']))->toBe(3);
    expect(count($expected['baidu']))->toBe(4);
    expect(count($expected['cloudflare']))->toBe(1);
    expect(count($expected['aws']))->toBe(8);
    expect(count($expected['upyun']))->toBe(2);
    expect(count($expected['digitalocean']))->toBe(1);
    expect(count($expected['ksyun']))->toBe(3);
    expect(count($expected['volcengine']))->toBe(11);
    expect(count($expected['jdcloud']))->toBe(6);
    expect(count($expected['byteplus']))->toBe(7);
    expect(count($expected['ucloud']))->toBe(6);
    expect(count($expected['huaweicloud']))->toBe(9);
});

test('实际注册集与期望集逐键一致（无漏注册、无误删/改名）', function () {
    $registry = app(Registry::class);

    // 实际：provider => sorted products
    $actual = [];
    foreach ($registry->allDeployers() as ['provider' => $p, 'product' => $pr]) {
        $actual[$p][] = $pr;
    }
    foreach ($actual as &$products) {
        sort($products);
    }
    unset($products);

    $expected = expectedCloudDeployCatalog();
    foreach ($expected as &$products) {
        sort($products);
    }
    unset($products);

    expect($actual)->toEqual($expected);
});

test('纯上传部署器显式标记订单级唯一性且资源部署器不误标', function () {
    $registry = app(Registry::class);
    $expectedUploadOnly = [
        'aliyun.cas', 'axisnow.certificate', 'aws.acm', 'aws.iam', 'azure.keyvault',
        'baidu.cert', 'byteplus.certcenter', 'cachefly.certificate',
        'ctcccloud.cms', 'digitalocean.certificate', 'dokploy.certificate',
        'googlecloud.certificatemanager', 'huaweicloud.scm', 'jdcloud.ssl', 'ksyun.kcm',
        'oraclecloud.certificatesmgmt', 'tencent.ssl', 'vercel.certificate',
        'volcengine.certcenter', 'wangsu.certificate',
    ];
    sort($expectedUploadOnly);

    $actualUploadOnly = [];
    $emptySchemaResourceExceptions = [
        'baotapanelgo.console',
        'baotawaf.console',
        'ratpanel.console',
    ];

    foreach ($registry->allDeployers() as ['provider' => $provider, 'product' => $product]) {
        $deployer = $registry->resolveDeployer($provider, $product);
        $key = "$provider.$product";

        if ($deployer instanceof UploadOnlyDeployerInterface) {
            $actualUploadOnly[] = $key;
        }

        if ($deployer->configSchema() === []) {
            expect(in_array($key, $expectedUploadOnly, true) || in_array($key, $emptySchemaResourceExceptions, true))
                ->toBeTrue("$key 配置为空，必须明确归类为纯上传或固定资源例外");
        }
    }
    sort($actualUploadOnly);

    expect($actualUploadOnly)->toBe($expectedUploadOnly);

    foreach ([['aliyun', 'cdn'], ['flyio', 'certificate'], ['rainyun', 'sslcenter'], ['s3', 's3']] as [$provider, $product]) {
        expect($registry->resolveDeployer($provider, $product))
            ->not->toBeInstanceOf(UploadOnlyDeployerInterface::class, "$provider.$product 有明确资源目标，不应按订单放宽唯一性");
    }
});

test('每个注册端点都能 resolveDeployer 且元信息 + configSchema 合法', function () {
    $registry = app(Registry::class);

    foreach (expectedCloudDeployCatalog() as $provider => $products) {
        foreach ($products as $product) {
            $label = "$provider.$product";

            expect($registry->hasDeployer($provider, $product))->toBeTrue("应注册 $label");

            $deployer = $registry->resolveDeployer($provider, $product);
            expect($deployer)->toBeInstanceOf(DeployerInterface::class, "$label 应实现 DeployerInterface");
            expect($deployer)->toBeInstanceOf(AbstractDeployer::class, "$label 应继承 AbstractDeployer");

            // provider()/product() 与注册键一致（防 deployer 内写错 provider/product 字符串）
            expect($deployer->provider())->toBe($provider, "$label provider() 应为 $provider");
            expect($deployer->product())->toBe($product, "$label product() 应为 $product");

            // label() 非空
            expect($deployer->label())->toBeString()->not->toBe('', "$label label() 非空");

            // usesRemoteCertStore() 是 bool；为 true 时 certUploader() 非 null 且 storeKind 非空
            expect($deployer->usesRemoteCertStore())->toBeBool();
            if ($deployer->usesRemoteCertStore()) {
                $uploader = $deployer->certUploader([]);
                expect($uploader)->not->toBeNull("$label 证书服务型应有 certUploader");
                $storeKind = $uploader->storeKind();
                expect($storeKind)->toBeString()->not->toBe('', "$label storeKind 非空");
                expect(strlen($storeKind))->toBeLessThanOrEqual(32, "$label storeKind 不得超过存储列上限");
            }

            // configSchema() 是合法 list，每项含 key/label/type/required
            $schema = $deployer->configSchema();
            expect($schema)->toBeArray("$label configSchema 应为数组");
            expect(array_is_list($schema))->toBeTrue("$label configSchema 应为 list");
            $seenKeys = [];
            foreach ($schema as $i => $field) {
                $where = "$label configSchema[$i]";
                expect($field)->toHaveKeys(['key', 'label', 'type', 'required'], "$where 应含 key/label/type/required");
                expect($field['key'])->toBeString()->not->toBe('', "$where key 非空");
                expect($field['label'])->toBeString()->not->toBe('', "$where label 非空");
                expect($field['type'])->toBeString()->not->toBe('', "$where type 非空");
                expect($field['required'])->toBeBool("$where required 是 bool");
                // key 在该 deployer 内唯一
                expect($seenKeys)->not->toContain($field['key'], "$where key 重复: {$field['key']}");
                $seenKeys[] = $field['key'];
            }
        }
    }
});

test('每个 provider 有非空 credentialSchema（每项含 key/label）', function () {
    $registry = app(Registry::class);

    foreach (array_keys(expectedCloudDeployCatalog()) as $provider) {
        expect($registry->hasProvider($provider))->toBeTrue("应注册 provider $provider");

        $p = $registry->resolveProvider($provider);
        expect($p->key())->toBe($provider);
        expect($p->label())->toBeString()->not->toBe('');

        $schema = $p->credentialSchema();
        expect($schema)->toBeArray()->not->toBe([], "$provider credentialSchema 非空");
        expect(array_is_list($schema))->toBeTrue();
        foreach ($schema as $i => $field) {
            $where = "$provider credentialSchema[$i]";
            expect($field)->toHaveKeys(['key', 'label'], "$where 应含 key/label");
            expect($field['key'])->toBeString()->not->toBe('', "$where key 非空");
            expect($field['label'])->toBeString()->not->toBe('', "$where label 非空");
        }
    }
});

test('catalog() 的 providers/products 与实际注册集完全一致（数量 + 每个 key）', function () {
    $registry = app(Registry::class);
    $catalog = $registry->catalog();

    expect($catalog)->toHaveKey('providers');
    $expected = expectedCloudDeployCatalog();

    // provider 集一致
    $catalogProviderKeys = array_column($catalog['providers'], 'key');
    sort($catalogProviderKeys);
    $expectedProviderKeys = array_keys($expected);
    sort($expectedProviderKeys);
    expect($catalogProviderKeys)->toEqual($expectedProviderKeys);

    foreach ($catalog['providers'] as $providerEntry) {
        $providerKey = $providerEntry['key'];

        // 每个 provider entry 结构完整
        expect($providerEntry)->toHaveKeys(['key', 'label', 'credentialSchema', 'products']);
        expect($providerEntry['credentialSchema'])->toBeArray()->not->toBe([]);

        // products 集与期望逐键一致（数量 + key）
        $catalogProducts = array_column($providerEntry['products'], 'product');
        sort($catalogProducts);
        $expectedProducts = $expected[$providerKey];
        sort($expectedProducts);
        expect($catalogProducts)->toEqual($expectedProducts, "$providerKey 的 catalog products 应与注册集一致");

        // 每个 product entry 带 label + configSchema
        foreach ($providerEntry['products'] as $productEntry) {
            expect($productEntry)->toHaveKeys(['product', 'label', 'configSchema']);
            expect($productEntry['label'])->toBeString()->not->toBe('');
            expect($productEntry['configSchema'])->toBeArray();
        }
    }
});
