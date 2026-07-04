<?php

namespace Plugins\CloudDeploy\Deployers\Cloudflare;

use RuntimeException;

/**
 * Cloudflare REST 接口错误（区别于本地/网络层异常）。
 *
 * Cloudflare API v4 走 Bearer Token 鉴权（凭证在 Authorization 请求头，非 URL/body），响应体
 * `{success, errors:[{code,message}], result}`。CloudflareClient 把「HTTP 非 2xx」或「success=false」
 * 归一为本异常：携带 Cloudflare 错误码 + 自带 message（取自响应体 errors，不含凭证）。
 * CloudflareErrorSanitizer 据此类型只取 code + 安全描述。
 */
class CloudflareApiException extends RuntimeException
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
