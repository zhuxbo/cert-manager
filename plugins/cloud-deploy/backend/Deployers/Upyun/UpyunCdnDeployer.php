<?php

namespace Plugins\CloudDeploy\Deployers\Upyun;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Cookie\CookieJar;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 又拍云 CDN（证书服务型）：证书先经又拍云控制台证书库上传拿 certificate_id（走 RemoteCertStore 去重），
 * 再按域名当前 HTTPS 状态绑定（未启用→启用并绑；已启用且证书不同→迁移；相同→无操作）。
 *
 * 对齐 certimate upyun-cdn 的 exact 单域名核心路径；不做其 wildcard/certsan 多域名遍历
 * （GetBuckets 拉全量域名再匹配）——本插件统一只 exact 匹配 config.domain。
 *
 * config：domain（加速域名，必填）。
 */
class UpyunCdnDeployer extends AbstractDeployer
{
    use BindsUpyunDomainHttps;

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
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => true],
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
     * @param  string  $certRef  remote_cert_id（又拍云 certificate_id）
     * @param  array{username:string,password:string}  $credentials
     * @param  array{domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        $this->bindUpyunDomain((string) $certRef, $domain, $credentials);
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
