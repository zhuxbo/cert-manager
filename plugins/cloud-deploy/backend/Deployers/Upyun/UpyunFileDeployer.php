<?php

namespace Plugins\CloudDeploy\Deployers\Upyun;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Cookie\CookieJar;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 又拍云云存储（File，证书服务型）：证书先经又拍云控制台证书库上传拿 certificate_id（走 RemoteCertStore
 * 去重），再按云存储自定义域名当前 HTTPS 状态绑定（未启用→启用并绑；已启用且证书不同→迁移；相同→无操作）。
 *
 * 对齐 certimate upyun-file：与 upyun-cdn 共用同一套控制台 HTTPS 管理接口（GetHttpsServiceManager /
 * UpdateHttpsCertificateManager / MigrateHttpsDomain），区别仅在它直接用 config.domain 单域名（无多域名
 * 匹配分支）。
 *
 * config：domain（自定义域名，必填）/ bucket（存储桶名，选填——对齐 certimate「暂时无用」，仅前端展示，
 * bind 不读，故不 requireConfig）。
 */
class UpyunFileDeployer extends AbstractDeployer
{
    use BindsUpyunDomainHttps;

    private const BASE_URI = 'https://console.upyun.com';

    public function provider(): string
    {
        return 'upyun';
    }

    public function product(): string
    {
        return 'file';
    }

    public function label(): string
    {
        return '又拍云云存储';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '自定义域名', 'type' => 'string', 'required' => true],
            ['key' => 'bucket', 'label' => '存储桶名（选填）', 'type' => 'string', 'required' => false],
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
     * @param  array{domain:string,bucket?:string}  $config
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
