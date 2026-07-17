<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 华为云 SCM 证书管理上传器（storeKind=huawei_scm）。
 *
 * 对齐 certimate certmgr huaweicloud-scm 的 Upload：调华为云 SCM REST
 *   POST /v3/scm/certificates/import {name, certificate=完整链, private_key=私钥, enterprise_project_id?} → certificate_id
 * 返回 certificate_id 作为 remote_cert_id。
 *
 * 复用范围（对齐 certimate：scm/cdn/live/obs 的 certmgr 都用 huaweicloud-scm）：
 *   - scm 端点（HuaweicloudScmDeployer）：纯托管，bind no-op，certificate_id 即终态。
 *   - cdn 端点（HuaweicloudCdnDeployer）：上传拿 certificate_id 后 UpdateDomainMultiCertificates 绑定（ScmCertificateId）。
 *   - live 端点（HuaweicloudLiveDeployer）：上传拿 certificate_id 后 UpdateDomainHttpsCert（source=scm, cert_id）。
 *   - obs 端点（HuaweicloudObsDeployer）：上传拿 certificate_id 后 PutBucketCustomDomain（CertificateId）。
 *
 * storeKind 为全局 `huawei_scm`（**非 region 维度**）：SCM 证书托管默认区域 cn-north-4，与 certimate 一致
 * （certmgr huaweicloud-scm 的 createSDKClient region 为空时回落 cn-north-4）。CDN/OBS 等用 global 凭证，证书也按账号全局可见。
 *
 * 与 certimate 对齐的取舍：certimate 上传前先 ListCertificates 按域名/有效期/内容查重复用；本类省略该查重 ——
 * 插件 RemoteCertStore 已按 (access_id, store_kind, fingerprint) 去重，上传器只管上传（同 Ksyun/Baidu 上传器）。
 *
 * SDK client（HuaweicloudRestClient）经注入缝 $clientFactory（deployer 的 makeClient('scm', …)）构造 ——
 * 测试 override deployer::makeClient 即自动作用于此处，无需单独 mock 上传器。
 *
 * 企业项目 ID 取自 $credentials（HuaweicloudProvider credentialSchema 含 enterprise_project_id，是账号级凭证字段，
 * 对齐 certimate AccessConfigForHuaweiCloud.EnterpriseProjectId），非空才下发（对齐 certimate lo.EmptyableToPtr）。
 */
class HuaweiScmUploader implements CertUploaderInterface
{
    /** @param Closure(array<string,mixed>):object $clientFactory 返回 HuaweicloudRestClient（或测试 mock，需有 post() 方法） */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'huawei_scm';
    }

    /**
     * @param  array{access_key_id?:string,secret_access_key?:string,enterprise_project_id?:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 证书 + 中间证书拼成完整链上传（与 Ksyun/Baidu 上传器一致；certimate 的单 certPEM ≈ 此处 cert+chain）。
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        // 华为云证书命名：需符合命名规则（字母开头 + 字母数字下划线），毫秒时间戳保唯一（对齐 certimate certimate-{ms}）。
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        $body = [
            'name' => $certName,
            'certificate' => $fullChain,
            'private_key' => trim($keyPem),
        ];
        $enterpriseProjectId = isset($credentials['enterprise_project_id']) ? (string) $credentials['enterprise_project_id'] : '';
        if ($enterpriseProjectId !== '') {
            $body['enterprise_project_id'] = $enterpriseProjectId;
        }

        try {
            /** @var HuaweicloudRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $result = $client->post('/v3/scm/certificates/import', $body);
        } catch (Throwable $e) {
            throw new RuntimeException(HuaweicloudErrorSanitizer::sanitize($e), 0);
        }

        // 华为云 ImportCertificate 响应：{certificate_id}。
        $certId = $result['certificate_id'] ?? null;
        if (! is_string($certId) || $certId === '') {
            throw new RuntimeException('华为云 SCM ImportCertificate 未返回 certificate_id');
        }

        return $certId;
    }
}
