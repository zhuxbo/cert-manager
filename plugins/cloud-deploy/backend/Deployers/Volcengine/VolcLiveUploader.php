<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 火山引擎视频直播（Live）证书上传器（storeKind=volc_live）。
 *
 * 火山 Live 用自有证书空间（非证书中心），对齐 certimate certmgr/volcengine-live：
 *   POST live.volcengineapi.com/?Action=CreateCert&Version=2023-01-01
 *   body {ProjectName?, CertName, Rsa:{Prikey=私钥, Pubkey=完整链}, UseWay:"https"}
 *   → Result.ChainID
 *
 * 删 certimate 端的查重（ListCertV2 + DescribeCertDetailSecretV2 对比）—— RemoteCertStore 去重，上传器只管上传。
 * 证书名 CertName 须符合火山命名规则，用 clouddeploy_{毫秒}。Live 签名 region 固定 cn-north-1。
 *
 * SDK client 经注入缝 $clientFactory（由 deployer 的 makeClient('live', …) 提供 VolcRestClient，host=live.volcengineapi.com）。
 */
class VolcLiveUploader implements CertUploaderInterface
{
    /**
     * @param  Closure(array<string,mixed>):object  $clientFactory  返回 VolcRestClient（live host）
     * @param  string  $projectName  火山项目名（空则不传）
     */
    public function __construct(
        private readonly Closure $clientFactory,
        private readonly string $projectName = '',
    ) {}

    public function storeKind(): string
    {
        return 'volc_live';
    }

    /**
     * @param  array<string,mixed>  $credentials
     * @return string Live ChainID
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        $body = [
            'CertName' => $certName,
            'Rsa' => [
                'Prikey' => $keyPem,
                'Pubkey' => $fullChain,
            ],
            'UseWay' => 'https',
        ];
        $projectName = is_string($credentials['project_name'] ?? null)
            ? $credentials['project_name']
            : $this->projectName;
        if ($projectName !== '') {
            $body['ProjectName'] = $projectName;
        }

        try {
            /** @var VolcRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $result = $client->callJson('CreateCert', '2023-01-01', $body);
        } catch (Throwable $e) {
            throw new RuntimeException(VolcErrorSanitizer::sanitize($e), 0);
        }

        $chainId = $result['ChainID'] ?? null;
        if (! is_string($chainId) || $chainId === '') {
            throw new RuntimeException('火山引擎 Live CreateCert 未返回 ChainID');
        }

        return $chainId;
    }
}
