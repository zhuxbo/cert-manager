<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Models\ModifyDefaultHttpsRequest;
use AlibabaCloud\SDK\Wafopenapi\V20211001\Wafopenapi;
use Darabonba\OpenApi\Models\Config;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 阿里云 WAF 3.0（证书服务型，复用 CAS 上传器）：证书先经 AliyunCasUploader 上传拿 CertIdentifier
 * （走 RemoteCertStore 去重，store_kind=cas），再调 waf.ModifyDefaultHttps 把证书设为 WAF 实例的
 * **默认 SSL/TLS 证书**。
 *
 * 与 alb/nlb 同：WAF 接口吃**完整 CertIdentifier 字符串**（"{certId}-{region}"）作为 CertId，**不**拆
 * certId+region（对齐 certimate aliyun-waf：`certId := upres.ExtendedData["CertIdentifier"].(string)`
 * 原样塞 ModifyDefaultHttps.CertId）。故 bind 把 remote_cert_id 原样作 certId，无需 ParsesCasCertIdentifier。
 *
 * **简化**：仅实现 certimate WAF3 的「CNAME 接入 + 默认证书」核心路径（deployToWAF3WithCNAME 无 domain 分支）。
 * 不做：cloudresource 云产品接入（DescribeResourceInstanceCerts/ModifyCloudResourceCert）、扩展域名
 * （ModifyDomain，需回灌全部 Listen/Redirect 字段，极复杂）、DescribeDefaultHttps 预读保留原 TLS 配置。
 * TLS 取 certimate 同款默认（tlsv1.2 + EnableTLSv3）——这是 certimate 未拿到 describe 结果前的种子值，安全不降级。
 *
 * region：WAF 服务自身 region（config.region，定 WAF endpoint + ModifyDefaultHttps.RegionId），
 * 与证书的 CAS region（编在 CertIdentifier 里、CAS 全局）相互独立。
 */
class AliyunWafDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'waf';
    }

    public function label(): string
    {
        return '阿里云 WAF';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'instance_id', 'label' => 'WAF 实例 ID', 'type' => 'string', 'required' => true],
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // CAS 全局，上传不需要 region；复用 deployer 注入缝：测试 override makeClient('cas') 即作用于上传
        return new AliyunCasUploader(fn (array $credentials): object => $this->makeClient('cas', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertIdentifier "{certId}-{region}"，原样作 CertId）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{instance_id:string,region:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $instanceId = (string) $this->requireConfig($config, 'instance_id');
        $region = (string) $this->requireConfig($config, 'region');
        $certId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $region, $instanceId, $certId) {
            /** @var Wafopenapi $client */
            $client = $this->makeClient('waf', $credentials, $region);
            $client->modifyDefaultHttps(new ModifyDefaultHttpsRequest([
                'regionId' => $region,
                'instanceId' => $instanceId,
                'certId' => $certId,
                'TLSVersion' => 'tlsv1.2',
                'enableTLSv3' => true,
            ]));
        });
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        $ak = $credentials['access_key_id'] ?? '';
        $sk = $credentials['access_key_secret'] ?? '';

        return match ($kind) {
            'cas' => new Cas(new Config([
                'accessKeyId' => $ak,
                'accessKeySecret' => $sk,
                'endpoint' => 'cas.aliyuncs.com',
            ])),
            // 接入点：wafopenapi.{region}.aliyuncs.com（空 region 回落 cn-hangzhou）
            'waf' => new Wafopenapi(new Config([
                'accessKeyId' => $ak,
                'accessKeySecret' => $sk,
                'endpoint' => $region !== '' ? "wafopenapi.$region.aliyuncs.com" : 'wafopenapi.cn-hangzhou.aliyuncs.com',
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
