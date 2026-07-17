<?php

namespace Plugins\CloudDeploy\Deployers\Ctcccloud;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 天翼云弹性负载均衡 ELB 证书上传器（storeKind=ctcccloud_elb:{region}，region 维度）。
 *
 * 对齐 certimate certmgr ctcccloud-elb 的 Upload：调天翼云 ELB REST
 *   POST /v4/elb/create-certificate {clientToken, regionID, name, description, type:"Server", certificate=完整链, privateKey} → returnObj.id
 * 返回 **CertId** 作为 remote_cert_id（ELB UpdateListener 用 certificateID 绑定，收 CertId）。
 *
 * region 维度：ELB 证书隶属资源池（regionID），不同 region 是独立标识空间，故 storeKind 编入 region
 * （ctcccloud_elb:{region}）以隔离去重键；uploader 据 config.region 构造（与 AliyunSlbUploader 同模式）。
 *
 * 与 certimate 对齐的取舍：certimate 上传前先 ListCertificates 按内容查重复用；本类省略 —— RemoteCertStore 已
 * 按 (access_id, store_kind, fingerprint) 去重。clientToken 用 32 字符随机串（对齐 certimate security.RandomString）
 * 作幂等令牌。
 */
class CtcccloudElbUploader implements CertUploaderInterface
{
    /**
     * @param  Closure(array<string,mixed>):object  $clientFactory  返回 CtcccloudRestClient（绑定 elb endpoint）
     * @param  string  $region  天翼云资源池 ID（regionID）
     */
    public function __construct(
        private readonly Closure $clientFactory,
        private readonly string $region,
    ) {}

    public function storeKind(): string
    {
        return 'ctcccloud_elb:'.$this->region;
    }

    /**
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @return string remote_cert_id = ELB 证书 ID（CertId）
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        $certName = 'clouddeploy-'.(int) (microtime(true) * 1000);

        try {
            /** @var CtcccloudRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $result = $client->post('/v4/elb/create-certificate', [
                'clientToken' => bin2hex(random_bytes(16)),
                'regionID' => $this->region,
                'name' => $certName,
                'description' => 'upload from cloud-deploy',
                'type' => 'Server',
                'certificate' => $fullChain,
                'privateKey' => trim($keyPem),
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(CtcccloudErrorSanitizer::sanitize($e), 0);
        }

        // 响应 returnObj.id（注意 ELB 用 lowerCamel "id"，模型字段为 ID）。
        $returnObj = is_array($result['returnObj'] ?? null) ? $result['returnObj'] : [];
        $certId = $returnObj['id'] ?? null;
        if (! is_string($certId) || $certId === '') {
            throw new RuntimeException('天翼云 ELB 证书创建未返回 id');
        }

        return $certId;
    }
}
