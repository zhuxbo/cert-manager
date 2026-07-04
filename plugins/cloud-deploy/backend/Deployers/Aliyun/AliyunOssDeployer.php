<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\Oss\V2\Client as OssClient;
use AlibabaCloud\Oss\V2\Config as OssConfig;
use AlibabaCloud\Oss\V2\Credentials\StaticCredentialsProvider;
use AlibabaCloud\Oss\V2\Models\BucketCnameConfiguration;
use AlibabaCloud\Oss\V2\Models\CertificateConfiguration;
use AlibabaCloud\Oss\V2\Models\Cname;
use AlibabaCloud\Oss\V2\Models\PutCnameRequest;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 阿里云 OSS 存储桶自定义域名（内联型）：PutCname 直传 PEM 给存储桶的 CNAME 证书配置，
 * 不走 CAS 证书服务，故 usesRemoteCertStore=false、certUploader=null。对齐 certimate aliyun-oss。
 *
 * 注意 SDK 体系差异：OSS 用独立的 alibabacloud/oss-v2（非 darabonba），异常为
 * AlibabaCloud\Oss\V2\Exception\{ServiceException,OperationException}（均 extends \RuntimeException，
 * 非 TeaError）。其中 OperationException 把底层 Guzzle 异常（message/trace 含签名 URI 的 AK/Signature）
 * 链入 $previous 并拼进 getMessage()——脱敏必须挡住（见 AliyunErrorSanitizer，只放行 ServiceException 的
 * 服务端错误码/描述，其余仅暴露类名）。
 *
 * PEM 结构（PHP SDK v2，与 Go 的 BucketCnameConfiguration 扁平结构不同，多一层 Cname）：
 *   PutCnameRequest(bucket, BucketCnameConfiguration(Cname(domain, CertificateConfiguration(force, certificate, privateKey))))
 *
 * 仅实现 exact domain 核心路径（OSS 本身不支持泛域名）。
 */
class AliyunOssDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'oss';
    }

    public function label(): string
    {
        return '阿里云 OSS';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'bucket', 'label' => '存储桶名', 'type' => 'string', 'required' => true],
            ['key' => 'domain', 'label' => '自定义域名', 'type' => 'string', 'required' => true],
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{bucket:string,domain:string,region:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $bucket = $this->requireConfig($config, 'bucket');
        $domain = $this->requireConfig($config, 'domain');
        $region = $this->requireConfig($config, 'region');
        // 证书 + 中间证书拼成完整链上传（与 CDN/Live 同口径）
        $certificate = rtrim($certRef['cert'])."\n".trim($certRef['chain']);

        $this->guardSdk(function () use ($credentials, $region, $bucket, $domain, $certificate, $certRef) {
            /** @var OssClient $client */
            $client = $this->makeClient('oss', $credentials + ['region' => $region]);
            $client->putCname(new PutCnameRequest(
                $bucket,
                new BucketCnameConfiguration(new Cname(
                    $domain,
                    new CertificateConfiguration(
                        force: true,
                        certificate: $certificate,
                        privateKey: $certRef['key'],
                    ),
                )),
            ));
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'oss' => new OssClient(
                (new OssConfig)
                    ->setCredentialsProvider(new StaticCredentialsProvider(
                        $credentials['access_key_id'] ?? '',
                        $credentials['access_key_secret'] ?? '',
                    ))
                    ->setSignatureVersion('v4')
                    ->setRegion($credentials['region'] ?? '')
                    ->setEndpoint($this->endpointForRegion($credentials['region'] ?? '')),
            ),
        };
    }

    /**
     * OSS 接入点：region 为空回落公网总站，否则 oss-{region}.aliyuncs.com（对齐 certimate）。
     */
    private function endpointForRegion(string $region): string
    {
        return $region === '' ? 'oss.aliyuncs.com' : "oss-$region.aliyuncs.com";
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
