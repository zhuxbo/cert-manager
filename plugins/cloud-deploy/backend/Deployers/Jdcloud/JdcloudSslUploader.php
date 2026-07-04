<?php

namespace Plugins\CloudDeploy\Deployers\Jdcloud;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 京东云 SSL 证书中心上传器（storeKind=jdcloud_ssl）。
 *
 * 对齐 certimate jdcloud-ssl Upload：
 *   POST /v1/sslCert:upload {certName, certFile, keyFile} → result.certId
 * 删其 DescribeCerts 查重（按 CN/SAN/有效期/私钥摘要复用）—— 插件 RemoteCertStore 已按
 * (access_id, store_kind, fingerprint) 去重，上传器只管上传。边界：RemoteCertStore 未命中但云端
 * 已存在时会多传一张证书，无害（不影响绑定正确性，京东云证书数量有配额，与 certimate 同不 GC）。
 *
 * 与 certimate 对齐的细节：
 * - certFile 传**完整链**（leaf + 中间证书），与本插件其他上传器（AliyunCas/Qiniu）一致。
 * - keyFile 传私钥并按 certimate 规范化为 CRLF 行尾 + 末尾追加 CRLF（certimate Upload 对 privkeyPEM
 *   做 TrimSpace + 统一 \r\n + 末尾补 \r\n，用于计算私钥摘要；这里保持同一线协议）。
 *
 * SDK client（JdcloudRestClient）经注入缝 $clientFactory（deployer 的 makeClient('ssl', …)）构造 ——
 * 测试 override deployer::makeClient 即自动作用于此处，无需单独 mock 上传器。
 */
class JdcloudSslUploader implements CertUploaderInterface
{
    /** @param Closure(array<string,mixed>):object $clientFactory 返回 JdcloudRestClient（或测试 mock，需有 uploadCert() 方法） */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'jdcloud_ssl';
    }

    /**
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @return string 云端证书 certId
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 证书 + 中间证书拼完整链（与 AliyunCas/Qiniu 上传器一致）
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        // 京东云证书命名规则：字母/数字/连字符；毫秒时间戳保唯一（对齐 certimate certimate-{ms}）
        $certName = 'clouddeploy-'.(int) (microtime(true) * 1000);
        // 私钥规范化为 CRLF + 末尾 CRLF（对齐 certimate Upload 私钥摘要前处理）
        $keyFile = str_replace(["\r\n", "\r", "\n"], "\n", trim($keyPem));
        $keyFile = str_replace("\n", "\r\n", $keyFile)."\r\n";

        try {
            /** @var JdcloudRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $certId = $client->uploadCert($certName, trim($fullChain), $keyFile);
        } catch (Throwable $e) {
            throw new RuntimeException(JdcloudErrorSanitizer::sanitize($e), 0);
        }

        if (! is_string($certId) || $certId === '') {
            throw new RuntimeException('京东云 SSL 证书中心 UploadCert 未返回 certId');
        }

        return $certId;
    }
}
