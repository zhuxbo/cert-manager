<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\FC\V20230330\FC;
use AlibabaCloud\SDK\FC\V20230330\Models\CertConfig;
use AlibabaCloud\SDK\FC\V20230330\Models\UpdateCustomDomainInput;
use AlibabaCloud\SDK\FC\V20230330\Models\UpdateCustomDomainRequest;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 阿里云函数计算 FC 自定义域名（内联型）：UpdateCustomDomain 直传 PEM 给自定义域名的 CertConfig，
 * 不走 CAS 证书服务，故 usesRemoteCertStore=false、certUploader=null。对齐 certimate aliyun-fc（FC 3.0 一路）。
 *
 * 仅实现 FC 3.0（fc-20230330）+ exact domain 核心路径；不做 certimate 的 FC 2.0（fc-open-20210406）
 * 与 DomainMatchPattern（wildcard/certsan）/遍历域名（留后续）。
 *
 * get-then-update（不可省）：UpdateCustomDomain 是全量覆盖，若不带 protocol/tlsConfig 会把域名既有的
 * 协议/TLS 配置重置。故先 GetCustomDomain 读回 protocol/tlsConfig 原样回填；并在证书未变化时短路跳过
 * （幂等，避免重复签发）；protocol=HTTP 时升级为 HTTP,HTTPS（与 certimate 一致，保证 HTTPS 可用）。
 *
 * FC SDK 走 darabonba OpenApiClient，API 错误抛 TeaError，沿用 AliyunErrorSanitizer 脱敏。
 */
class AliyunFcDeployer extends AbstractDeployer
{
    use BuildsAliyunConfig;

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'fc';
    }

    public function label(): string
    {
        return '阿里云函数计算 FC';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '自定义域名', 'type' => 'string', 'required' => true],
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{domain:string,region:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = $this->requireConfig($config, 'domain');
        $region = $this->requireConfig($config, 'region');
        // 证书 + 中间证书拼成完整链上传（与 CDN/Live/OSS 同口径）
        $certificate = rtrim($certRef['cert'])."\n".trim($certRef['chain']);
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        $this->guardSdk(function () use ($credentials, $region, $domain, $certificate, $certRef, $certName) {
            /** @var FC $client */
            $client = $this->makeClient('fc', $credentials + ['region' => $region]);

            // 读回既有配置（protocol/tlsConfig 需原样保留；证书未变则短路）
            $existing = $client->getCustomDomain($domain)->body;
            if ($existing?->certConfig !== null && $existing->certConfig->certificate === $certificate) {
                return;
            }

            $input = new UpdateCustomDomainInput([
                'certConfig' => new CertConfig([
                    'certName' => $certName,
                    'certificate' => $certificate,
                    'privateKey' => $certRef['key'],
                ]),
                'protocol' => $existing?->protocol === 'HTTP' ? 'HTTP,HTTPS' : $existing?->protocol,
                'tlsConfig' => $existing?->tlsConfig,
            ]);
            $client->updateCustomDomain($domain, new UpdateCustomDomainRequest(['body' => $input]));
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'fc' => new FC($this->aliyunConfig($credentials, $this->endpointForRegion($credentials['region'] ?? ''))),
        };
    }

    /**
     * FC 3.0 接入点：region 为空回落杭州，否则 fcv3.{region}.aliyuncs.com（对齐 certimate）。
     */
    private function endpointForRegion(string $region): string
    {
        return $region === '' ? 'fcv3.cn-hangzhou.aliyuncs.com' : "fcv3.$region.aliyuncs.com";
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
