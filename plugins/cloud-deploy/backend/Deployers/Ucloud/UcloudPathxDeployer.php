<?php

namespace Plugins\CloudDeploy\Deployers\Ucloud;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 优刻得 全球加速（PathX）部署器 —— 证书服务型（usesRemoteCertStore=true，USSL）。
 *
 * 流程对齐 certimate ucloud-upathx：
 *   1. 证书经 USSL 上传拿 CertificateID（走 RemoteCertStore 去重）。
 *   2. BindPathXSSL {UGAId=加速器实例 ID, Port.0=监听端口, SSLId=数字 certId}。
 *
 * ProjectId 处理（对齐 certimate getSDKDefaultProjectId）：PathX 接口要求必传 ProjectId。
 *   - 凭证已配 project_id → UcloudRestClient 自动随请求外发，无需特殊处理。
 *   - 未配 project_id → bind 前 GetProjectList 取默认项目 ID，构造带该 ProjectId 的 client。
 *
 * config：accelerator_id（加速器实例 ID）+ listener_port（监听端口）。仅 exact 核心路径。
 */
class UcloudPathxDeployer extends AbstractDeployer
{
    use ParsesUcloudCertRef;
    use UcloudClientFactory;

    public function provider(): string
    {
        return 'ucloud';
    }

    public function product(): string
    {
        return 'pathx';
    }

    public function label(): string
    {
        return '优刻得 全球加速 PathX';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'endpoint', 'label' => '接口端点（选填）', 'type' => 'string', 'required' => false, 'destination' => true],
            ['key' => 'accelerator_id', 'label' => '加速器实例 ID', 'type' => 'string', 'required' => true],
            ['key' => 'listener_port', 'label' => '监听端口', 'type' => 'number', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new UcloudUsslUploader(fn (array $credentials): object => $this->makeClient('api', $this->withUcloudEndpoint($credentials, $config)));
    }

    /**
     * @param  string  $certRef  复合 remote_cert_id "{certId}|{certName}"（PathX 仅用 certId）
     * @param  array{public_key?:string,private_key?:string,project_id?:string}  $credentials
     * @param  array{accelerator_id:string,listener_port:int|string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $credentials = $this->withUcloudEndpoint($credentials, $config);
        $acceleratorId = (string) $this->requireConfig($config, 'accelerator_id');
        $port = (int) $this->requireConfig($config, 'listener_port');
        [$certIdStr] = $this->parseCertRef((string) $certRef);

        // PathX 必传 ProjectId：凭证未配时取默认项目（含 SDK 调用 + 业务判断，自行分隔 guardSdk）。
        $resolvedCredentials = $this->ensureProjectId($credentials);

        $this->guardSdk(fn () => $this->makeClient('api', $resolvedCredentials)
            ->bindPathXSSL($acceleratorId, [$port], $certIdStr));
    }

    /**
     * 凭证已配 project_id 直接返回；否则 GetProjectList 取默认项目并补入 project_id。
     * SDK 调用包 guardSdk、业务判断（无默认项目 fail）放其外。
     *
     * @param  array<string,mixed>  $credentials
     * @return array<string,mixed>
     */
    private function ensureProjectId(array $credentials): array
    {
        $projectId = is_string($credentials['project_id'] ?? null) ? $credentials['project_id'] : '';
        if ($projectId !== '') {
            return $credentials;
        }

        $projects = $this->guardSdk(fn () => $this->makeClient('api', $credentials)->getProjectList());
        foreach ($projects as $project) {
            if ($project['IsDefault'] && $project['ProjectId'] !== '') {
                $credentials['project_id'] = $project['ProjectId'];

                return $credentials;
            }
        }

        $this->fail('优刻得 PathX 需要项目 ID，但凭证未配置且未找到默认项目');
    }

    protected function sanitize(Throwable $e): string
    {
        return UcloudErrorSanitizer::sanitize($e);
    }
}
