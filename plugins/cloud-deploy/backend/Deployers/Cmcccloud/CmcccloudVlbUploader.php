<?php

namespace Plugins\CloudDeploy\Deployers\Cmcccloud;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 移动云弹性负载均衡 VLB 证书上传器（storeKind=cmcccloud_vlb:{poolId}:{type}，poolId + 证书类型维度隔离）。
 *
 * 对齐 certimate certmgr cmcccloud-vlb 的 Upload：调 eCloud VLB
 *   POST /api/openapi-vlb/lb-console/acl/v3/certification
 *     {name, type(SNI|SERVER), description, publicKey=证书完整链, privateKey} → body=certId（字符串）
 * 返回 certId 作为 remote_cert_id —— vlb deployer bind 时把它设到监听器（默认证书或 SNI 证书）。
 *
 * 维度隔离（关键）：
 *   - poolId：VLB 证书按资源池隔离（不同 poolId 的 certId 不通用）。
 *   - type（SNI/SERVER）：certimate 据「是否指定 SNI 域名」决定上传 SNI 证书还是默认（SERVER）证书，
 *     两类证书不可混用同 fingerprint 去重，故一并编入 storeKind。
 *
 * 与 certimate 对齐的取舍：certimate 上传前先 ListLoadbalanceCertification 按有效期/内容查重复用；本类省略该
 * 查重——插件 RemoteCertStore 已按 (access_id, store_kind, fingerprint) 去重。publicKey 传证书 + 中间证书完整链
 * （certimate 单 certPEM ≈ 此处 cert+chain）。
 *
 * SDK client（CmcccloudRestClient）经注入缝 $clientFactory（deployer 的 makeClient('vlb', …, poolId)）构造 ——
 * 测试 override deployer::makeClient 即自动作用于此处，无需单独 mock 上传器。
 */
class CmcccloudVlbUploader implements CertUploaderInterface
{
    private const GW_CREATE_CERTIFICATION = '/api/openapi-vlb/lb-console/acl/v3/certification';

    /**
     * @param  Closure(array<string,mixed>):object  $clientFactory  返回 CmcccloudRestClient（绑定 poolId）
     * @param  string  $poolId  资源池 ID（编入 storeKind 隔离跨资源池标识空间）
     * @param  bool  $isSni  是否上传 SNI 证书（true → type=SNI；false → type=SERVER）
     */
    public function __construct(
        private readonly Closure $clientFactory,
        private readonly string $poolId,
        private readonly bool $isSni,
    ) {}

    public function storeKind(): string
    {
        return 'cmcccloud_vlb:'.$this->poolId.':'.($this->isSni ? 'sni' : 'server');
    }

    /**
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 证书 + 中间证书拼成完整链上传。
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        // 移动云证书命名：毫秒时间戳保唯一（对齐 certimate certimate_{ms}）。
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        try {
            /** @var CmcccloudRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $result = $client->call('POST', self::GW_CREATE_CERTIFICATION, [], [], [
                'name' => $certName,
                'type' => $this->isSni ? 'SNI' : 'SERVER',
                'description' => 'upload from cloud-deploy',
                'publicKey' => $fullChain,
                'privateKey' => trim($keyPem),
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(CmcccloudErrorSanitizer::sanitize($e), 0);
        }

        // eCloud CreateLoadbalanceCertification 响应：{state, body=certId（字符串）}。
        $certId = $result['body'] ?? null;
        if (! is_string($certId) || $certId === '') {
            throw new RuntimeException('移动云 CreateLoadbalanceCertification 未返回证书 id');
        }

        return $certId;
    }
}
