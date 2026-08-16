<?php

declare(strict_types=1);

namespace Plugins\CloudDeploy\Support;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use LogicException;
use Plugins\CloudDeploy\Deployers\Aws\AwsMetadataRoute;
use Plugins\CloudDeploy\Deployers\Oraclecloud\OracleMetadataRoute;
use RuntimeException;
use Throwable;

/**
 * 云 metadata 专用传输层。
 *
 * 它与普通 SafeHttpClientFactory 完全隔离：后者继续拒绝 link-local，本客户端则只接受
 * 工厂选定的代码内建 route enum，外部配置无法覆盖 method、address、path 或传输选项。
 */
final class CloudMetadataHttpClient
{
    public const CONNECT_TIMEOUT_SECONDS = 1.0;

    public const TIMEOUT_SECONDS = 2.0;

    /**
     * @param  class-string<CloudMetadataRoute>  $routeEnum
     */
    private function __construct(
        private readonly string $routeEnum,
        private readonly ClientInterface $client,
    ) {}

    public static function forAws(?ClientInterface $client = null): self
    {
        return new self(AwsMetadataRoute::class, $client ?? new Client);
    }

    public static function forOracle(?ClientInterface $client = null): self
    {
        return new self(OracleMetadataRoute::class, $client ?? new Client);
    }

    /**
     * @param  array<string,string>  $parameters
     * @param  array<string,string>  $context
     */
    public function request(
        CloudMetadataRoute $route,
        array $parameters = [],
        array $context = [],
    ): string {
        if (! $route instanceof \UnitEnum || $route::class !== $this->routeEnum) {
            throw new LogicException('Unsupported cloud metadata route');
        }

        $url = $route->baseUri().$route->path($parameters);

        try {
            $response = $this->client->request($route->method(), $url, [
                'headers' => $route->headers($context),
                'allow_redirects' => false,
                'proxy' => null,
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::TIMEOUT_SECONDS,
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException('Cloud metadata returned an unexpected status');
            }

            $body = (string) $response->getBody();
            if (strlen($body) > 65536) {
                throw new RuntimeException('Cloud metadata response is too large');
            }

            return $body;
        } catch (Throwable) {
            // 不携带 previous，也不拼接 body/header，避免 token 或临时凭证进入异常链。
            throw new RuntimeException('云 metadata 请求失败');
        }
    }
}
