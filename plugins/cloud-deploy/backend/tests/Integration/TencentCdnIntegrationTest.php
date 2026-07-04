<?php

/**
 * 腾讯云 CDN 真打集成测试（e2e）。
 *
 * 真实调用腾讯云 SSL UploadCertificate 拿 CertificateId，再调 CDN UpdateDomainConfig 绑定到真实加速域名。
 * 缺任一 env 凭证即 markTestSkipped——默认 skip、不进 CI、无外部请求。运行方式见同目录 README.md。
 *
 * 必需 env：
 *   CLOUDDEPLOY_TENCENT_SECRET_ID / CLOUDDEPLOY_TENCENT_SECRET_KEY   腾讯云密钥
 *   CLOUDDEPLOY_TENCENT_CDN_DOMAIN                                   已接入 CDN 的加速域名
 *   CLOUDDEPLOY_CERT_PEM / CLOUDDEPLOY_KEY_PEM                       证书 + 私钥文件路径（PEM）
 *   CLOUDDEPLOY_CHAIN_PEM                                            中间证书链文件路径（可指向空文件）
 */

use Plugins\CloudDeploy\Deployers\Tencent\TencentCdnDeployer;
use Tests\TestCase;

uses(TestCase::class);

/**
 * 读必需 env，缺失即 skip 整条测试。返回 [secretId, secretKey, domain, certPem, keyPem, chainPem]。
 *
 * @return array{string,string,string,string,string,string}
 */
function tencentCdnIntegrationContext(): array
{
    $secretId = getenv('CLOUDDEPLOY_TENCENT_SECRET_ID') ?: '';
    $secretKey = getenv('CLOUDDEPLOY_TENCENT_SECRET_KEY') ?: '';
    $domain = getenv('CLOUDDEPLOY_TENCENT_CDN_DOMAIN') ?: '';
    $certPath = getenv('CLOUDDEPLOY_CERT_PEM') ?: '';
    $keyPath = getenv('CLOUDDEPLOY_KEY_PEM') ?: '';
    $chainPath = getenv('CLOUDDEPLOY_CHAIN_PEM') ?: '';

    if ($secretId === '' || $secretKey === '' || $domain === '' || $certPath === '' || $keyPath === '') {
        test()->markTestSkipped(
            '腾讯云 CDN 真打：缺 CLOUDDEPLOY_TENCENT_SECRET_ID/KEY、CLOUDDEPLOY_TENCENT_CDN_DOMAIN 或证书路径，跳过（见 Integration/README.md）'
        );
    }

    foreach (['cert' => $certPath, 'key' => $keyPath] as $label => $path) {
        if (! is_file($path) || ! is_readable($path)) {
            test()->markTestSkipped("腾讯云 CDN 真打：$label PEM 文件不可读: $path");
        }
    }

    return [
        $secretId,
        $secretKey,
        $domain,
        (string) file_get_contents($certPath),
        (string) file_get_contents($keyPath),
        $chainPath !== '' && is_file($chainPath) ? (string) file_get_contents($chainPath) : '',
    ];
}

test('腾讯云 CDN：真实上传证书到 SSL 服务并绑定到加速域名', function () {
    [$secretId, $secretKey, $domain, $cert, $key, $chain] = tencentCdnIntegrationContext();

    $credentials = ['secret_id' => $secretId, 'secret_key' => $secretKey];

    // 真实 deployer（不 override makeClient，走真实 SDK）
    $deployer = new TencentCdnDeployer;

    // 证书服务型：usesRemoteCertStore=true，先 upload 拿 CertificateId 再 bind
    expect($deployer->usesRemoteCertStore())->toBeTrue();

    $uploader = $deployer->certUploader();
    expect($uploader)->not->toBeNull();

    // 真打第一步：上传到腾讯云 SSL，拿到 CertificateId（非空）
    $certId = $uploader->upload($cert, $key, $chain, $credentials);
    expect($certId)->toBeString()->not->toBe('');

    // 真打第二步：用 CertificateId 绑定到 CDN 域名；不抛异常 = UpdateDomainConfig 成功
    $deployer->bind($certId, $credentials, ['domain' => $domain]);

    // 请到腾讯云 CDN 控制台二次确认证书已生效（真打会改动线上域名配置）
    expect(true)->toBeTrue();
});
