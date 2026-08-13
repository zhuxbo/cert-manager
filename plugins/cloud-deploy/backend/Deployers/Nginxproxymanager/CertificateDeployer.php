<?php

namespace Plugins\CloudDeploy\Deployers\Nginxproxymanager;

use GuzzleHttp\Cookie\CookieJar;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * Nginx Proxy Manager（NPM，自建反代面板，内联型）—— 替换指定证书或绑定主机。
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
 * config：deploy_target（certificate/host，默认 certificate）及其条件配置。
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
            ['key' => 'deploy_target', 'label' => '部署目标（certificate/host）', 'type' => 'string', 'required' => false],
            ['key' => 'host_type', 'label' => '主机类型（proxy/redirection/stream/dead）', 'type' => 'string', 'required' => false],
            ['key' => 'host_match_pattern', 'label' => '主机匹配（specified/certsan）', 'type' => 'string', 'required' => false],
            ['key' => 'host_id', 'label' => '主机 ID', 'type' => 'number', 'required' => false],
            ['key' => 'certificate_id', 'label' => '证书 ID', 'type' => 'number', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{server_url:string,auth_method?:string,username?:string,password?:string,api_token?:string,allow_insecure_connections?:bool|string}  $credentials
     * @param  array{certificate_id:int|string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $leaf = rtrim($certRef['cert']);
        $intermediate = trim($certRef['chain']);
        $key = $certRef['key'];

        $deployTarget = (string) ($config['deploy_target'] ?? 'certificate');
        if ($deployTarget === 'host') {
            $this->bindHosts($leaf, $key, $intermediate, $credentials, $config);

            return;
        }
        if ($deployTarget !== 'certificate') {
            $this->fail("不支持的部署目标: $deployTarget");
        }
        $certificateId = (int) $this->requireConfig($config, 'certificate_id');

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

    /** @param array<string,mixed> $credentials @param array<string,mixed> $config */
    private function bindHosts(string $leaf, string $key, string $intermediate, array $credentials, array $config): void
    {
        $hostType = (string) $this->requireConfig($config, 'host_type');
        $matchPattern = (string) ($config['host_match_pattern'] ?? 'specified');
        $hostId = $matchPattern === 'specified' ? (int) $this->requireConfig($config, 'host_id') : 0;

        $this->guardSdk(function () use ($credentials, $hostType, $matchPattern, $hostId, $leaf, $key, $intermediate) {
            /** @var NginxproxymanagerClient $client */
            $client = $this->makeClient('api', $credentials);
            $certificateId = $client->ensureCertificate('clouddeploy-'.(int) (microtime(true) * 1000), $leaf, $key, $intermediate);
            $hosts = $client->listHosts($hostType);
            if ($matchPattern === 'specified') {
                $ids = [$hostId];
            } elseif ($matchPattern === 'certsan') {
                $ids = [];
                foreach ($hosts as $host) {
                    $domains = array_values(array_filter((array) ($host['domain_names'] ?? []), 'is_string'));
                    if ($domains !== [] && $this->certificateMatchesAll($leaf, $domains)) {
                        $ids[] = (int) ($host['id'] ?? 0);
                    }
                }
                if ($ids === []) {
                    throw new NginxproxymanagerApiException('HostNotFound', '未找到证书 SAN 匹配的 NPM 主机');
                }
            } else {
                throw new NginxproxymanagerApiException('InvalidMatchPattern', "不支持的 NPM 主机匹配模式: $matchPattern");
            }
            foreach ($ids as $id) {
                $current = null;
                foreach ($hosts as $host) {
                    if ((int) ($host['id'] ?? 0) === $id) {
                        $current = (int) ($host['certificate_id'] ?? 0);
                        break;
                    }
                }
                if ($current !== $certificateId) {
                    $client->updateHostCertificate($hostType, $id, $certificateId);
                }
            }
        });
    }

    /** @param list<string> $domains */
    private function certificateMatchesAll(string $certificate, array $domains): bool
    {
        $parsed = @openssl_x509_parse($certificate);
        if (! is_array($parsed)) {
            return false;
        }
        $certificateNames = [];
        $san = $parsed['extensions']['subjectAltName'] ?? '';
        if (is_string($san) && $san !== '') {
            foreach (explode(',', $san) as $entry) {
                $entry = trim($entry);
                if (str_starts_with(strtoupper($entry), 'DNS:')) {
                    $certificateNames[] = trim(substr($entry, 4));
                }
            }
        }
        if ($certificateNames === []) {
            $cn = $parsed['subject']['CN'] ?? '';
            if (is_string($cn) && $cn !== '') {
                $certificateNames[] = $cn;
            }
        }
        foreach ($domains as $domain) {
            $covered = false;
            foreach ($certificateNames as $certificateName) {
                if ($this->certificateNameMatches($certificateName, $domain)) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                return false;
            }
        }

        return true;
    }

    private function certificateNameMatches(string $certificateName, string $hostname): bool
    {
        if (strcasecmp($certificateName, $hostname) === 0) {
            return true;
        }
        if (! str_starts_with($certificateName, '*.')) {
            return false;
        }
        $suffix = substr($certificateName, 2);
        if (! str_ends_with(strtolower($hostname), '.'.strtolower($suffix))) {
            return false;
        }
        $prefix = substr($hostname, 0, -strlen('.'.$suffix));

        return $prefix !== '' && ! str_contains($prefix, '.');
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
