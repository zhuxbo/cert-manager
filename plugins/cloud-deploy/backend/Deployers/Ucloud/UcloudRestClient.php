<?php

namespace Plugins\CloudDeploy\Deployers\Ucloud;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

/**
 * 优刻得（UCloud）REST 薄客户端（USSL / ULB / UCDN / UEWAF / PathX / UFile）。
 *
 * UCloud 没有官方模块化 PHP SDK（与 certimate 用的 ucloud-sdk-go 不同）。本类照 ucloud-sdk-go
 * 的「公钥/私钥签名」手写：所有动作走单一 endpoint `https://api.ucloud.cn`，HTTP POST，
 * body 为 form-urlencoded 的扁平参数表（含 Action / PublicKey / 业务参数 / Signature）。
 * 用主系统 GuzzleHttp（来自共享 vendor），不依赖任何官方云 SDK。
 *
 * 签名算法（逐字节对齐 ucloud-sdk-go `ucloud/auth/signature.go`）：
 *   1. 把请求参数拍扁成 string=>string 的 map（数组按 `Key.0`/`Key.1` 展开，与官方 FormEncoder 一致）。
 *   2. 加入公共参数 PublicKey（PrivateKey 不入参，仅用于拼签名尾部）。
 *   3. 按 key 升序排序，依次拼 `key + value`（无分隔符），末尾再拼 PrivateKey。
 *   4. 取该串的 SHA1，hex 小写，作为 `Signature` 参数。
 *   官方测试向量（docs.ucloud.cn/api/summary/signature）：
 *     {Action:DescribeUHostInstance, Region:cn-bj2, Limit:10, PublicKey:...} + PrivateKey
 *     → cba5cf5ec4d4233d206b1b54951e3787350a642f（UcloudRestClientSignatureTest 钉死）。
 *
 * 业务方法逐一对齐 certimate pkg/sdk3rd/ucloud 与官方 ucloud-sdk-go 的 InvokeAction 参数，
 * 供 deployer/uploader 调用。本类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override
 * makeClient 返回 mock 即覆盖全部 REST 调用，无需打真实 HTTP。
 *
 * 错误归一：HTTP 非 2xx 或 响应体 RetCode≠0 → 抛 UcloudApiException（携 UCloud 错误码 + Message
 * 描述，均取自响应体、不含凭证）。底层网络异常（GuzzleException）原样冒泡，交 UcloudErrorSanitizer
 * 只暴露类名兜底。
 */
class UcloudRestClient
{
    private const API_ENDPOINT = 'https://api.ucloud.cn';

    private ClientInterface $http;

    /**
     * @param  string  $publicKey  UCloud API 公钥（作为 PublicKey 参数随请求外发）
     * @param  string  $privateKey  UCloud API 私钥（仅参与签名计算，绝不外发）
     * @param  string  $projectId  项目 ID（可选；非空时作为公共参数 ProjectId 随请求外发）
     * @param  string  $region  地域（可选；非空时作为公共参数 Region 随请求外发，ULB/UFile 等区域型服务需要）
     * @param  ClientInterface|null  $http  注入用于测试；生产用默认 Guzzle Client（30s 超时上限）
     */
    public function __construct(
        private readonly string $publicKey,
        private readonly string $privateKey,
        private readonly string $projectId = '',
        private readonly string $region = '',
        ?ClientInterface $http = null,
        private readonly string $endpoint = self::API_ENDPOINT,
    ) {
        // 设 socket 超时上限（默认无限），避免上游慢/挂时 worker 长期阻塞。
        $this->http = $http ?? new Client([
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::TIMEOUT => 30,
            RequestOptions::HTTP_ERRORS => false,
        ]);
    }

