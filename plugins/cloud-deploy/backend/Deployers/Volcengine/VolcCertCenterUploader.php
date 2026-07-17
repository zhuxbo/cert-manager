<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 火山引擎证书中心（Certificate Service）上传器（storeKind=volc_certcenter）。
 *
 * 火山多数端点（dcdn/alb/clb/apig/vod/waf/tos/certcenter）的证书都经证书中心上传换 InstanceId，
 * 各端点再把该 id 绑到资源。对齐 certimate certmgr/volcengine-certcenter：
 *   POST /?Action=ImportCertificate&Version=2024-10-01
 *   body {ProjectName?, CertificateInfo:{CertificateChain=完整链, PrivateKey}, Repeatable:false}
 *   → Result.InstanceId（重复证书走 Result.RepeatId）
 *
 * 删 certimate 端的查重（List/对比指纹）—— RemoteCertStore 已按 (access_id, store_kind, fingerprint) 去重，
 * 上传器只管上传；火山侧 Repeatable=false 亦自带「同证书返回既有 RepeatId」幂等。
 *
 * region：证书中心默认 cn-beijing（与 certimate createSDKClient 一致），由 deployer 透传其 region（空则 cn-beijing）。
 * SDK client 经注入缝 $clientFactory（由 deployer 的 makeClient('certcenter', …) 提供 VolcRestClient）——
 * 测试 override deployer::makeClient 即自动作用于此处。
 */
class VolcCertCenterUploader implements CertUploaderInterface
{
    /**
     * @param  Closure(array<string,mixed>):object  $clientFactory  返回 VolcRestClient（已按 certcenter region 构造）
     * @param  string  $projectName  火山项目名（凭证级，空则不传）
     */
    public function __construct(
        private readonly Closure $clientFactory,
        private readonly string $projectName = '',
    ) {}

    public function storeKind(): string
    {
        return 'volc_certcenter';
    }

    /**
     * @param  array<string,mixed>  $credentials
     * @return string 证书中心 InstanceId（或 RepeatId）
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 证书 + 中间证书拼成完整链上传（与其他上传器一致；certimate 的 certPEM 即完整链）
        $fullChain = rtrim($certPem)."\n".trim($chainPem);

        $body = [
            'CertificateInfo' => [
                'CertificateChain' => $fullChain,
                'PrivateKey' => $keyPem,
            ],
            'Repeatable' => false,
        ];
        if ($this->projectName !== '') {
            $body['ProjectName'] = $this->projectName;
        }

        try {
            /** @var VolcRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $result = $client->callJson('ImportCertificate', '2024-10-01', $body);
        } catch (Throwable $e) {
            throw new RuntimeException(VolcErrorSanitizer::sanitize($e), 0);
        }

        $instanceId = $result['InstanceId'] ?? null;
        $repeatId = $result['RepeatId'] ?? null;
        $certId = is_string($instanceId) && $instanceId !== '' ? $instanceId
            : (is_string($repeatId) ? $repeatId : '');

        if ($certId === '') {
            throw new RuntimeException('火山引擎 ImportCertificate 未返回 InstanceId/RepeatId');
        }

        return $certId;
    }
}
