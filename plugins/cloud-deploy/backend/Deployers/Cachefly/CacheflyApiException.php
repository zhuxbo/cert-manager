<?php

namespace Plugins\CloudDeploy\Deployers\Cachefly;

use RuntimeException;

/**
 * CacheFly REST 接口错误（区别于本地/网络层异常）。
 *
 * CacheFly API 走 Bearer Token 鉴权（凭证在 X-CF-Authorization 请求头，非 URL/body），失败响应体可能带
 * `{message: "..."}`。CacheflyClient 把「HTTP 非 2xx」归一为本异常：携带 HTTP 状态码作错误码 + 自带
 * message（取自响应体，不含 token）。CacheflyErrorSanitizer 据此类型只取 code + 安全描述。
 */
class CacheflyApiException extends RuntimeException
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