    /**
     * 上传托管证书到 USSL 证书服务。
     * REF: certimate ucloud-ussl Upload —— Action=UploadNormalCertificate
     *   {CertificateName, SslPublicKey=base64(cert), SslPrivateKey=base64(key), SslMD5=md5(base64cert+base64key)}
     *   → {CertificateID(int), LongResourceID}
     *
     * @return int 云端证书 CertificateID（0 表示接口未返回）
     */
    public function uploadNormalCertificate(string $certificateName, string $sslPublicKeyB64, string $sslPrivateKeyB64, string $sslMd5, string $sslCaKey = ''): int
    {
        $params = [
            'CertificateName' => $certificateName,
            'SslPublicKey' => $sslPublicKeyB64,
            'SslPrivateKey' => $sslPrivateKeyB64,
            'SslMD5' => $sslMd5,
        ];
        if ($sslCaKey !== '') {
            $params['SslCaKey'] = $sslCaKey;
        }

        $json = $this->invoke('UploadNormalCertificate', $params);
        $certId = $json['CertificateID'] ?? null;

        return is_int($certId) ? $certId : (is_numeric($certId) ? (int) $certId : 0);
    }

    /**
     * 创建 ULB SSL 证书（区域型负载均衡的服务证书空间，与 USSL 不同标识空间）。
     * REF: certimate ucloud-ulb Upload —— Action=CreateSSL
     *   {SSLName, SSLType=Pem, UserCert=服务器证书, CaCert=中间证书, PrivateKey=私钥} → {SSLId}
     *
     * @return string 云端 SSLId（空串表示接口未返回）
     */
    public function createUlbSSL(string $sslName, string $userCert, string $caCert, string $privateKey): string
    {
        $json = $this->invoke('CreateSSL', [
            'SSLName' => $sslName,
            'SSLType' => 'Pem',
            'UserCert' => $userCert,
            'CaCert' => $caCert,
            'PrivateKey' => $privateKey,
        ]);
        $sslId = $json['SSLId'] ?? null;

        return is_string($sslId) ? $sslId : '';
    }

    /**
     * 描述 ULB 监听器（应用型负载均衡 ALB）。
     * REF: certimate ucloud-ualb —— Action=DescribeListeners
     *
     * @return array{Listeners:list<array<string,mixed>>}
     */
    public function describeListeners(string $loadBalancerId, ?string $listenerId = null, int $offset = 0, int $limit = 100): array
    {
        $params = [
            'LoadBalancerId' => $loadBalancerId,
            'Offset' => $offset,
            'Limit' => $limit,
        ];
        if ($listenerId !== null && $listenerId !== '') {
            $params['ListenerId'] = $listenerId;
        }

        $json = $this->invoke('DescribeListeners', $params);
        $listeners = $json['Listeners'] ?? null;

        return ['Listeners' => is_array($listeners) ? array_values(array_filter($listeners, 'is_array')) : []];
    }

    /**
     * 更新 ALB 监听器属性（绑定默认证书）。
     * REF: certimate ucloud-ualb —— Action=UpdateListenerAttribute {Certificates.0=certId}
     *
     * @param  list<string>  $certificates
     */
    public function updateListenerAttribute(string $loadBalancerId, string $listenerId, array $certificates): void
    {
        $this->invoke('UpdateListenerAttribute', [
            'LoadBalancerId' => $loadBalancerId,
            'ListenerId' => $listenerId,
            'Certificates' => array_values($certificates),
        ]);
    }

    /**
     * 新增 ALB 监听器扩展证书绑定（SNI）。
     * REF: certimate ucloud-ualb —— Action=AddSSLBinding {SSLIds.0=certId}
     *
     * @param  list<string>  $sslIds
     */
    public function addSSLBinding(string $loadBalancerId, string $listenerId, array $sslIds): void
    {
        $this->invoke('AddSSLBinding', [
            'LoadBalancerId' => $loadBalancerId,
            'ListenerId' => $listenerId,
            'SSLIds' => array_values($sslIds),
        ]);
    }

