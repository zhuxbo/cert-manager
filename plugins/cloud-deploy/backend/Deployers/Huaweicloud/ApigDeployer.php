<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * 华为云 API 网关 APIG（内联型，替换既有证书）。
 *
 * 对齐 certimate deployer huaweicloud-apig 的 DEPLOY_TARGET_CERTIFICATE：
 *   1. 用 global 凭证 IAM 反查 region → projectId。
 *   2. ShowDetailsOfCertificateV2（GET /v2/{project_id}/apigw/certificates/{certificate_id}）取 type/instance_id。
 *   3. UpdateCertificateV2（PUT 同 path）直灌新证书 {name, cert_content, private_key, type, instance_id}。
 *
 * 内联型：APIG 直接更新「既有证书资源」的内容（不经证书托管服务），故 usesRemoteCertStore=false、certUploader=null，
 * bind 收 {cert,key,chain} 三元组（与 certimate 直传 certPEM/privkeyPEM 一致）。type 沿用既有证书的 instance/global 类型。
 *
 * APIG 为 region 服务（apig.{region}.myhuaweicloud.com，basic 凭证 + projectId）。region + certificate_id 必填。
 */
class ApigDeployer extends AbstractDeployer
{
    use ResolvesHuaweiProjectId;

    public function provider(): string
    {
        return 'huaweicloud';
    }

    public function product(): string
    {
        return 'apig';
    }

    public function label(): string
    {
        return '华为云 API 网关 APIG';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'certificate_id', 'label' => '证书 ID', 'type' => 'string', 'required' => true],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef
     * @param  array<string,mixed>  $credentials
     * @param  array{region:string,certificate_id:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $certificateId = (string) $this->requireConfig($config, 'certificate_id');
        // 内联型：$certRef 为 {cert,key,chain} 三元组。证书本体 + 中间证书拼完整链。
        $certContent = is_array($certRef) ? rtrim((string) $certRef['cert'])."\n".trim((string) $certRef['chain']) : '';
        $privateKey = is_array($certRef) ? trim((string) $certRef['key']) : '';
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        $this->guardSdk(function () use ($credentials, $region, $certificateId, $certContent, $privateKey, $certName) {
            $projectId = $this->resolveProjectId($credentials, $region);

            /** @var HuaweicloudRestClient $client */
            $client = $this->makeClient('apig', $credentials, $region, $projectId);

            // ShowDetailsOfCertificateV2：取既有证书的 type / instance_id（替换时沿用，对齐 certimate）。
            $detail = $client->get("/v2/$projectId/apigw/certificates/$certificateId");
            $type = is_string($detail['type'] ?? null) && $detail['type'] !== '' ? $detail['type'] : 'global';
            $instanceId = is_string($detail['instance_id'] ?? null) ? $detail['instance_id'] : '';

            $body = [
                'name' => $certName,
                'cert_content' => $certContent,
                'private_key' => $privateKey,
                'type' => $type,
            ];
            // instance 型证书需带 instance_id（global 型无）。
            if ($type === 'instance' && $instanceId !== '') {
                $body['instance_id'] = $instanceId;
            }

            // UpdateCertificateV2：直灌新证书内容。
            $client->put("/v2/$projectId/apigw/certificates/$certificateId", $body);
        });
    }

    /**
     * @param  array<string,mixed>  $credentials
     */
    protected function makeClient(string $kind, array $credentials, string $region = '', string $projectId = ''): object
    {
        return match ($kind) {
            'iam' => new HuaweicloudRestClient(
                $this->iamHost(),
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
            ),
            'apig' => new HuaweicloudRestClient(
                $this->regionalHost('apig', $region),
                $credentials['access_key_id'] ?? '',
                $credentials['secret_access_key'] ?? '',
                $projectId,
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return HuaweicloudErrorSanitizer::sanitize($e);
    }
}
