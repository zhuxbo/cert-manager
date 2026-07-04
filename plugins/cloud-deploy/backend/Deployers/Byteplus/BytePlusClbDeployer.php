<?php

namespace Plugins\CloudDeploy\Deployers\Byteplus;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * BytePlus 传统型负载均衡 CLB（证书服务型）：证书先经证书中心上传拿 id（走 RemoteCertStore 去重），
 * 再设到 CLB 的 HTTPS 监听器。
 *
 * 对齐 certimate byteplus-clb（service=clb, version 2020-04-01，GET 类 OpenAPI）：
 *   - 上传：证书中心 UploadCertificate（region ap-singapore-1）→ CertId。
 *   - deploy_target=loadbalancer：取实例全部 HTTPS 监听逐个更新；listener：直接更新指定监听。
 *   - CLB **不支持 SNI**（certimate updateListenerCertificate 直接 ModifyListenerAttributes 设主证书，无 DomainExtensions）。
 *
 * 业务流程在 BytePlusLoadBalancerDeployTrait（与 ALB 共享）；CLB supportsSni=false（即便配了 domain 也忽略）。
 * 证书上传固定 ap-singapore-1；CLB 资源操作签名 region 取 config.region。
 */
class BytePlusClbDeployer extends AbstractDeployer
{
    use BytePlusLoadBalancerDeployTrait;

    /** 证书中心上传固定 ap-singapore-1（对齐 certimate clb 的 certmgr region）。 */
    private const CERTCENTER_REGION = 'ap-singapore-1';

    public function provider(): string
    {
        return 'byteplus';
    }

    public function product(): string
    {
        return 'clb';
    }

    public function label(): string
    {
        return 'BytePlus 传统型负载均衡 CLB';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'deploy_target', 'label' => '部署目标', 'type' => 'string', 'required' => true, 'options' => [
                ['label' => '负载均衡实例（全部 HTTPS 监听）', 'value' => 'loadbalancer'],
                ['label' => '指定监听器', 'value' => 'listener'],
            ]],
            ['key' => 'loadbalancer_id', 'label' => '负载均衡实例 ID（部署目标为实例时必填）', 'type' => 'string', 'required' => false],
            ['key' => 'listener_id', 'label' => '监听器 ID（部署目标为指定监听器时必填）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new BytePlusCertCenterUploader(
            fn (array $credentials): object => $this->makeClient('certcenter', $credentials),
        );
    }

    /** CLB 不支持 SNI 扩展域名。 */
    protected function supportsSni(): bool
    {
        return false;
    }

    /** CLB 签名 service。 */
    protected function lbService(): string
    {
        return 'clb';
    }

    /** CLB OpenAPI 版本。 */
    protected function lbApiVersion(): string
    {
        return '2020-04-01';
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        return match ($kind) {
            // 证书中心：上传，固定 ap-singapore-1。
            'certcenter' => new BytePlusRestClient(
                'certificate_service',
                self::CERTCENTER_REGION,
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            // CLB：资源操作，签名 region 取 config.region。
            'lb' => new BytePlusRestClient(
                $this->lbService(),
                $region,
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return BytePlusErrorSanitizer::sanitize($e);
    }
}
