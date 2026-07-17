<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 火山引擎 DCDN 全站加速（证书服务型）：证书经证书中心上传拿 InstanceId（走 RemoteCertStore 去重），
 * 再批量绑定到域名。对齐 certimate volcengine-dcdn：
 *   CreateCertBind {CertSource:"volc", CertId, DomainNames:[域名]}（Action=CreateCertBind, Version=2021-04-01）
 *
 * 仅实现 exact domain 核心路径；exact 模式去掉前导 "*"（"*.example.com" → ".example.com"，适配火山 DCDN 泛域名格式，
 * 与 certimate exact 分支一致）。region 默认 cn-beijing（证书中心 + DCDN 同 region）。
 *
 * region 透传：certUploader($config) 与 bind($config) 都从 config 解析 region 经 makeClient 第三参传入，
 * 使「上传到证书中心」与「绑定 DCDN」用同一 region（certimate 二者共用 config.Region）。
 */
class VolcDcdnDeployer extends AbstractDeployer
{
    use ResolvesVolcRegion;

    public function provider(): string
    {
        return 'volcengine';
    }

    public function product(): string
    {
        return 'dcdn';
    }

    public function label(): string
    {
        return '火山引擎 DCDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域（默认 cn-beijing）', 'type' => 'string', 'required' => false],
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        $region = $this->resolveRegion($config, 'cn-beijing');

        // 证书中心上传（与 DCDN 同 region），uploader 复用 deployer 的注入缝 makeClient('certcenter')
        return new VolcCertCenterUploader(
            fn (array $credentials): object => $this->makeClient('certcenter', $credentials, $region),
        );
    }

    /**
     * @param  string  $certRef  证书中心 InstanceId
     * @param  array{access_key_id?:string,secret_access_key?:string}  $credentials
     * @param  array{domain:string,region?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = $this->resolveRegion($config, 'cn-beijing');
        $domain = (string) $this->requireConfig($config, 'domain');
        // exact：去掉前导 "*"（"*.example.com" → ".example.com"）
        $domain = preg_replace('/^\*/', '', $domain) ?? $domain;
        $certId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $domain, $certId, $region) {
            /** @var VolcRestClient $client */
            $client = $this->makeClient('dcdn', $credentials, $region);
            $client->callJson('CreateCertBind', '2021-04-01', [
                'CertSource' => 'volc',
                'CertId' => $certId,
                'DomainNames' => [$domain],
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials, string $region = 'cn-beijing'): object
    {
        return match ($kind) {
            'dcdn' => new VolcRestClient(
                VolcRestClient::OPEN_HOST,
                'dcdn',
                $region,
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            'certcenter' => new VolcRestClient(
                VolcRestClient::OPEN_HOST,
                'certificate_service',
                $region,
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return VolcErrorSanitizer::sanitize($e);
    }
}
