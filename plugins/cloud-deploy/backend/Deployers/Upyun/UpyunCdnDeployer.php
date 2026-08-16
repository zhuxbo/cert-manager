<?php

namespace Plugins\CloudDeploy\Deployers\Upyun;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Cookie\CookieJar;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\MatchesCertificateHostnames;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * 又拍云 CDN（证书服务型）：证书先经又拍云控制台证书库上传拿 certificate_id（走 RemoteCertStore 去重），
 * 再按域名当前 HTTPS 状态绑定（未启用→启用并绑；已启用且证书不同→迁移；相同→无操作）。
 *
 * 对齐 certimate upyun-cdn 的 exact/wildcard/certsan；certsan 经可选远端证书材料契约取得叶证书，
 * 私钥不进入 bind 上下文。
 *
 * config：非 certsan 时 domain 为必填加速域名。
 */
class UpyunCdnDeployer extends AbstractDeployer implements ReceivesRemoteCertificateMaterial
{
    use BindsUpyunDomainHttps;
    use MatchesCertificateHostnames;

    private const BASE_URI = 'https://console.upyun.com';

    public function provider(): string
    {
        return 'upyun';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return '又拍云 CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain_match_pattern', 'label' => '域名匹配（exact/wildcard/certsan）', 'type' => 'string', 'required' => false],
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // 上传器复用 deployer 的注入缝：测试 override makeClient('api') 即作用于上传
        return new UpyunSslUploader(fn (array $credentials): object => $this->makeClient('api', $credentials));
    }

    /**
     * @param  string|array{remote_cert_id:string,cert:string,chain:string}  $certRef  证书 id 或 opt-in 的 leaf/中间链上下文
     * @param  array{username:string,password:string}  $credentials
     * @param  array{domain?:string,domain_match_pattern?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $pattern = (string) ($config['domain_match_pattern'] ?? 'exact');
        $remoteCertId = is_array($certRef) ? (string) ($certRef['remote_cert_id'] ?? '') : (string) $certRef;
        $certificate = is_array($certRef) ? (string) ($certRef['cert'] ?? '') : '';
        $domain = $pattern === 'certsan' ? '' : (string) $this->requireConfig($config, 'domain');
        if ($pattern === '' || $pattern === 'exact' || ($pattern === 'wildcard' && ! str_starts_with($domain, '*.'))) {
            $domains = [$domain];
        } elseif ($pattern === 'wildcard') {
            /** @var UpyunRestClient $client */
            $client = $this->makeClient('api', $credentials);
            $domains = array_values(array_filter($client->getDomains(), fn (string $candidate): bool => $this->matchesWildcard($domain, $candidate)));
            if ($domains === []) {
                $this->fail('未找到 wildcard 匹配的又拍云 CDN 域名');
            }
        } elseif ($pattern === 'certsan') {
            /** @var UpyunRestClient $client */
            $client = $this->makeClient('api', $credentials);
            $domains = array_values(array_filter($client->getDomains(), fn (string $candidate): bool => $this->certificateMatchesHostname($certificate, $candidate)));
            if ($domains === []) {
                $this->fail('未找到证书匹配的又拍云 CDN 域名');
            }
        } else {
            $this->fail("不支持的域名匹配模式: $pattern");
        }
        foreach ($domains as $candidate) {
            $this->bindUpyunDomain($remoteCertId, $candidate, $credentials);
        }
    }

    private function matchesWildcard(string $pattern, string $hostname): bool
    {
        if (str_starts_with($hostname, '.') || str_starts_with($hostname, '*.')) {
            return strcasecmp(ltrim($pattern, '*'), ltrim($hostname, '*')) === 0;
        }
        $suffix = substr($pattern, 2);
        $prefix = substr($hostname, 0, -strlen('.'.$suffix));

        return str_ends_with(strtolower($hostname), '.'.strtolower($suffix)) && $prefix !== '' && ! str_contains($prefix, '.');
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new UpyunRestClient(
                new GuzzleClient([
                    'base_uri' => self::BASE_URI,
                    'connect_timeout' => 10,
                    'timeout' => 30,
                    // 又拍云控制台账号登录拿 Cookie，jar 自动保存 Set-Cookie 并回送后续请求
                    'cookies' => new CookieJar,
                    'headers' => ['Accept' => 'application/json'],
                ]),
                (string) ($credentials['username'] ?? ''),
                (string) ($credentials['password'] ?? ''),
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return UpyunErrorSanitizer::sanitize($e);
    }
}
