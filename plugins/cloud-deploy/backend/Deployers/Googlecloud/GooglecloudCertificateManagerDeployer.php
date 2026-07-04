<?php

namespace Plugins\CloudDeploy\Deployers\Googlecloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * Google Cloud Certificate Manager（仅上传，证书服务型）。
 *
 * 对齐 certimate googlecloud-certificatemanager：Deploy 把证书创建为 Certificate Manager 的 self-managed
 * 证书（拿证书资源名 projects/{p}/locations/{l}/certificates/{id}），**不绑定 Load Balancer 等资源**
 * （后续在控制台 / 其他流程引用证书资源名）。
 *
 * 插件模型：usesRemoteCertStore=true + GcpCertManagerUploader（store_kind="gcp_certmanager:{location}"），
 * bind 为 no-op —— 上传由 CloudDeployJob 经 RemoteCertStore::ensure(certUploader($config)) 完成。
 *
 * 鉴权：Google service-account OAuth2（自签 RS256 JWT 换 access_token，scope cloud-platform），再 Bearer
 * 调 Certificate Manager REST。仅用 GuzzleHttp + PHP openssl，不依赖 google/apiclient（见 GoogleOAuth2）。
 *
 * config：location（选填，默认 global）。project 走凭证（缺省从 service account JSON 的 project_id 派生）。
 */
class GooglecloudCertificateManagerDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'googlecloud';
    }

    public function product(): string
    {
        return 'certificatemanager';
    }

    public function label(): string
    {
        return 'Google Cloud 证书管理器（仅上传）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'location', 'label' => '地域（选填，默认 global）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        $location = isset($config['location']) && (string) $config['location'] !== ''
            ? (string) $config['location']
            : 'global';
        $project = isset($config['project']) ? (string) $config['project'] : '';

        return new GcpCertManagerUploader(
            fn (): GoogleOAuth2 => $this->makeClient('oauth', []),
            fn (string $token): object => $this->makeClient('api', [], $token),
            $project,
            $location,
        );
    }

    /**
     * 纯上传端点：上传已由 RemoteCertStore::ensure 完成，bind 无后续动作。
     *
     * @param  string  $certRef  remote_cert_id（证书资源名，本端点不使用）
     * @param  array{credentials_json:string}  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // no-op：证书已创建为 Certificate Manager self-managed 证书，无资源绑定。
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials, string $token = ''): object
    {
        return match ($kind) {
            'oauth' => new GoogleOAuth2,
            'api' => new GooglecloudClient($token),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return GooglecloudErrorSanitizer::sanitize($e);
    }
}
