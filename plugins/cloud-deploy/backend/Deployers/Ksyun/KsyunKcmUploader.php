<?php

namespace Plugins\CloudDeploy\Deployers\Ksyun;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 金山云证书管理 KCM 上传器（storeKind=ksyun_kcm）。
 *
 * 对齐 certimate ksyun-kcm 的 Upload：调金山云 KCM REST
 *   POST / {Action:UploadCertificate, Version:2016-03-04, ProjectId, CertName, CertFile=完整链, CertKey=私钥} → Ret.CertId
 * 返回 CertId 作为 remote_cert_id。
 *
 * 复用范围：
 *   - kcm 端点（KsyunKcmDeployer）：纯托管，bind no-op，CertId 即终态。
 *   - slb 端点（KsyunSlbDeployer）：先经本上传器托管到 KCM 拿 SslCertificateId，再 bind 时
 *     ModifyCertificate 把既有负载均衡证书指向该 SslCertificateId（对齐 certimate ksyun-slb 的 Replace）。
 *
 * storeKind 为全局 `ksyun_kcm`（**非 region 维度**）：KCM 证书托管为 region-less，与 certimate 一致
 * （ksyun-slb 的 Replace 也是先 region-less 上传到 KCM 再按 region ModifyCertificate）。
 *
 * 与 certimate 对齐的取舍：certimate 上传前先 ListUserCertificates 按域名/颁发者/SHA1 指纹查重复用；本类省略
 * 该查重 —— 插件 RemoteCertStore 已按 (access_id, store_kind, fingerprint) 去重，上传器只管上传。边界：
 * RemoteCertStore 未命中但云端已存在时会多托管一张证书，无害（不影响绑定正确性）；金山云对「重复的证书文件」
 * 会返回错误码，由 sanitizer 归一为可读文案。
 *
 * SDK client（KsyunRestClient）经注入缝 $clientFactory（deployer 的 makeClient('kcm', …)）构造 ——
 * 测试 override deployer::makeClient 即自动作用于此处，无需单独 mock 上传器。
 */
class KsyunKcmUploader implements CertUploaderInterface
{
    /** @param Closure(array<string,mixed>):object $clientFactory 返回 KsyunRestClient（或测试 mock，需有 post() 方法） */
    public function __construct(
        private readonly Closure $clientFactory,
        private readonly string $projectId = '',
    ) {}

    public function storeKind(): string
    {
        return $this->projectId !== '' && $this->projectId !== '0'
            ? 'ksyun_kcm:'.$this->projectId
            : 'ksyun_kcm';
    }

    /**
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 证书 + 中间证书拼成完整链上传（与 Qiniu/Aliyun 上传器一致；certimate 的单 certPEM ≈ 此处 cert+chain）。
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        // 金山云证书命名：毫秒时间戳保唯一（对齐 certimate certimate-{ms}）。
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        try {
            /** @var KsyunRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $result = $client->post('/', [
                'Action' => 'UploadCertificate',
                'Version' => '2016-03-04',
                'ProjectId' => $this->projectId !== '' ? $this->projectId : '0',
                'CertName' => $certName,
                'CertFile' => $fullChain,
                'CertKey' => trim($keyPem),
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(KsyunErrorSanitizer::sanitize($e), 0);
        }

        // 金山云 UploadCertificate 响应：{Success, Ret:{CertID, CertName, ...}}（注意 Ret.CertID 大小写）。
        $ret = is_array($result['Ret'] ?? null) ? $result['Ret'] : [];
        $certId = $ret['CertID'] ?? null;
        if (! is_string($certId) || $certId === '') {
            throw new RuntimeException('金山云 KCM UploadCertificate 未返回 CertID');
        }

        return $certId;
    }
}
