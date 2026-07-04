<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Alb\V20200616\Alb;
use AlibabaCloud\SDK\Alb\V20200616\Models\UpdateListenerAttributeRequest;
use AlibabaCloud\SDK\Alb\V20200616\Models\UpdateListenerAttributeRequest\certificates;
use AlibabaCloud\SDK\Cas\V20200407\Cas;
use Darabonba\OpenApi\Models\Config;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 阿里云 ALB（应用型负载均衡，证书服务型）：证书先经 CAS 上传拿 CertIdentifier（走 RemoteCertStore 去重），
 * 再调 alb.UpdateListenerAttribute 把证书关联到指定 HTTPS/QUIC 监听器。
 *
 * 与 dcdn/vod 的差异：ALB 监听 API 直接吃**完整 CertIdentifier 字符串**（"{certId}-{region}"）作为
 * CertificateId，**不**拆 certId+region（对齐 certimate aliyun-alb 的 updateListenerCertificate）。故 bind 把
 * remote_cert_id 原样塞进 Certificates[].CertificateId，无需 ParsesCasCertIdentifier。
 *
 * 简化：仅实现「指定 listener_id 关联主证书」核心路径（对应 certimate DEPLOY_TARGET_LISTENER 且无 SNI）；
 * 不做 certimate 的遍历负载均衡所有监听 / SNI 扩展证书（Associate/Dissociate）/ 域名匹配（留后续）。
 */
class AliyunAlbDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'alb';
    }

    public function label(): string
    {
        return '阿里云 ALB';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'listener_id', 'label' => '监听 ID', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // 上传器复用 deployer 的注入缝：测试 override makeClient('cas') 即作用于上传
        return new AliyunCasUploader(fn (array $credentials): object => $this->makeClient('cas', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertIdentifier "{certId}-{region}"，原样作 CertificateId）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{region:string,listener_id:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $listenerId = (string) $this->requireConfig($config, 'listener_id');
        $certificateId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $region, $listenerId, $certificateId) {
            /** @var Alb $client */
            $client = $this->makeClient('alb', $credentials, $region);
            $client->updateListenerAttribute(new UpdateListenerAttributeRequest([
                'listenerId' => $listenerId,
                'certificates' => [new certificates(['certificateId' => $certificateId])],
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
            'alb' => new Alb(new Config([
                'accessKeyId' => $ak,
                'accessKeySecret' => $sk,
                'endpoint' => $region !== '' ? "alb.$region.aliyuncs.com" : 'alb.cn-hangzhou.aliyuncs.com',
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
