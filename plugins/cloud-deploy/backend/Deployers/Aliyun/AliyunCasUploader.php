<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\GetUserCertificateDetailRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\UploadUserCertificateRequest;
use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 阿里云 SSL 证书服务（CAS）上传器（storeKind=cas）。
 *
 * 上传流程（对齐 certimate aliyun-cas，删其 ListUserCertificateOrder 查重——RemoteCertStore 已按
 * (access_id, store_kind, fingerprint) 去重，上传器只管上传）：
 *   1. UploadUserCertificate(Name, Cert=完整链, Key) → CertId
 *   2. GetUserCertificateDetail(CertId, CertFilter=true) → CertIdentifier（格式 "{certId}-{region}"）
 * 返回 CertIdentifier 作为 remote_cert_id —— dcdn/vod bind 时拆出 CertId(int)+CertRegion。
 *
 * 重复上传核实结论：阿里云 CAS **不**对「上传同内容证书」报错，而是每次新建一个 CertId（certimate
 * 即因此才在上传前用 ListUserCertificateOrder 主动查重复用）。故本类无重复错误码可 catch；唯一边界
 * 是「RemoteCertStore 未命中但云端已存在」时会多传一张 CAS 证书，无害且可接受（不影响绑定正确性）。
 *
 * SDK client 经注入缝 $clientFactory（由 deployer 的 makeClient('cas', …) 提供）——
 * 测试 override deployer::makeClient 即自动作用于此处，无需单独 mock 上传器。
 */
class AliyunCasUploader implements CertUploaderInterface
{
    /** @param Closure(array<string,mixed>):object $clientFactory 返回 AlibabaCloud\SDK\Cas\V20200407\Cas */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'cas';
    }

    /**
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 证书 + 中间证书拼成完整链上传（与内联型 CDN 一致）
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        // 阿里云证书命名规则：字母/数字/下划线，避免冒号点号等；用毫秒时间戳保唯一
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        // 注意：SDK 异常（TeaError）本身 extends RuntimeException。故脱敏统一在 catch(Throwable) 内重抛，
        // try 块内**不**做业务校验抛 RuntimeException（否则会被自己 catch 后当 SDK 错误二次脱敏）；
        // 也**绝不**在外层 catch(RuntimeException){throw} 透传 SDK 异常——否则 TeaError 原始 message
        // （含签名 URI/AK）绕过脱敏泄露。两次 SDK 调用合并一个 try、共用一个 client，返回原始值，校验放 try 外。
        try {
            /** @var Cas $client */
            $client = ($this->clientFactory)($credentials);

            $certId = $client->uploadUserCertificate(new UploadUserCertificateRequest([
                'name' => $certName,
                'cert' => $fullChain,
                'key' => $keyPem,
            ]))->body?->certId;

            // 拿 CertIdentifier（"{certId}-{region}"），dcdn/vod 绑定需按 "-" 拆 CertId + CertRegion。
            // certId 为空时传 0 给详情接口必失败，但仍由下方 try 外校验给出明确文案——故此处先判空短路。
            $identifier = (is_int($certId) || (is_string($certId) && $certId !== ''))
                ? $client->getUserCertificateDetail(new GetUserCertificateDetailRequest([
                    'certId' => (int) $certId,
                    'certFilter' => true,
                ]))->body?->certIdentifier
                : null;
        } catch (Throwable $e) {
            throw new RuntimeException(AliyunErrorSanitizer::sanitize($e), 0);
        }

        if (! is_int($certId) && ! (is_string($certId) && $certId !== '')) {
            throw new RuntimeException('阿里云 UploadUserCertificate 未返回 CertId');
        }

        if (! is_string($identifier) || $identifier === '') {
            throw new RuntimeException('阿里云 GetUserCertificateDetail 未返回 CertIdentifier');
        }

        return $identifier;
    }
}
