<?php

namespace Plugins\CloudDeploy\Deployers\Volcengine;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 火山引擎 Web 应用防火墙 WAF（证书服务型）：证书经证书中心上传拿 InstanceId（走 RemoteCertStore 去重），
 * 再设到 WAF 防护网站。对齐 certimate volcengine-waf（CNAME 接入，JSON 协议）：
 *   ListDomain(AccurateQuery=1) 精确查防护网站 → UpdateDomain 保留既有 LBAlgorithm/Protocols/ProtocolPorts
 *   + VolcCertificateID=证书 id + CertificatePlatform="certificate-service" + AccessMode=10
 *   （Action=ListDomain/UpdateDomain, Version=2023-12-25）
 *
 * 仅支持 CNAME 接入（access_mode=cname，与 certimate 唯一实现的接入方式一致）。region 必填；证书中心同 region。
 */
class VolcWafDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'volcengine';
    }

    public function product(): string
    {
        return 'waf';
    }

    public function label(): string
    {
        return '火山引擎 WAF';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'access_mode', 'label' => 'WAF 接入模式', 'type' => 'string', 'required' => true, 'options' => [
                ['label' => 'CNAME 接入', 'value' => 'cname'],
            ]],
            ['key' => 'domain', 'label' => '防护域名', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // RegistryCompleteness 以空 config 探活：region 优雅默认（真实 region 由 bind 强校验），不抛
        $region = (string) ($config['region'] ?? '');

        return new VolcCertCenterUploader(
            fn (array $credentials): object => $this->makeClient('certcenter', $credentials, $region),
        );
    }

    /**
     * @param  string  $certRef  证书中心 InstanceId
     * @param  array{access_key_id?:string,secret_access_key?:string}  $credentials
     * @param  array{region:string,access_mode:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $accessMode = (string) $this->requireConfig($config, 'access_mode');
        $domain = (string) $this->requireConfig($config, 'domain');
        $certId = (string) $certRef;

        if ($accessMode !== 'cname') {
            $this->fail("不支持的接入模式 $accessMode");
        }

        // 精确查防护网站（SDK 读，guardSdk 包裹后返回）
        $domainInfo = $this->guardSdk(function () use ($credentials, $domain, $region): array {
            /** @var VolcRestClient $client */
            $client = $this->makeClient('waf', $credentials, $region);
            $listResult = $client->callJson('ListDomain', '2023-12-25', [
                'Region' => $region,
                'Domain' => $domain,
                'AccurateQuery' => 1,
                'Page' => 1,
                'PageSize' => 1,
            ]);
            $data = is_array($listResult['Data'] ?? null) ? $listResult['Data'] : [];

            return is_array($data[0] ?? null) ? $data[0] : [];
        });

        // 业务错误：未找到防护域名（在 guardSdk 之外）
        if ($domainInfo === []) {
            $this->fail("未找到防护域名 $domain");
        }

        $this->guardSdk(function () use ($credentials, $domain, $certId, $region, $domainInfo) {
            /** @var VolcRestClient $client */
            $client = $this->makeClient('waf', $credentials, $region);

            // 更新防护网站：保留既有 LBAlgorithm/Protocols/ProtocolPorts，换证书
            $protocols = ['HTTP', 'HTTPS'];
            if (is_string($domainInfo['Protocols'] ?? null) && $domainInfo['Protocols'] !== '') {
                $protocols = explode(',', $domainInfo['Protocols']);
                if (! in_array('HTTPS', $protocols, true)) {
                    $protocols[] = 'HTTPS';
                }
            }

            $protocolPorts = [
                'HTTP' => [80],
                'HTTPS' => [443],
            ];
            $existingPorts = is_array($domainInfo['ProtocolPorts'] ?? null) ? $domainInfo['ProtocolPorts'] : [];
            if (is_array($existingPorts['HTTP'] ?? null) && $existingPorts['HTTP'] !== []) {
                $protocolPorts['HTTP'] = array_values($existingPorts['HTTP']);
            }
            if (is_array($existingPorts['HTTPS'] ?? null) && $existingPorts['HTTPS'] !== []) {
                $protocolPorts['HTTPS'] = array_values($existingPorts['HTTPS']);
            }

            $body = [
                'Region' => $region,
                'Domain' => $domain,
                'AccessMode' => 10,
                'Protocols' => $protocols,
                'ProtocolPorts' => $protocolPorts,
                'VolcCertificateID' => $certId,
                'CertificatePlatform' => 'certificate-service',
            ];
            if (isset($domainInfo['LBAlgorithm']) && is_string($domainInfo['LBAlgorithm']) && $domainInfo['LBAlgorithm'] !== '') {
                $body['LBAlgorithm'] = $domainInfo['LBAlgorithm'];
            }

            $client->callJson('UpdateDomain', '2023-12-25', $body);
        });
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        return match ($kind) {
            'waf' => new VolcRestClient(
                VolcRestClient::OPEN_HOST,
                'waf',
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
