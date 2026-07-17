<?php

namespace Plugins\CloudDeploy\Deployers\Ksyun;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 金山云负载均衡 SLB 证书替换（证书服务型，region 维度绑定）。
 *
 * 对齐 certimate ksyun-slb 的 deployToCertificate（DEPLOY_TARGET_CERTIFICATE）+ certmgr ksyun-slb 的 Replace：
 *   1. 先把证书托管到金山云 KCM 拿 SslCertificateId（经 KsyunKcmUploader，store_kind=ksyun_kcm，region-less，走 RemoteCertStore 去重）。
 *   2. bind 调 POST / {Action:ModifyCertificate, Version:2016-03-04, Region, CertificateId=config.certificate_id, SslCertificateId=KCM CertId}
 *      把既有负载均衡证书（CertificateId）指向新托管的 KCM 证书，实现「替换」。
 *
 * 证书空间说明（两层）：
 *   - KCM SslCertificateId（region-less）：上传产物，由 KsyunKcmUploader 返回，进 remote_cert_id。
 *   - 负载均衡证书 CertificateId（region 维度）：用户已有的 SLB 证书资源 ID，作 config 必填项，bind 时被 ModifyCertificate 重定向。
 * 故上传器 storeKind 为全局 `ksyun_kcm`（与 kcm 端点共用，region 仅在 bind 的 ModifyCertificate 生效，与 certimate 一致）。
 *
 * 与 certimate 的偏差（已知，刻意修正）：certimate certmgr ksyun-slb 的 Replace 构建 ModifyCertificateRequest 时
 * **漏设 CertificateId**（只设 Region/Description/SSLCertificateId），而 deployer 明确把 config.CertificateId 传入 Replace
 * 意在「替换该 ID 的证书」——不带 CertificateId 的 ModifyCertificate 无法定位目标、语义不完整（疑似 certimate 上游 bug）。
 * 本实现按 deployer 的真实意图把 CertificateId（= config.certificate_id）一并下发，使替换确定作用于配置的负载均衡证书。
 */
class KsyunSlbDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'ksyun';
    }

    public function product(): string
    {
        return 'slb';
    }

    public function label(): string
    {
        return '金山云 SLB';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'certificate_id', 'label' => '负载均衡证书 ID', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // KCM 托管为 region-less，与 SLB 的 region 维度无关（region 仅在 bind 的 ModifyCertificate 生效）。
        return new KsyunKcmUploader(fn (array $credentials): object => $this->makeClient('kcm', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（KCM SslCertificateId）
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array{region:string,certificate_id:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $certificateId = (string) $this->requireConfig($config, 'certificate_id');
        $sslCertificateId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $region, $certificateId, $sslCertificateId) {
            /** @var KsyunRestClient $client */
            $client = $this->makeClient('kcm', $credentials);
            $client->post('/', [
                'Action' => 'ModifyCertificate',
                'Version' => '2016-03-04',
                'Region' => $region,
                'CertificateId' => $certificateId,
                'Description' => 'upload from cloud-deploy',
                'SslCertificateId' => $sslCertificateId,
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            // KCM 托管 + 负载均衡证书 ModifyCertificate 同走 kcm 服务 endpoint（certimate ksyun-slb 用 kcm SDK client）。
            'kcm' => new KsyunRestClient(
                'kcm',
                'kcm.api.ksyun.com',
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return KsyunErrorSanitizer::sanitize($e);
    }
}
