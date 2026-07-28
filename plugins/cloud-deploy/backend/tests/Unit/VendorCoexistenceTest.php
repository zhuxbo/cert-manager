<?php

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailRequest;
use Darabonba\OpenApi\Models\Config;
use GuzzleHttp\Client;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ssl\V20191205\Models\DescribeCertificatesRequest;
use TencentCloud\Ssl\V20191205\SslClient;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Phase 0 守护测试：插件独立 vendor 与主系统共存。
 *
 * 由 Tests\TestCase 引导完整 Laravel 应用 → PluginServiceProvider::boot 注册各插件 provider →
 * CloudDeployServiceProvider::register 在最前面 loadPluginVendor()（require_once vendor/autoload.php
 * 后把插件 ClassLoader 挂 SPL 栈尾）。因此到达这里时插件 vendor 已加载、且挂在栈尾。
 *
 * 杀手场景 1：共享依赖冲突 → register(false) 挂栈尾使共享类回落主系统。
 */

// 插件 vendor 在容器内的真实根（用于"不来自插件"的判定，路径无关 host/容器差异）
function cloudDeployPluginVendorReal(): string|false
{
    return realpath(dirname(__DIR__, 2).'/vendor');
}

test('加载插件后 GuzzleHttp\\Client 仍来自主系统 vendor 而非插件 vendor', function () {
    // 触发主系统对 Guzzle 的解析路径（主系统锁 guzzle 7.10 / psr7 2.9）
    expect(class_exists(Client::class))->toBeTrue();

    $resolved = (new ReflectionClass(Client::class))->getFileName();
    expect($resolved)->toBeString()->not->toBeFalse();

    $pluginVendor = cloudDeployPluginVendorReal();
    expect($pluginVendor)->not->toBeFalse();

    $resolvedReal = realpath($resolved);

    // 核心断言：Guzzle 不能从插件 vendor 解析（否则插件版本接管了主系统，psr7 会被切到 2.8）
    expect(str_starts_with($resolvedReal, $pluginVendor.DIRECTORY_SEPARATOR))
        ->toBeFalse("GuzzleHttp\\Client 解析到插件 vendor（{$resolvedReal}），栈尾挂载失败");

    // 正向断言：解析路径就是主系统 vendor 那份（base_path() = 主系统 backend 根）
    expect($resolvedReal)->toContain('guzzlehttp'.DIRECTORY_SEPARATOR.'guzzle');
    expect($resolvedReal)->toBe(realpath(base_path('vendor/guzzlehttp/guzzle/src/Client.php')));
});

