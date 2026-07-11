<?php

namespace Plugins\CloudDeploy\Deployers\Aws;

/**
 * AWS SDK client 构造配置单一来源（G3：显式 connect/read 超时，防 TCP 黑洞无限挂起）。
 *
 * aws-sdk-php 默认无读超时 → 上游挂死时调用无限阻塞（挂到 CloudDeployJob 的 $timeout=55 被 SIGALRM
 * 击杀，落 reserved+600s 而非优雅退避重试）。本 trait 统一注入 `http.connect_timeout/timeout`（秒），
 * 8 个 AWS deployer 的 makeClient 一律经此构造 $cfg（对齐 Aliyun 半边的 BuildsAliyunConfig）。
 *
 * timeout=15 依据：最多调用端点 CloudFront（getDistributionConfig + updateDistribution）+ ACM upload
 * = 3 次 × 15 = 45 ≤ 50，闭合 CloudDeployJob 的 $timeout=55 预算（AWS 端点均非长轮询）。
 */
trait BuildsAwsClientConfig
{
    /** 连接超时（秒）。 */
    public const AWS_CONNECT_TIMEOUT_SECONDS = 5;

    /** 单次请求总超时（秒）。 */
    public const AWS_TIMEOUT_SECONDS = 15;

    /**
     * @param  array<string,mixed>  $credentials
     * @return array<string,mixed>
     */
    protected function awsClientConfig(array $credentials, string $region): array
    {
        return [
            'version' => 'latest',
            'region' => $region !== '' ? $region : 'us-east-1',
            'credentials' => [
                'key' => $credentials['access_key_id'] ?? '',
                'secret' => $credentials['secret_access_key'] ?? '',
            ],
            'http' => [
                'connect_timeout' => self::AWS_CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::AWS_TIMEOUT_SECONDS,
            ],
        ];
    }
}
