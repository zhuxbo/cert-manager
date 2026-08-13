<?php

namespace Plugins\CloudDeploy\Deployers\Dogecloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\MatchesCertificateHostnames;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * 多吉云 CDN（证书服务型）：证书先上传到多吉云拿 certId（走 RemoteCertStore 去重），再绑定到 CDN 域名。
 *
 * 对齐 certimate dogecloud-cdn 的 deployToDomain（DEPLOY_TARGET 默认 exact）：
 *   1. 证书经 DogecloudSslUploader 上传（POST /cdn/cert/upload.json）拿 certId（store_kind=dogecloud，走 RemoteCertStore 去重）。
 *   2. bind 调 POST /cdn/cert/bind.json {id: certId(int64), domain} 把证书绑定到加速域名。
 *
 * 支持 exact / certsan：certsan 经可选远端证书材料契约取得叶证书，ListCdnDomain 后按 SAN/CN
 * 过滤非 offline 域名并批量绑定；私钥不进入 bind 上下文。
 */
class DogecloudCdnDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use MatchesCertificateHostnames;

    public function provider(): string
    {
        return 'dogecloud';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '多吉云 CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain_match_pattern', 'label' => '域名匹配（exact/certsan）', 'type' => 'string', 'required' => false],
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new DogecloudSslUploader(fn (array $credentials): object => $this->makeClient('cdn', $credentials));
    }

    /**
     * @param  string|array{remote_cert_id:string,cert:string,chain:string}  $certRef  证书 id 或 opt-in 的 leaf/中间链上下文
     * @param  array{access_key:string,secret_key:string}  $credentials
     * @param  array{domain?:string,domain_match_pattern?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $pattern = (string) ($config['domain_match_pattern'] ?? 'exact');
        $certId = (int) (is_array($certRef) ? ($certRef['remote_cert_id'] ?? 0) : $certRef);
        $certificate = is_array($certRef) ? (string) ($certRef['cert'] ?? '') : '';
        $domain = $pattern === 'certsan' ? '' : (string) $this->requireConfig($config, 'domain');

        $this->guardSdk(function () use ($credentials, $domain, $pattern, $certificate, $certId) {
            /** @var DogecloudRestClient $client */
            $client = $this->makeClient('cdn', $credentials);
            if ($pattern === '' || $pattern === 'exact') {
                $domains = [$domain];
            } elseif ($pattern === 'certsan') {
                $response = $client->get('/cdn/domain/list.json');
                $items = is_array($response['data']['domains'] ?? null) ? $response['data']['domains'] : [];
                $domains = [];
                foreach ($items as $item) {
                    if (! is_array($item) || strtolower((string) ($item['status'] ?? '')) === 'offline') {
                        continue;
                    }
                    $name = (string) ($item['name'] ?? '');
                    if ($name !== '' && $this->certificateMatchesHostname($certificate, $name)) {
                        $domains[] = $name;
                    }
                }
                if ($domains === []) {
                    throw new DogecloudApiException('DomainNotFound', '未找到证书匹配的多吉云 CDN 域名');
                }
            } else {
                throw new DogecloudApiException('InvalidMatchPattern', "不支持的域名匹配模式: $pattern");
            }
            foreach ($domains as $candidate) {
                $client->post('/cdn/cert/bind.json', ['id' => $certId, 'domain' => $candidate]);
            }
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'cdn' => new DogecloudRestClient(
                $credentials['access_key'] ?? '',
                $credentials['secret_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return DogecloudErrorSanitizer::sanitize($e);
    }
}
