<?php

namespace Plugins\CloudDeploy\Deployers\Byteplus;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * BytePlus 视频直播（Media Live）证书上传器（storeKind=byteplus_medialive）。
 *
 * 对齐 certimate byteplus-medialive 的 certmgr Upload：调直播 OpenAPI（service=live）
 *   Action=CreateCert Version=2023-01-01
 *   body {ProjectName?, CertName, Rsa:{Prikey, Pubkey}, UseWay:"https"}  →  Result.ChainID
 * 返回 ChainID 作为 remote_cert_id —— medialive bind 时塞进 BindCert.ChainID。
 *
 * 关键：直播证书用 ChainID 标识（与证书中心 InstanceId、CDN CertId 都是不同标识空间），故单独
 * storeKind=byteplus_medialive 隔离去重。
 *
 * 与 certimate 对齐的取舍：
 * - certimate 上传前先 ListCertV2 + DescribeCertDetailSecretV2 逐条比对证书内容查重复用；本类省略
 *   （RemoteCertStore 已按 fingerprint 去重）。
 * - Rsa.Pubkey 传**完整链**（cert+chain），Rsa.Prikey 传私钥（certimate Pubkey 传 certPEM；拼全链更稳）。
 * - UseWay 固定 "https"。CertName 为 clouddeploy_{毫秒}（符合命名规则）。
 *
 * 直播为 region-less（统一网关，签名 region 用 cn-north-1 占位）。
 */
class BytePlusMediaLiveUploader implements CertUploaderInterface
{
    /** @param Closure(array<string,mixed>):object $clientFactory 返回 BytePlusRestClient（或测试 mock，需有 openApi() 方法） */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'byteplus_medialive';
    }

    /**
     * @param  array{access_key_id:string,secret_access_key:string,project_name?:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        $body = [
            'CertName' => $certName,
            'Rsa' => [
                'Prikey' => trim($keyPem),
                'Pubkey' => trim($fullChain),
            ],
            'UseWay' => 'https',
        ];
        $projectName = (string) ($credentials['project_name'] ?? '');
        if ($projectName !== '') {
            $body['ProjectName'] = $projectName;
        }

        try {
            /** @var BytePlusRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $result = $client->openApi('POST', 'CreateCert', '2023-01-01', [], $body);
        } catch (Throwable $e) {
            throw new RuntimeException(BytePlusErrorSanitizer::sanitize($e), 0);
        }

        $chainId = $result->ChainID ?? null;
        if (! is_string($chainId) || $chainId === '') {
            throw new RuntimeException('BytePlus 视频直播 CreateCert 未返回 ChainID');
        }

        return $chainId;
    }
}
