<?php

namespace Plugins\CloudDeploy\Deployers\Aws;

use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;

/**
 * ACM / IAM 双证书源选择（alb / nlb / clb / cloudfront 共用）。
 *
 * certimate 的 alb/nlb/clb/cloudfront 都支持 certificateSource ∈ {ACM, IAM}，默认 ACM：
 *   - ACM 源：证书导入 ACM，绑定用 CertificateArn（region 维度，uploader storeKind="acm:{region}"）。
 *   - IAM 源：证书上传 IAM 服务器证书（全局，storeKind="iam"），绑定用其 Arn；certimate 对 ELB 系用
 *     Path="/elb/"、CloudFront 用 "/cloudfront/" 归类（仅控制台分组，不影响返回 Arn 与绑定）。
 *
 * 两类上传器都返回 **ARN**，故 bind 一律拿 ARN 绑资源（alb/nlb 的 Certificates[].CertificateArn、
 * clb 的 SSLCertificateId、cloudfront 的 ViewerCertificate.ACMCertificateArn）——单一 remote_cert_id
 * 契约下两源统一为 ARN，无需区分。
 *
 * 用此 trait 的 deployer 必须有 makeClient(string $kind, array $credentials, string $region = '')
 * 签名（'acm'/'iam' 两 kind），并在 configSchema 声明 'certificate_source' 字段。
 */
trait ResolvesAwsCertSource
{
    public const CERT_SOURCE_ACM = 'ACM';

    public const CERT_SOURCE_IAM = 'IAM';

    /**
     * 按 config.certificate_source 构造对应 uploader（默认 ACM）。
     *
     * @param  array<string,mixed>  $config
     * @param  string  $iamPath  IAM 源的证书 Path（如 "/elb/"、"/cloudfront/"）
     */
    protected function resolveCertUploader(array $config, string $iamPath): CertUploaderInterface
    {
        $region = (string) ($config['region'] ?? '');
        $source = $this->normalizeCertSource($config);

        if ($source === self::CERT_SOURCE_IAM) {
            // 上传器复用 deployer 注入缝：测试 override makeClient('iam') 即作用于上传
            return new AwsIamUploader(
                fn (array $credentials): object => $this->makeClient('iam', $credentials, $region),
                $iamPath,
            );
        }

        return new AwsAcmUploader(
            fn (array $credentials): object => $this->makeClient('acm', $credentials, $region),
            $region,
        );
    }

    /**
     * 归一 certificate_source：仅 'IAM'（不分大小写）走 IAM，其余（含缺省/空/非法）回落 ACM。
     *
     * @param  array<string,mixed>  $config
     */
    protected function normalizeCertSource(array $config): string
    {
        $raw = $config['certificate_source'] ?? '';
        $raw = is_string($raw) ? strtoupper(trim($raw)) : '';

        return $raw === self::CERT_SOURCE_IAM ? self::CERT_SOURCE_IAM : self::CERT_SOURCE_ACM;
    }

    abstract protected function makeClient(string $kind, array $credentials, string $region = ''): object;
}
