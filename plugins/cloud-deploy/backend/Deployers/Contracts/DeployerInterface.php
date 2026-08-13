<?php

namespace Plugins\CloudDeploy\Deployers\Contracts;

/**
 * 每个 (provider, product) 一个 deployer。负责把证书绑定到具体云资源。
 *
 * 上传职责已剥离到 CertUploaderInterface（删除了旧 uploadCert）：
 * - usesRemoteCertStore()=true：先经 certUploader() 上传拿云端 cert id（走 RemoteCertStore 去重），再 bind(id)。
 * - usesRemoteCertStore()=false：bind 内联直灌 PEM（certUploader() 返回 null）。
 */
interface DeployerInterface
{
    /** provider 标识，如 'aliyun'。 */
    public function provider(): string;

    /** product 标识，如 'cdn'。 */
    public function product(): string;

    /** 展示名，如 '阿里云 CDN'。 */
    public function label(): string;

    /**
     * config 字段 schema，供前端表单渲染 + 后端服务端校验。
     *
     * @return list<array{key:string,label:string,type?:string,required?:bool,default?:mixed,required_when?:array{key:string,equals:mixed},visible_when?:array{key:string,equals:mixed}}>
     */
    public function configSchema(): array;

    /** true: 经 certUploader 上传拿 id；false: bind 内联直灌 PEM。 */
    public function usesRemoteCertStore(): bool;

    /**
     * 该端点的证书上传器（CAS/SLB/腾讯 SSL）；内联端点返回 null。
     *
     * $config 是 target 的部署配置（与 bind 同源）。多数证书服务（CAS 全局、腾讯 SSL）上传时
     * 不需要它；但 region 维度的服务（阿里云 SLB 传统型负载均衡服务证书）必须在上传前知道 region，
     * 故由调用方（CloudDeployJob）在 ensure 前透传 config，让此类 deployer 据其 region 构造上传器
     * （决定上传 endpoint + RegionId，并把 region 编码进 storeKind/remote_cert_id 以隔离跨 region 标识空间）。
     *
     * @param  array<string,mixed>  $config
     */
    public function certUploader(array $config = []): ?CertUploaderInterface;

    /**
     * 绑定证书到资源。
     *
     * @param  string|array{cert:string,key:string,chain:string}|array{remote_cert_id:string,cert:string,chain:string}  $certRef  remote_cert_id、内联 PEM 三元组，或 opt-in 的安全远端材料（cert=leaf，chain=中间链，不含私钥）
     * @param  array<string,mixed>  $credentials
     * @param  array<string,mixed>  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void;
}
