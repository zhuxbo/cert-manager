<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 火山引擎视频直播 Live（证书服务型）：证书经火山 Live 自有证书空间上传拿 ChainID（走 RemoteCertStore 去重），
 * 再绑定到直播流域名。对齐 certimate volcengine-live（volc-sdk-golang）：
 *   CreateCert（上传，VolcLiveUploader）→ BindCert {ChainID, Domain, HTTPS:true}
 *   （host live.volcengineapi.com，Version=2023-01-01，签名 service=live，region cn-north-1）
 *
 * 仅 exact 域名匹配（不做 wildcard/certsan 遍历）。Live 用自有证书空间（VolcLiveUploader，storeKind=volc_live），
 * 非证书中心。⚠ BindCert body 的 HTTPS 键为大写（对齐官方 SDK json tag）。
 */
class VolcLiveDeployer extends AbstractDeployer
{
    public const HOST = 'live.volcengineapi.com';

    public const VERSION = '2023-01-01';

    public function provider(): string
    {
        return 'volcengine';
    }

    public function product(): string
    {
        return 'live';
    }

    public function label(): string
    {
        return '火山引擎视频直播';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '直播流域名', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // Live 自有证书空间（非证书中心）；projectName 由凭证级配置注入（此处取不到凭证，留空，
        // certimate Live certmgr 的 ProjectName 来自凭证，上传时若需可在 makeClient 注入——保持与无 projectName 一致）。
        return new VolcLiveUploader(
            fn (array $credentials): object => $this->makeClient('live', $credentials),
        );
    }

    /**
     * @param  string  $certRef  Live ChainID
     * @param  array{access_key_id?:string,secret_access_key?:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        $chainId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $domain, $chainId) {
            /** @var VolcRestClient $client */
            $client = $this->makeClient('live', $credentials);
            $client->callJson('BindCert', self::VERSION, [
                'ChainID' => $chainId,
                'Domain' => $domain,
                'HTTPS' => true,
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            // Live 专属 host + 签名 service live + region cn-north-1
            'live' => new VolcRestClient(
                self::HOST,
                'live',
                'cn-north-1',
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return VolcErrorSanitizer::sanitize($e);
    }
}
