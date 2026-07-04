<?php

namespace Plugins\CloudDeploy\Deployers\Byteplus;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * BytePlus 证书中心（Certificate Center）上传器（storeKind=byteplus_certcenter）。
 *
 * 对齐 certimate byteplus-certcenter 的 Upload：调证书中心 OpenAPI
 *   Action=UploadCertificate Version=2021-06-01
 *   body {CertificateInfo:{CertificateChain, PrivateKey}, ProjectName?, Repeatable:false}
 *   → Result.InstanceId（已存在时返回 Result.RepeatId）
 * 返回该 id 作为 remote_cert_id —— certcenter bind no-op；alb/clb 把它塞监听器 CertCenterCertificateId；
 * apig 塞 CertificateId；tos 塞 CustomDomainRule.CertId。
 *
 * 与 certimate 对齐的取舍：
 * - CertificateChain 传 **证书本体 + 中间证书拼成的完整链**（certimate 传 certPEM；本插件 RemoteCertStore
 *   按 fingerprint 去重，上传器只管上传，故拼全链更稳）。PrivateKey 传私钥。
 * - `Repeatable=false`：已存在相同证书时返回 RepeatId 复用，不重复创建（与 certimate 一致）。
 * - certimate 上传后会判 InstanceId / RepeatId 取非空者；本类同。
 *
 * region：certcenter 默认 ap-singapore-1（新加坡），由 deployer 的 makeClient('certcenter', …) 注入对应签名 region。
 * SDK client（BytePlusRestClient）经注入缝 $clientFactory —— 测试 override deployer::makeClient 即自动作用于此处。
 */
class BytePlusCertCenterUploader implements CertUploaderInterface
{
    /** @param Closure(array<string,mixed>):object $clientFactory 返回 BytePlusRestClient（或测试 mock，需有 openApi() 方法） */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'byteplus_certcenter';
    }

    /**
     * @param  array{access_key_id:string,secret_access_key:string,project_name?:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        $body = [
            'CertificateInfo' => [
                'CertificateChain' => trim($fullChain),
                'PrivateKey' => trim($keyPem),
            ],
            'Repeatable' => false,
        ];
        $projectName = (string) ($credentials['project_name'] ?? '');
        if ($projectName !== '') {
            $body['ProjectName'] = $projectName;
        }

        try {
            /** @var BytePlusRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $result = $client->openApi('POST', 'UploadCertificate', '2021-06-01', [], $body);
        } catch (Throwable $e) {
            throw new RuntimeException(BytePlusErrorSanitizer::sanitize($e), 0);
        }

        // 新建返回 InstanceId；已存在（Repeatable=false 命中）返回 RepeatId。
        $instanceId = $result->InstanceId ?? null;
        $repeatId = $result->RepeatId ?? null;
        $certId = is_string($instanceId) && $instanceId !== '' ? $instanceId
            : (is_string($repeatId) && $repeatId !== '' ? $repeatId : '');

        if ($certId === '') {
            throw new RuntimeException('BytePlus 证书中心 UploadCertificate 未返回证书 id（InstanceId/RepeatId 均空）');
        }

        return $certId;
    }
}
