<?php

namespace Plugins\CloudDeploy\Deployers\Kong;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * Kong（自建 API 网关，内联型）—— 替换指定证书。
 *
 * 对齐 certimate kong（deploy_target=certificate）：把证书 upsert 到 Kong 已有 SSL 证书对象——
 *   PUT /certificates/{certificateId} {id, cert, key, snis}
 * （workspace 选填，certimate 拼到 base path；本插件由 KongClient 前缀化）。
 *
 * 内联型（usesRemoteCertStore=false）：cert/key 直灌 Kong 证书对象，无云端证书去重。
 * snis 取证书 SAN 的 DNS 条目（对齐 certimate `certX509.DNSNames`）。
 * 鉴权 Kong-Admin-Token（凭证含自建服务地址 server_url + allow_insecure_connections）。
 *
 * config：certificate_id（必填，Kong SSL 证书对象 id）/ workspace（选填，Kong 工作空间）。
 */
class CertificateDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'kong';
    }

    public function product(): string
    {
        return 'certificate';
    }

    public function label(): string
    {
        return 'Kong 证书';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'certificate_id', 'label' => '证书 ID', 'type' => 'string', 'required' => true],
            ['key' => 'workspace', 'label' => '工作空间（选填）', 'type' => 'string', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{server_url:string,api_token:string,allow_insecure_connections?:bool|string}  $credentials
     * @param  array{certificate_id:string,workspace?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $certificateId = (string) $this->requireConfig($config, 'certificate_id');
        $workspace = isset($config['workspace']) ? (string) $config['workspace'] : '';
        // cert + chain（完整链）作 cert 内容；snis 取叶证书 SAN
        $fullChain = rtrim($certRef['cert'])."\n".trim($certRef['chain']);
        $snis = $this->parseCertSans($certRef['cert']);

        $this->guardSdk(function () use ($credentials, $workspace, $certificateId, $fullChain, $certRef, $snis) {
            /** @var KongClient $client */
            $client = $this->makeClient('api', $credentials, $workspace);
            $client->upsertCertificate($certificateId, [
                'id' => $certificateId,
                'cert' => $fullChain,
                'key' => $certRef['key'],
                'snis' => $snis,
            ]);
        });
    }

    /** @param  array<string,mixed>  $credentials */
    protected function makeClient(string $kind, array $credentials, string $workspace = ''): object
    {
        return match ($kind) {
            'api' => new KongClient(
                $this->outboundHttpClient(rtrim((string) ($credentials['server_url'] ?? ''), '/').'/', [
                    'timeout' => 30,
                    'verify' => ! $this->truthy($credentials['allow_insecure_connections'] ?? false),
                    'headers' => [
                        'Kong-Admin-Token' => (string) ($credentials['api_token'] ?? ''),
                        'Accept' => 'application/json',
                    ],
                ]),
                $workspace,
            ),
        };
    }

    /**
     * 解析证书 SAN（subjectAltName）的 DNS 条目；解析失败返回空数组。
     *
     * @return list<string>
     */
    private function parseCertSans(string $certPem): array
    {
        $parsed = @openssl_x509_parse($certPem);
        $san = is_array($parsed) ? ($parsed['extensions']['subjectAltName'] ?? '') : '';
        if (! is_string($san) || $san === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $san) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $out[] = substr($entry, 4);
            }
        }

        return array_values(array_unique($out));
    }

    /** 把凭证里的「允许不安全连接」开关归一为 bool（兼容前端可能传字符串 "1"/"true"）。 */
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
        return KongErrorSanitizer::sanitize($e);
    }
}