    /**
     * 查询 ULB SSL 证书详情，用于 UALB SNI 替换时识别同域名或已过期的旧扩展证书。
     * REF: certimate ucloud-ualb —— Action=DescribeSSLV2
     *
     * @return array{DataSet:list<array<string,mixed>>}
     */
    public function describeSSLV2(string $sslId): array
    {
        $json = $this->invoke('DescribeSSLV2', [
            'SSLId' => $sslId,
            'Limit' => 1,
        ]);
        $dataSet = $json['DataSet'] ?? null;

        return ['DataSet' => is_array($dataSet) ? array_values(array_filter($dataSet, 'is_array')) : []];
    }

    /**
     * 解绑 UALB 监听器的扩展 SSL 证书。
     * REF: certimate ucloud-ualb —— Action=DeleteSSLBinding
     *
     * @param  list<string>  $sslIds
     */
    public function deleteSSLBinding(string $loadBalancerId, string $listenerId, array $sslIds): void
    {
        $this->invoke('DeleteSSLBinding', [
            'LoadBalancerId' => $loadBalancerId,
            'ListenerId' => $listenerId,
            'SSLIds' => array_values($sslIds),
        ]);
    }

    /**
     * 描述 ULB VServer（传统型负载均衡 CLB）。
     * REF: certimate ucloud-uclb —— Action=DescribeVServer
     *
     * @return array{DataSet:list<array<string,mixed>>}
     */
    public function describeVServer(string $ulbId, ?string $vserverId = null, int $offset = 0, int $limit = 100): array
    {
        $params = [
            'ULBId' => $ulbId,
            'Offset' => $offset,
            'Limit' => $limit,
        ];
        if ($vserverId !== null && $vserverId !== '') {
            $params['VServerId'] = $vserverId;
        }

        $json = $this->invoke('DescribeVServer', $params);
        $dataSet = $json['DataSet'] ?? null;

        return ['DataSet' => is_array($dataSet) ? array_values(array_filter($dataSet, 'is_array')) : []];
    }

    /**
     * VServer 解绑 SSL 证书（CLB 单证书，绑新前须先解旧）。
     * REF: certimate ucloud-uclb —— Action=UnbindSSL
     */
    public function unbindSSL(string $ulbId, string $vserverId, string $sslId): void
    {
        $this->invoke('UnbindSSL', [
            'ULBId' => $ulbId,
            'VServerId' => $vserverId,
            'SSLId' => $sslId,
        ]);
    }

    /**
     * VServer 绑定 SSL 证书。
     * REF: certimate ucloud-uclb —— Action=BindSSL
     */
    public function bindSSL(string $ulbId, string $vserverId, string $sslId): void
    {
        $this->invoke('BindSSL', [
            'ULBId' => $ulbId,
            'VServerId' => $vserverId,
            'SSLId' => $sslId,
        ]);
    }

    /**
     * 获取 UCDN 加速域名配置（含 HTTPS 状态）。
     * REF: certimate ucloud-ucdn —— Action=GetUcdnDomainConfig {DomainId.0=domainId}
     *
     * @return array{DomainList:list<array<string,mixed>>}
     */
    public function getUcdnDomainConfig(string $domainId): array
    {
        $json = $this->invoke('GetUcdnDomainConfig', [
            'DomainId' => [$domainId],
        ]);
        $domainList = $json['DomainList'] ?? null;

        return ['DomainList' => is_array($domainList) ? array_values(array_filter($domainList, 'is_array')) : []];
    }

    /**
     * 更新 UCDN 域名 HTTPS 配置（绑定 USSL 证书）。
     * REF: certimate ucloud-ucdn —— Action=UpdateUcdnDomainHttpsConfigV2
     *   {DomainId, HttpsStatusCn, HttpsStatusAbroad, CertId(int), CertName, CertType=ussl}
     */
    public function updateUcdnDomainHttpsConfigV2(string $domainId, string $httpsStatusCn, string $httpsStatusAbroad, int $certId, string $certName): void
    {
        $this->invoke('UpdateUcdnDomainHttpsConfigV2', [
            'DomainId' => $domainId,
            'HttpsStatusCn' => $httpsStatusCn,
            'HttpsStatusAbroad' => $httpsStatusAbroad,
            'CertId' => $certId,
            'CertName' => $certName,
            'CertType' => 'ussl',
        ]);
    }

