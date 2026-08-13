<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ssl\V20191205\SslClient;
use TencentCloud\Waf\V20180125\Models\ModifySpartaProtectionRequest;
use TencentCloud\Waf\V20180125\WafClient;
use Throwable;

/**
 * 腾讯云 Web 应用防火墙 WAF（证书服务型）：证书先经 SSL 服务上传拿 CertificateId，
 * 再调 WAF（waf/v20180125）ModifySpartaProtection 以 CertType=2（托管证书）+ SSLId=CertificateId
 * 把证书设到 SaaS 型防护域名。
 *
 * 简化：直接以 config 三件套（instance_id/domain/domain_id）调 ModifySpartaProtection 设证书核心路径；
 * 不先 DescribeDomainDetailsSaas 读现配置（certimate 仅用其做日志，更新所需参数均来自 config）。
 *
 * 字段大小写陷阱（亲读 vendor 核实）：ModifySpartaProtection 用 **InstanceID**（大写 ID）、**SSLId**（大写 SSL）；
 * 而 DescribeDomainDetailsSaas 用 **InstanceId**（小写 d）——同一产品两接口拼写不一致，本端点只用前者。
 * CertType 为 integer（2=托管证书），deserialize 传 int。
 *
 * WAF 接口是 region 维度，client 构造必须带 region（certimate createSDKClient 传 config.Region），
 * 故 region 为必填配置；SSL 上传服务仍为全局（空 region）。
 */
class TencentWafDeployer extends AbstractDeployer
{
    use UsesTencentEndpoint;

    public function provider(): string
    {
        return 'tencent';
    }

    public function product(): string
    {
        return 'waf';
    }

    public function label(): string
    {
        return '腾讯云 WAF';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'endpoint', 'label' => '接口端点（选填）', 'type' => 'string', 'required' => false, 'destination' => true],
            ['key' => 'instance_id', 'label' => 'WAF 实例 ID', 'type' => 'string', 'required' => true],
            ['key' => 'domain', 'label' => '防护域名', 'type' => 'string', 'required' => true],
            ['key' => 'domain_id', 'label' => '防护域名 ID', 'type' => 'string', 'required' => true],
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // 腾讯 SSL 上传是全局服务（空 region），与 WAF 的 region 维度无关
        return new TencentSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $this->withTencentEndpoint($credentials, $config)));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertificateId）
     * @param  array{secret_id:string,secret_key:string}  $credentials
     * @param  array{instance_id:string,domain:string,domain_id:string,region:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $credentials = $this->withTencentEndpoint($credentials, $config);
        $instanceId = (string) $this->requireConfig($config, 'instance_id');
        $domain = (string) $this->requireConfig($config, 'domain');
        $domainId = (string) $this->requireConfig($config, 'domain_id');
        $region = (string) $this->requireConfig($config, 'region');

        $this->guardSdk(function () use ($credentials, $region, $instanceId, $domain, $domainId, $certRef) {
            /** @var WafClient $client */
            $client = $this->makeClient('waf', $credentials, $region);
            $req = new ModifySpartaProtectionRequest;
            // InstanceID/SSLId 大写拼写为腾讯 WAF 官方（区别于 Describe 的 InstanceId）；CertType=2 为托管证书
            $req->deserialize([
                'InstanceID' => $instanceId,
                'Domain' => $domain,
                'DomainId' => $domainId,
                'CertType' => 2,
                'SSLId' => $certRef,
            ]);
            $client->ModifySpartaProtection($req);
        });
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        $cred = new Credential($credentials['secret_id'] ?? '', $credentials['secret_key'] ?? '');
        $http = new HttpProfile;
        $http->setReqTimeout(15);
        $this->configureTencentEndpoint($http, $credentials, $kind);
        $profile = new ClientProfile;
        $profile->setHttpProfile($http);

        return match ($kind) {
            // SSL 全局服务（certimate 亦传空 region）
            'ssl' => new SslClient($cred, '', $profile),
            // WAF region 维度：client 构造必须带 region
            'waf' => new WafClient($cred, $region, $profile),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}
