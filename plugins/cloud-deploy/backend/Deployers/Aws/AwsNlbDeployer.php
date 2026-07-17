<?php

namespace Plugins\CloudDeploy\Deployers\Aws;

use Aws\Acm\AcmClient;
use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;
use Aws\Iam\IamClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * AWS NLB（Network Load Balancer，证书服务型）：证书先经 ACM（默认）或 IAM 上传拿 ARN
 * （走 RemoteCertStore 去重），再调 ElasticLoadBalancingV2 把证书关联到指定 TLS 侦听器。
 *
 * 与 ALB 结构相同（同 ElasticLoadBalancingV2 client + ModifyListener/AddListenerCertificates），
 * **唯一差异**：DescribeLoadBalancers 校验 Type=network（ALB 是 application），对齐 certimate aws-nlb。
 *
 * 简化：仅「指定 listener_arn 关联」核心路径。脱敏边界同 ALB（SDK 在 guardSdk 内、fail 在外）。
 */
class AwsNlbDeployer extends AbstractDeployer
{
    use BuildsAwsClientConfig, ResolvesAwsCertSource;

    public function provider(): string
    {
        return 'aws';
    }

    public function product(): string
    {
        return 'nlb';
    }

    public function label(): string
    {
        return 'AWS NLB（网络型负载均衡）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'load_balancer_arn', 'label' => '负载均衡器 ARN', 'type' => 'string', 'required' => true],
            ['key' => 'listener_arn', 'label' => '侦听器 ARN', 'type' => 'string', 'required' => true],
            ['key' => 'certificate_source', 'label' => '证书来源（ACM/IAM）', 'type' => 'string', 'required' => false],
            ['key' => 'is_default', 'label' => '设为默认证书', 'type' => 'boolean', 'required' => false],
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
     * @param  string  $certRef  remote_cert_id（ACM/IAM 证书 ARN，原样作 CertificateArn）
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @param  array{region:string,load_balancer_arn:string,listener_arn:string,certificate_source?:string,is_default?:bool}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $loadBalancerArn = (string) $this->requireConfig($config, 'load_balancer_arn');
        $listenerArn = (string) $this->requireConfig($config, 'listener_arn');
        $certificateArn = (string) $certRef;
        $isDefault = filter_var($config['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN);

        /** @var ElasticLoadBalancingV2Client $client */
        $client = $this->makeClient('elbv2', $credentials, $region);

        // 校验负载均衡器存在且为 network 类型
        $loadBalancers = $this->guardSdk(fn () => $client->describeLoadBalancers([
            'LoadBalancerArns' => [$loadBalancerArn],
            'PageSize' => 1,
        ])['LoadBalancers'] ?? []);
        if (empty($loadBalancers) || ($loadBalancers[0]['Type'] ?? null) !== 'network') {
            $this->fail("未找到 NLB 实例: $loadBalancerArn");
        }

        // 校验侦听器存在
        $listeners = $this->guardSdk(fn () => $client->describeListeners([
            'LoadBalancerArn' => $loadBalancerArn,
            'ListenerArns' => [$listenerArn],
            'PageSize' => 1,
        ])['Listeners'] ?? []);
        if (empty($listeners)) {
            $this->fail("未找到 NLB 侦听器: $listenerArn");
        }

        if ($isDefault) {
            foreach ($listeners[0]['Certificates'] ?? [] as $cert) {
                if (($cert['CertificateArn'] ?? null) === $certificateArn) {
                    return;
                }
            }
            $this->guardSdk(fn () => $client->modifyListener([
                'ListenerArn' => $listenerArn,
                'Certificates' => [['CertificateArn' => $certificateArn]],
            ]));
        } else {
            $this->guardSdk(fn () => $client->addListenerCertificates([
                'ListenerArn' => $listenerArn,
                'Certificates' => [['CertificateArn' => $certificateArn]],
            ]));
        }
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        $cfg = $this->awsClientConfig($credentials, $region);

        return match ($kind) {
            'acm' => new AcmClient($cfg),
            'iam' => new IamClient($cfg),
            'elbv2' => new ElasticLoadBalancingV2Client($cfg),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AwsErrorSanitizer::sanitize($e);
    }
}
