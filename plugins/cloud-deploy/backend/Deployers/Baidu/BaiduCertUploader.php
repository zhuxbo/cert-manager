<?php

namespace Plugins\CloudDeploy\Deployers\Baidu;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 百度智能云证书中心（SSL 证书服务）上传器（storeKind=baidu_cert）。
 *
 * 对齐 certimate baiducloud-cert 的 Upload：调 BCE 证书中心 REST
 *   POST /v1/certificate  {certName, certServerData, certPrivateData}  →  {certId, certName}
 * 返回 certId 作为 remote_cert_id —— blb/appblb bind 时把 certId 塞进监听器 CertIds。
 *
 * 与 certimate 对齐的取舍：
 * - certServerData 传**证书本体**（certPEM），certPrivateData 传私钥（与 certimate CreateCertArgs 一致，
 *   certimate 不把中间证书拼进 certServerData，也不传 certLinkData）。中间证书（chain）由百度证书中心据
 *   服务端证书自动补链；本上传器同 certimate **不**外发 chain，保持线协议一致。
 * - certimate 上传前先 ListCertDetail 按 CN/SAN/有效期/内容查重复用；本类省略该查重——插件的 RemoteCertStore
 *   已按 (access_id, store_kind, fingerprint) 去重，上传器只管上传。边界：RemoteCertStore 未命中但云端已存在时
 *   会多传一张证书，无害（不影响绑定正确性）。
 *
 * SDK client（BaiduRestClient）经注入缝 $clientFactory（deployer 的 makeClient('cert', …)）构造 ——
 * 测试 override deployer::makeClient 即自动作用于此处，无需单独 mock 上传器。
 */
class BaiduCertUploader implements CertUploaderInterface
{
    /** @param Closure(array<string,mixed>):object $clientFactory 返回 BaiduRestClient（或测试 mock，需有 request() 方法） */
    public function __construct(private readonly Closure $clientFactory) {}

    public function storeKind(): string
    {
        return 'baidu_cert';
    }

    /**
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 百度证书命名规则：用毫秒时间戳保唯一（对齐 certimate certimate-{ms}）。
        $certName = 'clouddeploy_'.(int) (microtime(true) * 1000);

        try {
            $client = ($this->clientFactory)($credentials);
            // POST /v1/certificate 创建证书，返回 {certId, certName}。
            $result = $client->request('POST', '/v1/certificate', [
                'certName' => $certName,
                'certServerData' => trim($certPem),
                'certPrivateData' => trim($keyPem),
            ], []);
        } catch (Throwable $e) {
            throw new RuntimeException(BaiduErrorSanitizer::sanitize($e), 0);
        }

        $certId = $result->certId ?? null;
        if (! is_string($certId) || $certId === '') {
            throw new RuntimeException('百度智能云证书中心 CreateCert 未返回 certId');
        }

        return $certId;
    }
}
