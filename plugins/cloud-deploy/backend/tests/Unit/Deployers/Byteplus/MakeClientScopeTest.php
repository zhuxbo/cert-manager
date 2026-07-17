<?php

use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusAlbDeployer;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusApigDeployer;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusCdnDeployer;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusCertCenterDeployer;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusClbDeployer;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusMediaLiveDeployer;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusRestClient;
use Plugins\CloudDeploy\Deployers\Byteplus\BytePlusTosDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 锁定各 deployer 真实 makeClient 构造出的 BytePlusRestClient 的 (service, region, host) 签名 scope 字段。
 *
 * 这是 per-deployer mock 测试**绕过**的真实集成点 —— 签名 service/region 写错（如 CDN 用小写 "cdn"
 * 或 region 用 cn-north-1）会令凭证 scope 与 BytePlus 网关不一致、线上签名校验失败，但 mock 测试看不出。
 * 本测试经反射调 protected makeClient + 读 client 私有字段，把对齐 byteplus-sdk 源码的 scope 字面量钉死，防漂移。
 *
 * 源码出处：
 *   - CDN  : byteplus-sdk-golang service/cdn/config.go  → ServiceName="CDN"  DefaultRegion="ap-singapore-1"
 *   - live : byteplus-sdk-golang service/live/v20230101/config.go → ServiceName="live" region "cn-north-1"
 *   - certificate_service : byteplus-go-sdk-v2 .../certificateservice → ServiceName="certificate_service"
 *   - alb/clb/apig : byteplus-go-sdk-v2 各 service（签名 region 取用户 config.region）
 */

/**
 * 反射调 protected makeClient，再读返回 BytePlusRestClient 的私有 (service, region, host)。
 *
 * @param  array<int,mixed>  $extraArgs  makeClient 的额外位置参数（region / bucket）
 * @return array{service:string,region:string,host:string}
 */
function byteplusClientScope(object $deployer, string $kind, array $extraArgs = []): array
{
    $make = new ReflectionMethod($deployer, 'makeClient');
    $make->setAccessible(true);
    /** @var BytePlusRestClient $client */
    $client = $make->invoke($deployer, $kind, ['access_key_id' => 'AK', 'secret_access_key' => 'SK'], ...$extraArgs);

    expect($client)->toBeInstanceOf(BytePlusRestClient::class);

    $read = function (string $prop) use ($client) {
        $r = new ReflectionProperty($client, $prop);
        $r->setAccessible(true);

        return (string) $r->getValue($client);
    };

    return ['service' => $read('service'), 'region' => $read('region'), 'host' => $read('host')];
}

test('CDN makeClient：service=CDN（大写）region=ap-singapore-1 host=open.byteplusapi.com', function () {
    $scope = byteplusClientScope(new BytePlusCdnDeployer, 'cdn');
    expect($scope['service'])->toBe('CDN');
    expect($scope['region'])->toBe('ap-singapore-1');
    expect($scope['host'])->toBe('open.byteplusapi.com');
});

test('Media Live makeClient：service=live region=cn-north-1 host=open.byteplusapi.com', function () {
    $scope = byteplusClientScope(new BytePlusMediaLiveDeployer, 'live');
    expect($scope['service'])->toBe('live');
    expect($scope['region'])->toBe('cn-north-1');
    expect($scope['host'])->toBe('open.byteplusapi.com');
});

test('CertCenter makeClient：service=certificate_service region 默认 ap-singapore-1', function () {
    $scope = byteplusClientScope(new BytePlusCertCenterDeployer, 'certcenter', ['cn-beijing']);
    expect($scope['service'])->toBe('certificate_service');
    expect($scope['region'])->toBe('cn-beijing'); // 透传传入 region

    // 缺 region（空串）回落默认 ap-singapore-1
    $scopeDefault = byteplusClientScope(new BytePlusCertCenterDeployer, 'certcenter', ['']);
    expect($scopeDefault['region'])->toBe('ap-singapore-1');
});

test('ALB makeClient：certcenter 上传固定 ap-singapore-1；lb 资源 service=alb 取 config.region', function () {
    $cc = byteplusClientScope(new BytePlusAlbDeployer, 'certcenter');
    expect($cc['service'])->toBe('certificate_service');
    expect($cc['region'])->toBe('ap-singapore-1');

    $lb = byteplusClientScope(new BytePlusAlbDeployer, 'lb', ['cn-shanghai']);
    expect($lb['service'])->toBe('alb');
    expect($lb['region'])->toBe('cn-shanghai');
    expect($lb['host'])->toBe('open.byteplusapi.com');
});

test('CLB makeClient：lb 资源 service=clb 取 config.region', function () {
    $lb = byteplusClientScope(new BytePlusClbDeployer, 'lb', ['ap-singapore-1']);
    expect($lb['service'])->toBe('clb');
    expect($lb['region'])->toBe('ap-singapore-1');
});

test('APIG makeClient：apig 资源 service=apig 取 config.region；certcenter 固定 ap-singapore-1', function () {
    $cc = byteplusClientScope(new BytePlusApigDeployer, 'certcenter');
    expect($cc['service'])->toBe('certificate_service');
    expect($cc['region'])->toBe('ap-singapore-1');

    $apig = byteplusClientScope(new BytePlusApigDeployer, 'apig', ['ap-southeast-1']);
    expect($apig['service'])->toBe('apig');
    expect($apig['region'])->toBe('ap-southeast-1');
});

test('TOS makeClient：certcenter 固定 ap-singapore-1；tos service=tos host={bucket}.tos-{region}.bytepluses.com', function () {
    $cc = byteplusClientScope(new BytePlusTosDeployer, 'certcenter');
    expect($cc['service'])->toBe('certificate_service');
    expect($cc['region'])->toBe('ap-singapore-1');

    $tos = byteplusClientScope(new BytePlusTosDeployer, 'tos', ['ap-singapore-1', 'mybucket']);
    expect($tos['service'])->toBe('tos');
    expect($tos['region'])->toBe('ap-singapore-1');
    expect($tos['host'])->toBe('mybucket.tos-ap-singapore-1.bytepluses.com');
});
