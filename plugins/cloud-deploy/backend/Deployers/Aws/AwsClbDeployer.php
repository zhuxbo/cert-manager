<?php

namespace Plugins\CloudDeploy\Deployers\Aws;

use Aws\Acm\AcmClient;
use Aws\ElasticLoadBalancing\ElasticLoadBalancingClient;
use Aws\Iam\IamClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * AWS CLB（Classic Load Balancer，经典型负载均衡，证书服务型）：证书先经 ACM（默认）或 IAM 上传拿 ARN
 * （走 RemoteCertStore 去重），再调经典 ElasticLoadBalancing 的 SetLoadBalancerListenerSSLCertificate
 * 把证书绑到指定监听端口。
 *
 * 对齐 certimate aws-clb：用 **经典 ElasticLoadBalancing**（非 v2），
 * SetLoadBalancerListenerSSLCertificate(LoadBalancerName, LoadBalancerPort, SSLCertificateId=ARN)。
 * 两源都返回 ARN（ACM CertificateArn / IAM 服务器证书 Arn），统一作 SSLCertificateId。certimate 无 describe
 * 预检，直接 Set，故本端点 bind 不做资源存在性校验（错误由 SDK 返回）。
 *
 * 简化：仅「指定 listener_port 关联」核心路径。
 */
class AwsClbDeployer extends AbstractDeployer
{
    use ResolvesAwsCertSource;

    public function provider(): string
    {
        return 'aws';
    }

    public function product(): string
    {
        return 'clb';
    }

    public function label(): string
    {
        return 'AWS CLB（经典型负载均衡）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'load_balancer_name', 'label' => '负载均衡器名称', 'type' => 'string', 'required' => true],
            ['key' => 'listener_port', 'label' => '监听端口', 'type' => 'number', 'required' => true],
            ['key' => 'certificate_source', 'label' => '证书来源（ACM/IAM）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return $this->resolveCertUploader($config, '/elb/');
    }

    /**
     * @param  string  $certRef  remote_cert_id（ACM/IAM 证书 ARN，原样作 SSLCertificateId）
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array{region:string,load_balancer_name:string,listener_port:int|string,certificate_source?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $loadBalancerName = (string) $this->requireConfig($config, 'load_balancer_name');
        $listenerPort = (int) $this->requireConfig($config, 'listener_port');
        $sslCertificateId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $region, $loadBalancerName, $listenerPort, $sslCertificateId) {
            /** @var ElasticLoadBalancingClient $client */
            $client = $this->makeClient('elb', $credentials, $region);
            $client->setLoadBalancerListenerSSLCertificate([
                'LoadBalancerName' => $loadBalancerName,
                'LoadBalancerPort' => $listenerPort,
                'SSLCertificateId' => $sslCertificateId,
            ]);
        });
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        $cfg = [
            'version' => 'latest',
            'region' => $region !== '' ? $region : 'us-east-1',
            'credentials' => [
                'key' => $credentials['access_key_id'] ?? '',
                'secret' => $credentials['secret_access_key'] ?? '',
            ],
        ];

        return match ($kind) {
            'acm' => new AcmClient($cfg),
            'iam' => new IamClient($cfg),
            'elb' => new ElasticLoadBalancingClient($cfg),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AwsErrorSanitizer::sanitize($e);
    }
}
