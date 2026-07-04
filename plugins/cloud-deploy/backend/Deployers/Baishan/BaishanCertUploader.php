<?php

namespace Plugins\CloudDeploy\Deployers\Baishan;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 白山云证书上传器（storeKind=baishan）。
 *
 * 对齐 certimate certmgr baishan-cdn 的 Upload：调白山云开放 API
 *   POST /v2/domain/certificate {name, certificate, key} → data.cert_id
 * 返回 cert_id 作为 remote_cert_id —— cdn deployer bind 时把它设到域名 https 配置。
 *
 * 「证书已存在」幂等（对齐 certimate）：白山云对重复证书返回 code=400699 且 message 含
 * "this certificate is exists"，此时从 message 里正则提取已存在证书的 cert_id 复用（白山云不允许重复上传同内容证书）。
 *
 * 与 certimate 对齐的取舍：证书 + 中间证书拼完整链上传（certimate 单 certPEM ≈ 此处 cert+chain）。
 *
 * SDK client（BaishanRestClient）经注入缝 $clientFactory（deployer 的 makeClient('cdn', …)）构造 ——
 * 测试 override deployer::makeClient 即自动作用于此处，无需单独 mock 上传器。
 */
class BaishanCertUploader implements CertUploaderInterface
{
    /** @param Closure(array<string,mixed>):object $clientFactory 返回 BaishanRestClient（或测试 mock，需有 post() 方法） */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'baishan';
    }

    /**
     * @param  array{api_token:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 证书 + 中间证书拼成完整链上传。
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        // 白山云证书命名：毫秒时间戳保唯一（对齐 certimate certimate_{ms}）。
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        try {
            /** @var BaishanRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $result = $client->post('/v2/domain/certificate', [
                'name' => $certName,
                'certificate' => $fullChain,
                'key' => trim($keyPem),
            ]);
        } catch (Throwable $e) {
            // 「证书已存在」幂等：白山云 code=400699 + "this certificate is exists" → 从 message 提取已有 cert_id 复用。
            if ($e instanceof BaishanApiException && $e->getErrorCode() === '400699'
                && str_contains($e->getErrorMessage(), 'this certificate is exists')) {
                if (preg_match('/\d+/', $e->getErrorMessage(), $m) === 1) {
                    return $m[0];
                }
            }

            throw new RuntimeException(BaishanErrorSanitizer::sanitize($e), 0);
        }

        // 白山云 UploadDomainCertificate 响应：{code, message, data:{cert_id}}。cert_id 为数字（json.Number）。
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $certId = $data['cert_id'] ?? null;
        if ((! is_int($certId) && ! is_string($certId)) || (string) $certId === '') {
            throw new RuntimeException('白山云 UploadDomainCertificate 未返回 cert_id');
        }

        return (string) $certId;
    }
}
