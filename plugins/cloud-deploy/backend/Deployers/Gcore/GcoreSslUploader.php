<?php

namespace Plugins\CloudDeploy\Deployers\Gcore;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * Gcore SSL 证书上传器（storeKind=gcore）。
 *
 * 对齐 certimate gcore-cdn certmgr 的 Upload：调 Gcore CDN REST
 *   POST /cdn/sslData  {name, sslCertificate, sslPrivateKey, automated:false, validate_root_ca:false}  →  {id, name}
 * 返回 id（字符串化）作为 remote_cert_id —— gcore-cdn bind 时把它作 sslData 绑到 CDN 资源。
 *
 * 字段名严格对齐 gcorelabscdn-go v1.0.37 sslcerts.CreateRequest：
 *   Cert → `sslCertificate`、PrivateKey → `sslPrivateKey`（写错大小写会被静默丢成 null，绑定失败）。
 *
 * 与 certimate 对齐的取舍：
 * - sslCertificate 传**完整链**（cert+chain），与 certimate certmgr 把 certPEM（完整链）整体上传一致。
 * - certimate gcore-cdn 的 certificateId 配置（更新已有证书 PATCH /cdn/sslData/{id}）被插件的「上传+去重」模型取代：
 *   RemoteCertStore 已按 (access_id, store_kind, fingerprint) 去重，每张新证书上传一次拿新 id，bind 绑新 id。
 *
 * SDK client（GcoreClient）经注入缝 $clientFactory（deployer 的 makeClient('api', …)）构造 ——
 * 测试 override deployer::makeClient 即自动作用于此处，无需单独 mock 上传器。
 */
class GcoreSslUploader implements CertUploaderInterface
{
    /** @param Closure(array<string,mixed>):object $clientFactory 返回 GcoreClient */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'gcore';
    }

    /**
     * @param  array{api_token:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 完整链：服务器证书 + 中间证书（对齐 certmgr 上传 certPEM 完整链）。
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        $name = 'clouddeploy_'.(int) (microtime(true) * 1000);

        try {
            /** @var GcoreClient $client */
            $client = ($this->clientFactory)($credentials);
            $id = $client->createSslData([
                'name' => $name,
                'sslCertificate' => $fullChain,
                'sslPrivateKey' => trim($keyPem),
                'automated' => false,
                'validate_root_ca' => false,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(GcoreErrorSanitizer::sanitize($e), 0);
        }

        if ($id <= 0) {
            throw new RuntimeException('Gcore CreateSslData 未返回证书 id');
        }

        return (string) $id;
    }
}
