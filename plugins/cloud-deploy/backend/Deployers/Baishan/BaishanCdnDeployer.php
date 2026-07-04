<?php

namespace Plugins\CloudDeploy\Deployers\Baishan;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 白山云 CDN（证书服务型）：证书先上传到白山云拿 cert_id（走 RemoteCertStore 去重），再设置到域名 https 配置。
 *
 * 对齐 certimate baishan-cdn 的 deployToDomain（DEPLOY_TARGET_DOMAIN + exact）：
 *   1. 证书经 BaishanCertUploader 上传（POST /v2/domain/certificate）拿 cert_id（store_kind=baishan，走 RemoteCertStore 去重）。
 *   2. GET /v2/domain/config?domains={domain}&config[]=https 查询域名既有 https 配置（保留 force_https/http2/ocsp 不被覆盖）。
 *   3. POST /v2/domain/config {domains, config:{https:{cert_id, force_https, http2, ocsp}}} 设置新证书。
 *
 * 与 certimate 对齐的取舍：certimate 支持 DEPLOY_TARGET_DOMAIN（域名）与 DEPLOY_TARGET_CERTIFICATE（按既有证书 ID 替换）
 * 两类目标 + exact 匹配。本端点**仅实现 DEPLOY_TARGET_DOMAIN + exact**（domain 必填、精确域名），与插件其他端点
 * （KsyunCdn/Dogecloud/Baidu cdn 等）「仅 exact」的简化口径一致。
 */
class BaishanCdnDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'baishan';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '白山云 CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new BaishanCertUploader(fn (array $credentials): object => $this->makeClient('cdn', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（白山云 cert_id，十进制字符串）
     * @param  array{api_token:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        $certId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $domain, $certId) {
            /** @var BaishanRestClient $client */
            $client = $this->makeClient('cdn', $credentials);

            // 查询既有 https 配置（保留 force_https/http2/ocsp）
            $getResp = $client->get('/v2/domain/config', ['domains' => $domain], ['config' => ['https']]);
            $data = is_array($getResp['data'] ?? null) ? $getResp['data'] : [];
            if ($data === []) {
                throw new BaishanApiException('DomainNotFound', "未找到白山云域名: $domain");
            }
            $existingHttps = is_array($data[0]['config']['https'] ?? null) ? $data[0]['config']['https'] : [];

            // 设置域名证书：cert_id 换新，其余 https 字段沿用既有值（不存在则不下发该字段）
            $https = ['cert_id' => $certId];
            foreach (['force_https', 'http2', 'ocsp'] as $key) {
                if (array_key_exists($key, $existingHttps) && $existingHttps[$key] !== null) {
                    $https[$key] = $existingHttps[$key];
                }
            }

            $client->post('/v2/domain/config', [
                'domains' => $domain,
                'config' => ['https' => $https],
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'cdn' => new BaishanRestClient($credentials['api_token'] ?? ''),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return BaishanErrorSanitizer::sanitize($e);
    }
}
