<?php

namespace Plugins\CloudDeploy\Deployers\Apisix;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * APISIX（自建 API 网关，内联型）—— 替换指定证书。
 *
 * 对齐 certimate apisix（deploy_target=certificate）：把证书更新到 APISIX 已有 SSL 对象——
 *   PUT /apisix/admin/ssls/{certificateId} {id, cert, key, snis, type:"server", status:1}
 *
 * 内联型（usesRemoteCertStore=false）：cert/key 直灌 APISIX SSL 对象，无云端证书去重。
 * snis 取证书 SAN 的 DNS 条目（对齐 certimate `certX509.DNSNames`）；type 固定 "server"、status 固定 1。
 * 鉴权 X-API-KEY（凭证含自建服务地址 server_url + allow_insecure_connections）。
 *
 * config：certificate_id（必填，APISIX SSL 对象 id）。
 */
class CertificateDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'apisix';
    }

    public function product(): string
    {
        return 'certificate';
    }

    public function label(): string
    {
        return 'APISIX 证书';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'certificate_id', 'label' => '证书 ID（SSL 对象 ID）', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{server_url:string,api_key:string,allow_insecure_connections?:bool|string}  $credentials
     * @param  array{certificate_id:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $certificateId = (string) $this->requireConfig($config, 'certificate_id');
        // cert + chain（完整链）作 cert 内容；snis 取叶证书 SAN
        $fullChain = rtrim($certRef['cert'])."\n".trim($certRef['chain']);
        $snis = $this->parseCertSans($certRef['cert']);

        $this->guardSdk(function () use ($credentials, $certificateId, $fullChain, $certRef, $snis) {
            /** @var ApisixClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->updateSsl($certificateId, [
                'id' => $certificateId,
                'cert' => $fullChain,
                'key' => $certRef['key'],
                'snis' => $snis,
                'type' => 'server',
                'status' => 1,
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new ApisixClient(
                $this->outboundHttpClient(rtrim((string) ($credentials['server_url'] ?? ''), '/').'/apisix/admin/', [
                    'timeout' => 30,
                    'verify' => ! $this->truthy($credentials['allow_insecure_connections'] ?? false),
                    'headers' => [
                        'X-API-KEY' => (string) ($credentials['api_key'] ?? ''),
                        'Accept' => 'application/json',
                    ],
                ]),
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
        return ApisixErrorSanitizer::sanitize($e);
    }
}
