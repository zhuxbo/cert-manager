<?php

use Plugins\CloudDeploy\Deployers\Aliyun\AliyunCasDeployDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsAcmDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsAlbDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsAmplifyDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsApigatewayDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsClbDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsCloudFrontDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsIamDeployer;
use Plugins\CloudDeploy\Deployers\Aws\AwsNlbDeployer;
use Plugins\CloudDeploy\Deployers\Aws\BuildsAwsClientConfig;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| G3：云 SDK / RestClient 必设显式 connect/read 超时（防 TCP 黑洞无限挂起 → SIGALRM 击杀 + reserved 600s）。
| 守门覆盖 aliyun（trait 收口 26 调用点）+ AWS（trait 收口 8 makeClient，遍历防单点假绿）+ 5 个 RestClient。
|--------------------------------------------------------------------------
*/

test('8 个 AWS deployer 均经 BuildsAwsClientConfig 且 http connect/read timeout 存在（遍历防单点假绿）', function () {
    $deployers = [
        AwsAcmDeployer::class, AwsIamDeployer::class, AwsCloudFrontDeployer::class, AwsAlbDeployer::class,
        AwsNlbDeployer::class, AwsClbDeployer::class, AwsAmplifyDeployer::class, AwsApigatewayDeployer::class,
    ];

    // 全部 use trait（防某 deployer 漏改回退到无 timeout 的内联 $cfg）——收集缺失者精确报出
    $missing = array_values(array_filter($deployers, fn ($c) => ! in_array(BuildsAwsClientConfig::class, class_uses_recursive($c), true)));
    expect($missing)->toBe([]);

    foreach ($deployers as $class) {
        // 调 protected awsClientConfig 断 http timeout 存在
        $m = new ReflectionMethod($class, 'awsClientConfig');
        $m->setAccessible(true);
        $cfg = $m->invoke(new $class, ['access_key_id' => 'AK', 'secret_access_key' => 'SK'], 'us-east-1');
        expect($cfg['http']['timeout'] ?? null)->not->toBeNull()
            ->and($cfg['http']['connect_timeout'] ?? null)->not->toBeNull();
    }
});

test('BuildsAliyunConfig 产物含 readTimeout/connectTimeout（darabonba Config 单一来源）', function () {
    $m = new ReflectionMethod(AliyunCasDeployDeployer::class, 'aliyunConfig');
    $m->setAccessible(true);
    $cfg = $m->invoke(new AliyunCasDeployDeployer, ['access_key_id' => 'AK', 'access_key_secret' => 'SK'], 'cas.aliyuncs.com');

    expect($cfg->readTimeout)->not->toBeNull()
        ->and($cfg->connectTimeout)->not->toBeNull();
});

test('Aliyun deployer 不得内联 new Config（守门：darabonba Config 仅经 BuildsAliyunConfig::aliyunConfig 注入 timeout）', function () {
    $dir = dirname(__DIR__, 3).'/Deployers/Aliyun';

    $offenders = [];
    foreach (glob("$dir/*.php") as $f) {
        if (basename($f) === 'BuildsAliyunConfig.php') {
            continue; // trait 是唯一允许 new Config( 的地方
        }
        if (str_contains((string) file_get_contents($f), 'new Config(')) {
            $offenders[] = basename($f);
        }
    }

    expect($offenders)->toBe([]);
});

test('AliyunOssDeployer（OSS V2 SDK，独立于 darabonba Config）显式设 Connect/Readwrite 超时', function () {
    // OSS V2 走 OssConfig 非 darabonba Config，「new Config 仅 trait」守门覆盖不到——单独钉住两个 setter
    $src = (string) file_get_contents(dirname(__DIR__, 3).'/Deployers/Aliyun/AliyunOssDeployer.php');

    expect($src)->toContain('->setConnectTimeout(')->toContain('->setReadwriteTimeout(');
});

test('5 个 RestClient deployer makeClient 均设 connect_timeout（防 TCP 黑洞挂起）', function () {
    $base = dirname(__DIR__, 3); // tests/Unit/Deployers → backend
    $files = [
        'Deployers/Flexcdn/FlexcdnDeployer.php',
        'Deployers/Goedge/GoedgeDeployer.php',
        'Deployers/Lecdn/LecdnDeployer.php',
        'Deployers/Ratpanel/BuildsRatpanelClient.php',
        'Deployers/Upyun/UpyunFileDeployer.php',
        'Deployers/Upyun/UpyunCdnDeployer.php',
    ];

    foreach ($files as $rel) {
        $src = (string) file_get_contents("$base/$rel");
        expect($src)->toContain("'connect_timeout' => 10")->toContain("'timeout' => 30");
    }
});
