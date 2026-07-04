<?php

namespace Plugins\CloudDeploy\Deployers\Aws;

use Aws\Iam\IamClient;
use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * AWS IAM 服务器证书上传器（storeKind="iam"）。
 *
 * 适用场景：传统 ELB（CLB）必须用 IAM 服务器证书；ALB/NLB/CloudFront 也可选 IAM 源（默认 ACM）。
 * IAM 是**全局服务**（非 region），故 storeKind 返回裸 "iam"，不编 region。
 *
 * 上传流程（对齐 certimate aws-iam，删其 ListServerCertificates 查重——RemoteCertStore 已去重）：
 *   UploadServerCertificate(ServerCertificateName, Path, CertificateBody=服务器证书,
 *     CertificateChain=中间证书, PrivateKey) → ServerCertificateMetadata.Arn
 * 返回 **Arn** 作为 remote_cert_id —— alb/nlb（Certificates[].CertificateArn）、clb（SSLCertificateId）
 * 均吃 ARN。证书名用毫秒时间戳保唯一（符合 IAM 命名规则：字母/数字/部分符号）。
 *
 * Path：certimate 对 alb/nlb/clb 用 "/elb/" 归类（IAM 路径不影响返回 Arn 与绑定，仅控制台分组 + 配额命名空间）。
 * 由构造注入，缺省 "/"（对齐 certimate 默认）。
 *
 * 与 ACM 上传器一样：插件传入的 $certPem 已是 leaf、$chainPem 是中间链，直接 CertificateBody=$certPem /
 * CertificateChain=$chainPem，不再拼接。SDK 异常裹 try、catch(Throwable) 脱敏重抛；业务校验放 try 外。
 *
 * SDK client 经注入缝 $clientFactory（由 deployer 的 makeClient('iam', …) 提供）——
 * 测试 override deployer::makeClient 即自动作用于此处。
 */
class AwsIamUploader implements CertUploaderInterface
{
    /**
     * @param  Closure(array<string,mixed>):object  $clientFactory  返回 Aws\Iam\IamClient
     * @param  string  $path  IAM 证书路径（如 "/elb/"），缺省 "/"
     */
    public function __construct(private readonly Closure $clientFactory, private readonly string $path = '/') {}

    public function storeKind(): string
    {
        return 'iam';
    }

    /**
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // IAM 服务器证书命名规则：字母/数字 + 部分符号；毫秒时间戳保唯一。
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);
        // Path 必须以 "/" 开头结尾；空值回落根路径。
        $path = $this->path !== '' ? $this->path : '/';

        try {
            /** @var IamClient $client */
            $client = ($this->clientFactory)($credentials);

            $result = $client->uploadServerCertificate([
                'ServerCertificateName' => $certName,
                'Path' => $path,
                'CertificateBody' => $certPem,
                'CertificateChain' => $chainPem,
                'PrivateKey' => $keyPem,
            ]);
            $arn = $result['ServerCertificateMetadata']['Arn'] ?? null;
        } catch (Throwable $e) {
            throw new RuntimeException(AwsErrorSanitizer::sanitize($e), 0);
        }

        if (! is_string($arn) || $arn === '') {
            throw new RuntimeException('AWS UploadServerCertificate 未返回 ServerCertificateMetadata.Arn');
        }

        return $arn;
    }
}
