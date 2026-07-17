<?php

namespace Plugins\CloudDeploy\Deployers\Dogecloud;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 多吉云 CDN 证书上传器（storeKind=dogecloud）。
 *
 * 对齐 certimate certmgr dogecloud 的 Upload：调多吉云开放 API
 *   POST /cdn/cert/upload.json {note, cert, private} → data.id（int64）
 * 返回 id（转字符串）作为 remote_cert_id —— cdn deployer bind 时把它转回 int64 调 BindCdnCert。
 *
 * 与 certimate 对齐的取舍：certimate 直接上传，无查重；本类同样只管上传（插件 RemoteCertStore 已按
 * (access_id, store_kind, fingerprint) 去重）。证书 + 中间证书拼完整链上传（与其他上传器一致；certimate
 * 的单 certPEM ≈ 此处 cert+chain）。
 *
 * SDK client（DogecloudRestClient）经注入缝 $clientFactory（deployer 的 makeClient('cdn', …)）构造 ——
 * 测试 override deployer::makeClient 即自动作用于此处，无需单独 mock 上传器。
 */
class DogecloudSslUploader implements CertUploaderInterface
{
    /** @param Closure(array<string,mixed>):object $clientFactory 返回 DogecloudRestClient（或测试 mock，需有 post() 方法） */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'dogecloud';
    }

    /**
     * @param  array{access_key:string,secret_key:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 证书 + 中间证书拼成完整链上传。
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        // 多吉云证书命名（note）：毫秒时间戳保唯一（对齐 certimate certimate-{ms}）。
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        try {
            /** @var DogecloudRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $result = $client->post('/cdn/cert/upload.json', [
                'note' => $certName,
                'cert' => $fullChain,
                'private' => trim($keyPem),
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(DogecloudErrorSanitizer::sanitize($e), 0);
        }

        // 多吉云 UploadCdnCert 响应：{code, msg, data:{id}}。id 为 int64。
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $id = $data['id'] ?? null;
        if (! is_int($id) && ! (is_string($id) && $id !== '' && ctype_digit($id))) {
            throw new RuntimeException('多吉云 UploadCdnCert 未返回证书 id');
        }

        return (string) $id;
    }
}
