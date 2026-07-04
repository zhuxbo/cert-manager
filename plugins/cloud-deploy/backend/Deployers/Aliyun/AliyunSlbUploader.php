<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Slb\V20140515\Models\UploadServerCertificateRequest;
use AlibabaCloud\SDK\Slb\V20140515\Slb;
use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 阿里云传统型负载均衡（SLB / CLB）服务证书上传器（storeKind="slb:{region}"）。
 *
 * 与 CAS 上传器的本质差异 —— **SLB 服务证书是 region 维度**：
 *   - 上传走 region 化 endpoint（slb.{region}.aliyuncs.com）+ RegionId 入参，
 *     返回的 ServerCertificateId **只在该 region 有效**，跨 region 不可用（区别于 CAS 全局 CertId）。
 *   - 故本上传器构造时即接 region（由 deployer 的 certUploader($config) 从 config.region 注入），
 *     upload 用该 region 上传，bind 时 deployer 用**同一** config.region 调监听接口，二者天然对齐。
 *
 * region 隔离策略（关键，防跨 region 误复用）：
 *   storeKind 返回 **"slb:{region}"** 而非裸 "slb" —— RemoteCertStore 去重键 = (access_id, store_kind, fingerprint)，
 *   把 region 揉进 store_kind 后，同账号同证书部署到 region A 与 region B 各自落一行、各持本 region 的
 *   ServerCertificateId，不会拿 region A 的 cert id 去 bind region B（那个 id 在 B 不存在）。
 *   因 region 已进 store_kind，remote_cert_id 只需返回**裸 ServerCertificateId**，无需再编码 "{id}@{region}"。
 *
 * 重复上传：阿里云 SLB UploadServerCertificate 同 CAS，不对「同内容重复上传」报错而是每次新建一个
 * ServerCertificateId（certimate 才在上传前 DescribeServerCertificates 查重复用）。本类不查重——
 * RemoteCertStore 已按 (access_id, "slb:{region}", fingerprint) 去重；未命中但云端已存在时多传一张无害。
 *
 * SDK 异常脱敏：TeaError extends RuntimeException，故所有 SDK 调用裹一个 try、catch(Throwable) 经
 * AliyunErrorSanitizer 重抛干净异常（绝不 catch(RuntimeException) 透传，否则签名 URI/AK 泄露）；
 * 业务校验（空 id）放 try 外，避免被自己的 catch 二次脱敏。
 */
class AliyunSlbUploader implements CertUploaderInterface
{
    /**
     * @param  Closure(array<string,mixed>):object  $clientFactory  返回 AlibabaCloud\SDK\Slb\V20140515\Slb
     * @param  string  $region  上传目标 region（决定 endpoint + RegionId + storeKind 隔离段）
     */
    public function __construct(private readonly Closure $clientFactory, private readonly string $region) {}

    public function storeKind(): string
    {
        return 'slb:'.$this->region;
    }

    /**
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 证书 + 中间证书拼完整链（ServerCertificate 需含完整链）
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        // 阿里云证书命名规则：字母/数字/下划线；毫秒时间戳保唯一
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        try {
            /** @var Slb $client */
            $client = ($this->clientFactory)($credentials);

            $serverCertId = $client->uploadServerCertificate(new UploadServerCertificateRequest([
                'regionId' => $this->region,
                'serverCertificateName' => $certName,
                'serverCertificate' => $fullChain,
                'privateKey' => $keyPem,
            ]))->body?->serverCertificateId;
        } catch (Throwable $e) {
            throw new RuntimeException(AliyunErrorSanitizer::sanitize($e), 0);
        }

        if (! is_string($serverCertId) || $serverCertId === '') {
            throw new RuntimeException('阿里云 UploadServerCertificate 未返回 ServerCertificateId');
        }

        return $serverCertId;
    }
}
