<?php

namespace Plugins\CloudDeploy\Deployers\Oraclecloud;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;

/**
 * Oracle Cloud Certificates Management REST 薄客户端（OCI 签名）。
 *
 * 无轻量官方 PHP SDK，照 certimate oraclecloud-certificatesmgmt 用 GuzzleHttp（来自主系统 vendor）直调
 * REST。鉴权：OCI HTTP Signatures（RSA-SHA256），由 OciRequestSigner 计算并注入 Authorization + 签名头。
 * host：certificatesmanagement.{region}.oci.oraclecloud.com。API 版本路径前缀 /20210224。
 *
 * 仅实现「导入证书（CreateCertificate by importing config）」：
 *   POST /20210224/certificates
 *   body: {compartmentId, name, certificateConfig: {configType:"IMPORTED", certificatePem, certChainPem,
 *          privateKeyPem}, description}
 * 返回证书 OCID（响应体 id）。
 *
 * HTTP 非 2xx 归一为 OraclecloudApiException（响应体 code + message，不含私钥/签名）。
 * 此类是 deployer 的 makeClient('api', …) 唯一产物 —— 测试 override makeClient 返回 mock 即覆盖 REST。
 */
class OraclecloudClient
{
    private const API_PREFIX = '/20210224';

    private readonly ClientInterface $http;

    private readonly string $host;

    /**
     * @param  OciRequestSigner  $signer  OCI 请求签名器
     * @param  string  $region  OCI 区域（如 ap-tokyo-1）
     * @param  ClientInterface|null  $http  注入缝（测试用）；缺省按 host 构造 Guzzle
     */
    public function __construct(
        private readonly OciRequestSigner $signer,
        string $region,
        ?ClientInterface $http = null,
    ) {
        $this->host = 'certificatesmanagement.'.$region.'.oci.oraclecloud.com';
        $this->http = $http ?? new GuzzleClient([
            'base_uri' => 'https://'.$this->host,
            'timeout' => 30,
        ]);
    }

    /**
     * 导入证书，返回证书 OCID。
     * REF: https://docs.oracle.com/en-us/iaas/api/#/en/certificatesmgmt/20210224/Certificate/CreateCertificate
     *
     * @return string 证书 OCID
     */
    public function createImportedCertificate(string $compartmentId, string $name, string $serverCertPem, string $chainPem, string $keyPem, string $description = 'upload from cloud-deploy'): string
    {
        $body = [
            'compartmentId' => $compartmentId,
            'name' => $name,
            'certificateConfig' => array_filter([
                'configType' => 'IMPORTED',
                'certificatePem' => $serverCertPem,
                'certChainPem' => $chainPem !== '' ? $chainPem : null,
                'privateKeyPem' => $keyPem,
            ], fn ($v) => $v !== null),
            'description' => $description,
        ];

        $json = $this->request('POST', self::API_PREFIX.'/certificates', $body);
        $id = $json['id'] ?? null;

        return is_string($id) ? $id : '';
    }

    /**
     * 发起请求并归一错误。OCI 签名注入 Authorization + 签名头；http_errors=false 自行判状态。
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $body): array
    {
        $bodyJson = (string) json_encode($body);
        $signedHeaders = $this->signer->sign($method, $this->host, $path, $bodyJson);

        $resp = $this->http->request($method, $path, [
            'headers' => $signedHeaders + ['Accept' => 'application/json'],
            'body' => $bodyJson,
            'http_errors' => false,
        ]);

        $status = $resp->getStatusCode();
        $json = json_decode((string) $resp->getBody(), true);
        $json = is_array($json) ? $json : [];

        if ($status >= 200 && $status < 300) {
            return $json;
        }

        // OCI 错误体：{code, message}
        $code = isset($json['code']) && (string) $json['code'] !== ''
            ? (string) $json['code']
            : ($status > 0 ? (string) $status : 'OracleCloudError');
        $message = is_string($json['message'] ?? null) && $json['message'] !== ''
            ? $json['message']
            : "Oracle Cloud 接口返回 HTTP $status";

        throw new OraclecloudApiException($code, $message);
    }
}
