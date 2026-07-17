<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 华为云直播 Live（证书服务型，region 维度）。
 *
 * 对齐 certimate deployer huaweicloud-live 的 exact 路径：
 *   1. 经 HuaweiScmUploader 把证书托管到 SCM 拿 certificate_id（store_kind=huawei_scm，走 RemoteCertStore 去重）。
 *   2. 用 global 凭证调 IAM 反查 region → projectId（ResolvesHuaweiProjectId）。
 *   3. bind 调 Live UpdateDomainHttpsCert（PUT /v1/{project_id}/guard/https-cert?domain={domain}）：
 *      body {tls_certificate:{source:"scm", cert_id}}。
 *
 * Live 为 region 服务（live.{region}.myhuaweicloud.com，basic 凭证 + projectId），对齐 certimate createSDKClient 用
 * basic.WithProjectId。region 必填（路径 {project_id} 与 endpoint 都需要）。
 *
 * 与 certimate 对齐的取舍：certimate 支持 exact/certsan。本端点**仅实现 exact**（domain 必填、单域名绑定），
 * 不做 certsan 的「列举全部域名再 SAN 匹配」——与插件其他端点「仅 exact」口径一致。
 */
class LiveDeployer extends AbstractDeployer
{
    use ResolvesHuaweiProjectId;

    public function provider(): string
    {
        return 'huaweicloud';
    }

    public function product(): string
    {
        return 'live';
    }

    public function label(): string
    {
        return '华为云视频直播 Live';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'domain', 'label' => '直播流域名', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // Live 证书走 SCM 托管（region-less，回落 cn-north-4），与 Live 业务 region 无关（certimate certmgr 用空 region）。
        return new HuaweiScmUploader(
            fn (array $credentials): object => $this->makeClient('scm', $credentials),
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（SCM certificate_id）
     * @param  array<string,mixed>  $credentials
     * @param  array{region:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $domain = (string) $this->requireConfig($config, 'domain');
        $scmCertId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $region, $domain, $scmCertId) {
            $projectId = $this->resolveProjectId($credentials, $region);

            /** @var HuaweicloudRestClient $client */
            $client = $this->makeClient('live', $credentials, $region, $projectId);

            // UpdateDomainHttpsCert：source=scm 表示使用 SCM 托管证书。
            $client->put("/v1/$projectId/guard/https-cert", [
                'tls_certificate' => [
                    'source' => 'scm',
                    'cert_id' => $scmCertId,
                ],
            ], ['domain' => $domain]);
        });
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials, string $region = '', string $projectId = ''): object
    {
        return match ($kind) {
            // IAM 全局（global 凭证，无 projectId）。
            'iam' => new HuaweicloudRestClient(
                $this->iamHost(),
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            // Live region 服务（basic 凭证 + projectId）。
            'live' => new HuaweicloudRestClient(
                $this->regionalHost('live', $region),
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
                $projectId,
            ),
            // SCM 托管走 region 服务（回落 cn-north-4）。
            'scm' => new HuaweicloudRestClient(
                $this->scmHost(),
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return HuaweicloudErrorSanitizer::sanitize($e);
    }
}
