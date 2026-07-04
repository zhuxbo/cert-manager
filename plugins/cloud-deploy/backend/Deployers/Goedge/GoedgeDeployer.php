<?php

namespace Plugins\CloudDeploy\Deployers\Goedge;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * GoEdge 证书（内联型）。
 *
 * 对齐 certimate goedge（deployTarget=certificate）：更新 GoEdge 已有 SSL 证书内容
 * （POST /SSLCertService/updateSSLCert，cert/key base64 编码）。
 * - 完整链（certRef.cert + certRef.chain）→ certData（base64）
 * - 私钥（certRef.key）→ keyData（base64）
 * - 解析 leaf 证书填 serverName(CN) / commonNames / dnsNames / timeBeginAt(NotBefore) / timeEndAt(NotAfter)
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} 三元组。鉴权登录态 `X-Edge-Access-Token`。
 *
 * config：certificate_id（GoEdge 证书数字 ID，必填）。
 * server_url / api_role / access_key_id / access_key / allow_insecure 归 credentialSchema。
 */
class GoedgeDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'goedge';
    }

    public function product(): string
    {
        return 'goedge';
    }

    public function label(): string
    {
        return 'GoEdge 证书';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'certificate_id', 'label' => 'GoEdge 证书 ID', 'type' => 'number', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array<string,mixed>  $credentials
     * @param  array{certificate_id:int|string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $certificateId = (int) $this->requireConfig($config, 'certificate_id');

        // 完整链（leaf + 中间证书），与 certimate 传 certPEM 完整链一致
        $fullChain = rtrim($certRef['cert']);
        if (trim($certRef['chain']) !== '') {
            $fullChain .= "\n".trim($certRef['chain']);
        }
        $key = $certRef['key'];

        // 解析 leaf 证书元信息（CN / 有效期 / SAN）；解析失败用空值兜底（不阻断部署）
        [$cn, $dnsNames, $notBefore, $notAfter] = $this->parseCert($certRef['cert']);

        $this->guardSdk(function () use ($credentials, $certificateId, $cn, $fullChain, $key, $notBefore, $notAfter, $dnsNames) {
            /** @var GoedgeRestClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->updateSslCert($certificateId, $cn, $fullChain, $key, $notBefore, $notAfter, $dnsNames);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $serverUrl = rtrim((string) ($credentials['server_url'] ?? ''), '/');

        return match ($kind) {
            'api' => new GoedgeRestClient(
                new GuzzleClient([
                    'base_uri' => "$serverUrl/",
                    'timeout' => 30,
                    'verify' => empty($credentials['allow_insecure']),
                ]),
                (string) ($credentials['api_role'] ?? ''),
                (string) ($credentials['access_key_id'] ?? ''),
                (string) ($credentials['access_key'] ?? ''),
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return GoedgeErrorSanitizer::sanitize($e);
    }

    /**
     * 解析 leaf 证书：CommonName、DNSNames（list）、有效期 Unix 秒。失败回 ['', [], 0, 0]。
     *
     * @return array{0:string,1:list<string>,2:int,3:int}
     */
    private function parseCert(string $certPem): array
    {
        if (! function_exists('openssl_x509_parse')) {
            return ['', [], 0, 0];
        }
        $parsed = @openssl_x509_parse($certPem);
        if (! is_array($parsed)) {
            return ['', [], 0, 0];
        }

        $cn = is_string($parsed['subject']['CN'] ?? null) ? $parsed['subject']['CN'] : '';
        $notBefore = (int) ($parsed['validFrom_time_t'] ?? 0);
        $notAfter = (int) ($parsed['validTo_time_t'] ?? 0);

        $san = is_string($parsed['extensions']['subjectAltName'] ?? null) ? $parsed['extensions']['subjectAltName'] : '';
        $dnsNames = [];
        foreach (explode(',', $san) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $dnsNames[] = substr($entry, 4);
            }
        }

        return [$cn, array_values(array_unique($dnsNames)), $notBefore, $notAfter];
    }
}
