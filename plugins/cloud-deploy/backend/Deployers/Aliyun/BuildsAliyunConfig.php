<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use Darabonba\OpenApi\Models\Config;

/**
 * 阿里云 darabonba OpenApi Config 构造单一来源（G3：显式读/连超时，防 TCP 黑洞无限挂起）。
 *
 * darabonba Config 默认**无读超时** → 上游挂死时 SDK 调用无限阻塞（挂到 CloudDeployJob 的
 * $timeout=55 被 SIGALRM 击杀，落 reserved+600s 而非优雅退避重试）。本 trait 统一注入
 * readTimeout/connectTimeout（毫秒），全 Aliyun deployer 的 makeClient 一律经此构造 Config，
 * 守门 grep 断言 `Deployers/Aliyun` 下 `new Config(` 仅本 trait 一处。
 *
 * **单次调用最坏墙钟 = readTimeout + connectTimeout 之和**：darabonba 把 Guzzle 总 `timeout` 设为
 * `(readTimeout + connectTimeout)/1000` 秒（vendor/alibabacloud/darabonba/src/Dara.php:368），非仅
 * read。故 §G2.3 预算算式的 T 必须取二者之和（aliyunCallBudgetSeconds()，当前 7+3=10s），
 * CloudDeployPollBudgetTest 据此计算断言（改任一常量即破预算、测试红）。
 */
trait BuildsAliyunConfig
{
    /** SDK 读超时（毫秒）= 7s。 */
    public const ALIYUN_READ_TIMEOUT_MS = 7000;

    /** SDK 连接超时（毫秒）= 3s。 */
    public const ALIYUN_CONNECT_TIMEOUT_MS = 3000;

    /**
     * 单次 SDK 调用最坏墙钟（秒）= read+connect 之和（darabonba Dara.php:368 把 Guzzle 总 timeout
     * 设为二者之和）。长轮询预算 T 的单一来源。
     */
    public static function aliyunCallBudgetSeconds(): int
    {
        return intdiv(self::ALIYUN_READ_TIMEOUT_MS + self::ALIYUN_CONNECT_TIMEOUT_MS, 1000);
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function aliyunConfig(array $credentials, string $endpoint): Config
    {
        return new Config([
            'accessKeyId' => $credentials['access_key_id'] ?? '',
            'accessKeySecret' => $credentials['access_key_secret'] ?? '',
            'endpoint' => $endpoint,
            'readTimeout' => self::ALIYUN_READ_TIMEOUT_MS,
            'connectTimeout' => self::ALIYUN_CONNECT_TIMEOUT_MS,
        ]);
    }
}
