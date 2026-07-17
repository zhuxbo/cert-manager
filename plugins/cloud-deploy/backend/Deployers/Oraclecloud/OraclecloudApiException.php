<?php

namespace Plugins\CloudDeploy\Deployers\Oraclecloud;

use RuntimeException;

/**
 * Oracle Cloud（OCI）REST 接口错误（区别于本地/网络层异常）。
 *
 * OCI 走 HTTP Signatures 鉴权（签名在 Authorization 请求头，无凭证明文；私钥仅本地签名用）。
 * OraclecloudClient 把「HTTP 非 2xx」归一为本异常：携带 OCI 错误码（响应体 code）+ message
 * （取自响应体 message，不含私钥/签名）。OraclecloudErrorSanitizer 据此类型只取 code + 安全描述。
 */
class OraclecloudApiException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        private readonly string $errorMessage,
    ) {
        parent::__construct("[$errorCode] $errorMessage");
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }
}
