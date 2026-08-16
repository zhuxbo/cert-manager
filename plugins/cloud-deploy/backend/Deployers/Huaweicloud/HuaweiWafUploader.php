<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 华为云 WAF 证书上传器（storeKind=huawei_waf:{region}，region 维度）。
 *
 * 对齐 certimate certmgr huaweicloud-waf 的 Upload：先用 global 凭证调 IAM 反查 region → projectId，再调 WAF
 *   POST /v1/{project_id}/waf/certificate?enterprise_project_id=... {name, content=完整链, key=私钥} → id
 * 返回 WAF 证书 id 作为 remote_cert_id。
 *
 * WAF 证书空间与 SCM/ELB **不同**（WAF 自有证书资源，仅 WAF 防护域名可引用），按 region 隔离 —— storeKind 含 region
 * （huawei_waf:{region}）。注意 WAF 用 `content`/`key` 字段名（非 certificate/private_key），写错会静默丢字段（见陷阱清单）。
 *
 * region/projectId 解析同 ELB 上传器：region 由 deployer 注入、projectId upload 内 IAM 自查。
 */
class HuaweiWafUploader implements CertUploaderInterface
{
    /**
     * @param  string  $region  WAF 区域（构造 host + storeKind）
     * @param  Closure(array<string,mixed>):object  $iamFactory  返回绑定 IAM host 的 HuaweicloudRestClient（反查 projectId）
     * @param  Closure(array<string,mixed>,string):object  $wafFactory  返回绑定 WAF host + projectId 的 HuaweicloudRestClient
     */
    public function __construct(
        private readonly string $region,
        private readonly Closure $iamFactory,
        private readonly Closure $wafFactory,
        private readonly string $replaceCertificateId = '',
    ) {}

    public function storeKind(): string
    {
        if ($this->replaceCertificateId !== '') {
            return 'huawei-waf-r:'.substr(hash('sha256', $this->region."\0".$this->replaceCertificateId), 0, 18);
        }

        return 'huawei_waf:'.($this->region !== '' ? $this->region : 'default');
    }

    /**
     * @param  array{access_key_id?:string,secret_access_key?:string,enterprise_project_id?:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        if ($this->region === '') {
            throw new RuntimeException('华为云 WAF 证书上传缺少 region');
        }

        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);
        $enterpriseProjectId = isset($credentials['enterprise_project_id']) ? (string) $credentials['enterprise_project_id'] : '';

        try {
            /** @var HuaweicloudRestClient $iam */
            $iam = ($this->iamFactory)($credentials);
            $projectResp = $iam->get('/v3/projects', ['name' => $this->region]);
            $projectId = self::firstProjectId($projectResp);
            if ($projectId === '') {
                throw new HuaweicloudApiException('ProjectNotFound', "未找到 region '{$this->region}' 对应的华为云项目 ID");
            }

            $query = [];
            if ($enterpriseProjectId !== '') {
                $query['enterprise_project_id'] = $enterpriseProjectId;
            }

            /** @var HuaweicloudRestClient $waf */
            $waf = ($this->wafFactory)($credentials, $projectId);
            if ($this->replaceCertificateId !== '') {
                $path = "/v1/$projectId/waf/certificate/{$this->replaceCertificateId}";
                $existing = $waf->get($path, $query);
                $waf->put($path, [
                    'name' => (string) ($existing['name'] ?? ''),
                    'content' => $fullChain,
                    'key' => trim($keyPem),
                ], $query);

                return $this->replaceCertificateId;
            }

            // WAF CreateCertificate：字段名 content / key（严格对齐 certimate，写错会静默丢）。
            $result = $waf->post("/v1/$projectId/waf/certificate", [
                'name' => $certName,
                'content' => $fullChain,
                'key' => trim($keyPem),
            ], $query);
        } catch (Throwable $e) {
            throw new RuntimeException(HuaweicloudErrorSanitizer::sanitize($e), 0);
        }

        $certId = $result['id'] ?? null;
        if (! is_string($certId) || $certId === '') {
            throw new RuntimeException('华为云 WAF CreateCertificate 未返回 id');
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
