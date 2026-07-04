<?php

namespace Plugins\CloudDeploy\Deployers\Ctcccloud;

use Closure;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use RuntimeException;
use Throwable;

/**
 * 天翼云「CDN 系」证书创建上传器（ao / cdn / icdn / lvdn 共用，每家独立 storeKind）。
 *
 * 这四家（AccessOne / CDN / 国际 CDN / 直播 LVDN）各自有独立的证书空间，但 create-cert 接口形状一致：
 *   POST {createPath} {name, certs=完整链, key=私钥} → returnObj.id
 * 绑定时都用 **CertName**（域名配置 ModifyDomainConfig/UpdateDomain 收 cert_name），故本上传器返回 **certName**
 * 作为 remote_cert_id（各 deployer bind 直接拿来当 cert_name 用）。
 *
 * 与 certimate 对齐的取舍：certimate certmgr 上传前先分页 QueryCertList/ListCerts 按 CN/SAN/有效期/内容查重复用；
 * 本类省略该查重 —— 插件 RemoteCertStore 已按 (access_id, store_kind, fingerprint) 去重，上传器只管创建。边界：
 * RemoteCertStore 未命中但云端已存在同证书时会多创建一张，无害（不影响绑定正确性）。
 *
 * storeKind 各家独立（ctcccloud_ao / ctcccloud_cdn / ctcccloud_icdn / ctcccloud_lvdn），均 region-less：天翼云
 * 这四家证书服务都是全局的（与 certimate Certmgr 不带 RegionId 一致），不同服务的证书空间互不相通，故 storeKind
 * 必须分家以隔离去重键。
 *
 * SDK client（CtcccloudRestClient）经注入缝 $clientFactory（deployer 的 makeClient(...)）构造 —— 测试 override
 * deployer::makeClient 即自动作用于此处，无需单独 mock 上传器。
 */
class CtcccloudCertCreateUploader implements CertUploaderInterface
{
    /**
     * @param  Closure(array<string,mixed>):object  $clientFactory  返回 CtcccloudRestClient（或测试 mock，需有 post() 方法）
     * @param  string  $storeKind  去重隔离键（ctcccloud_ao / ctcccloud_cdn / ctcccloud_icdn / ctcccloud_lvdn）
     * @param  string  $createPath  create-cert 接口路径（各家不同，见各 SDK api_create_cert.go）
     */
    public function __construct(
        private readonly Closure $clientFactory,
        private readonly string $storeKind,
        private readonly string $createPath,
    ) {}

    public function storeKind(): string
    {
        return $this->storeKind;
    }

    /**
     * @param  array{access_key_id:string,secret_access_key:string}  $credentials
     * @return string remote_cert_id = 天翼云证书名（CertName），供域名配置的 cert_name 使用
     */
    public function upload(string $certPem, string $keyPem, string $chainPem, array $credentials): string
    {
        // 证书本体 + 中间证书拼完整链（与 Qiniu/Ksyun 上传器一致；certimate 的单 certPEM ≈ 此处 cert+chain）。
        $fullChain = rtrim($certPem)."\n".trim($chainPem);
        // 天翼云证书命名规则：字母/数字/连字符；用毫秒时间戳保唯一（对齐 certimate certimate-{ms}）。
        $certName = 'clouddeploy-'.(int) (microtime(true) * 1000);

        try {
            /** @var CtcccloudRestClient $client */
            $client = ($this->clientFactory)($credentials);
            $result = $client->post($this->createPath, [
                'name' => $certName,
                'certs' => $fullChain,
                'key' => trim($keyPem),
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(CtcccloudErrorSanitizer::sanitize($e), 0);
        }

        // 响应 returnObj.id 仅作存在性校验（绑定用 certName，不用 id）。
        $returnObj = is_array($result['returnObj'] ?? null) ? $result['returnObj'] : [];
        if (! array_key_exists('id', $returnObj)) {
            throw new RuntimeException('天翼云证书创建未返回 id');
        }

        return $certName;
    }
}
