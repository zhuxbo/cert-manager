<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 华为云 ELB 证书上传器（storeKind=huawei_elb:{region}，region 维度）。
 *
 * 对齐 certimate certmgr huaweicloud-elb 的 Upload：先用 global 凭证调 IAM 反查 region → projectId，再调 ELB
 *   POST /v3/{project_id}/elb/certificates {certificate:{name, certificate=完整链, private_key, enterprise_project_id?}} → certificate.id
 * 返回 ELB 证书 id 作为 remote_cert_id。
 *
 * ELB 证书空间与 SCM **不同**（ELB 自有证书资源，仅 ELB 监听器可引用），且按 region 隔离 —— 故 storeKind 含 region
 * （huawei_elb:{region}），与 certimate「ELB CreateCertificate 是 region 服务」一致。
 *
 * region/projectId 解析：region 由 deployer 据 config 注入构造（同 SLB region 型上传器）；projectId 在 upload 内经
 * IAM 反查（上传器拿不到 config，只能自查）。两个 client（iam/elb）都经注入缝 $iamFactory / $elbFactory 构造，
 * 测试 override deployer::makeClient 即覆盖。
 */
class HuaweiElbUploader implements CertUploaderInterface
{
    /**
     * @param  string  $region  ELB 区域（构造 host + storeKind）
     * @param  Closure(array<string,mixed>):object  $iamFactory  返回绑定 IAM host 的 HuaweicloudRestClient（反查 projectId）
     * @param  Closure(array<string,mixed>,string):object  $elbFactory  返回绑定 ELB host + projectId 的 HuaweicloudRestClient
     */
    public function __construct(
        private readonly string $region,
        private readonly Closure $iamFactory,
        private readonly Closure $elbFactory,
        private readonly string $replaceCertificateId = '',
    ) {}

    public function storeKind(): string
    {
        // region 维度隔离（ELB 证书是 region 服务）。空 region（探活）回落 default，真实 region 由 bind 校验。
        if ($this->replaceCertificateId !== '') {
            return 'huawei-elb-r:'.substr(hash('sha256', $this->region."\0".$this->replaceCertificateId), 0, 18);
        }

        return 'huawei_elb:'.($this->region !== '' ? $this->region : 'default');
    }

    /**
     * @param  array{access_key_id?:string,secret_access_key?:string,enterprise_project_id?:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        if ($this->region === '') {
            throw new RuntimeException('华为云 ELB 证书上传缺少 region');
        }

        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);
        $enterpriseProjectId = isset($credentials['enterprise_project_id']) ? (string) $credentials['enterprise_project_id'] : '';

        try {
            // 1. IAM 反查 region → projectId（global 凭证）。
            /** @var HuaweicloudRestClient $iam */
            $iam = ($this->iamFactory)($credentials);
            $projectResp = $iam->get('/v3/projects', ['name' => $this->region]);
            $projectId = self::firstProjectId($projectResp);
            if ($projectId === '') {
                throw new HuaweicloudApiException('ProjectNotFound', "未找到 region '{$this->region}' 对应的华为云项目 ID");
            }

            /** @var HuaweicloudRestClient $elb */
            $elb = ($this->elbFactory)($credentials, $projectId);
            if ($this->replaceCertificateId !== '') {
                $elb->put("/v3/$projectId/elb/certificates/{$this->replaceCertificateId}", [
                    'certificate' => [
                        'certificate' => $fullChain,
                        'private_key' => trim($keyPem),
                    ],
                ]);

                return $this->replaceCertificateId;
            }

            // 2. ELB CreateCertificate（basic 凭证 + projectId）。
            $certificate = [
                'name' => $certName,
                'certificate' => $fullChain,
                'private_key' => trim($keyPem),
                'type' => 'server',
            ];
            if ($enterpriseProjectId !== '') {
                $certificate['enterprise_project_id'] = $enterpriseProjectId;
            }

            $result = $elb->post("/v3/$projectId/elb/certificates", ['certificate' => $certificate]);
        } catch (Throwable $e) {
            throw new RuntimeException(HuaweicloudErrorSanitizer::sanitize($e), 0);
        }

        $cert = is_array($result['certificate'] ?? null) ? $result['certificate'] : [];
        $certId = $cert['id'] ?? null;
        if (! is_string($certId) || $certId === '') {
            throw new RuntimeException('华为云 ELB CreateCertificate 未返回 certificate.id');
        }

        return $certId;
    }

    /**
     * @param  array<string,mixed>  $resp
     */
    private static function firstProjectId(array $resp): string
    {
        $projects = is_array($resp['projects'] ?? null) ? $resp['projects'] : [];
        foreach ($projects as $project) {
            if (is_array($project) && is_string($project['id'] ?? null) && $project['id'] !== '') {
                return $project['id'];
            }
        }

        return '';
    }
}
