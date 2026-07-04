<?php

namespace Plugins\CloudDeploy\Deployers\Ucloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 优刻得 Web 应用防火墙（UEWAF）部署器 —— **内联型**（usesRemoteCertStore=false）。
 *
 * 与其他 5 个 UCloud 端点不同：UEWAF 不经 USSL 证书服务，AddWafDomainCertificateInfo 直接把
 * base64 证书/私钥随请求灌给指定域名（对齐 certimate ucloud-uewaf）。故无 uploader，bind 收到的
 * $certRef 是内联 PEM 三元组 {cert,key,chain}。
 *
 * 仅 exact 域名核心路径：domain 配置即目标域名（不支持泛域名，与 certimate 一致）。
 */
class UcloudUewafDeployer extends AbstractDeployer
{
    use UcloudClientFactory;

    public function provider(): string
    {
        return 'ucloud';
    }

    public function product(): string
    {
        return 'uewaf';
    }

    public function label(): string
    {
        return '优刻得 Web 应用防火墙';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '防护域名', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{public_key?:string,private_key?:string,project_id?:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');

        if (! is_array($certRef)) {
            $this->fail('优刻得 UEWAF 为内联型，bind 需要 PEM 三元组');
        }
        $certPem = (string) ($certRef['cert'] ?? '');
        $keyPem = (string) ($certRef['key'] ?? '');
        $chainPem = (string) ($certRef['chain'] ?? '');

        // 证书 + 中间证书拼完整链，再 base64（与 USSL 上传同口径）。
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        $certB64 = base64_encode($fullChain);
        $keyB64 = base64_encode(trim($keyPem)."\n");
        // SslMD = md5( base64(cert) 拼 base64(key) )，hex 小写（对齐 certimate ucloud-uewaf）。
        $md5 = md5($certB64.$keyB64);
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        $this->guardSdk(function () use ($credentials, $domain, $certName, $certB64, $keyB64, $md5) {
            /** @var UcloudRestClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->addWafDomainCertificateInfo($domain, $certName, $certB64, $keyB64, $md5);
        });
    }

    protected function sanitize(Throwable $e): string
    {
        return UcloudErrorSanitizer::sanitize($e);
    }
}
