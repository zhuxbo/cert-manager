<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Nlb\V20220430\Models\UpdateListenerAttributeRequest;
use AlibabaCloud\SDK\Nlb\V20220430\Nlb;
use Darabonba\OpenApi\Models\Config;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 阿里云 NLB（网络型负载均衡，证书服务型）：证书先经 CAS 上传拿 CertIdentifier（走 RemoteCertStore 去重），
 * 再调 nlb.UpdateListenerAttribute 把证书关联到指定 TCPSSL 监听器。
 *
 * 与 alb 一样，NLB 监听 API 吃**完整 CertIdentifier 字符串**作为 CertificateIds 元素，**不**拆 certId+region。
 * 与 alb 的形参差异：NLB 的 certificateIds 是**扁平 string 数组**（非嵌套对象，对齐 certimate aliyun-nlb）。
 * certimate 在 update 前先 GetListenerAttribute 读一次，但其响应仅用于日志、不影响 update 入参，故此处省略。
 *
 * 简化：仅实现「指定 listener_id 关联证书」核心路径；不做遍历负载均衡所有 TCPSSL 监听（留后续）。
 */
class AliyunNlbDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'nlb';
    }

    public function label(): string
    {
        return '阿里云 NLB';
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
        return new AliyunCasUploader(fn (array $credentials): object => $this->makeClient('cas', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertIdentifier "{certId}-{region}"，原样作 CertificateIds 元素）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{region:string,listener_id:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $listenerId = (string) $this->requireConfig($config, 'listener_id');
        $certificateId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $region, $listenerId, $certificateId) {
            /** @var Nlb $client */
            $client = $this->makeClient('nlb', $credentials, $region);
            $client->updateListenerAttribute(new UpdateListenerAttributeRequest([
                'listenerId' => $listenerId,
                'certificateIds' => [$certificateId],
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
            'nlb' => new Nlb(new Config([
                'accessKeyId' => $ak,
                'accessKeySecret' => $sk,
                'endpoint' => $region !== '' ? "nlb.$region.aliyuncs.com" : 'nlb.cn-hangzhou.aliyuncs.com',
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
