<?php

namespace Plugins\CloudDeploy\Deployers\Qingcloud;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 青云负载均衡服务器证书上传器（storeKind=qingcloud:{zone}，zone 维度隔离）。
 *
 * 对齐 certimate certmgr qingcloud-lb 的 Upload：调青云 IaaS OpenAPI
 *   POST CreateServerCertificate {server_certificate_name, certificate_content, private_key} → server_certificate_id
 * 返回 server_certificate_id 作为 remote_cert_id —— lb deployer bind 时把它绑到监听器。
 *
 * zone 维度（关键）：青云服务器证书是 **按 zone（区域）隔离** 的标识空间（不同 zone 的 cert id 不通用），
 * 故 storeKind 编入 zone（qingcloud:{zone}），并据 config.zoneId 构造上传器（与阿里 SLB region 维度上传器同思路）。
 *
 * 与 certimate 对齐的取舍：certimate 上传前先 DescribeServerCertificates 按内容查重复用；本类省略该查重——
 * 插件 RemoteCertStore 已按 (access_id, store_kind, fingerprint) 去重，上传器只管上传。证书 + 中间证书拼完整链
 * （certimate 单 certPEM ≈ 此处 cert+chain）。
 *
 * SDK client（QingcloudRestClient）经注入缝 $clientFactory（deployer 的 makeClient('lb', …, zone)）构造 ——
 * 测试 override deployer::makeClient 即自动作用于此处，无需单独 mock 上传器。
 */
class QingcloudLbUploader implements CertUploaderInterface
{
    /**
     * @param  Closure(array<string,mixed>):object  $clientFactory  返回 QingcloudRestClient（或测试 mock，需有 post() 方法）
     * @param  string  $zone  青云区域 ID（编入 storeKind 以隔离跨 zone 标识空间）
     */
    public function __construct(
        private readonly Closure $clientFactory,
        private readonly string $zone,
    ) {}

    public function storeKind(): string
    {
        return 'qingcloud:'.$this->zone;
    }

    /**
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 证书 + 中间证书拼成完整链上传。
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        // 青云证书命名：毫秒时间戳保唯一（对齐 certimate certimate-{ms}）。
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        try {
            /** @var QingcloudRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $result = $client->post('CreateServerCertificate', [
                'server_certificate_name' => $certName,
                'certificate_content' => $fullChain,
                'private_key' => trim($keyPem),
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(QingcloudErrorSanitizer::sanitize($e), 0);
        }

        // 青云 CreateServerCertificate 响应：{ret_code, server_certificate_id}。
        $certId = $result['server_certificate_id'] ?? null;
        if (! is_string($certId) || $certId === '') {
            throw new RuntimeException('青云 CreateServerCertificate 未返回 server_certificate_id');
        }

        return $certId;
    }
}
