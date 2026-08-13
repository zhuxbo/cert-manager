<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/** 华为云视频点播 VOD：SCM 托管证书绑定到单个点播加速域名。 */
class VodDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use ResolvesHuaweiProjectId;

    public function provider(): string
    {
        return 'huaweicloud';
    }

    public function product(): string
    {
        return 'vod';
    }

    public function label(): string
    {
        return '华为云视频点播 VOD';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'domain_match_pattern', 'label' => '域名匹配模式', 'type' => 'string', 'required' => false, 'default' => 'exact'],
            ['key' => 'domain', 'label' => '点播加速域名', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new HuaweiScmUploader(fn (array $credentials): object => $this->makeClient('scm', $credentials));
    }

    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $pattern = strtolower((string) ($config['domain_match_pattern'] ?? 'exact'));
        if (! in_array($pattern, ['', 'exact'], true)) {
            $this->fail("VOD 不支持的域名匹配模式: $pattern");
        }
        $domain = (string) $this->requireConfig($config, 'domain');
        $certId = is_array($certRef) ? (string) ($certRef['remote_cert_id'] ?? '') : $certRef;

        $this->guardSdk(function () use ($credentials, $region, $domain, $certId): void {
            $projectId = $this->resolveProjectId($credentials, $region);
            /** @var HuaweicloudRestClient $client */
            $client = $this->makeClient('vod', $credentials, $region, $projectId);
            $path = "/v1.0/$projectId/asset/domain/https";
            $current = $client->get($path, ['domain' => $domain]);
            if ((string) ($current['cert_id'] ?? '') === $certId) {
                return;
            }

            $body = [
                'domain' => $domain,
                'source' => 'scm',
                'cert_id' => $certId,
                'https_status' => 1,
            ];
            foreach (['http2', 'force_redirect_https'] as $key) {
                if (array_key_exists($key, $current)) {
                    $body[$key] = $current[$key];
                }
            }
            $client->put($path, $body);
        });
    }

    protected function makeClient(string $kind, array $credentials, string $region = '', string $projectId = ''): object
    {
        return match ($kind) {
            'iam' => $this->safeRestClient(
                $this->iamHost(),
                $credentials,
            ),
            'vod' => $this->safeRestClient(
                $this->regionalHost('vod', $region),
                $credentials,
                $projectId,
            ),
            'scm' => $this->safeRestClient(
                $this->scmHost(),
                $credentials,
            ),
        };
    }

    /** @param array<string,mixed> $credentials */
    private function safeRestClient(string $host, array $credentials, string $projectId = ''): HuaweicloudRestClient
    {
        return new HuaweicloudRestClient(
            $host,
            $credentials['access_key_id'] ?? '',
            $credentials['secret_access_key'] ?? '',
            $projectId,
            $this->outboundHttpClient("https://$host/", [
                'connect_timeout' => 10,
                'timeout' => 30,
            ]),
        );
    }

    protected function sanitize(Throwable $e): string
    {
        return HuaweicloudErrorSanitizer::sanitize($e);
    }
}
