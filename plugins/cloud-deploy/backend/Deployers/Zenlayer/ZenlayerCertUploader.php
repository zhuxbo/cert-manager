<?php

namespace Plugins\CloudDeploy\Deployers\Zenlayer;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * Zenlayer 证书上传器（storeKind 按服务区分：zenlayer_cdn / zenlayer_zga）。
 *
 * 对齐 certimate certmgr zenlayer-cdn / zenlayer-ga 的 Upload：调对应服务的 CreateCertificate
 *   CreateCertificate {certificateLabel, certificateContent, certificateKey, resourceGroupId} → response.certificateId
 * 返回 certificateId 作为 remote_cert_id —— deployer bind 时把它绑到域名/加速器。
 *
 * storeKind 按服务隔离（关键）：cdn 与 zga 是**两套独立的证书库**（同一证书在两个服务里 certificateId 不通用），
 * 故 storeKind 编入服务名（zenlayer_cdn / zenlayer_zga），由各 deployer 构造时传入。
 *
 * resourceGroupId：从凭证级可选项透传（certimate AccessConfig 的 ResourceGroupId）。
 *
 * 与 certimate 对齐的取舍：certimate 上传前先 DescribeCertificates 按 CN/SAN/有效期/指纹/算法查重复用；本类省略该
 * 查重——插件 RemoteCertStore 已按 (access_id, store_kind, fingerprint) 去重。certificateContent 传证书 + 中间证书
 * 完整链（certimate 单 certPEM ≈ 此处 cert+chain）。
 *
 * SDK client（ZenlayerRestClient）经注入缝 $clientFactory（deployer 的 makeClient(service, …)）构造 ——
 * 测试 override deployer::makeClient 即自动作用于此处，无需单独 mock 上传器。
 */
class ZenlayerCertUploader implements CertUploaderInterface
{
    /**
     * @param  Closure(array<string,mixed>):object  $clientFactory  返回 ZenlayerRestClient（绑定对应服务）
     * @param  string  $storeKind  去重隔离键（zenlayer_cdn / zenlayer_zga）
     */
    public function __construct(
        private readonly Closure $clientFactory,
        private readonly string $storeKind,
    ) {}

    public function storeKind(): string
    {
        return $this->storeKind;
    }

    /**
     * @param  array{access_key_id:string,access_key_password:string,resource_group_id?:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 证书 + 中间证书拼成完整链上传。
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        // Zenlayer 证书命名（label）：毫秒时间戳保唯一（对齐 certimate certimate_{ms}）。
        $certLabel = 'clouddeploy_'.(int) (microtime(true) * 1000);
        $resourceGroupId = isset($credentials['resource_group_id']) ? (string) $credentials['resource_group_id'] : '';

        $body = [
            'certificateLabel' => $certLabel,
            'certificateContent' => $fullChain,
            'certificateKey' => trim($keyPem),
        ];
        if ($resourceGroupId !== '') {
            $body['resourceGroupId'] = $resourceGroupId;
        }

        try {
            /** @var ZenlayerRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $result = $client->call('CreateCertificate', $body);
        } catch (Throwable $e) {
            throw new RuntimeException(ZenlayerErrorSanitizer::sanitize($e), 0);
        }

        // CreateCertificate 响应：response.certificateId（call() 已剥到 response 子对象）。
        $certId = $result['certificateId'] ?? null;
        if (! is_string($certId) || $certId === '') {
            throw new RuntimeException('Zenlayer CreateCertificate 未返回 certificateId');
        }

        return $certId;
    }
}
