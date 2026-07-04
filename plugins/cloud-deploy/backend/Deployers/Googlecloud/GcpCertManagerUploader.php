<?php

namespace Plugins\CloudDeploy\Deployers\Googlecloud;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * Google Certificate Manager 证书上传器（storeKind="gcp_certmanager:{location}"）。
 *
 * 对齐 certimate googlecloud-certificatemanager certmgr：创建 self-managed 证书（pemCertificate +
 * pemPrivateKey）→ 证书资源名 projects/{p}/locations/{l}/certificates/{certId}。
 * 删 certimate 上传前的 ListCertificates 查重（RemoteCertStore 已按 (access_id, store_kind, fingerprint)
 * 去重），上传器只管创建。上传完整链：pemCertificate 含 leaf + 中间证书。
 *
 * 关于 storeKind 与 project：RemoteCertStore::ensure 在 upload() **之前**调 storeKind() 做去重，
 * 而凭证（含 service account JSON / project）只在 upload() 才到达，故 storeKind 只能用 config 已知的
 * location 维度。这已足够隔离 —— RemoteCertStore 去重键含 access_id，而一个 access = 一套 service
 * account = 一个 GCP 项目，project 对同一 access 恒定，无需再进 store_kind。
 *
 * project 解析（upload 时）：优先 config.project（构造注入），缺省则取 service account JSON 的 project_id
 * （对齐 certimate GetProjectIDFromServiceAccountKey）。鉴权经 GoogleOAuth2 自签 JWT 换 access_token，
 * 再用 $clientFactory(token) 构造带 Bearer 的 REST client，两工厂经注入缝（测试可 mock）。
 * 私钥/密钥仅本地用，绝不进 remote_cert_id 或错误文案。
 */
class GcpCertManagerUploader implements CertUploaderInterface
{
    /**
     * @param  Closure():GoogleOAuth2  $oauthFactory  返回 OAuth2 helper（自签 JWT 换 token）
     * @param  Closure(string):object  $clientFactory  入参 access_token，返回 GooglecloudClient
     * @param  string  $project  config 注入的 GCP 项目 ID（可空，空则 upload 时从 SA JSON 派生）
     * @param  string  $location  GCP 地域（通常 global）
     */
    public function __construct(
        private readonly Closure $oauthFactory,
        private readonly Closure $clientFactory,
        private readonly string $project,
        private readonly string $location,
    ) {}

    public function storeKind(): string
    {
        return 'gcp_certmanager:'.$this->location;
    }

    /**
     * @param  array{credentials_json:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        $serviceAccountJson = isset($credentials['credentials_json']) ? (string) $credentials['credentials_json'] : '';
        if ($serviceAccountJson === '') {
            throw new RuntimeException('Google Cloud 缺少服务账号密钥（credentials_json）');
        }

        $project = $this->project !== '' ? $this->project : $this->projectFromServiceAccount($serviceAccountJson);
        if ($project === '') {
            throw new RuntimeException('Google Cloud 缺少项目 ID（project，且密钥内无 project_id）');
        }

        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        $certId = 'clouddeploy-'.(int) (microtime(true) * 1000);

        try {
            $oauth = ($this->oauthFactory)();
            $token = $oauth->fetchAccessToken($serviceAccountJson, GoogleOAuth2::SCOPE_CLOUD_PLATFORM);

            /** @var GooglecloudClient $client */
            $client = ($this->clientFactory)($token);
            $resourceName = $client->createCertificate($project, $this->location, $certId, [
                'description' => $certId,
                'selfManaged' => [
                    'pemCertificate' => $fullChain,
                    'pemPrivateKey' => $keyPem,
                ],
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(GooglecloudErrorSanitizer::sanitize($e), 0);
        }

        if ($resourceName === '') {
            throw new RuntimeException('Google Cloud 创建证书未返回资源名');
        }

        return $resourceName;
    }

    /**
     * 从 service account JSON 取 project_id（对齐 certimate GetProjectIDFromServiceAccountKey）。
     */
    private function projectFromServiceAccount(string $serviceAccountJson): string
    {
        $sa = json_decode($serviceAccountJson, true);

        return is_array($sa) && isset($sa['project_id']) ? (string) $sa['project_id'] : '';
    }
}
