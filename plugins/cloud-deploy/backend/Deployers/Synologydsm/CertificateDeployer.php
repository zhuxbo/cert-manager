<?php

namespace Plugins\CloudDeploy\Deployers\Synologydsm;

use GuzzleHttp\Cookie\CookieJar;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 群晖 DSM（内联型）—— 导入/替换证书。
 *
 * 对齐 certimate synologydsm：
 *   1. 登录（账号密码 + 可选 TOTP）。
 *   2. certificate_id_or_desc 为空 → 新建证书（id 留空，desc 自动生成）；
 *      否则 → SYNO.Core.Certificate.CRT:list 找匹配（先按 id 再按 desc），用其 id+desc 导入（替换）。
 *   3. 登出（finally 兜底）。
 *
 * 内联型（usesRemoteCertStore=false）：cert/key 直灌群晖证书库，无云端证书去重。
 * 叶证书走 certRef.cert、中间证书走 certRef.chain（对齐 certimate ExtractCertificatesFromPEM 拆分）。
 * as_default 由 config.is_default 决定（导入即设为默认证书；更新时若原证书已是默认则保持）。
 * 鉴权 登录拿 sid + SynoToken（凭证含 server_url + 账号密码 + 可选 totp_secret）。
 *
 * config：certificate_id_or_desc（选填，空=新建/非空=更新指定证书）/ is_default（选填，bool）。
 */
class CertificateDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'synologydsm';
    }

    public function product(): string
    {
        return 'certificate';
    }

    public function label(): string
    {
        return '群晖 DSM 证书';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'certificate_id_or_desc', 'label' => '证书 ID 或描述（选填，空则新建证书）', 'type' => 'string', 'required' => false],
            ['key' => 'is_default', 'label' => '设为默认证书（选填）', 'type' => 'bool', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{server_url:string,username:string,password:string,totp_secret?:string,allow_insecure_connections?:bool|string}  $credentials
     * @param  array{certificate_id_or_desc?:string,is_default?:bool|string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $idOrDesc = isset($config['certificate_id_or_desc']) ? (string) $config['certificate_id_or_desc'] : '';
        $isDefault = $this->truthy($config['is_default'] ?? false);
        $leaf = rtrim($certRef['cert']);
        $intermediate = trim($certRef['chain']);
        $key = $certRef['key'];

        /** @var SynologydsmClient $client */
        $client = $this->makeClient('api', $credentials);

        // 阶段 1（SDK）：登录 + 解析目标证书（新建留空 id；更新则查列表匹配）。listCertificates 是 SDK
        // 调用须包 guardSdk；但「未找到证书」是业务错误，须在 guardSdk **外**走 fail()（否则会被
        // guardSdk 的 catch(Throwable) 当 SDK 异常重建、丢失可读文案）。故此处只解析、不在闭包内 fail。
        $resolved = $this->guardSdk(function () use ($client, $idOrDesc, $isDefault) {
            $client->login();

            if ($idOrDesc === '') {
                // 新建：id 留空、desc 自动
                return ['id' => '', 'desc' => 'clouddeploy-'.(int) (microtime(true) * 1000), 'as_default' => $isDefault, 'found' => true];
            }

            // 更新：先按 id 再按 desc 匹配（找不到返回 found=false，业务判定移到闭包外）
            $matched = $this->findCertificate($client->listCertificates(), $idOrDesc);
            if ($matched === null) {
                return ['found' => false];
            }

            return [
                'id' => (string) ($matched['id'] ?? ''),
                'desc' => (string) ($matched['desc'] ?? ''),
                'as_default' => $isDefault || ($matched['is_default'] ?? false) === true,
                'found' => true,
            ];
        });

        // 业务判定在 guardSdk 外：未找到则登出后 fail（清晰文案，不被脱敏吞掉）
        if ($resolved['found'] !== true) {
            $this->guardSdk(fn () => $client->logout());
            $this->fail("未找到证书 '$idOrDesc'");
        }

        // 阶段 2（SDK）：导入证书 + 登出（finally 兜底）
        $this->guardSdk(function () use ($client, $resolved, $key, $leaf, $intermediate, $isDefault) {
            try {
                $client->importCertificate($resolved['id'], $resolved['desc'], $key, $leaf, $intermediate, $resolved['as_default']);
                if ($isDefault) {
                    $certificates = $client->listCertificates();
                    $defaultId = '';
                    foreach ($certificates as $certificate) {
                        if (($certificate['is_default'] ?? false) === true) {
                            $defaultId = (string) ($certificate['id'] ?? '');
                            break;
                        }
                    }
                    $settings = [];
                    if ($defaultId !== '') {
                        foreach ($certificates as $certificate) {
                            $oldId = (string) ($certificate['id'] ?? '');
                            if ($oldId === '' || $oldId === $defaultId) {
                                continue;
                            }
                            foreach ((array) ($certificate['services'] ?? []) as $service) {
                                if (is_array($service)) {
                                    $settings[] = ['service' => $service, 'old_id' => $oldId, 'id' => $defaultId];
                                }
                            }
                        }
                    }
                    if ($settings !== []) {
                        $client->setServiceCertificates($settings);
                    }
                }
            } finally {
                $client->logout();
            }
        });
    }

    /**
     * 按 id 优先、再按 desc 在证书列表中匹配（对齐 certimate）；未命中返回 null。
     *
     * @param  list<array<string,mixed>>  $certs
     * @return array<string,mixed>|null
     */
    private function findCertificate(array $certs, string $idOrDesc): ?array
    {
        foreach ($certs as $cert) {
            if (($cert['id'] ?? null) === $idOrDesc) {
                return $cert;
            }
        }
        foreach ($certs as $cert) {
            if (($cert['desc'] ?? null) === $idOrDesc) {
                return $cert;
            }
        }

        return null;
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new SynologydsmClient(
                $this->outboundHttpClient(rtrim((string) ($credentials['server_url'] ?? ''), '/'), [
                    'timeout' => 30,
                    'verify' => ! $this->truthy($credentials['allow_insecure_connections'] ?? false),
                    'cookies' => new CookieJar,
                    'headers' => ['Accept' => 'application/json'],
                ]),
                (string) ($credentials['username'] ?? ''),
                (string) ($credentials['password'] ?? ''),
                (string) ($credentials['totp_secret'] ?? ''),
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
        return SynologydsmErrorSanitizer::sanitize($e);
    }
}
