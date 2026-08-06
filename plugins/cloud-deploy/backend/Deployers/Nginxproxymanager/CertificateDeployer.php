<?php

namespace Plugins\CloudDeploy\Deployers\Nginxproxymanager;

use GuzzleHttp\Cookie\CookieJar;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * Nginx Proxy Manager（NPM，自建反代面板，内联型）—— 替换指定证书。
 *
 * 对齐 certimate nginxproxymanager（deploy_target=certificate）：替换 NPM 已有证书内容并触发重启——
 *   1. POST /nginx/certificates/{certificateId}/upload（multipart：certificate=叶证书 /
 *      certificate_key=私钥 / intermediate_certificate=中间证书）替换证书。
 *   2. GET /settings/default-site 取原 value → PUT /settings/default-site 原样写回，触发 nginx 重启。
 *
 * 内联型（usesRemoteCertStore=false）：cert/key 直灌 NPM 已有证书对象，无云端证书去重。
 * 叶证书走 certRef.cert、中间证书走 certRef.chain（对齐 certimate ExtractCertificatesFromPEM 拆分）。
 * 鉴权 JWT Bearer（凭证 auth_method=password 走账号登录、=token 直接用 api_token）。
 *
 * config：certificate_id（必填，NPM 证书数字 ID）。
 */
class CertificateDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'nginxproxymanager';
    }

    public function product(): string
    {
        return 'certificate';
    }

    public function label(): string
    {
        return 'Nginx Proxy Manager 证书';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'certificate_id', 'label' => '证书 ID', 'type' => 'number', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{server_url:string,auth_method?:string,username?:string,password?:string,api_token?:string,allow_insecure_connections?:bool|string}  $credentials
     * @param  array{certificate_id:int|string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $certificateId = (int) $this->requireConfig($config, 'certificate_id');
        $leaf = rtrim($certRef['cert']);
        $intermediate = trim($certRef['chain']);
        $key = $certRef['key'];

        $this->guardSdk(function () use ($credentials, $certificateId, $leaf, $key, $intermediate) {
            /** @var NginxproxymanagerClient $client */
            $client = $this->makeClient('api', $credentials);

            // 替换证书内容
            $client->uploadCertificate($certificateId, $leaf, $key, $intermediate);

            // 原样写回默认站点以触发 nginx 重启
            $value = $client->getDefaultSiteValue();
            $client->setDefaultSite($value);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $authMethod = (string) ($credentials['auth_method'] ?? '');
        // 仅 auth_method=token 时使用 api_token；password（含空缺省）走账号登录
        $apiToken = $authMethod === 'token' ? (string) ($credentials['api_token'] ?? '') : '';

        return match ($kind) {
            'api' => new NginxproxymanagerClient(
                $this->outboundHttpClient(rtrim((string) ($credentials['server_url'] ?? ''), '/').'/api/', [
                    'timeout' => 30,
                    'verify' => ! $this->truthy($credentials['allow_insecure_connections'] ?? false),
                    'cookies' => new CookieJar,
                    'headers' => ['Accept' => 'application/json'],
                ]),
                (string) ($credentials['username'] ?? ''),
                (string) ($credentials['password'] ?? ''),
                $apiToken,
            ),
        };
    }

    /** 归一 bool 开关（兼容前端可能传字符串 "1"/"true"）。 */
    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    protected function sanitize(Throwable $e): string
    {
        return NginxproxymanagerErrorSanitizer::sanitize($e);
    }
}
