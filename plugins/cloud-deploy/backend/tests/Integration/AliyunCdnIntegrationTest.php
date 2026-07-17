<?php

/**
 * 阿里云 CDN 真打集成测试（e2e）。
 *
 * 真实调用阿里云 CDN SetCdnDomainSSLCertificate 把证书内联上传绑定到真实加速域名。
 * 缺任一 env 凭证即 markTestSkipped——默认 skip、不进 CI、无外部请求。运行方式见同目录 README.md。
 *
 * 必需 env：
 *   CLOUDDEPLOY_ALIYUN_AK / CLOUDDEPLOY_ALIYUN_SK   阿里云 AccessKey
 *   CLOUDDEPLOY_ALIYUN_CDN_DOMAIN                    已接入 CDN 的加速域名
 *   CLOUDDEPLOY_CERT_PEM / CLOUDDEPLOY_KEY_PEM       证书 + 私钥文件路径（PEM）
 *   CLOUDDEPLOY_CHAIN_PEM                            中间证书链文件路径（可指向空文件）
 */

use Plugins\CloudDeploy\Deployers\Aliyun\AliyunCdnDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 读必需 env，缺失即 skip 整条测试。返回 [ak, sk, domain, certPem, keyPem, chainPem]。
 *
 * @return array{string,string,string,string,string,string}
 */
function aliyunCdnIntegrationContext(): array
{
    $ak = getenv('CLOUDDEPLOY_ALIYUN_AK') ?: '';
    $sk = getenv('CLOUDDEPLOY_ALIYUN_SK') ?: '';
    $domain = getenv('CLOUDDEPLOY_ALIYUN_CDN_DOMAIN') ?: '';
    $certPath = getenv('CLOUDDEPLOY_CERT_PEM') ?: '';
    $keyPath = getenv('CLOUDDEPLOY_KEY_PEM') ?: '';
    $chainPath = getenv('CLOUDDEPLOY_CHAIN_PEM') ?: '';

    if ($ak === '' || $sk === '' || $domain === '' || $certPath === '' || $keyPath === '') {
        test()->markTestSkipped(
            '阿里云 CDN 真打：缺 CLOUDDEPLOY_ALIYUN_AK/SK、CLOUDDEPLOY_ALIYUN_CDN_DOMAIN 或证书路径，跳过（见 Integration/README.md）'
        );
    }

    foreach (['cert' => $certPath, 'key' => $keyPath] as $label => $path) {
        if (! is_file($path) || ! is_readable($path)) {
            test()->markTestSkipped("阿里云 CDN 真打：$label PEM 文件不可读: $path");
        }
    }

    return [
        $ak,
        $sk,
        $domain,
        (string) file_get_contents($certPath),
        (string) file_get_contents($keyPath),
        $chainPath !== '' && is_file($chainPath) ? (string) file_get_contents($chainPath) : '',
    ];
}

test('阿里云 CDN：真实上传并绑定证书到加速域名', function () {
    [$ak, $sk, $domain, $cert, $key, $chain] = aliyunCdnIntegrationContext();

    // 真实 deployer（不 override makeClient，走真实 SDK）
    $deployer = new AliyunCdnDeployer;

    // CDN 内联型：usesRemoteCertStore=false，直接 bind {cert,key,chain}
    expect($deployer->usesRemoteCertStore())->toBeFalse();

    // 真打：抛异常 = 部署失败（凭证/域名/证书问题）；不抛 = SetCdnDomainSSLCertificate 成功
    $deployer->bind(
        ['cert' => $cert, 'key' => $key, 'chain' => $chain],
        ['access_key_id' => $ak, 'access_key_secret' => $sk],
        ['domain' => $domain],
    );

    // 走到这里即无异常 = 绑定成功（请到阿里云 CDN 控制台二次确认证书已生效）
    expect(true)->toBeTrue();
});
