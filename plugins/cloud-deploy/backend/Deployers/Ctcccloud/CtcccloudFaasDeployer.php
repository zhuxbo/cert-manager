<?php

namespace Plugins\CloudDeploy\Deployers\Ctcccloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 天翼云函数计算 FaaS（内联型，自定义域名直灌 PEM）。
 *
 * FaaS 自定义域名支持直接在域名配置里写入证书 PEM（不经独立证书服务），故 usesRemoteCertStore=false、
 * certUploader=null，bind 内 $certRef 是 {cert,key,chain} 三元组。
 *
 * 对齐 certimate ctcccloud-faas 的 Deploy：
 *   1. GET /openapi/v1/domains/customdomains/{domain}?cnameCheck=false（regionId 走请求头）获取当前自定义域名配置：
 *      若已部署的 certConfig.certificate / privateKey 与本次完全一致则跳过（幂等）。
 *   2. PUT /openapi/v1/domains/customdomains/{domain}（regionId 走请求头）更新：
 *      body = {domainName, protocol(保留原值并确保含 HTTPS), authConfig(原样回写), certConfig:{certName, certificate=完整链, privateKey}}。
 *      protocol 处理：原值不含 "HTTPS" 时——空则设 "HTTPS"，非空则追加 ",HTTPS"。
 *
 * 配置：region_id + domain 必填（FaaS 不支持泛域名）。endpoint host：cf-global.ctapi.ctyun.cn，成功码 0。
 */
class CtcccloudFaasDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'ctcccloud';
    }

    public function product(): string
    {
        return 'faas';
    }

    public function label(): string
    {
        return '天翼云函数计算 FaaS';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region_id', 'label' => '资源池 ID', 'type' => 'string', 'required' => true],
            ['key' => 'domain', 'label' => '自定义域名', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array{region_id:string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $regionId = (string) $this->requireConfig($config, 'region_id');
        $domain = (string) $this->requireConfig($config, 'domain');
        // 内联型：证书本体 + 中间证书拼完整链；私钥 trim。
        $certificate = is_array($certRef) ? rtrim((string) $certRef['cert'])."\n".trim((string) $certRef['chain']) : '';
        $privateKey = is_array($certRef) ? trim((string) $certRef['key']) : '';

        $this->guardSdk(function () use ($credentials, $regionId, $domain, $certificate, $privateKey) {
            /** @var CtcccloudRestClient $client */
            $client = $this->makeClient('faas', $credentials);

            $headers = ['regionId' => $regionId];
            $path = '/openapi/v1/domains/customdomains/'.rawurlencode($domain);

            // 获取当前自定义域名配置（幂等检查 + 回写 protocol/authConfig）。
            $resp = $client->get($path, ['cnameCheck' => 'false'], $headers);
            $current = is_array($resp['returnObj'] ?? null) ? $resp['returnObj'] : [];

            // 幂等：证书 + 私钥与已部署完全一致则跳过。
            $currentCertConfig = is_array($current['certConfig'] ?? null) ? $current['certConfig'] : [];
            if (($currentCertConfig['certificate'] ?? null) === $certificate
                && ($currentCertConfig['privateKey'] ?? null) === $privateKey) {
                return;
            }

            $protocol = is_string($current['protocol'] ?? null) ? $current['protocol'] : '';
            if (! str_contains($protocol, 'HTTPS')) {
                $protocol = $protocol === '' ? 'HTTPS' : $protocol.',HTTPS';
            }

            $body = [
                'domainName' => $domain,
                'protocol' => $protocol,
                'certConfig' => [
                    'certName' => 'clouddeploy-'.(int) (microtime(true) * 1000),
                    'certificate' => $certificate,
                    'privateKey' => $privateKey,
                ],
            ];
            // 回写原鉴权配置（存在才带，避免被清空）。
            if (isset($current['authConfig']) && is_array($current['authConfig'])) {
                $body['authConfig'] = $current['authConfig'];
            }

            $client->put($path, $body, [], $headers);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'faas' => new CtcccloudRestClient(
                'cf-global.ctapi.ctyun.cn',
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
                ['0'],   // FaaS 成功码 0
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return CtcccloudErrorSanitizer::sanitize($e);
    }
}