test('插件独有的官方云 SDK 类可从插件 vendor 解析（不 scoping）', function () {
    // 主 loader findFile 这些命名空间返回 false → 落到栈尾的插件 loader 解析
    expect(class_exists('AlibabaCloud\\SDK\\Cdn\\V20180510\\Cdn'))
        ->toBeTrue('阿里云 CDN SDK 类未能从插件 vendor 解析');
    expect(class_exists('TencentCloud\\Ssl\\V20191205\\SslClient'))
        ->toBeTrue('腾讯云 SSL SDK 类未能从插件 vendor 解析');
    // Phase 1 腾讯 ecdn 复用的 CDN SDK（合并进 CDN v20180606），Phase 0 一并验在库
    expect(class_exists('TencentCloud\\Cdn\\V20180606\\CdnClient'))
        ->toBeTrue('腾讯云 CDN SDK 类未能从插件 vendor 解析');
    // Phase 2 批 1 新增阿里云 CAS/dcdn/live/vod SDK，验同样能从插件 vendor 栈尾解析（共存不破）
    expect(class_exists('AlibabaCloud\\SDK\\Cas\\V20200407\\Cas'))
        ->toBeTrue('阿里云 CAS SDK 类未能从插件 vendor 解析');
    expect(class_exists('AlibabaCloud\\SDK\\Dcdn\\V20180115\\Dcdn'))
        ->toBeTrue('阿里云 DCDN SDK 类未能从插件 vendor 解析');
    expect(class_exists('AlibabaCloud\\SDK\\Live\\V20161101\\Live'))
        ->toBeTrue('阿里云直播 SDK 类未能从插件 vendor 解析');
    expect(class_exists('AlibabaCloud\\SDK\\Vod\\V20170321\\Vod'))
        ->toBeTrue('阿里云 VOD SDK 类未能从插件 vendor 解析');
    // Phase 2 批 3 新增阿里云 SLB（CLB 服务证书）/ WAF SDK，验同样能从插件 vendor 栈尾解析
    expect(class_exists('AlibabaCloud\\SDK\\Slb\\V20140515\\Slb'))
        ->toBeTrue('阿里云 SLB SDK 类未能从插件 vendor 解析');
    expect(class_exists('AlibabaCloud\\SDK\\Wafopenapi\\V20211001\\Wafopenapi'))
        ->toBeTrue('阿里云 WAF SDK 类未能从插件 vendor 解析');
    // Phase 3 批 1 新增腾讯云 teo（EdgeOne）/ live（直播 CSS）/ vod（点播）SDK，验同样能从插件 vendor 栈尾解析
    expect(class_exists('TencentCloud\\Teo\\V20220901\\TeoClient'))
        ->toBeTrue('腾讯云 EdgeOne SDK 类未能从插件 vendor 解析');
    expect(class_exists('TencentCloud\\Live\\V20180801\\LiveClient'))
        ->toBeTrue('腾讯云直播 SDK 类未能从插件 vendor 解析');
    expect(class_exists('TencentCloud\\Vod\\V20180717\\VodClient'))
        ->toBeTrue('腾讯云点播 SDK 类未能从插件 vendor 解析');

    // 这些类确实来自插件 vendor（与上一个测试的"共享类回主系统"形成对照）
    $aliyunFile = realpath((new ReflectionClass('AlibabaCloud\\SDK\\Cdn\\V20180510\\Cdn'))->getFileName());
    $tencentFile = realpath((new ReflectionClass('TencentCloud\\Ssl\\V20191205\\SslClient'))->getFileName());
    $pluginVendor = cloudDeployPluginVendorReal();

    expect(str_starts_with($aliyunFile, $pluginVendor.DIRECTORY_SEPARATOR))->toBeTrue();
    expect(str_starts_with($tencentFile, $pluginVendor.DIRECTORY_SEPARATOR))->toBeTrue();
});

test('真实构造腾讯 SslClient 并触发调用：抛可 catch 的 SDK 异常而非 Class not found（杀手场景 8）', function () {
    // 指向丢弃端口，连接立即被拒，请求快速失败且不接触真实腾讯 API（hermetic）
    $http = new HttpProfile;
    $http->setEndpoint('127.0.0.1:9');
    $http->setProtocol('http://');
    $http->setReqTimeout(2);

    $profile = new ClientProfile;
    $profile->setHttpProfile($http);

    // 全程不依赖 Class not found 兜底：构造 → __call 动态分发 → 真实 SDK 请求/签名路径执行
    $cred = new Credential('fake-secret-id', 'fake-secret-key');
    $client = new SslClient($cred, 'ap-guangzhou', $profile);
    $req = new DescribeCertificatesRequest;

    $caught = null;
    try {
        $client->DescribeCertificates($req);
    } catch (Throwable $e) {
        $caught = $e;
    }

    // 必须真的抛了东西（连接被拒），且是 SDK 的 \Exception 子类——绝不能是 PHP Error: Class not found
    expect($caught)->not->toBeNull('调用未抛异常：SDK 请求路径可能被短路，未真正解析执行');
    expect($caught)->toBeInstanceOf(TencentCloudSDKException::class);
    expect($caught)->not->toBeInstanceOf(Error::class); // \Error 含 Class-not-found 致命错误
});

