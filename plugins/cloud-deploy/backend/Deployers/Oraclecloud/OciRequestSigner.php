<?php

namespace Plugins\CloudDeploy\Deployers\Oraclecloud;

/**
 * OCI（Oracle Cloud Infrastructure）API 请求签名器 —— HTTP Signatures（RFC draft-cavage），RSA-SHA256。
 *
 * 对齐 OCI 官方签名规范 / oci-go-sdk 的 RawConfigurationProvider + DefaultRequestSigner：
 *   - keyId = "{tenancyOcid}/{userOcid}/{fingerprint}"（API Key 认证）。
 *   - 签名串（signing string）按 headers 列表逐行拼 "name: value"，行间 "\n" 连接：
 *       * 所有请求都含： (request-target)、date、host
 *       * 含 body 的方法（POST/PUT/PATCH）追加： x-content-sha256、content-type、content-length
 *     (request-target) 的值 = "{method 小写} {path}{?query}"。
 *   - 用 API 私钥对签名串做 RSA-SHA256 签名（PHP openssl_sign），结果 base64。
 *   - Authorization 头：
 *       Signature version="1",keyId="...",algorithm="rsa-sha256",headers="<空格分隔>",signature="<base64>"
 *
 * 仅依赖 PHP 原生 openssl + base64，无外部 SDK。私钥仅本地签名用、绝不外发；Authorization 头里不含私钥
 * 明文（只有签名值）。本签名器产出待加请求头（数组），由 OraclecloudClient 注入到 Guzzle 请求。
 *
 * 设计为可单测（KAT）：给定固定私钥 + 固定 date + 固定 body，输出确定的 Authorization 头。
 */
class OciRequestSigner
{
    /** 签名头集合（无 body 请求）。 */
    private const HEADERS_NO_BODY = ['(request-target)', 'date', 'host'];

    /** 签名头集合（含 body 请求，OCI 要求追加这三个）。 */
    private const HEADERS_WITH_BODY = ['(request-target)', 'date', 'host', 'x-content-sha256', 'content-type', 'content-length'];

    /**
     * @param  string  $tenancyOcid  租户 OCID
     * @param  string  $userOcid  用户 OCID
     * @param  string  $fingerprint  API 公钥指纹
     * @param  string  $privateKey  API 私钥 PEM
     * @param  string  $privateKeyPassphrase  私钥口令（选填）
     */
    private OraclecloudAuthMaterial $authMaterial;

    public function __construct(
        string $tenancyOcid,
        string $userOcid,
        string $fingerprint,
        string $privateKey,
        string $privateKeyPassphrase = '',
    ) {
        $this->authMaterial = OraclecloudAuthMaterial::apiKey(
            $tenancyOcid,
            $userOcid,
            $fingerprint,
            $privateKey,
            $privateKeyPassphrase,
            '',
        );
    }

    public static function fromAuthMaterial(OraclecloudAuthMaterial $authMaterial): self
    {
        $signer = new self('', '', '', '');
        $signer->authMaterial = $authMaterial;

        return $signer;
    }

    public function keyId(): string
    {
        return $this->authMaterial->keyId();
    }

    /**
     * 计算请求签名头。返回需注入到请求的 header 数组（含 Authorization）。
     *
     * @param  string  $method  HTTP 方法（GET/POST/...）
     * @param  string  $host  目标 host（不含 scheme），如 certificatesmanagement.ap-tokyo-1.oci.oraclecloud.com
     * @param  string  $requestTarget  path + ?query（如 "/20210224/certificates?compartmentId=x"）
     * @param  string  $body  请求体（无 body 传 ''）
     * @param  string|null  $date  RFC7231 GMT 日期（测试可注入固定值；缺省取当前 UTC）
     * @return array<string,string>
     */
    public function sign(string $method, string $host, string $requestTarget, string $body = '', ?string $date = null): array
    {
        $method = strtolower($method);
        $date ??= gmdate('D, d M Y H:i:s').' GMT';
        $hasBody = in_array($method, ['post', 'put', 'patch'], true);

        $headers = [
            'date' => $date,
            'host' => $host,
        ];

        if ($hasBody) {
            $headers['x-content-sha256'] = base64_encode(hash('sha256', $body, true));
            $headers['content-type'] = 'application/json';
            $headers['content-length'] = (string) strlen($body);
        }

        $signedHeaderNames = $hasBody ? self::HEADERS_WITH_BODY : self::HEADERS_NO_BODY;

        // 构造签名串：(request-target) 用 "method path"；其余取上面 headers 值
        $lines = [];
        foreach ($signedHeaderNames as $name) {
            if ($name === '(request-target)') {
                $lines[] = '(request-target): '.$method.' '.$requestTarget;
            } else {
                $lines[] = $name.': '.$headers[$name];
            }
        }
        $signingString = implode("\n", $lines);

        $signature = $this->rsaSha256Sign($signingString, $this->authMaterial);

        $authorization = sprintf(
            'Signature version="1",keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"',
            $this->keyId(),
            implode(' ', $signedHeaderNames),
            $signature,
        );

        $headers['Authorization'] = $authorization;

        return $headers;
    }

    /**
     * RSA-SHA256 签名（支持带口令私钥），返回 base64。
     */
    private function rsaSha256Sign(string $data, OraclecloudAuthMaterial $authMaterial): string
    {
        $key = $authMaterial->privateKeyPassphrase() !== ''
            ? openssl_pkey_get_private($authMaterial->privateKey(), $authMaterial->privateKeyPassphrase())
            : openssl_pkey_get_private($authMaterial->privateKey());

        if ($key === false) {
            throw new OraclecloudApiException('InvalidCredential', 'OCI API 私钥解析失败（私钥或口令无效）');
        }

        $signature = '';
        $ok = openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);
        if (! $ok || $signature === '') {
            throw new OraclecloudApiException('InvalidCredential', 'OCI 请求签名失败');
        }

        return base64_encode($signature);
    }
}
