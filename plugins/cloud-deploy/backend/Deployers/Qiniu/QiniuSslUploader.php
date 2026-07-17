<?php

namespace Plugins\CloudDeploy\Deployers\Qiniu;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 七牛云 SSL 证书中心上传器（storeKind=qiniu）。
 *
 * 上传流程（对齐 certimate qiniu-sslcert，删其 GetSslCertList 查重 —— RemoteCertStore 已按
 * (access_id, store_kind, fingerprint) 去重，上传器只管上传）：
 *   POST /sslcert {name, common_name, ca=完整链, pri=私钥} → certID
 *
 * 返回值约定（关键差异）：七牛 cdn/kodo 绑定用 **certID**，而 pili 绑定用 **certName**（七牛 Pili
 * SetDomainCert 只收 certName，不收 certID）。CertUploaderInterface::upload 只返回单串 remote_cert_id，
 * 故本类返回**复合串 "{certID}|{certName}"**（仿 AliyunCasUploader 返回 "{certId}-{region}" 复合
 * CertIdentifier 的做法），各 deployer 经 ParsesQiniuCertRef 拆出所需的一端。certName 由本类生成
 * （clouddeploy_{毫秒}，符合七牛命名规则：字母/数字/下划线），certID 由接口返回，二者均不含 "|"。
 *
 * SDK client 经注入缝 $clientFactory（由 deployer 的 makeClient('api', …) 提供 QiniuRestClient）——
 * 测试 override deployer::makeClient 即自动作用于此处，无需单独 mock 上传器。
 */
class QiniuSslUploader implements CertUploaderInterface
{
    use ParsesQiniuCertRef;

    /** @param Closure(array<string,mixed>):object $clientFactory 返回 QiniuRestClient */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'qiniu';
    }

    /**
     * @param  array{access_key:string,secret_key:string}  $credentials
     * @return string 复合 remote_cert_id："{certID}|{certName}"
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 证书 + 中间证书拼成完整链上传（与 AliyunCas/TencentSsl 上传器一致；certimate 的单 certPEM ≈ 此处 cert+chain）
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        $commonName = $this->extractCommonName($certPem);
        // 七牛证书命名规则：字母/数字/下划线；用毫秒时间戳保唯一
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        try {
            /** @var QiniuRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $certId = $client->uploadSslCert($certName, $commonName, $fullChain, $keyPem);
        } catch (Throwable $e) {
            throw new RuntimeException(QiniuErrorSanitizer::sanitize($e), 0);
        }

        if (! is_string($certId) || $certId === '') {
            throw new RuntimeException('七牛云 UploadSslCert 未返回 certID');
        }

        return $this->buildCertRef($certId, $certName);
    }

    /** 从证书 PEM 解析 CommonName（best-effort，PHP 内置 openssl，无外部二进制）；失败回空串（仅作上传标签）。 */
    private function extractCommonName(string $certPem): string
    {
        if (! function_exists('openssl_x509_parse')) {
            return '';
        }

        $parsed = @openssl_x509_parse($certPem);
        $cn = is_array($parsed) ? ($parsed['subject']['CN'] ?? null) : null;

        return is_string($cn) ? $cn : '';
    }
}