    /**
     * UEWAF 添加域名 SSL 证书（内联：证书 base64 直接随请求外发，不经 USSL 证书服务）。
     * REF: certimate ucloud-uewaf —— Action=AddWafDomainCertificateInfo
     *   {Domain, CertificateName, SslPublicKey=base64(cert), SslPrivateKey=base64(key), SslMD=md5, SslKeyLess=off}
     */
    public function addWafDomainCertificateInfo(string $domain, string $certificateName, string $sslPublicKeyB64, string $sslPrivateKeyB64, string $sslMd): void
    {
        $this->invoke('AddWafDomainCertificateInfo', [
            'Domain' => $domain,
            'CertificateName' => $certificateName,
            'SslPublicKey' => $sslPublicKeyB64,
            'SslPrivateKey' => $sslPrivateKeyB64,
            'SslMD' => $sslMd,
            'SslKeyLess' => 'off',
        ]);
    }

    /**
     * PathX 全球加速绑定 SSL 证书。
     * REF: certimate ucloud-upathx —— Action=BindPathXSSL {UGAId, Port.0=port, SSLId}
     *
     * @param  list<int>  $ports
     */
    public function bindPathXSSL(string $ugaId, array $ports, string $sslId): void
    {
        $this->invoke('BindPathXSSL', [
            'UGAId' => $ugaId,
            'Port' => array_values($ports),
            'SSLId' => $sslId,
        ]);
    }

    /**
     * 获取项目列表（PathX 必传 ProjectId，未配置时取默认项目）。
     * REF: certimate ucloud-upathx getSDKDefaultProjectId —— Action=GetProjectList
     *
     * @return list<array{ProjectId:string,IsDefault:bool}>
     */
    public function getProjectList(): array
    {
        $json = $this->invoke('GetProjectList', []);
        $set = $json['ProjectSet'] ?? null;
        if (! is_array($set)) {
            return [];
        }

        $result = [];
        foreach ($set as $item) {
            if (! is_array($item)) {
                continue;
            }
            $result[] = [
                'ProjectId' => is_string($item['ProjectId'] ?? null) ? $item['ProjectId'] : '',
                'IsDefault' => (bool) ($item['IsDefault'] ?? false),
            ];
        }

        return $result;
    }

    /**
     * US3（UFile）对象存储添加自定义域名 SSL 证书。
     * REF: certimate ucloud-us3 —— Action=AddUFileSSLCert {BucketName, Domain, CertificateName, USSLId}
     */
    public function addUFileSSLCert(string $bucketName, string $domain, string $certificateName, string $usslId): void
    {
        $this->invoke('AddUFileSSLCert', [
            'BucketName' => $bucketName,
            'Domain' => $domain,
            'CertificateName' => $certificateName,
            'USSLId' => $usslId,
        ]);
    }

    /**
     * 发起一次带签名的 UCloud API 请求并返回解析后的响应体（数组）。
     *
     * @param  array<string,mixed>  $params  业务参数（值可为标量或标量数组；数组按 Key.N 展开）
     * @return array<string,mixed>
     */
    private function invoke(string $action, array $params): array
    {
        $payload = $this->buildSignedPayload($action, $params);

        $response = $this->http->request('POST', $this->endpoint, [
            RequestOptions::FORM_PARAMS => $payload,
            RequestOptions::HTTP_ERRORS => false,
        ]);

        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        $json = is_array($decoded) ? $decoded : [];

        return $this->ensureOk($action, $status, $json);
    }

