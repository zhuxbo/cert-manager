<?php

namespace Plugins\CloudDeploy\Deployers\Upyun;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 又拍云控制台 SSL 证书上传器（storeKind=upyun_ssl）。
 *
 * 上传流程（对齐 certimate upyun-ssl certmgr）：
 *   POST /api/https/certificate/ {certificate=完整链, private_key} → data.result.certificate_id
 *
 * 返回单串 certificate_id 作为 remote_cert_id —— cdn / file 两端点 bind 时直接用此 certId（二者绑定
 * 标识空间一致，无 Qiniu 那种 certID/certName 复合需求）。
 *
 * SDK client 经注入缝 $clientFactory（由 deployer 的 makeClient('api', …) 提供 UpyunRestClient）——
 * 测试 override deployer::makeClient 即自动作用于此处，无需单独 mock 上传器。
 */
class UpyunSslUploader implements CertUploaderInterface
{
    /** @param Closure(array<string,mixed>):object $clientFactory 返回 UpyunRestClient */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'upyun_ssl';
    }

    /**
     * @param  array{username:string,password:string}  $credentials
     * @return string 云端证书 certificate_id
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 证书 + 中间证书拼成完整链上传（与 Qiniu/AliyunCas 上传器一致；certimate 的单 certPEM ≈ 此处 cert+chain）
        $fullChain = rtrim($certPem)."\n".trim($chainPem);

        try {
            /** @var UpyunRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $certId = $client->uploadHttpsCertificate($fullChain, $keyPem);
        } catch (Throwable $e) {
            throw new RuntimeException(UpyunErrorSanitizer::sanitize($e), 0);
        }

        if (! is_string($certId) || $certId === '') {
            throw new RuntimeException('又拍云 UploadHttpsCertificate 未返回 certificate_id');
        }

        return $certId;
    }
}
