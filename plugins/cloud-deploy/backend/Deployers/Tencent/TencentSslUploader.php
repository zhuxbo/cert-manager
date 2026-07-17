<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use TencentCloud\Ssl\V20191205\Models\UploadCertificateRequest;
use Throwable;

/**
 * 腾讯云 SSL 证书服务上传器（storeKind=tencent_ssl）。
 *
 * SDK client 构造经注入缝 $clientFactory（由 deployer 的 makeClient('ssl', …) 提供）——
 * 测试 override deployer::makeClient 即自动作用于此处，无需单独 mock 上传器。
 */
class TencentSslUploader implements CertUploaderInterface
{
    /** @param Closure(array<string,mixed>):object $clientFactory 返回 TencentCloud\Ssl\V20191205\SslClient */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'tencent_ssl';
    }

    /**
     * @param  array{secret_id:string,secret_key:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        $fullChain = rtrim($certPem)."\n".trim($chainPem);

        try {
            $client = ($this->clientFactory)($credentials);
            // 腾讯 AbstractModel 无数组构造（区别于阿里 Tea\Model），必须经 deserialize 填充
            $req = new UploadCertificateRequest;
            $req->deserialize([
                'CertificatePublicKey' => $fullChain,
                'CertificatePrivateKey' => $keyPem,
                'CertificateType' => 'SVR',
            ]);
            $resp = $client->UploadCertificate($req);
        } catch (Throwable $e) {
            throw new RuntimeException(TencentErrorSanitizer::sanitize($e), 0);
        }

        $certId = $resp->getCertificateId();
        if (! is_string($certId) || $certId === '') {
            throw new RuntimeException('腾讯云 UploadCertificate 未返回 CertificateId');
        }

        return $certId;
    }
}