    /**
     * 组装含签名的扁平参数表：拍扁 → 加 PublicKey/Region/ProjectId → 计算 Signature。
     *
     * @param  array<string,mixed>  $params
     * @return array<string,string>
     */
    private function buildSignedPayload(string $action, array $params): array
    {
        $flat = $this->flatten([
            'Action' => $action,
        ] + $params);

        // 公共参数（与官方 SDK Config/Credential.Apply 对齐）：非空才入参（空值 UCloud 视为缺省）。
        $flat['PublicKey'] = $this->publicKey;
        if ($this->region !== '') {
            $flat['Region'] = $this->region;
        }
        if ($this->projectId !== '') {
            $flat['ProjectId'] = $this->projectId;
        }

        $flat['Signature'] = $this->sign($flat);

        return $flat;
    }

    /**
     * 把参数拍扁为 string=>string：标量直接 stringify，标量数组展开为 `Key.0`/`Key.1`…
     * （与官方 ucloud-sdk-go FormEncoder.encodeMapToForm 一致：数组用 `.N` 下标）。
     * 空字符串值会被丢弃（官方 encodeOne 对空串 `if s != ""` 同样不写入）。
     *
     * @param  array<string,mixed>  $params
     * @return array<string,string>
     */
    private function flatten(array $params): array
    {
        $flat = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                foreach (array_values($value) as $i => $item) {
                    $s = $this->stringifyScalar($item);
                    if ($s !== '') {
                        $flat["$key.$i"] = $s;
                    }
                }

                continue;
            }

            $s = $this->stringifyScalar($value);
            if ($s !== '') {
                $flat[$key] = $s;
            }
        }

        return $flat;
    }

    /** 标量 → 字符串（bool→true/false、int/float 用十进制、与官方 simple2String 一致）。 */
    private function stringifyScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            // 与 Go strconv.Format* 一致：整数无小数、浮点去尾零（PHP 默认 string cast 已满足常见场景）
            return (string) $value;
        }

        return is_string($value) ? $value : '';
    }

    /**
     * UCloud 签名：按 key 升序拼 `key+value`（无分隔符），末尾拼 PrivateKey，取 SHA1 hex。
     * 逐字节对齐 ucloud-sdk-go ucloud/auth/signature.go 的 sign()/map2String()。
     *
     * @param  array<string,string>  $flat  已扁平化、已含 PublicKey 的参数（不含 Signature）
     */
    private function sign(array $flat): string
    {
        ksort($flat, SORT_STRING);

        $buf = '';
        foreach ($flat as $key => $value) {
            $buf .= $key.$value;
        }
        $buf .= $this->privateKey;

        return sha1($buf);
    }

    /**
     * HTTP 非 2xx 或 响应体 RetCode≠0 → 抛 UcloudApiException（仅响应体来源的 code + Message，无凭证）。
     *
     * @param  array<string,mixed>  $json
     * @return array<string,mixed>
     */
    private function ensureOk(string $action, int $status, array $json): array
    {
        $retCode = $json['RetCode'] ?? null;

        // UCloud 标准：RetCode=0 即成功（即便 HTTP 2xx，业务失败也靠 RetCode 体现）。
        if (is_int($retCode) && $retCode === 0) {
            return $json;
        }

        $message = is_string($json['Message'] ?? null) && $json['Message'] !== ''
            ? $json['Message']
            : "优刻得接口 $action 返回错误";

        // 走到这里 retCode 必非 0（=0 已在上方提前返回）：是 int 即业务错误码。
        if (is_int($retCode)) {
            throw new UcloudApiException((string) $retCode, $message);
        }

        // 无 RetCode 字段（异常响应/网关错误）：用 HTTP 状态码兜底
        if ($status < 200 || $status >= 300) {
            $message = is_string($json['Message'] ?? null) && $json['Message'] !== ''
                ? $json['Message']
                : "优刻得接口 $action 返回 HTTP $status";

            throw new UcloudApiException($status > 0 ? (string) $status : 'UcloudError', $message);
        }

        // HTTP 2xx 但响应体缺 RetCode：当作错误（避免静默把异常响应当成功）
        throw new UcloudApiException('UcloudError', $message);
    }
}