test('真实构造阿里云 Cas 并触发调用：抛可 catch 的 \\Exception 而非 Class not found（杀手场景 8 阿里侧）', function () {
    // 指向丢弃端口（127.0.0.1:9）+ HTTP + 短超时，连接立即被拒，hermetic 不触真实阿里 API。
    // Cas 经 Darabonba/openapi-core 栈发请求，比 CDN（已有测试仅 mock）更全地踩通新装 SDK 的依赖链。
    // 注：删除孤儿 alibabacloud/darabonba-openapi 后，Darabonba\OpenApi\* 单一来自 openapi-core——
    // 新一代 callApi 不再把底层 Guzzle 网络异常包成旧 TeaError，连接被拒会以 GuzzleHttp\ConnectException
    // 原样上抛（仍是 \Exception 子类，deployer 的 catch(Throwable) 照常兜住）。故断言放宽到「可 catch 的
    // \Exception、绝非 \Error 致命」——守的是「真跑通请求路径、无 Class not found / 方法不存在 fatal」本意。
    $client = new Cas(new Config([
        'accessKeyId' => 'fake-ak',
        'accessKeySecret' => 'fake-sk',
        'endpoint' => '127.0.0.1:9',
        'protocol' => 'HTTP',
        'readTimeout' => 2000,
        'connectTimeout' => 2000,
    ]));

    $caught = null;
    try {
        $client->getUserCertificateDetail(new GetUserCertificateDetailRequest(['certId' => 1, 'certFilter' => true]));
    } catch (Throwable $e) {
        $caught = $e;
    }

    // 必须真抛（连接被拒）且是可 catch 的 \Exception 子类——绝不能是 PHP \Error（Class not found / 方法不存在等致命）
    expect($caught)->not->toBeNull('调用未抛异常：阿里云 SDK 请求路径可能被短路，未真正解析执行');
    expect($caught)->toBeInstanceOf(Exception::class);
    expect($caught)->not->toBeInstanceOf(Error::class);
});

test('插件 vendor 不得携带与主系统跨大版本冲突的共享依赖（防 psr/log 1.x 类古董再污染主系统）', function () {
    // 本次事故根因：baidubce 古董 SDK 拖入 psr/log 1.x（vs 主系统 3.x）+ symfony/event-dispatcher 2.x（vs 7.x），
    // 在 PHP-FPM 多 worker 下「挂栈尾」隔离失效、污染主系统 Monolog（Logger::emergency 签名不兼容）→ 全站 500。
    // 已用 composer `replace` 把这些古董挡在插件 vendor 外。本测试遍历插件↔主系统重叠包，断言**无跨大版本**
    // 冲突，防止未来再引入老 SDK 复发（同大版本差异由「挂栈尾回落主系统」兜，跨大版本则必须 replace/换实现）。
    $read = function (string $path): array {
        if (! is_file($path)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($path), true);
        $packages = $json['packages'] ?? $json;

        return is_array($packages) ? $packages : [];
    };
    $major = fn (string $v): string => explode('.', ltrim($v, 'vV'))[0];

    $hostVer = [];
    foreach ($read(base_path('vendor/composer/installed.json')) as $p) {
        if (isset($p['name'], $p['version'])) {
            $hostVer[$p['name']] = $p['version'];
        }
    }
    expect($hostVer)->not->toBe([], '读不到主系统 installed.json，测试前置不满足');

    $conflicts = [];
    foreach ($read(dirname(__DIR__, 2).'/vendor/composer/installed.json') as $p) {
        $name = $p['name'] ?? null;
        $ver = $p['version'] ?? null;
        if ($name === null || $ver === null || ! isset($hostVer[$name])) {
            continue; // 插件独有的官方云 SDK 包不共享、无冲突面
        }
        if ($major($ver) !== $major($hostVer[$name])) {
            $conflicts[] = "{$name}（插件 {$ver} vs 主系统 {$hostVer[$name]}）";
        }
    }

    expect($conflicts)->toBe(
        [],
        '插件 vendor 携带与主系统跨大版本冲突的共享依赖，PHP-FPM 下会污染主系统类（如 psr/log 1.x 致 Monolog 签名不兼容、全站 500）。'.
        '请在插件 composer.json 用 replace 挡掉、或改用不依赖该古董的实现：'.implode('；', $conflicts)
    );
});
