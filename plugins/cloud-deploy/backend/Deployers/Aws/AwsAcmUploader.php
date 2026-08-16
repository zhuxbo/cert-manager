<?php

namespace Plugins\CloudDeploy\Deployers\Aws;

use Aws\Acm\AcmClient;
use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * AWS Certificate Manager（ACM）证书上传器（storeKind="acm:{region}"）。
 *
 * 与 IAM 上传器的本质差异 —— **ACM 证书是 region 维度**：
 *   - 上传走 region 化 endpoint（client 按 region 构造）+ 返回的 CertificateArn **含 region 段**、
 *     只在该 region 有效（CloudFront 强制 us-east-1，由 config.region 体现）。
 *   - 故本上传器构造时即接 region（由 deployer 的 certUploader($config) 从 config.region 注入），
 *     storeKind 返回 "acm:{region}" 把 region 揉进去重键，同账号同证书部署到不同 region 各落一行、
 *     各持本 region 的 Arn，绝不跨 region 误复用（对齐阿里 SLB 上传器 region 隔离策略）。
 *
 * 上传流程（对齐 certimate aws-acm，删其 ListCertificates 查重——RemoteCertStore 已按
 * (access_id, store_kind, fingerprint) 去重，上传器只管上传）：
 *   ImportCertificate(Certificate=服务器证书, CertificateChain=中间证书, PrivateKey) → CertificateArn
 * 返回 CertificateArn 作为 remote_cert_id —— 各 bind（cloudfront/alb/nlb/clb/amplify/apigateway）原样作 ARN。
 *
 * 注意：插件传入的 $certPem 已是单张服务器证书（leaf）、$chainPem 是中间证书链（与 certimate
 * ExtractCertificatesFromPEM 拆分语义一致），故直接 Certificate=$certPem / CertificateChain=$chainPem，
 * **不**再拼接（区别于阿里 CAS 需完整链）。
 *
 * SDK 异常脱敏：AwsException extends RuntimeException，故所有 SDK 调用裹一个 try、catch(Throwable) 经
 * AwsErrorSanitizer 重抛干净异常（绝不 catch(RuntimeException) 透传，否则签名 URI/AK 泄露）；
 * 业务校验（空 Arn）放 try 外，避免被自己的 catch 二次脱敏。
 *
 * SDK client 经注入缝 $clientFactory（由 deployer 的 makeClient('acm', …, region) 提供）——
 * 测试 override deployer::makeClient 即自动作用于此处，无需单独 mock 上传器。
 */
class AwsAcmUploader implements CertUploaderInterface
{
    /**
     * @param  Closure(array<string,mixed>):object  $clientFactory  返回 Aws\Acm\AcmClient
     * @param  string  $region  上传目标 region（决定 endpoint + storeKind 隔离段）
     */
    public function __construct(
        private readonly Closure $clientFactory,
        private readonly string $region,
        private readonly string $certificateArn = '',
    ) {}

    public function storeKind(): string
    {
        if ($this->certificateArn !== '') {
            // store_kind 列最长 32；ARN 自带 region，以稳定短 hash 隔离不同原地替换目标。
            return 'acm-replace:'.substr(hash('sha256', $this->certificateArn), 0, 16);
        }

        return 'acm:'.$this->region;
    }

    /**
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        try {
            /** @var AcmClient $client */
            $client = ($this->clientFactory)($credentials);

            $request = [
                'Certificate' => $certPem,
                'CertificateChain' => $chainPem,
                'PrivateKey' => $keyPem,
            ];
            if ($this->certificateArn !== '') {
                $request['CertificateArn'] = $this->certificateArn;
            }
            $result = $client->importCertificate($request);
            $arn = $result['CertificateArn'] ?? null;
        } catch (Throwable $e) {
            throw new RuntimeException(AwsErrorSanitizer::sanitize($e), 0);
        }

        if (! is_string($arn) || $arn === '') {
            throw new RuntimeException('AWS ImportCertificate 未返回 CertificateArn');
        }

        return $arn;
    }
}
