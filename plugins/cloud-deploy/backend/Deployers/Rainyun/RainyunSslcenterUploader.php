<?php

namespace Plugins\CloudDeploy\Deployers\Rainyun;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 雨云 SSL 证书中心上传器（storeKind=rainyun_sslcenter）。
 *
 * 用于 RCDN 绑定场景：RCDN ssl_bind 需要 cert_id，而雨云证书中心 create 接口**不返回 id**，故上传后必须
 * 经 list-match 反查刚上传证书的 id（对齐 certimate rainyun-sslcenter certmgr 的 tryGetResultIfCertExists）。
 *
 * 上传流程：
 *   1. 先 list-match（按 CN 分页查 + DNSNames/有效期/证书内容三重比对）查是否已存在 → 命中直接返回 id；
 *   2. 否则 POST /product/sslcenter/ 新建；
 *   3. 再 list-match 反查新建证书 id（create 不回 id）。
 *
 * 边界：RemoteCertStore 已按 (access_id, store_kind, fingerprint) 去重；但雨云 create 无 id，本上传器的
 * list-match 是**取 id 的唯一手段**（非冗余去重），不可删。
 *
 * SDK client 经注入缝 $clientFactory（由 deployer 的 makeClient('api', …) 提供 RainyunClient）。
 */
class RainyunSslcenterUploader implements CertUploaderInterface
{
    private const PER_PAGE = 100;

    /** @param Closure(array<string,mixed>):object $clientFactory 返回 RainyunClient */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'rainyun_sslcenter';
    }

    /**
     * @param  array{api_key:string}  $credentials
     * @return string 雨云证书中心 cert id（数字字符串）
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 证书 + 中间证书拼完整链（与其余上传器一致）
        $fullChain = rtrim($certPem)."\n".trim($chainPem);

        try {
            /** @var RainyunClient $client */
            $client = ($this->clientFactory)($credentials);

            // 1) 上传前先查是否已存在（取 id）
            $existing = $this->findExistingCertId($client, $fullChain);
            if ($existing !== '') {
                return $existing;
            }

            // 2) 新建（create 不返回 id）
            $client->sslCenterCreate($fullChain, $keyPem);

            // 3) 反查新建证书 id
            $created = $this->findExistingCertId($client, $fullChain);
        } catch (Throwable $e) {
            throw new RuntimeException(RainyunErrorSanitizer::sanitize($e), 0);
        }

        if ($created === '') {
            throw new RuntimeException('雨云证书中心上传后未能反查到证书 id（可能上传失败）');
        }

        return $created;
    }

    /**
     * 按 CN 分页查证书中心，三重比对（DNSNames + 有效期 + 证书内容）找匹配证书 id。
     * 对齐 certimate rainyun-sslcenter certmgr.tryGetResultIfCertExists。
     */
    private function findExistingCertId(RainyunClient $client, string $certPem): string
    {
        $info = $this->parseCert($certPem);
        if ($info === null) {
            return '';
        }
        [$commonName, $dnsJoined, $notBefore, $notAfter] = $info;

        $page = 1;
        while (true) {
            $list = $client->sslCenterList($commonName, $page, self::PER_PAGE);
            $records = $list['records'];
            if ($records === []) {
                return '';
            }

            foreach ($records as $record) {
                // 比对备用名称（DNSNames 以 ", " 连接）
                if ((string) ($record['Domain'] ?? '') !== $dnsJoined) {
                    continue;
                }
                // 比对有效期（Unix 秒）
                if ((int) ($record['StartDate'] ?? 0) !== $notBefore || (int) ($record['ExpDate'] ?? 0) !== $notAfter) {
                    continue;
                }
                // 比对证书内容
                $id = (int) ($record['ID'] ?? 0);
                if ($id === 0) {
                    continue;
                }
                $detail = $client->sslCenterGet($id);
                if ($this->equalCertPem($certPem, (string) ($detail['Cert'] ?? ''))) {
                    return (string) $id;
                }
            }

            if (count($records) < self::PER_PAGE) {
                return '';
            }
            $page++;
        }
    }

    /**
     * 解析证书：CommonName、DNSNames（", " 连接）、有效期 Unix 秒。失败回 null。
     *
     * @return array{0:string,1:string,2:int,3:int}|null
     */
    private function parseCert(string $certPem): ?array
    {
        if (! function_exists('openssl_x509_parse')) {
            return null;
        }
        $parsed = @openssl_x509_parse($certPem);
        if (! is_array($parsed)) {
            return null;
        }

        $cn = is_string($parsed['subject']['CN'] ?? null) ? $parsed['subject']['CN'] : '';
        $notBefore = (int) ($parsed['validFrom_time_t'] ?? 0);
        $notAfter = (int) ($parsed['validTo_time_t'] ?? 0);

        // SAN DNS 名（"DNS:a, DNS:b" → ["a","b"]）
        $san = is_string($parsed['extensions']['subjectAltName'] ?? null) ? $parsed['extensions']['subjectAltName'] : '';
        $dnsNames = [];
        foreach (explode(',', $san) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $dnsNames[] = substr($entry, 4);
            }
        }

        return [$cn, implode(', ', $dnsNames), $notBefore, $notAfter];
    }

    /** 比较两段 PEM 是否同一证书（用 DER 指纹，避免空白差异误判）。 */
    private function equalCertPem(string $a, string $b): bool
    {
        if (! function_exists('openssl_x509_fingerprint')) {
            // 退化：规整空白后字符串比较
            return trim($a) === trim($b);
        }
        $fa = @openssl_x509_fingerprint($a, 'sha256');
        $fb = @openssl_x509_fingerprint($b, 'sha256');

        return $fa !== false && $fb !== false && $fa === $fb;
    }
}
