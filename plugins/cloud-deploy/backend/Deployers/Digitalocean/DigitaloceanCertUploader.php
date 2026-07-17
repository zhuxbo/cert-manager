<?php

namespace Plugins\CloudDeploy\Deployers\Digitalocean;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * DigitalOcean 证书上传器（storeKind=digitalocean_certificate）。
 *
 * 对齐 certimate digitalocean-certificate certmgr：POST /v2/certificates 上传自定义证书
 * （type=custom，leaf_certificate=服务器证书、certificate_chain=中间证书、private_key）→ certificate.id。
 * 删 certimate 上传前的 ListCertificates 查重（RemoteCertStore 已按 (access_id, store_kind, fingerprint) 去重）。
 *
 * SDK client 经注入缝 $clientFactory（由 deployer 的 makeClient('api', …) 提供）。
 */
class DigitaloceanCertUploader implements CertUploaderInterface
{
    /** @param Closure(array<string,mixed>):object $clientFactory 返回 DigitaloceanClient */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'digitalocean_certificate';
    }

    /**
     * @param  array{access_token:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        try {
            /** @var DigitaloceanClient $client */
            $client = ($this->clientFactory)($credentials);
            $certId = $client->createCertificate([
                'name' => 'clouddeploy-'.(int) (microtime(true) * 1000),
                'type' => 'custom',
                'leaf_certificate' => $certPem,
                'certificate_chain' => $chainPem,
                'private_key' => $keyPem,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(DigitaloceanErrorSanitizer::sanitize($e), 0);
        }

        if ($certId === '') {
            throw new RuntimeException('DigitalOcean CreateCertificate 未返回证书 id');
        }

        return $certId;
    }
}
