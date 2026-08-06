<?php

namespace Plugins\CloudDeploy\Deployers\Dokploy;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\UploadOnlyDeployerInterface;
use Throwable;

/**
 * Dokploy（自建 PaaS，内联型）—— 创建证书。
 *
 * 对齐 certimate dokploy：把证书上传到 Dokploy 证书库（Deploy 即创建，无后续 bind）——
 *   1. GET /certificates.all 去重（已存在相同 certificateData+privateKey 则跳过）。
 *   2. GET /user.get 取默认组织 ID。
 *   3. POST /certificates.create {name, certificateData, privateKey, organizationId}。
 *
 * 内联型（usesRemoteCertStore=false）：cert/key 直灌 Dokploy 证书库，本插件不复用 RemoteCertStore
 * （Dokploy 自身按内容去重）。无 config 字段——服务地址/API Key 归凭证（provider 级）。
 * 鉴权 X-Api-Key（凭证含自建服务地址 server_url + allow_insecure_connections）。
 */
class CertificateDeployer extends AbstractDeployer implements UploadOnlyDeployerInterface
{
    public function provider(): string
    {
        return 'dokploy';
    }

    public function product(): string
    {
        return 'certificate';
    }

    public function label(): string
    {
        return 'Dokploy 证书';
    }

    public function configSchema(): array
    {
        return [];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{server_url:string,api_key:string,allow_insecure_connections?:bool|string}  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        // cert + chain（完整链）作 certificateData
        $fullChain = rtrim($certRef['cert'])."\n".trim($certRef['chain']);
        $key = $certRef['key'];

        $this->guardSdk(function () use ($credentials, $fullChain, $key) {
            /** @var DokployClient $client */
            $client = $this->makeClient('api', $credentials);

            // 去重：已存在相同 certificateData + privateKey 则跳过
            foreach ($client->certificatesAll() as $cert) {
                if (($cert['certificateData'] ?? null) === $fullChain && ($cert['privateKey'] ?? null) === $key) {
                    return;
                }
            }

            // 取默认组织 ID
            $organizationId = $client->userGetOrganizationId();

            // 创建证书
            $client->certificatesCreate([
                'name' => 'clouddeploy-'.time(),
                'certificateData' => $fullChain,
                'privateKey' => $key,
                'organizationId' => $organizationId,
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new DokployClient(
                $this->outboundHttpClient(rtrim((string) ($credentials['server_url'] ?? ''), '/').'/api/', [
                    'timeout' => 30,
                    'verify' => ! $this->truthy($credentials['allow_insecure_connections'] ?? false),
                    'headers' => [
                        'X-Api-Key' => (string) ($credentials['api_key'] ?? ''),
                        'Accept' => 'application/json',
                    ],
                ]),
            ),
        };
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
        return DokployErrorSanitizer::sanitize($e);
    }
}
