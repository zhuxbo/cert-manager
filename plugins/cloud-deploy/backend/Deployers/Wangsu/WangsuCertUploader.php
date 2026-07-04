<?php

namespace Plugins\CloudDeploy\Deployers\Wangsu;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 网宿云证书中心上传器（storeKind=wangsu_certificate）。
 *
 * 对齐 certimate pkg/core/certmgr/providers/wangsu-certificate 的 Upload：调网宿证书中心 REST
 *   POST /api/certificate {name, certificate, privateKey, comment} → 证书 id 在响应 Location 头。
 * 返回数字 certId 作为 remote_cert_id —— cdn deployer bind 时把 certId 转 int 塞进
 * BatchUpdateCertificateConfig；certificate deployer 为纯上传 no-op。
 *
 * 与 certimate 对齐的取舍：
 * - certificate 传**完整链**（证书本体 + 中间证书拼接），privateKey 传私钥（与 AliyunCas/Qiniu/Baidu
 *   上传器一致；网宿证书中心 certificate 字段接受 PEM 链）。
 * - certimate 上传前先 ListCertificates 按序列号/有效期查重复用；本类省略该查重 —— 插件的 RemoteCertStore
 *   已按 (access_id, store_kind, fingerprint) 去重，上传器只管上传。边界：RemoteCertStore 未命中但云端
 *   已存在时会多传一张证书，无害（不影响绑定正确性）。
 *
 * SDK client（WangsuRestClient）经注入缝 $clientFactory（deployer 的 makeClient('api', …)）构造 ——
 * 测试 override deployer::makeClient 即自动作用于此处，无需单独 mock 上传器。
 */
class WangsuCertUploader implements CertUploaderInterface
{
    /** @param Closure(array<string,mixed>):object $clientFactory 返回 WangsuRestClient */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'wangsu_certificate';
    }

    /**
     * @param  array{access_key_id?:string,access_key_secret?:string}  $credentials
     * @return string 云端证书数字 certId
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 证书 + 中间证书拼成完整链上传（与其他上传器一致）。
        $fullChain = trim($chainPem) === '' ? rtrim($certPem) : rtrim($certPem)."\n".trim($chainPem);
        // 网宿证书命名规则：字母/数字/下划线；用毫秒时间戳保唯一（对齐 certimate certimate_{ms}）。
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        try {
            /** @var WangsuRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $certId = $client->createCertificate($certName, $fullChain, $keyPem, 'upload from CloudDeploy');
        } catch (Throwable $e) {
            throw new RuntimeException(WangsuErrorSanitizer::sanitize($e), 0);
        }

        if ($certId === '') {
            throw new RuntimeException('网宿云证书中心 CreateCertificate 未返回 certId');
        }

        return $certId;
    }
}
